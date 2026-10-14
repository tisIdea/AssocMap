<?php

namespace App\Services;

use App\Models\AssociationPosition;
use App\Models\Member;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class MemberExecutivePositionService
{
    public const UNIQUE_INDEX = 'uq_members_active_executive_position';

    public function save(Member $member): void
    {
        $this->assertAvailable($member);

        try {
            $member->save();
        } catch (QueryException $exception) {
            if (! $this->isExecutiveConflict($exception)) {
                throw $exception;
            }

            throw $this->conflict();
        }
    }

    public function isExecutiveConflict(QueryException $exception): bool
    {
        if (($exception->errorInfo[0] ?? null) !== '23505') {
            return false;
        }

        // Match the index in the driver diagnostic, never SQL bindings or DETAIL values.
        $diagnostic = $exception->errorInfo[2] ?? '';

        return preg_match('/^[^\r\n]*?"([^"\r\n]+)"/', $diagnostic, $matches) === 1
            && $matches[1] === self::UNIQUE_INDEX;
    }

    private function assertAvailable(Member $member): void
    {
        if ($member->member_status !== 'active' || $member->archived_at !== null) {
            return;
        }

        $executive = AssociationPosition::query()
            ->whereKey($member->position_id)
            ->whereIn('code', ['PRESIDENT', 'VICE_PRESIDENT'])
            ->exists();

        if (! $executive) {
            return;
        }

        $holders = Member::query()
            ->where('association_id', $member->association_id)
            ->where('position_id', $member->position_id)
            ->where('member_status', 'active')
            ->whereNull('archived_at');

        if ($member->exists) {
            $holders->where('id', '<>', $member->getKey());
        }

        if ($holders->exists()) {
            throw $this->conflict();
        }
    }

    private function conflict(): ValidationException
    {
        return ValidationException::withMessages([
            'position_id' => 'The selected association already has an active, nonarchived holder of this executive position. Refresh the member list before trying again.',
        ])->status(409);
    }
}
