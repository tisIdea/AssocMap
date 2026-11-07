<?php

namespace App\Services\Areas;

use App\Models\Barangay;
use App\Models\UserProfile;
use App\Services\Programs\ProgramActorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BarangayService
{
    public function __construct(private ProgramActorService $actors) {}

    public function update(Barangay $barangay, array $data, UserProfile $currentUser): void
    {
        DB::transaction(function () use ($barangay, $data, $currentUser): void {
            $actor = $this->actors->lock($currentUser);
            abort_unless($actor->role?->code === 'system_admin', 403);
            $name = trim($data['name']);
            if (Barangay::query()
                ->where('id', '!=', $barangay->id)
                ->where('municipality_id', $data['municipality_id'])
                ->whereRaw('LOWER(name) = LOWER(?)', [$name])
                ->exists()) {
                throw ValidationException::withMessages(['name' => 'This barangay already exists under the selected municipality or city.']);
            }

            $barangay->update([
                'name' => $name,
                'municipality_id' => $data['municipality_id'],
                'psgc_code' => $data['psgc_code'] ?? null,
            ]);
        });
    }

    public function deactivate(Barangay $barangay, UserProfile $currentUser): void
    {
        DB::transaction(function () use ($barangay, $currentUser): void {
            $actor = $this->actors->lock($currentUser);
            abort_unless($actor->role?->code === 'system_admin', 403);
            $barangay->update(['is_active' => false]);
        });
    }

    public function create(
        array $data,
        UserProfile $currentUser
    ): Barangay {
        return DB::transaction(function () use ($data, $currentUser): Barangay {
            $currentUser = $this->actors->lock($currentUser);
            abort_unless($currentUser->role?->code === 'system_admin', 403);
            $name = trim($data['name']);

            $duplicateExists = Barangay::query()
                ->where('municipality_id', $data['municipality_id'])
                ->whereRaw('LOWER(name) = LOWER(?)', [$name])
                ->exists();

            if ($duplicateExists) {
                throw ValidationException::withMessages([
                    'name' => 'This barangay already exists under the selected municipality or city.',
                ]);
            }

            $barangay = new Barangay([
                'municipality_id' => $data['municipality_id'],
                'name' => $name,
                'psgc_code' => $data['psgc_code'] ?? null,
                'source' => 'manual',
                'is_active' => true,
            ]);

            $barangay->created_by = $currentUser->id;

            $barangay->save();

            return $barangay;
        });
    }
}
