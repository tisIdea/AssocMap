<?php

namespace App\Services;

use App\Models\Municipality;
use App\Models\UserProfile;
use Illuminate\Validation\ValidationException;

class MunicipalityService
{
    public function update(Municipality $municipality, array $data): void
    {
        $name = trim($data['name']);
        if (Municipality::query()
            ->where('id', '!=', $municipality->id)
            ->whereRaw('LOWER(name) = LOWER(?)', [$name])
            ->whereRaw('LOWER(province) = LOWER(?)', [$municipality->province])
            ->exists()) {
            throw ValidationException::withMessages(['name' => 'This locality already exists in the province.']);
        }

        $municipality->update(['name' => $name, 'psgc_code' => $data['psgc_code'] ?? null]);
    }

    public function deactivate(Municipality $municipality): void
    {
        $municipality->update(['is_active' => false]);
    }

    public function create(
        array $data,
        UserProfile $currentUser
    ): Municipality {
        $name = trim($data['name']);

        $duplicateExists = Municipality::query()
            ->whereRaw('LOWER(name) = LOWER(?)', [$name])
            ->whereRaw('LOWER(province) = LOWER(?)', ['Cebu'])
            ->exists();

        if ($duplicateExists) {
            throw ValidationException::withMessages([
                'name' => 'This municipality already exists in Cebu.',
            ]);
        }

        $municipality = new Municipality([
            'name' => $name,
            'psgc_code' => $data['psgc_code'] ?? null,
            'province' => 'Cebu',
            'region' => 'Region VII',
            'source' => 'manual',
            'is_active' => true,
        ]);

        $municipality->created_by = $currentUser->id;

        $municipality->save();

        return $municipality;
    }
}
