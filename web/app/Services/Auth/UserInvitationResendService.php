<?php

namespace App\Services\Auth;

use App\Models\Association;
use App\Models\AssociationAccount;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\UserInvitation;
use App\Models\UserProfile;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UserInvitationResendService
{
    public function __construct(private SupabaseInvitationProvider $provider, private AuditLogService $audit)
    {
    }

    public function resend(Request $request, UserProfile $target): array
    {
        if (! $this->provider->invitationConfigurationAvailable()) {
            return $this->result('configuration_missing', 'Invitation configuration is unavailable. No resend was requested.', 503);
        }
        $claim = 'resend_preflight:'.Str::uuid();
        $invitation = DB::transaction(function () use ($request, $target, $claim) {
            $invitation = $this->lockEligible($request, $target->id);
            if ($invitation->last_attempt_at?->gt(now()->subMinute())) {
                throw ValidationException::withMessages(['confirm_resend' => 'Wait at least one minute before requesting another invitation.']);
            }
            $invitation->status = 'processing';
            $invitation->attempt_count++;
            $invitation->last_attempt_at = now();
            $invitation->last_error_code = $claim;
            $invitation->save();
            $this->record($request, $invitation, 'requested');

            return $invitation;
        }, 3);

        $identity = $this->provider->existingInvitationIdentity($target->id, $invitation->email);
        if ($identity['outcome'] !== 'eligible') {
            $message = $identity['code'] === 'identity_already_confirmed'
                ? 'This Auth identity has already verified its email. Initial invitations cannot be resent; review its interrupted activation separately.'
                : 'The bound Auth identity could not be verified. No resend was requested; review the account before retrying.';

            return $this->complete($request, $target->id, $claim, $identity['code'], $message, false, false);
        }

        $started = str_replace('resend_preflight:', 'resend_provider_started:', $claim);
        DB::transaction(function () use ($request, $target, $claim, $started) {
            $locked = $this->lockEligible($request, $target->id, $claim);
            $locked->last_error_code = $started;
            $locked->save();
        }, 3);
        $result = $this->provider->resendInvitation($target->id, $invitation->email, $invitation->display_name);
        $accepted = $result['outcome'] === 'sent';
        $uncertain = $result['outcome'] === 'uncertain';

        try {
            return $this->complete($request, $target->id, $started, $accepted ? 'provider_accepted' : $result['code'],
                $accepted ? 'Supabase accepted the replacement invitation request. Email delivery is unverified; the account remains inactive until activation.'
                    : ($uncertain ? 'The resend outcome is uncertain. Do not retry; reconcile the recorded operation first.'
                        : 'Supabase did not accept the resend request. No account information was changed. Review the account before retrying.'),
                $accepted, $uncertain, $result['reconciliation_auth_user_id'] ?? null);
        } catch (ValidationException|HttpException) {
            // Never overwrite a concurrent revocation or claim after the external request.
            return $this->result('reconciliation_required', 'Account eligibility changed after the provider request. Its saved outcome requires review; email delivery is unverified. Do not resend before reconciliation.', 409);
        }
    }

    private function complete(Request $request, string $targetId, string $marker, string $code, string $message,
        bool $accepted, bool $uncertain, ?string $reconciliationId = null): array
    {
        return DB::transaction(function () use ($request, $targetId, $marker, $code, $message, $accepted, $uncertain, $reconciliationId) {
            $invitation = $this->lockEligible($request, $targetId, $marker);
            $invitation->status = $uncertain ? 'processing' : 'sent';
            $invitation->last_error_code = $uncertain ? 'resend_outcome_unknown' : null;
            if ($uncertain && $reconciliationId !== null && Str::isUuid($reconciliationId)) {
                $invitation->last_error_code = UserInvitation::IDENTITY_CONFLICT_PREFIX.$reconciliationId;
            }
            if ($accepted) $invitation->sent_at = now();
            $invitation->save();
            $this->record($request, $invitation, $code);

            return $this->result($code, $message, $accepted || $uncertain ? 202 : 409);
        }, 3);
    }

    private function lockEligible(Request $request, string $targetId, ?string $marker = null): UserInvitation
    {
        // Match activation's role, association, invitation, profile lock order.
        $adminRole = Role::where('code', 'system_admin')->sharedLock()->first();
        $actor = UserProfile::whereKey($request->attributes->get('current_user')?->id)->sharedLock()->first();
        if ($actor) $actor->setRelation('role', $adminRole);
        abort_unless($actor && (string) $actor->role_id === (string) $adminRole?->id
            && $actor->canAccessApplication(throwOnDatabaseError: true), 403);
        $hint = UserInvitation::where('auth_user_id', $targetId)->whereIn('status', ['sent', 'processing'])->first();
        if (! $hint) $this->deny();
        $role = Role::whereKey($hint->role_id)->sharedLock()->first();
        $association = $hint->association_id ? Association::whereKey($hint->association_id)->lockForUpdate()->first() : null;
        $invitation = UserInvitation::whereKey($hint->id)->lockForUpdate()->first();
        $profile = UserProfile::whereKey($targetId)->lockForUpdate()->first();
        if (! $invitation || ! $profile || ! $role?->is_active
            || ! in_array($role->code, ['field_officer', 'association_account'], true)
            || $invitation->auth_user_id !== $targetId || (string) $profile->role_id !== (string) $role->id
            || (string) $invitation->role_id !== (string) $role->id || $invitation->association_id !== $hint->association_id
            || $invitation->status !== ($marker === null ? 'sent' : 'processing')
            || $invitation->last_error_code !== $marker || $invitation->accepted_at !== null
            || $profile->is_active || $profile->activated_at !== null
            || ! filter_var($invitation->email, FILTER_VALIDATE_EMAIL)
            || mb_strtolower(trim($invitation->email)) !== $invitation->email
            || AssociationAccount::where('user_id', $targetId)->exists()
            || AuditLog::where('action_type', UserAccessRevocationService::ACTION)
                ->where('entity_type', 'user_profile')->where('entity_id', $targetId)->exists()) $this->deny();
        if ($role->code === 'field_officer' && $invitation->association_id !== null) $this->deny();
        if ($role->code === 'association_account' && (! $association || $association->status !== 'active'
            || $association->archived_at !== null || AssociationAccount::where('association_id', $association->id)->where('is_active', true)->exists()
            || UserInvitation::where('association_id', $association->id)->where('id', '!=', $invitation->id)
                ->whereIn('status', UserInvitation::UNRESOLVED_STATUSES)->exists())) $this->deny();

        return $invitation;
    }

    private function record(Request $request, UserInvitation $invitation, string $outcome): void
    {
        $this->audit->record($request, 'RESEND INVITATION', 'User Management', 'user_invitation', $invitation->id,
            newValues: ['status' => $invitation->status, 'attempt_count' => $invitation->attempt_count, 'outcome' => $outcome],
            allowedSnapshotFields: ['status', 'attempt_count', 'outcome']);
    }

    private function deny(): never
    {
        throw ValidationException::withMessages(['confirm_resend' => 'This account is not eligible for invitation resending. Review its status and invitation history.']);
    }

    private function result(string $code, string $message, int $status): array
    {
        return ['code' => $code, 'message' => $message, 'http_status' => $status];
    }
}
