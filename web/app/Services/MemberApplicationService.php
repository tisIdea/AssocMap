<?php

namespace App\Services;

use App\Models\AssociationAccount;
use App\Models\AssociationPosition;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\UserProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Carbon;
use Throwable;

class MemberApplicationService
{
    public function __construct(
        private MemberExecutivePositionService $executivePositions,
        private MemberApplicationPhotoService $photos
    ) {}

    public function submit(array $data, UserProfile $currentUser): MemberApplication
    {
        $this->ensureAssociationAccountCanSubmit($currentUser);
        $associationAccount = AssociationAccount::query()
            ->where('user_id', $currentUser->id)
            ->where('is_active', true)
            ->first();

        if (! $associationAccount) {
            throw ValidationException::withMessages([
                'account' => 'No active Association Account is linked to this user.',
            ]);
        }

        $this->validatePositionOtherText($data);
        $this->ensureFishRAvailable($data['fishr_registration_number'] ?? null);

        $applicationId = (string) Str::uuid();
        $photoPath = $this->photos->store($data['applicant_photo'], $applicationId);
        $callbackCompleted = false;

        try {
            return DB::transaction(function () use ($data, $currentUser, $associationAccount,
                $applicationId, $photoPath, &$callbackCompleted) {
                $actor = UserProfile::query()->with('role')->whereKey($currentUser->id)->lockForUpdate()->first();
                $this->ensureAssociationAccountCanSubmit($actor);
                $link = AssociationAccount::query()
                    ->whereKey($associationAccount->id)
                    ->where('user_id', $actor->id)
                    ->where('association_id', $associationAccount->association_id)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                if (! $link) {
                    throw ValidationException::withMessages([
                        'account' => 'Your active association link changed. Refresh the page before submitting again.',
                    ]);
                }

                $application = new MemberApplication([
                    'association_id' => $link->association_id,
                    'submitted_by_user_id' => $actor->id,
                    'first_name' => $data['first_name'],
                    'middle_name' => $data['middle_name'] ?? null,
                    'last_name' => $data['last_name'],
                    'suffix' => $data['suffix'] ?? null,
                    'birth_date' => $data['birth_date'],
                    'sex_id' => $data['sex_id'],
                    'contact_number' => $data['contact_number'] ?? null,
                    'fishr_registration_number' => $data['fishr_registration_number'] ?? null,
                    'position_id' => $data['position_id'],
                    'position_other_text' => $this->resolvePositionOtherText($data),
                    'endorsement_letter' => $data['endorsement_letter'],
                    'applicant_photo_path' => $photoPath,
                    'status' => 'pending',
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'rejection_reason' => null,
                ]);
                $application->id = $applicationId;
                $application->save();
                $application->classifications()->sync($data['classification_ids'] ?? []);
                $callbackCompleted = true;

                return $application;
            });
        } catch (Throwable $exception) {
            // Commit failures may have an unknown outcome; retain evidence for reconciliation.
            if (! $callbackCompleted && DB::transactionLevel() === 0) {
                $this->photos->discard($photoPath, $applicationId);
            } else {
                Log::warning('Member application submission requires reconciliation.', [
                    'application_id' => $applicationId,
                    'code' => 'submission_outcome_uncertain',
                ]);
            }

            throw $exception;
        }
    }

    private function ensureAssociationAccountCanSubmit(?UserProfile $actor): void
    {
        if (! $actor || ! $actor->canAccessApplication() || $actor->role?->code !== 'association_account') {
            abort(403, 'Only an active authorized Association Account may submit applications.');
        }
    }

