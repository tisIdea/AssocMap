<?php

namespace App\Services;

use App\Models\Association;
use App\Models\AssociationRegistration;
use App\Models\RegistrationAgency;
use App\Models\Role;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssociationService
{
    private const LIFECYCLE_AUDIT_FIELDS = ['association_id', 'status', 'archived_at', 'archived_by', 'updated_by'];

    public function __construct(private AssociationLifecycleService $lifecycle, private AuditLogService $audit,
        private AssociationGeographyService $geography) {}

    public function create(
        array $data,
        UserProfile $currentUser
    ): Association {
        $name = trim($data['name']);

        $this->ensureUniqueName($name);
        $this->ensureValidFieldOfficer(
            $data['primary_field_officer_id']
        );

        return DB::transaction(function () use ($data, $currentUser, $name) {
            $association = new Association([
                'name' => $name,
                'acronym' => $data['acronym'] ?? null,
                'saad_phase2_join_date' => $data['saad_phase2_join_date'],
                'primary_field_officer_id' => $data['primary_field_officer_id'],
                'status' => $data['status'],
            ]);
            $this->geography->apply($association, $data);
            $association->created_by = $currentUser->id;
            $association->updated_by = $currentUser->id;
            $association->save();
            if (! empty($data['registration'])) $this->recordRegistration($association, $data['registration']);

            return $association;
        });
    }

    public function update(
        Association $association,
        array $data,
        UserProfile $currentUser
    ): Association {
        return DB::transaction(function () use ($association, $data, $currentUser) {
            if (array_key_exists('registration', $data)) $this->lockAdministrator($currentUser);
            $association = Association::whereKey($association->id)->lockForUpdate()->firstOrFail();
            if ($association->archived_at !== null) {
                throw ValidationException::withMessages(['association' => 'Archived associations cannot be edited.']);
            }
            $this->geography->assertCurrent($association, $data);
            // The existing administrative status form remains the reactivation workflow.
            if ($data['status'] !== 'active') $this->lifecycle->assertOperational($association, 'association');

            $name = trim($data['name']);
            $this->ensureUniqueName($name, $association);
            $this->ensureValidFieldOfficer($data['primary_field_officer_id']);
            $accreditation = array_key_exists('registration', $data)
                ? $this->lockAccreditation($association, $data['registration']) : null;
            $primary = $association->registrations()->where('is_primary', true)->first();
            if ($primary) $this->ensurePrimaryRegistrationDate([
                'is_primary' => true, 'registration_date' => $primary->id === $accreditation?->id
                    ? $data['registration']['registration_date'] : $primary->registration_date->format('Y-m-d'),
            ], $data['saad_phase2_join_date']);

            $association->fill([
                'name' => $name,
                'acronym' => $data['acronym'] ?? null,
                'saad_phase2_join_date' => $data['saad_phase2_join_date'],
                'primary_field_officer_id' => $data['primary_field_officer_id'],
                'status' => $data['status'],
            ]);
            $this->geography->apply($association, $data);
            $association->updated_by = $currentUser->id;
            $association->save();

            if ($accreditation) {
                $this->lifecycle->assertOperational($association, 'registration.id');
                $changes = array_intersect_key($data['registration'], array_flip([
                    'registration_number', 'registration_date', 'expiration_date', 'remarks',
                ]));
                $changes['registration_number'] = trim($changes['registration_number']);
                if (array_key_exists('remarks', $changes) && is_string($changes['remarks'])) {
                    $remarks = trim($changes['remarks']);
                    $changes['remarks'] = $remarks === '' ? null : $remarks;
                }
                $accreditation->forceFill($changes);
                if ($accreditation->isDirty()) {
                    try {
                        $accreditation->save();
                    } catch (QueryException $exception) {
                        if ($exception->getCode() === '23505') {
                            throw ValidationException::withMessages(['registration.registration_number' => 'This document number already exists for the issuing agency.']);
                        }
                        throw $exception;
                    }
                    // Advance the parent revision even when only its child record changed.
                    $association->newQuery()->whereKey($association->id)->update(['updated_at' => now()->format('Y-m-d H:i:s.u')]);
                    $association->refresh();
                }
            }

            return $association;
        });
    }

    private function lockAccreditation(Association $association, mixed $input): AssociationRegistration
    {
        $this->lifecycle->assertOperational($association, 'registration.id');
        $data = validator(['registration' => $input], [
            'registration' => ['required', 'array:id,expected_updated_at,registration_number,registration_date,expiration_date,remarks',
                'required_array_keys:id,expected_updated_at,registration_number,registration_date'],
            'registration.id' => ['required', 'uuid'],
            'registration.expected_updated_at' => ['required', 'string', 'max:50'],
            'registration.registration_number' => ['required', 'string', 'max:150'],
            'registration.registration_date' => ['bail', 'required', 'string', 'date_format:Y-m-d'],
            'registration.expiration_date' => ['bail', 'sometimes', 'nullable', 'string', 'date_format:Y-m-d',
                ...(is_array($input) && is_string($input['registration_date'] ?? null) ? ['after_or_equal:registration.registration_date'] : [])],
            'registration.remarks' => ['sometimes', 'nullable', 'string', 'max:4000'],
        ])->validate()['registration'];
        $record = AssociationRegistration::query()->where('association_id', $association->id)
            ->whereKey($data['id'])->lockForUpdate()->first();
        $agency = $record ? RegistrationAgency::whereKey($record->agency_id)->sharedLock()->first() : null;
        if (! $agency?->is_active || $agency->registration_kind !== 'accreditation') {
            throw ValidationException::withMessages(['registration.id' => 'Select an existing accreditation record for this association with an active issuing agency.']);
        }
        if ($data['expected_updated_at'] !== $record->updated_at?->format('Y-m-d\TH:i:s.uP')) {
            throw ValidationException::withMessages(['registration' => 'This accreditation changed after you opened the form. Reload it before saving.']);
        }
        if (AssociationRegistration::query()->where('agency_id', $agency->id)->whereKeyNot($record->id)
            ->where('registration_number', trim($data['registration_number']))->exists()) {
            throw ValidationException::withMessages(['registration.registration_number' => 'This document number already exists for the issuing agency.']);
        }

        return $record;
    }

    public function archive(
        Association $association,
        UserProfile $currentUser
    ): Association {
        return DB::transaction(function () use ($association, $currentUser) {
            $actor = $this->lockAdministrator($currentUser);
            $association = Association::whereKey($association->id)->lockForUpdate()->firstOrFail();
            if ($association->archived_at !== null) {
                throw ValidationException::withMessages(['association' => 'This association has already been archived.']);
            }
            $before = $this->lifecycleSnapshot($association);
            $association->archived_at = Carbon::now();
            $association->archived_by = $actor->id;
            $association->updated_by = $actor->id;
            $association->save();
            $this->recordLifecycleChange($actor, $association, $before, 'ARCHIVE ASSOCIATION');

            return $association;
        });
    }

    public function unarchive(
        Association $association,
        UserProfile $currentUser
    ): Association {
        return DB::transaction(function () use ($association, $currentUser) {
            $actor = $this->lockAdministrator($currentUser);
            $association = Association::whereKey($association->id)->lockForUpdate()->firstOrFail();
            if ($association->archived_at === null) {
                throw ValidationException::withMessages(['association' => 'This association is not archived.']);
            }
            $before = $this->lifecycleSnapshot($association);
            $association->archived_at = null;
            $association->archived_by = null;
            $association->updated_by = $actor->id;
            $association->save();
            $this->recordLifecycleChange($actor, $association, $before, 'RESTORE ASSOCIATION');

            return $association;
        });
    }

    private function lockAdministrator(UserProfile $user): UserProfile
    {
        $actor = UserProfile::whereKey($user->id)->sharedLock()->first();
        abort_unless($actor, 403);
        $actor->setRelation('role', Role::whereKey($actor->role_id)->sharedLock()->first());
        abort_unless($actor->canAccessApplication() && $actor->role?->code === 'system_admin', 403);

        return $actor;
    }

    private function lifecycleSnapshot(Association $association): array
    {
        return ['association_id' => $association->id, 'status' => $association->status,
            'archived_at' => $association->archived_at?->toIso8601String(),
            'archived_by' => $association->archived_by, 'updated_by' => $association->updated_by];
    }

    private function recordLifecycleChange(UserProfile $actor, Association $association, array $before, string $action): void
    {
        $request = new Request;
        $request->attributes->set('current_user', $actor);
        $this->audit->record($request, $action, 'Association Management', 'association_lifecycle', $association->id,
            $before, $this->lifecycleSnapshot($association), self::LIFECYCLE_AUDIT_FIELDS);
    }

    private function ensureUniqueName(
        string $name,
        ?Association $ignore = null
    ): void {
        $query = Association::query()
            ->whereRaw('LOWER(name) = LOWER(?)', [$name]);

        if ($ignore !== null) {
            $query->where('id', '!=', $ignore->id);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => 'An association with this name already exists.',
            ]);
        }
    }

    private function ensurePrimaryRegistrationDate(array $registration, string $joinDate): void
    {
        if (! empty($registration['is_primary']) && Carbon::parse($registration['registration_date'])->startOfDay()->gt(Carbon::parse($joinDate)->startOfDay())) {
            throw ValidationException::withMessages(['saad_phase2_join_date' => 'SAAD Phase II join date cannot precede the primary registration date.']);
        }
    }

    private function recordRegistration(Association $association, array $registration): void
    {
        $this->lifecycle->assertOperational($association);
        $agency = RegistrationAgency::whereKey($registration['agency_id'])->sharedLock()->first();
        if (! $agency?->is_active) {
            throw ValidationException::withMessages(['registration.agency_id' => 'Select an active registration agency.']);
        }
        $this->ensurePrimaryRegistrationDate($registration, $association->saad_phase2_join_date->format('Y-m-d'));
        $record = new AssociationRegistration;
        $record->forceFill([
            'association_id' => $association->id, 'agency_id' => $agency->id,
            'registration_number' => trim($registration['registration_number']),
            'registration_date' => $registration['registration_date'],
            'expiration_date' => $registration['expiration_date'] ?? null,
            'is_primary' => $registration['is_primary'] ?? false, 'remarks' => $registration['remarks'] ?? null,
        ]);
        try {
            $record->save();
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) === '23505') {
                throw ValidationException::withMessages(['registration.registration_number' => 'This registration is already recorded. No association was created.']);
            }
            throw $exception;
        }
    }

    private function ensureValidFieldOfficer(
        string $userId
    ): void {
        $fieldOfficer = UserProfile::query()
            ->with('role')
            ->whereKey($userId)
            ->where('is_active', true)
            ->first();

        if (
            ! $fieldOfficer ||
            $fieldOfficer->role?->code !== 'field_officer'
        ) {
            throw ValidationException::withMessages([
                'primary_field_officer_id' =>
                    'The selected user is not an active Field Officer.',
            ]);
        }
    }
}
