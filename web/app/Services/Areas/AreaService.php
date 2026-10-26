<?php

namespace App\Services\Areas;

use App\Models\Area;
use App\Models\Barangay;
use App\Models\Municipality;
use App\Models\UserProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AreaService
{
    public function create(
        array $data,
        UserProfile $currentUser
    ): Area {
        $data['address'] = is_string($data['address'] ?? null) ? trim($data['address']) : ($data['address'] ?? null);
        validator($data, [
            'municipality_id' => ['required', 'uuid'],
            'barangay_id' => ['required', 'uuid'],
            'address' => ['required', 'string'],
        ])->validate();

        return DB::transaction(function () use ($data, $currentUser): Area {
            $municipality = Municipality::whereKey($data['municipality_id'])->sharedLock()->first();
            // Serialize Area creation under one Barangay and recheck its current parent.
            $barangay = Barangay::whereKey($data['barangay_id'])->lockForUpdate()->first();
            if (! $municipality?->is_active) {
                throw ValidationException::withMessages([
                    'municipality_id' => 'Select an active municipality / city.',
                ]);
            }
            if (! $barangay?->is_active || strcasecmp($barangay->municipality_id, $municipality->id) !== 0) {
                throw ValidationException::withMessages([
                    'barangay_id' => 'Select an active barangay in the selected municipality / city.',
                ]);
            }

            if (Area::query()->where('barangay_id', $barangay->id)
                ->whereRaw('LOWER(address) = LOWER(?)', [$data['address']])->exists()) {
                throw ValidationException::withMessages([
                    'address' => 'This area already exists under the selected barangay.',
                ]);
            }

            $area = new Area([
                'barangay_id' => $barangay->id,
                'address' => $data['address'],
                'source' => 'manual',
                'is_active' => true,
            ]);

            $area->created_by = $currentUser->id;

            $area->save();

            return $area;
        });
    }
}
