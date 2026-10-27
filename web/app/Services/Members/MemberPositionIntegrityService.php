<?php

namespace App\Services\Members;

use App\Models\AssociationPosition;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\UserProfile;
use App\Services\Audit\AuditLogService;
use App\Services\Programs\ProgramActorService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MemberPositionIntegrityService
{
    private const AUDIT_FIELDS = ['association_id', 'position_id', 'member_status', 'archived_at'];

    public function __construct(private AuditLogService $audit, private ProgramActorService $actors)
    {
    }

    public function lockActor(UserProfile $actor, string $roleCode): UserProfile
    {
        abort_unless($actor->role?->code === $roleCode, 403);

        return $this->actors->lock($actor);
    }

    public function activePosition(int|string $positionId, ?string $otherText): AssociationPosition
    {
        $position = AssociationPosition::whereKey($positionId)->sharedLock()->first();
        if (! $position || ! $position->is_active) {
            throw ValidationException::withMessages(['position_id' => 'The selected position is invalid or inactive.']);
        }
        if (in_array($position->code, ['PRESIDENT', 'VICE_PRESIDENT'], true) && ! $position->is_officer) {
            throw ValidationException::withMessages(['position_id' => 'The executive position lookup is inconsistent. Contact the administrator.']);
        }
        if ($position->code === 'OTHER' && trim($otherText ?? '') === '') {
            throw ValidationException::withMessages(['position_other_text' => 'Please specify the member position.']);
        }

        return $position;
    }

    public function assertAdmitted(Member $member): void
    {
        if ($member->source_application_id === null) return;
        $application = MemberApplication::whereKey($member->source_application_id)->sharedLock()
            ->first(['id', 'association_id', 'status']);
        if (! $application || $application->status !== 'approved'
            || $application->association_id !== $member->association_id) {
            throw ValidationException::withMessages([
                'member' => 'This member requires an approved source application belonging to the same association.',
            ]);
        }
    }

    public function snapshot(Member $member): array
    {
        return [
            'association_id' => $member->association_id,
            'position_id' => (int) $member->position_id,
            'member_status' => $member->member_status,
            'archived_at' => $member->archived_at?->toIso8601String(),
        ];
    }

    public function recordOfficerChange(UserProfile $actor, Member $member, ?array $before, string $action): void
    {
        $after = $this->snapshot($member);
        if ($before === $after) return;
        $officerIds = AssociationPosition::whereIn('id', array_filter([
            $before['position_id'] ?? null, $after['position_id'],
        ]))->where('is_officer', true)->sharedLock()->pluck('id')->all();
        $activeOfficer = static fn (?array $state): bool => $state !== null
            && $state['member_status'] === 'active' && $state['archived_at'] === null
            && in_array($state['position_id'], $officerIds);
        if (! $activeOfficer($before) && ! $activeOfficer($after)) return;

        $request = new Request;
        $request->attributes->set('current_user', $actor);
        $this->audit->record($request, $action, 'Association Management', 'member_officer_position', $member->id,
            $before, $after, self::AUDIT_FIELDS);
    }
}
