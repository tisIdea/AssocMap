<?php

namespace App\Services\Associations;

use App\Models\Association;
use App\Models\AssociationPosition;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\Role;
use App\Models\UserProfile;
use App\Services\Audit\AuditLogService;
use App\Services\Members\MemberExecutivePositionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssociationOfficerService
{
    public function __construct(
        private MemberExecutivePositionService $executives,
        private AuditLogService $audit,
        private AssociationLifecycleService $lifecycle,
    ) {
    }

    public function authorize(UserProfile $actor, ?Association $association = null): void
    {
        $actor->loadMissing('role');
        abort_unless($actor->canAccessApplication()
            && in_array($actor->role?->code, ['system_admin', 'field_officer'], true), 403);
        if ($association && $actor->role->code === 'field_officer') {
            abort_unless($association->primary_field_officer_id === $actor->id, 403);
        }
    }

    public function eligibleMembers(Association $association): Builder
    {
        return Member::query()->where('association_id', $association->id)
            ->where('member_status', 'active')->whereNull('archived_at')
            ->where(function ($query) {
                $query->whereNull('source_application_id')->orWhereExists(function ($application) {
                    $application->selectRaw('1')->from('member_applications')
                        ->whereColumn('member_applications.id', 'members.source_application_id')
                        ->whereColumn('member_applications.association_id', 'members.association_id')
                        ->where('member_applications.status', 'approved');
                });
            });
    }

    public function change(Request $request, Association $association, array $data): void
    {
        DB::transaction(function () use ($request, $association, $data) {
            $actor = UserProfile::whereKey($request->attributes->get('current_user')?->id)->sharedLock()->first();
            abort_unless($actor, 403);
            $actor->setRelation('role', Role::whereKey($actor->role_id)->sharedLock()->first());
            $association = Association::whereKey($association->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $association);
            $request->attributes->set('current_user', $actor);
            $this->lifecycle->assertOperational($association, 'association');

            $action = $data['action'];
            if (! in_array($action, ['assign', 'replace', 'remove'], true)
                || ! in_array($data['confirm_change'] ?? null, ['1', 1, true, 'true', 'yes', 'on'], true)) {
                throw ValidationException::withMessages(['confirm_change' => 'Confirm the officer position change.']);
            }
            $ids = [strtolower($data['member_id'])];
            if ($action === 'replace') $ids[] = strtolower($data['former_member_id']);
            if (count(array_unique($ids)) !== count($ids)) {
                throw ValidationException::withMessages(['member_id' => 'Select a different replacement member.']);
            }
            $members = Member::where('association_id', $association->id)->whereIn('id', $ids)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($members->count() !== count($ids)) {
                throw ValidationException::withMessages(['member_id' => 'A selected member is missing or belongs to another association.']);
            }
            $member = $members[$ids[0]];
            $this->assertCurrent($member, $data['expected_position_id'], $data['expected_updated_at']);
            $former = $action === 'replace' ? $members[$ids[1]] : null;
            if ($former) $this->assertCurrent($former, $data['expected_former_position_id'], $data['expected_former_updated_at']);

            $application = $action !== 'remove' && $member->source_application_id
                ? MemberApplication::whereKey($member->source_application_id)->sharedLock()->first(['id', 'association_id', 'status'])
                : null;
            if ($action !== 'remove' && $member->source_application_id !== null
                && (! $application || $application->status !== 'approved' || $application->association_id !== $association->id)) {
                throw ValidationException::withMessages(['member_id' => 'Officer assignment requires an approved member application.']);
            }
            $positions = AssociationPosition::query()->orderBy('id')->sharedLock()->get();
            $ordinaryMember = $positions->firstWhere('code', 'MEMBER');
            $target = $action === 'remove' ? $ordinaryMember
                : $positions->firstWhere('id', $former ? $former->position_id : (int) $data['position_id']);
            if (! $target || ! $target->is_active || ($action !== 'remove' && ! $target->is_officer)) {
                throw ValidationException::withMessages(['position_id' => 'Select an active officer position.']);
            }
            if ($action !== 'assign') {
                $outgoing = $former ?? $member;
                $outgoingPosition = $positions->firstWhere('id', $outgoing->position_id);
                if (! $outgoingPosition?->is_officer || ! $ordinaryMember || ! $ordinaryMember->is_active || $ordinaryMember->is_officer) {
                    throw ValidationException::withMessages(['position_id' => 'The officer assignment or Member lookup is unavailable. Reload or contact the administrator.']);
                }
            }
            if ($action !== 'remove' && (int) $member->position_id === (int) $target->id) {
                throw ValidationException::withMessages(['position_id' => 'This member already holds the selected position.'])->status(409);
            }

            // Demotion, appointment and their audit events commit or roll back together.
            if ($former) $this->savePosition($request, $former, $ordinaryMember, 'REPLACE ASSOCIATION OFFICER');
            $this->savePosition($request, $member, $target, match ($action) {
                'replace' => 'REPLACE ASSOCIATION OFFICER',
                'remove' => 'REMOVE ASSOCIATION OFFICER',
                default => 'ASSIGN ASSOCIATION OFFICER',
            });
        });
    }

    private function assertCurrent(Member $member, int|string $positionId, string $updatedAt): void
    {
        if ($member->member_status !== 'active' || $member->archived_at !== null) {
            throw ValidationException::withMessages(['member_id' => 'Select an active, nonarchived association member.']);
        }
        if ((int) $member->position_id !== (int) $positionId
            || $member->updated_at?->format('Y-m-d\TH:i:s.uP') !== $updatedAt) {
            throw ValidationException::withMessages(['member_id' => 'This member changed after the form was loaded. Reload and review the current assignment.'])->status(409);
        }
    }

    private function savePosition(Request $request, Member $member, AssociationPosition $position, string $action): void
    {
        $previous = $member->position_id;
        $member->position_id = $position->id;
        $member->position_other_text = null;
        $this->executives->save($member);
        $this->audit->record($request, $action, 'Association Management', 'member_officer_position', $member->id,
            ['association_id' => $member->association_id, 'position_id' => $previous],
            ['association_id' => $member->association_id, 'position_id' => $position->id],
            ['association_id', 'position_id']);
    }
}
