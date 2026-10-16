<?php

namespace App\Services;

use App\Models\Association;
use App\Models\AssociationRegistration;
use App\Models\RegistrationAgency;
use App\Models\UserProfile;
use Illuminate\Support\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssociationService
{
    public function __construct(private AssociationLifecycleService $lifecycle) {}

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
                'area_id' => $data['area_id'],
                'saad_phase2_join_date' => $data['saad_phase2_join_date'],
                'primary_field_officer_id' => $data['primary_field_officer_id'],
                'status' => $data['status'],
            ]);
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
            $association = Association::whereKey($association->id)->lockForUpdate()->firstOrFail();
            if ($association->archived_at !== null) {
                throw ValidationException::withMessages(['association' => 'Archived associations cannot be edited.']);
            }
            // The existing administrative status form remains the reactivation workflow.
            if ($data['status'] !== 'active') $this->lifecycle->assertOperational($association, 'association');

            $name = trim($data['name']);
            $this->ensureUniqueName($name, $association);
            $this->ensureValidFieldOfficer($data['primary_field_officer_id']);
            $primary = $association->registrations()->where('is_primary', true)->first();
            if ($primary) $this->ensurePrimaryRegistrationDate([
                'is_primary' => true, 'registration_date' => $primary->registration_date->format('Y-m-d'),
            ], $data['saad_phase2_join_date']);

            $association->fill([
                'name' => $name,
                'acronym' => $data['acronym'] ?? null,
                'area_id' => $data['area_id'],
                'saad_phase2_join_date' => $data['saad_phase2_join_date'],
                'primary_field_officer_id' => $data['primary_field_officer_id'],
                'status' => $data['status'],
            ]);
            $association->updated_by = $currentUser->id;
            $association->save();

            return $association;
        });
    }

    public function archive(
        Association $association,
        UserProfile $currentUser
    ): Association {
        return DB::transaction(function () use ($association, $currentUser) {
            $association = Association::whereKey($association->id)->lockForUpdate()->firstOrFail();
            if ($association->archived_at !== null) {
                throw ValidationException::withMessages(['association' => 'This association has already been archived.']);
            }
            $association->archived_at = Carbon::now();
            $association->archived_by = $currentUser->id;
            $association->updated_by = $currentUser->id;
            $association->save();

            return $association;
        });
    }

    public function unarchive(
        Association $association,
        UserProfile $currentUser
    ): Association {
        return DB::transaction(function () use ($association, $currentUser) {
            $association = Association::whereKey($association->id)->lockForUpdate()->firstOrFail();
            if ($association->archived_at === null) {
                throw ValidationException::withMessages(['association' => 'This association is not archived.']);
            }
            $association->archived_at = null;
            $association->archived_by = null;
            $association->updated_by = $currentUser->id;
            $association->save();

            return $association;
        });
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
