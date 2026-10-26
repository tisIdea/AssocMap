<?php

namespace App\Services\Auth;

use App\Models\Association;
use App\Models\AssociationAccount;
use App\Models\Role;
use App\Models\UserInvitation;
use App\Models\UserProfile;
use App\Services\Audit\AuditLogService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UserInvitationService
{
    private const SAFE_RETRY_CODES = [
        'configuration_missing', 'identity_lookup_failed', 'identity_lookup_limit', 'identity_lookup_inconclusive',
        'provider_auth_rejected', 'provider_rate_limited',
    ];

    public function __construct(private SupabaseAuthService $auth, private AuditLogService $audit)
    {
    }

    public function initiate(array $data, UserProfile $administrator): array
    {
        try {
            $invitation = DB::transaction(function () use ($data, $administrator) {
                $actor = $this->validateScope($data, $administrator);
                $existing = UserInvitation::where('email', $data['email'])
                    ->whereIn('status', UserInvitation::UNRESOLVED_STATUSES)->lockForUpdate()->first();
                if ($existing) {
                    $this->validateSameRequest($existing, $data);
                    return $existing;
                }
                if (UserInvitation::where('email', $data['email'])->whereNotNull('auth_user_id')->exists()) {
                    throw ValidationException::withMessages(['email' => 'An invitation already exists for this email and is bound to an Auth identity. Review the related account details and invitation history before starting another operation.']);
                }
                $invitation = new UserInvitation($data);
                $invitation->invited_by = $administrator->id;
                $invitation->status = 'pending';
                $invitation->save();
                $this->record($actor, $invitation, 'REQUEST USER INVITATION', 'reserved');

                return $invitation;
            }, 3);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) === '23505') {
                return $this->result(null, 'duplicate_operation', 'An invitation operation already exists. Check its status before retrying.', 409);
            }
            Log::warning('Invitation reservation failed.', ['code' => 'storage_unavailable']);
            return $this->result(null, 'storage_unavailable', 'Invitation storage is unavailable. No invitation was sent.', 503);
        }

        try {
            $claim = DB::transaction(function () use ($invitation, $data, $administrator) {
                $this->validateScope($data, $administrator);
                $locked = UserInvitation::whereKey($invitation->id)->lockForUpdate()->firstOrFail();
                $this->validateSameRequest($locked, $data);
                if ($locked->status === 'sent') {
                    return $this->result($locked, 'already_sent', 'An invitation already exists for this email. Review its related account details and status. Supabase previously accepted the request; email delivery and activation are not confirmed.', 409);
                }
                if ($locked->status === 'processing') {
                    return $this->result($locked, 'processing', 'This operation is processing. If interrupted, reconcile it before sending again.', 409);
                }
                $retryable = $locked->status === 'failed' && $locked->auth_user_id === null
                    && in_array($locked->last_error_code, self::SAFE_RETRY_CODES, true);
                $freshPending = $locked->status === 'pending' && $locked->auth_user_id === null
                    && $locked->attempt_count === 0 && $locked->last_attempt_at === null && $locked->last_error_code === null;
                if (! $freshPending && ! $retryable) {
                    return $this->result($locked, 'reconciliation_required', 'Review this operation and its Auth identity before any further invitation.', 409);
                }
                if ($locked->last_attempt_at && $locked->last_attempt_at->gt(now()->subMinute())) {
                    return $this->result($locked, 'retry_cooldown', 'Wait at least one minute before retrying this operation.', 429);
                }
                // Use a database-stamped boundary for subsequent identity reconciliation.
                UserInvitation::whereKey($locked->id)->update([
                    'status' => 'processing', 'attempt_count' => $locked->attempt_count + 1,
                    'last_attempt_at' => DB::raw('CURRENT_TIMESTAMP'), 'last_error_code' => null,
                ]);

                return $locked->refresh();
            }, 3);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 403) throw $exception;
            return $this->recordFailure($invitation, 'authorization_revoked_before_send', 'Administrator authorization was revoked before claiming. No invitation was sent.', 403, beforeClaim: true);
        } catch (ValidationException $exception) {
            return $this->recordFailure($invitation, 'scope_changed', 'Eligibility changed before claiming. No invitation was sent.', 422, beforeClaim: true);
        } catch (QueryException $exception) {
            return $this->result($invitation, 'storage_unavailable', 'The operation could not be claimed. No new invitation request was sent.', 503);
        }
        if (is_array($claim)) return $claim;
        $invitation = $claim;

        if (! $this->auth->invitationConfigurationAvailable()) {
            return $this->recordFailure($invitation, 'configuration_missing', 'Invitation configuration is missing or invalid. No invitation was sent.', 503);
        }
        $lookup = $this->auth->findInvitationIdentity($invitation->email);
        if ($lookup['outcome'] === 'existing') {
            return $this->recordFailure($invitation, 'existing_identity', 'The authentication provider reports an existing identity for this email. Review any related account reference; if no local account can be identified, reconciliation is required. Existing accounts were not changed.', 409);
        }
        if ($lookup['outcome'] !== 'absent') {
            return $this->recordFailure($invitation, $lookup['code'], 'Existing-account verification failed. No invitation was sent.', 503);
        }

        try {
            $ready = DB::transaction(function () use ($data, $administrator, $invitation) {
                $actor = $this->validateScope($data, $administrator);
                $locked = UserInvitation::whereKey($invitation->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== 'processing' || $locked->last_error_code !== null) return false;
                $locked->last_error_code = 'provider_request_started';
                $locked->save();
                $this->record($actor, $locked, 'SEND USER INVITATION', 'request_started');
                return true;
            }, 3);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 403) throw $exception;
            return $this->recordFailure($invitation, 'authorization_revoked_before_send', 'Administrator authorization was revoked before sending. No invitation was sent.', 403);
        } catch (ValidationException $exception) {
            return $this->recordFailure($invitation, 'scope_changed', 'Account or association eligibility changed. No invitation was sent.', 409);
        } catch (QueryException $exception) {
            return $this->recordFailure($invitation, 'scope_check_failed', 'Eligibility could not be verified. No invitation was sent.', 503);
        }
        if (! $ready) return $this->result($invitation->refresh(), 'reconciliation_required', 'The operation changed before sending. No invitation was sent.', 409);

        $provider = $this->auth->inviteUser($invitation->email, $invitation->display_name);
        if ($provider['outcome'] !== 'sent') {
            $uncertain = $provider['outcome'] === 'uncertain';
            if ($provider['code'] === 'existing_identity') {
                return $this->recordFailure($invitation, 'existing_identity', 'The provider reports an existing Auth identity. Reconcile it separately; no profile or role was changed.', 409);
            }
            return $this->recordFailure($invitation, $provider['code'], $uncertain
                ? 'The provider outcome is uncertain. Reconcile Auth before retrying; no automatic resend will occur.'
                : 'The provider rejected the invitation request. Review the recorded operation before retrying.', $uncertain ? 202 : 502,
                authId: $provider['reconciliation_auth_user_id'] ?? null);
        }

        try {
            return DB::transaction(function () use ($invitation, $provider, $data, $administrator) {
                $actor = $this->validateScope($data, $administrator);
                $locked = UserInvitation::whereKey($invitation->id)->lockForUpdate()->firstOrFail();
                $profile = UserProfile::whereKey($provider['auth_user_id'])->lockForUpdate()->first();
                $canBind = $this->canBindIdentity($locked, $profile, $provider['auth_created_at']);
                if ($locked->status !== 'processing') {
                    $locked->sent_at = now();
                    if ($canBind && ($locked->auth_user_id === null || $locked->auth_user_id === $provider['auth_user_id'])) {
                        $locked->auth_user_id = $provider['auth_user_id'];
                        $locked->last_error_code = 'provider_state_changed';
                    } else {
                        $locked->last_error_code = UserInvitation::IDENTITY_CONFLICT_PREFIX.$provider['auth_user_id'];
                    }
                    $locked->save();
                    return $this->result($locked, 'reconciliation_required', 'The operation changed after the Auth request. Reconciliation is required.', 409);
                }
                $locked->sent_at = now();
                if ($profile !== null || ! $canBind) {
                    $locked->status = 'failed';
                    if ($canBind) {
                        $locked->auth_user_id = $provider['auth_user_id'];
                        $locked->last_error_code = 'profile_reconciliation_required';
                    } else {
                        // Preserve evidence without binding an established profile.
                        $locked->last_error_code = UserInvitation::IDENTITY_CONFLICT_PREFIX.$provider['auth_user_id'];
                    }
                    $locked->save();
                    return $this->result($locked, 'reconciliation_required', 'Auth accepted the request, but its identity or profile requires reconciliation. Existing profiles were not changed.', 409);
                }
                $locked->auth_user_id = $provider['auth_user_id'];
                $profile = new UserProfile([
                    'role_id' => $locked->role_id,
                    'display_name' => $locked->display_name,
                    'is_active' => false,
                ]);
                $profile->id = $provider['auth_user_id'];
                $profile->invited_by = $locked->invited_by;
                $profile->invited_at = $locked->created_at;
                $profile->save();
                $locked->status = 'sent';
                $locked->last_error_code = null;
                $locked->save();
                $this->record($actor, $locked, 'ISSUE USER INVITATION', 'provider_accepted');

                return $this->result($locked, 'provider_accepted', 'Supabase accepted the invitation request. Email delivery is unverified; the account remains inactive until the recipient completes activation.', 201);
            }, 3);
        } catch (ValidationException|HttpException $exception) {
            return $this->recordFailure($invitation, 'scope_changed_after_provider', 'Auth accepted the invitation, but authorization or association eligibility changed. Reconciliation is required; do not resend.', 409,
                authId: $provider['auth_user_id'], providerAccepted: true);
        } catch (QueryException $exception) {
            return $this->recordFailure($invitation, 'profile_sync_failed', 'Auth accepted the request, but application synchronization failed. Reconcile this operation; do not resend.', 503,
                authId: $provider['auth_user_id'], providerAccepted: true);
        }
    }

    private function canBindIdentity(UserInvitation $invitation, ?UserProfile $profile, \Carbon\CarbonImmutable $authCreatedAt): bool
    {
        if ($invitation->last_attempt_at === null) return false;
        if ($profile === null) return $authCreatedAt->gte($invitation->last_attempt_at);

        return $profile->created_at !== null && $profile->created_at->gte($invitation->last_attempt_at)
            && $profile->activated_at === null
            && (string) $profile->role_id === (string) $invitation->role_id
            && ! $profile->associationAccountHistory()->exists() && ! $profile->assignedAssociations()->exists();
    }

    private function validateScope(array $data, UserProfile $administrator): UserProfile
    {
        $roles = Role::where(fn ($query) => $query->where('code', 'system_admin')->orWhere('id', $data['role_id']))
            ->orderBy('id')->sharedLock()->get()->keyBy('id');
        $actorRole = $roles->firstWhere('code', 'system_admin');
        $actor = UserProfile::whereKey($administrator->id)->sharedLock()->first();
        if ($actor) $actor->setRelation('role', $actorRole);
        abort_unless($actor && (string) $actor->role_id === (string) $actorRole?->id
            && $actor->canAccessApplication(throwOnDatabaseError: true), 403);
        if (array_key_exists('email_change_hold_id', $actor->getAttributes())
            && DB::table('email_change_requests')->where('status', 'pending')->whereRaw('lower(new_email) = ?', [$data['email']])->exists()) {
            throw ValidationException::withMessages(['email' => 'This destination is reserved by an unresolved email change. Review that request before inviting another identity.']);
        }
        $role = $roles->get($data['role_id']);
        if (! $role?->is_active || ! in_array($role->code, ['field_officer', 'association_account'], true)) {
            throw ValidationException::withMessages(['role_id' => 'Select an active Field Officer or Association Account role.']);
        }
        $associationId = $data['association_id'] ?? null;
        if ($role->code === 'field_officer') {
            if ($associationId !== null) throw ValidationException::withMessages(['association_id' => 'Field Officer invitations do not change association assignments.']);
            return $actor;
        }
        $association = $associationId ? Association::whereKey($associationId)->lockForUpdate()->first() : null;
        if (! $association) {
            throw ValidationException::withMessages(['association_id' => 'Select an existing association for this invitation.']);
        }
        if ($association->archived_at || $association->status !== 'active') {
            throw ValidationException::withMessages(['association_id' => 'This association is not currently eligible for account invitation.']);
        }
        if ($conflict = $this->associationConflictMessage($association)) {
            throw ValidationException::withMessages(['association_id' => $conflict]);
        }
        if (UserInvitation::where('association_id', $associationId)->where('email', '!=', $data['email'])
            ->whereIn('status', UserInvitation::UNRESOLVED_STATUSES)->exists()) {
            throw ValidationException::withMessages(['association_id' => 'This association already has another unresolved invitation: '.$association->name.'. Review that operation before inviting another account.']);
        }

        return $actor;
    }

    private function record(UserProfile $actor, UserInvitation $invitation, string $action, string $outcome): void
    {
        $request = new Request;
        $request->attributes->set('current_user', $actor);
        $this->audit->record($request, $action, 'User Management', 'user_invitation', $invitation->id, null,
            ['status' => $invitation->status, 'outcome' => $outcome], ['status', 'outcome']);
    }

    public function associationConflictMessage(Association $association): ?string
    {
        $links = AssociationAccount::where('association_id', $association->id)->where('is_active', true)->get(['user_id']);
        if ($links->isEmpty()) return null;
        if ($links->count() !== 1 || AssociationAccount::where('user_id', $links->first()->user_id)->where('is_active', true)->count() !== 1
            || ! UserProfile::whereKey($links->first()->user_id)->whereHas('role', fn ($role) => $role->where('code', 'association_account'))->exists()) {
            return 'This association has inconsistent account relationships: '.$association->name.'. Review User Management; no account was reassigned.';
        }

        return 'This association already has an assigned Association Account: '.$association->name.'. Review the existing account details.';
    }

    private function validateSameRequest(UserInvitation $invitation, array $data): void
    {
        if ($invitation->display_name !== $data['display_name']
            || (string) $invitation->role_id !== (string) $data['role_id']
            || $invitation->association_id !== ($data['association_id'] ?? null)) {
            throw ValidationException::withMessages(['email' => 'An invitation already exists for this email with different account information. Review its status and any related account details. Its role and association were not changed.']);
        }
    }

    private function recordFailure(UserInvitation $invitation, string $code, string $message, int $httpStatus, ?string $authId = null, bool $beforeClaim = false, bool $providerAccepted = false): array
    {
        try {
            $invitation = DB::transaction(function () use ($invitation, $code, $authId, $beforeClaim, $providerAccepted) {
                $locked = UserInvitation::whereKey($invitation->id)->lockForUpdate()->firstOrFail();
                if ($beforeClaim ? $locked->status === 'pending' : $locked->status === 'processing') {
                    $locked->status = 'failed';
                    $locked->last_error_code = $code;
                }
                if ($authId) {
                    $locked->last_error_code = UserInvitation::IDENTITY_CONFLICT_PREFIX.$authId;
                    if ($providerAccepted) $locked->sent_at = now();
                }
                if ($locked->isDirty()) $locked->save();
                return $locked;
            }, 3);
        } catch (QueryException $exception) {
            Log::warning('Invitation outcome requires reconciliation.', ['invitation_id' => $invitation->id, 'code' => $code]);
            return $this->result($invitation, 'reconciliation_required', 'Outcome persistence failed. Reconcile this operation before sending again.', 503);
        }
        Log::notice('Invitation operation stopped.', ['invitation_id' => $invitation->id, 'code' => $code]);

        return $this->result($invitation, $code, $message, $httpStatus);
    }

    private function result(?UserInvitation $invitation, string $code, string $message, int $httpStatus): array
    {
        return [
            'http_status' => $httpStatus,
            'invitation_id' => $invitation?->id,
            'status' => $invitation?->status,
            'code' => $code,
            'message' => $message,
        ];
    }
}
