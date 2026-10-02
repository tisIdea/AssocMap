<?php

namespace App\Services;

use App\Models\Association;
use App\Models\UserProfile;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class AssociationService
{
    public function create(
        array $data,
        UserProfile $currentUser
    ): Association {
        $name = trim($data['name']);

        $this->ensureUniqueName($name);
        $this->ensureValidFieldOfficer(
            $data['primary_field_officer_id']
        );

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

        return $association;
    }

    public function update(
        Association $association,
        array $data,
        UserProfile $currentUser
    ): Association {
        if ($association->archived_at !== null) {
            throw ValidationException::withMessages([
                'association' => 'Archived associations cannot be edited.',
            ]);
        }

        $name = trim($data['name']);

        $this->ensureUniqueName($name, $association);
        $this->ensureValidFieldOfficer(
            $data['primary_field_officer_id']
        );

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
    }

    public function archive(
        Association $association,
        UserProfile $currentUser
    ): Association {
        if ($association->archived_at !== null) {
            throw ValidationException::withMessages([
                'association' => 'This association has already been archived.',
            ]);
        }

        $association->archived_at = Carbon::now();
        $association->archived_by = $currentUser->id;
        $association->updated_by = $currentUser->id;

        $association->save();

        return $association;
    }

    public function unarchive(
        Association $association,
        UserProfile $currentUser
    ): Association {
        if ($association->archived_at === null) {
            throw ValidationException::withMessages([
                'association' => 'This association is not archived.',
            ]);
        }

        $association->archived_at = null;
        $association->archived_by = null;
        $association->updated_by = $currentUser->id;
        $association->save();

        return $association;
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
