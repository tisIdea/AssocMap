<?php

namespace App\Services;

use App\Models\Association;
use App\Models\Member;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MemberService
{
    private const PROFILE_FIELDS = ['first_name', 'middle_name', 'last_name', 'suffix',
        'birth_date', 'sex_id', 'contact_number', 'fishr_registration_number'];
    private const AUDIT_FIELDS = ['association_id', 'member_status', 'archived_at', 'archived_by', 'changed_fields'];

    public function __construct(
        private MemberExecutivePositionService $executivePositions,
        private MemberPositionIntegrityService $positions,
        private AuditLogService $audit,
        private AssociationLifecycleService $lifecycle,
    ) {}

    public function update(Member $member, array $data, UserProfile $currentUser): Member
    {
        return DB::transaction(function () use ($member, $data, $currentUser) {
            [$actor, $member] = $this->lockForMutation($member, $data, $currentUser);
            $this->ensureActive($member);
            $this->ensureAllowedFields($data, self::PROFILE_FIELDS);
            $before = $this->lifecycleSnapshot($member);
            $member->fill(array_intersect_key($data, array_flip(self::PROFILE_FIELDS)));
            $changedFields = array_keys($member->getDirty());
            if ($changedFields === []) return $member;

            $member->save();
            $this->recordChange($actor, $member, 'UPDATE MEMBER PROFILE', $before, $changedFields);

            return $member;
        });
    }

    public function archive(Member $member, array $data, UserProfile $currentUser): Member
    {
        return DB::transaction(function () use ($member, $data, $currentUser) {
            [$actor, $member] = $this->lockForMutation($member, $data, $currentUser);
            $this->ensureAllowedFields($data, ['confirm_change']);
            $this->ensureConfirmed($data);
            $this->ensureActive($member);
            $before = $this->lifecycleSnapshot($member);
            $officerBefore = $this->positions->snapshot($member);
            $member->member_status = 'inactive';
            $member->archived_at = now();
            $member->archived_by = $actor->id;
            $member->save();
            $this->recordChange($actor, $member, 'ARCHIVE MEMBER', $before);
            $this->positions->recordOfficerChange($actor, $member, $officerBefore, 'ARCHIVE ASSOCIATION OFFICER');

            return $member;
        });
    }

    public function unarchive(Member $member, array $data, UserProfile $currentUser): Member
    {
        return DB::transaction(function () use ($member, $data, $currentUser) {
            [$actor, $member] = $this->lockForMutation($member, $data, $currentUser);
            $this->ensureAllowedFields($data, ['confirm_change']);
            $this->ensureConfirmed($data);
            if ($member->archived_at === null) {
                throw ValidationException::withMessages(['member' => 'Only archived members can be reactivated.']);
            }

            $before = $this->lifecycleSnapshot($member);
            $officerBefore = $this->positions->snapshot($member);
            $this->positions->assertAdmitted($member);
            $this->positions->activePosition($member->position_id, $member->position_other_text);
            $member->member_status = 'active';
            $member->archived_at = null;
            $member->archived_by = null;
            $this->executivePositions->save($member);
            $this->recordChange($actor, $member, 'REACTIVATE MEMBER', $before);
            $this->positions->recordOfficerChange($actor, $member, $officerBefore, 'RESTORE ASSOCIATION OFFICER');

            return $member;
        });
    }

    private function lockForMutation(Member $member, array $data, UserProfile $currentUser): array
    {
        $actor = $this->positions->lockActor($currentUser, 'field_officer');
        $association = Association::whereKey($member->association_id)->lockForUpdate()->firstOrFail();
        abort_unless($association->primary_field_officer_id === $actor->id, 403);
        $this->lifecycle->assertOperational($association, 'member');
        $locked = Member::whereKey($member->id)->lockForUpdate()->firstOrFail();
        if ($locked->association_id !== $association->id
            || ($data['member_record_id'] ?? null) !== $locked->id
            || ($data['expected_updated_at'] ?? null) !== $locked->updated_at?->format('Y-m-d\TH:i:s.uP')) {
            throw ValidationException::withMessages([
                'member' => 'This member changed after the form was opened. Refresh and review the current record before saving.',
            ])->status(409);
        }

        return [$actor, $locked];
    }

    private function ensureActive(Member $member): void
    {
        if ($member->member_status !== 'active' || $member->archived_at !== null) {
            throw ValidationException::withMessages(['member' => 'Only active, nonarchived members can be edited or archived.']);
        }
    }

    private function ensureAllowedFields(array $data, array $fields): void
    {
        if (array_diff(array_keys($data), [...$fields, 'member_record_id', 'member_action', 'expected_updated_at']) !== []) {
            throw ValidationException::withMessages(['member' => 'The request contains fields that cannot be changed in this workflow.']);
        }
    }

    private function ensureConfirmed(array $data): void
    {
        if (! in_array($data['confirm_change'] ?? null, ['1', 1, true, 'true', 'yes', 'on'], true)) {
            throw ValidationException::withMessages(['confirm_change' => 'Explicitly confirm this member action.']);
        }
    }

    private function lifecycleSnapshot(Member $member): array
    {
        return ['association_id' => $member->association_id, 'member_status' => $member->member_status,
            'archived_at' => $member->archived_at?->toIso8601String(), 'archived_by' => $member->archived_by];
    }

    private function recordChange(UserProfile $actor, Member $member, string $action, array $before, array $changedFields = []): void
    {
        $request = new Request;
        $request->attributes->set('current_user', $actor);
        $after = $this->lifecycleSnapshot($member);
        if ($changedFields !== []) $after['changed_fields'] = $changedFields;
        $this->audit->record($request, $action, 'Member Management', 'member', $member->id,
            $before, $after, self::AUDIT_FIELDS);
    }
}
