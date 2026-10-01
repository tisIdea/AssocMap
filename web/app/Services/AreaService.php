<?php

namespace App\Services;

use App\Models\Area;
use App\Models\UserProfile;
use Illuminate\Validation\ValidationException;

class AreaService
{
    public function create(
        array $data,
        UserProfile $currentUser
    ): Area {
        $address = trim($data['address']);

        $duplicateExists = Area::query()
            ->where('barangay_id', $data['barangay_id'])
            ->whereRaw('LOWER(address) = LOWER(?)', [$address])
            ->exists();

        if ($duplicateExists) {
            throw ValidationException::withMessages([
                'address' => 'This area already exists under the selected barangay.',
            ]);
        }

        $area = new Area([
            'barangay_id' => $data['barangay_id'],
            'address' => $address,
            'source' => 'manual',
            'is_active' => true,
        ]);

        $area->created_by = $currentUser->id;

        $area->save();

        return $area;
    }
}