<?php

namespace App\Services;

use App\Models\Municipality;
use App\Models\UserProfile;
use Illuminate\Validation\ValidationException;

class MunicipalityService
{
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