    public function approve(
        MemberApplication $application,
        UserProfile $fieldOfficer
    ): Member {
        return DB::transaction(function () use (
            $application,
            $fieldOfficer
        ) {
            $application = MemberApplication::query()
                ->whereKey($application->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureFieldOfficerCanReview(
                $application,
                $fieldOfficer
            );

            if ($application->status !== 'pending') {
                throw ValidationException::withMessages([
                    'application' =>
                        'This application has already been reviewed. Refresh the list before making another decision.',
                ])->status(409);
            }

            $member = new Member([
                'association_id' =>
                    $application->association_id,

                'source_application_id' =>
                    $application->id,

                'first_name' =>
                    $application->first_name,

                'middle_name' =>
                    $application->middle_name,

                'last_name' =>
                    $application->last_name,

                'suffix' =>
                    $application->suffix,

                'birth_date' =>
                    $application->birth_date,

                'sex_id' =>
                    $application->sex_id,

                'contact_number' =>
                    $application->contact_number,

                'fishr_registration_number' =>
                    $application->fishr_registration_number,

                'position_id' =>
                    $application->position_id,

                'position_other_text' =>
                    $application->position_other_text,

                'date_registered' => now()->toDateString(),

                'member_status' => 'active',
            ]);

            $this->executivePositions->save($member);
            $member->classifications()->sync(
                $application
                    ->classifications()
                    ->pluck('member_classifications.id')
                    ->all()
            );
            $application->status = 'approved';
            $application->reviewed_by = $fieldOfficer->id;
            $application->reviewed_at = Carbon::now();
            $application->rejection_reason = null;
            $application->save();

            return $member;
        });
    }

    public function reject(
        MemberApplication $application,
        UserProfile $fieldOfficer,
        string $reason
    ): MemberApplication {
        return DB::transaction(function () use (
            $application,
            $fieldOfficer,
            $reason
        ) {
            $application = MemberApplication::query()
                ->whereKey($application->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureFieldOfficerCanReview(
                $application,
                $fieldOfficer
            );

            if ($application->status !== 'pending') {
                throw ValidationException::withMessages([
                    'application' =>
                        'This application has already been reviewed. Refresh the list before making another decision.',
                ])->status(409);
            }

            $reason = trim($reason);

            if ($reason === '') {
                throw ValidationException::withMessages([
                    'rejection_reason' =>
                        'A rejection reason is required.',
                ]);
            }

            $application->status = 'rejected';
            $application->reviewed_by = $fieldOfficer->id;
            $application->reviewed_at = Carbon::now();
            $application->rejection_reason = $reason;

            $application->save();

            return $application;
        });
    }

    private function ensureFieldOfficerCanReview(
        MemberApplication $application,
        UserProfile $fieldOfficer
    ): void {
        $fieldOfficer->loadMissing('role');

        if (
            ! $fieldOfficer->is_active ||
            $fieldOfficer->role?->code !== 'field_officer'
        ) {
            throw ValidationException::withMessages([
                'reviewer' =>
                    'Only an active Field Officer can review applications.',
            ]);
        }

        $assigned = $fieldOfficer
            ->assignedAssociations()
            ->whereKey($application->association_id)
            ->exists();

        if (! $assigned) {
            throw ValidationException::withMessages([
                'reviewer' =>
                    'This application does not belong to an association assigned to this Field Officer.',
            ]);
        }
    }

    private function validatePositionOtherText(
        array $data
    ): void {
        $position = AssociationPosition::query()
            ->whereKey($data['position_id'])
            ->where('is_active', true)
            ->first();

        if (! $position) {
            throw ValidationException::withMessages([
                'position_id' =>
                    'The selected position is invalid or inactive.',
            ]);
        }

        if (
            $position->code === 'OTHER' &&
            empty($data['position_other_text'])
        ) {
            throw ValidationException::withMessages([
                'position_other_text' =>
                    'Please specify the member position.',
            ]);
        }
    }

    private function resolvePositionOtherText(
        array $data
    ): ?string {
        $position = AssociationPosition::query()
            ->find($data['position_id']);

        if ($position?->code !== 'OTHER') {
            return null;
        }

        return $data['position_other_text'] ?? null;
    }

    private function ensureFishRAvailable(
        ?string $fishRNumber
    ): void {
        if ($fishRNumber === null) {
            return;
        }

        $existsInMembers = Member::query()
            ->where(
                'fishr_registration_number',
                $fishRNumber
            )
            ->exists();

        if ($existsInMembers) {
            throw ValidationException::withMessages([
                'fishr_registration_number' =>
                    'This FishR registration number already belongs to an existing member.',
            ]);
        }

        $existsInApplications =
            MemberApplication::query()
                ->where(
                    'fishr_registration_number',
                    $fishRNumber
                )
                ->whereIn(
                    'status',
                    ['pending', 'approved']
                )
                ->exists();

        if ($existsInApplications) {
            throw ValidationException::withMessages([
                'fishr_registration_number' =>
                    'This FishR registration number already has a pending or approved application.',
            ]);
        }
    }
}
