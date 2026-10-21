<?php

namespace App\Services;

use App\Exceptions\InvitationActivationException;
use App\Models\Association;
use App\Models\AssociationAccount;
use App\Models\Role;
use App\Models\UserInvitation;
use App\Models\UserProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvitationActivationService
{
    public function identify(array $identity): array
    {
        $invitation = UserInvitation::where('auth_user_id', $identity['auth_user_id'])
            ->where('email', $identity['email'])->where('status', 'sent')->first();
        if (! $invitation) $this->deny();
        $context = [
            'invitation_id' => $invitation->id,
            'auth_user_id' => $identity['auth_user_id'],
            'email' => $identity['email'],
            'role_id' => (string) $invitation->role_id,
            'association_id' => $invitation->association_id,
        ];
        $this->eligible($context);

        return $context;
    }

    public function eligible(array $context, ?string $confirmedClaim = null): void
    {
        $invitation = UserInvitation::find($context['invitation_id']);
        $profile = UserProfile::find($context['auth_user_id']);
        $role = Role::find($context['role_id']);
        $association = $context['association_id'] ? Association::find($context['association_id']) : null;
        if ($confirmedClaim !== null && ($context['phase'] ?? null) === 'password_confirmed' && $invitation?->status === 'accepted') {
            $this->assertBinding($context, $invitation, $profile, $role);
            $this->assertCompleted($invitation, $profile, $role, $association);
            return;
        }
        $this->assertEligible($context, $invitation, $profile, $role, $association, $confirmedClaim);
    }

    public function claimPassword(array $context, string $claim): void
    {
        if (($context['expires_at'] ?? 0) <= time()) $this->deny();
        $this->eligible($context);
        // An atomic claim records the external write without activating any rows.
        $query = UserInvitation::whereKey($context['invitation_id'])
            ->where('auth_user_id', $context['auth_user_id'])->where('email', $context['email'])
            ->where('role_id', $context['role_id'])->where('association_id', $context['association_id'])
            ->where('status', 'sent')->whereNull('accepted_at')->whereNull('last_error_code')
            ->whereHas('profile', fn ($profile) => $profile->where('role_id', $context['role_id'])
                ->where('is_active', false)->whereNull('activated_at'))
            ->whereHas('role', fn ($role) => $role->where('is_active', true)->whereIn('code', ['field_officer', 'association_account']));
        if ($context['association_id'] !== null) {
            $query->whereHas('association', fn ($association) => $association->where('status', 'active')->whereNull('archived_at'));
        }
        if ($query->update(['last_error_code' => $claim]) !== 1) $this->deny();
    }

    public function finish(array $context, string $claim): void
    {
        if (($context['phase'] ?? null) !== 'password_confirmed') $this->deny();

        DB::transaction(function () use ($context, $claim) {
            [$invitation, $profile, $role, $association] = $this->lockScope($context);
            if (($context['expires_at'] ?? 0) <= time()) $this->deny();
            $this->assertBinding($context, $invitation, $profile, $role);

            if ($invitation->status === 'accepted') {
                $this->assertCompleted($invitation, $profile, $role, $association);
                return;
            }

            $this->assertEligible($context, $invitation, $profile, $role, $association, $claim);
            $activatedAt = now();
            $profile->is_active = true;
            $profile->activated_at = $activatedAt;
            $profile->save();

            if ($role->code === 'association_account') {
                // The database trigger requires an active profile first.
                $link = new AssociationAccount([
                    'association_id' => $association->id,
                    'user_id' => $profile->id,
                    'is_active' => true,
                ]);
                $link->id = (string) Str::uuid();
                $link->linked_by = $profile->id;
                $link->linked_at = $activatedAt;
                $link->save();
            }

            $invitation->status = 'accepted';
            $invitation->accepted_at = $activatedAt;
            $invitation->last_error_code = null;
            $invitation->save();
            if (($context['recovery_origin'] ?? null) === 'invitation_restart') {
                app(InvitationRestartAudit::class)->record($invitation, 'activation_completed', true);
            }
        });
    }

    private function lockScope(array $context): array
    {
        $role = Role::whereKey($context['role_id'])->sharedLock()->first();
        $association = $context['association_id']
            ? Association::whereKey($context['association_id'])->lockForUpdate()->first() : null;
        $invitation = UserInvitation::whereKey($context['invitation_id'])->lockForUpdate()->first();
        $profile = UserProfile::whereKey($context['auth_user_id'])->lockForUpdate()->first();

        return [$invitation, $profile, $role, $association];
    }

    private function assertBinding(array $context, ?UserInvitation $invitation, ?UserProfile $profile, ?Role $role): void
    {
        if (! $invitation || ! $profile || ! $role || ! Str::isUuid($invitation->auth_user_id ?? '')
            || strtolower($invitation->auth_user_id) !== $context['auth_user_id']
            || mb_strtolower(trim($invitation->email)) !== $context['email']
            || strtolower($profile->id) !== $context['auth_user_id']
            || (string) $invitation->role_id !== $context['role_id']
            || (string) $profile->role_id !== $context['role_id']
            || $invitation->association_id !== $context['association_id']
            || ! $role->is_active || ! in_array($role->code, ['field_officer', 'association_account'], true)) {
            $this->deny();
        }
    }

    private function assertEligible(array $context, ?UserInvitation $invitation, ?UserProfile $profile,
        ?Role $role, ?Association $association, ?string $confirmedClaim = null): void
    {
        $this->assertBinding($context, $invitation, $profile, $role);
        $error = $invitation->last_error_code;
        // Only a confirmed receipt in this context can resolve its own write marker.
        if ($confirmedClaim !== null && ($context['phase'] ?? null) === 'password_confirmed' && $error === $confirmedClaim) {
            $error = null;
        }
        if ($invitation->status !== 'sent' || $invitation->accepted_at !== null || $error !== null
            || $profile->is_active || $profile->activated_at !== null
            || AssociationAccount::where('user_id', $profile->id)->exists()) $this->deny();

        if ($role->code === 'field_officer') {
            if ($invitation->association_id !== null) $this->deny();
            return;
        }

        if (! $association || $association->status !== 'active' || $association->archived_at !== null
            || AssociationAccount::where('association_id', $association->id)->exists()
            || UserInvitation::where('association_id', $association->id)->where('id', '!=', $invitation->id)
                ->whereIn('status', UserInvitation::UNRESOLVED_STATUSES)->exists()) $this->deny();
    }

    private function assertCompleted(UserInvitation $invitation, UserProfile $profile, Role $role, ?Association $association): void
    {
        $link = AssociationAccount::where('user_id', $profile->id)->first();
        if ($invitation->accepted_at === null || ! $profile->is_active || $profile->activated_at === null
            || $invitation->last_error_code !== null
            || ($role->code === 'field_officer' && ($link !== null || $invitation->association_id !== null))
            || ($role->code === 'association_account' && (! $association || $association->archived_at !== null
                || $association->status !== 'active' || ! $link?->is_active
                || $link->association_id !== $association->id))) $this->deny();
    }

    private function deny(): never
    {
        throw new InvitationActivationException('invitation_not_eligible');
    }
}
