<?php

namespace App\Services;

use App\Models\AssociationPosition;
use App\Models\Member;
use App\Models\UserProfile;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class MemberService
{
    public function create(
        array $data,
        UserProfile $currentUser
    ): Member {
        $this->validatePositionOtherText($data);

        $member = new Member([
            'association_id' => $data['association_id'],
            'source_application_id' => null,

            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'last_name' => $data['last_name'],
            'suffix' => $data['suffix'] ?? null,

            'birth_date' => $data['birth_date'],
            'sex_id' => $data['sex_id'],

            'contact_number' =>
                $data['contact_number'] ?? null,

            'fishr_registration_number' =>
                $data['fishr_registration_number'] ?? null,

            'position_id' => $data['position_id'],
            'position_other_text' =>
                $this->resolvePositionOtherText($data),

            'date_registered' => $data['date_registered'],
            'member_status' => $data['member_status'],
        ]);

        $member->save();

        return $member;
    }

    public function update(
        Member $member,
        array $data,
        UserProfile $currentUser
    ): Member {
        if ($member->archived_at !== null) {
            throw ValidationException::withMessages([
                'member' =>
                    'Archived members cannot be edited.',
            ]);
        }

        $this->validatePositionOtherText($data);

        $member->fill([
            'association_id' => $data['association_id'],

            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'last_name' => $data['last_name'],
            'suffix' => $data['suffix'] ?? null,

            'birth_date' => $data['birth_date'],
            'sex_id' => $data['sex_id'],

            'contact_number' =>
                $data['contact_number'] ?? null,

            'fishr_registration_number' =>
                $data['fishr_registration_number'] ?? null,

            'position_id' => $data['position_id'],

            'position_other_text' =>
                $this->resolvePositionOtherText($data),

            'date_registered' => $data['date_registered'],
            'member_status' => $data['member_status'],
        ]);

        $member->save();

        return $member;
    }

    public function archive(
        Member $member,
        UserProfile $currentUser
    ): Member {
        if ($member->archived_at !== null) {
            throw ValidationException::withMessages([
                'member' =>
                    'This member has already been archived.',
            ]);
        }

        $member->archived_at = Carbon::now();
        $member->archived_by = $currentUser->id;

        $member->save();

        return $member;
    }

    public function unarchive(
        Member $member,
        UserProfile $currentUser
    ): Member {
        if ($member->archived_at === null) {
            throw ValidationException::withMessages([
                'member' =>
                    'This member is not archived.',
            ]);
        }

        $member->archived_at = null;
        $member->archived_by = null;

        $member->save();

        return $member;
    }

    private function validatePositionOtherText(
        array $data
    ): void {
        $position = AssociationPosition::query()
            ->whereKey($data['position_id'])
            ->where('is_active', true)
            ->first();

        if (! $position) {
            throw ValidationException::withMessages([
                'position_id' =>
                    'The selected position is invalid or inactive.',
            ]);
        }

        if (
            $position->code === 'OTHER' &&
            empty($data['position_other_text'])
        ) {
            throw ValidationException::withMessages([
                'position_other_text' =>
                    'Please specify the member position.',
            ]);
        }
    }

    private function resolvePositionOtherText(
        array $data
    ): ?string {
        $position = AssociationPosition::query()
            ->find($data['position_id']);

        if ($position?->code !== 'OTHER') {
            return null;
        }

        return $data['position_other_text'] ?? null;
    }
}