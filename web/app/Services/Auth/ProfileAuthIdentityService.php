<?php

namespace App\Services\Auth;

use Illuminate\Database\QueryException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProfileAuthIdentityService
{
    public function matchingEmails(string $pattern): Builder
    {
        return DB::table('auth.users')->select('id')->whereRaw("LOWER(email) LIKE ? ESCAPE '!'", [$pattern]);
    }

    public function read(array $profileIds): ?Collection
    {
        if ($profileIds === []) return collect();

        try {
            return DB::table('auth.users')->whereIn('id', $profileIds)->get(['id', 'email'])->keyBy('id');
        } catch (QueryException $exception) {
            Log::notice('Profile Auth metadata could not be read.', ['code' => 'auth_profile_lookup_unavailable']);

            return null;
        }
    }
}
