<?php

namespace App\Services\Auth;

use App\Models\Association;
use App\Models\AssociationAccount;
use App\Models\Role;
use App\Models\UserInvitation;
use App\Models\UserProfile;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class AccountReactivationService
{
    public const LIFETIME_SECONDS = 600;

    public const ASSOCIATION_APPOINTMENT_REQUIRED = 'A separately authorized association appointment is required before this Association Account can be reactivated. Reactivation does not restore former association links.';

    public function __construct(private AuditLogService $audit, private ProfileAuthIdentityService $identities,
        private SupabasePasswordRecoveryProvider $provider, private SupabaseAuthService $auth,
        private PasswordRecoveryContext $contexts) {}

    public function review(UserProfile $actor, UserProfile $target): array
    {
        $this->enabled();

        return DB::transaction(fn () => $this->scope($actor->id, $target->id));
    }

    public function requestLink(Request $request, UserProfile $target, string $reviewHash): void
    {
        $this->enabled();
        $this->contexts->checkStorage();
        [$approval, $reference] = DB::transaction(function () use ($request, $target, $reviewHash) {
            $scope = $this->scope($request->attributes->get('current_user')?->id, $target->id);
            if (! hash_equals($scope['review_hash'], $reviewHash)) {
                $this->deny('The account relationships changed. Reload and review them before continuing.');
            }
            // Prove the server can enforce the session boundary before requesting email.
            DB::table('auth.sessions')->useWritePdo()->where('user_id', $target->id)->limit(1)->get(['id', 'user_id', 'created_at']);
            $existing = DB::table('account_reactivations')->where('user_id', $target->id)->lockForUpdate()->first();
            if ($existing && ($existing->state === 'password_started'
                || ($existing->state !== 'completed' && Carbon::parse($existing->expires_at)->isFuture()))) {
                $this->deny('A reactivation is already pending or requires reconciliation. Do not repeat the request.');
            }
            $reference = Str::random(64);
            $values = ['id' => (string) Str::uuid(), 'user_id' => $target->id, 'requested_by' => $scope['actor']->id,
                'role_id' => $scope['target']->role_id, 'email' => $scope['email'], 'reference_hash' => hash('sha256', $reference),
                'review_hash' => $scope['review_hash'], 'revocation_count' => $scope['revocation_count'],
                'state' => 'issued', 'expires_at' => now()->addSeconds(self::LIFETIME_SECONDS), 'cancelled_at' => null, 'created_at' => now(), 'updated_at' => now()];
            DB::table('account_reactivations')->updateOrInsert(['user_id' => $target->id], $values);
            $approval = (object) $values;
            $this->record($scope, $approval, 'REQUEST ACCOUNT REACTIVATION', 'requested');

            return [$approval, $reference];
        });
        // A timeout may still deliver an email. Keep the single issued request and never retry automatically.
        $this->provider->requestReactivation($approval->email, $reference);
    }

    public function verify(Request $request, string $reference, string $hash): void
    {
        $this->enabled();
        if ($request->session()->has('supabase_access_token') && ! $request->boolean('sign_out_current_account')) {
            $this->deny();
        }
        $this->contexts->checkStorage();
        $approval = DB::table('account_reactivations')->where('reference_hash', hash('sha256', $reference))->first();
        if (! $approval) {
            $this->deny();
        }
        DB::transaction(function () use ($approval) {
            $scope = $this->scope($approval->requested_by, $approval->user_id, $approval);
            $this->lockApproval($approval, 'issued');
            $this->transition($approval, 'verifying');
            $this->record($scope, $approval, 'VERIFY ACCOUNT REACTIVATION', 'verification_started');
        });
        $verified = $this->provider->verify($hash);
        $this->assertIdentity($verified, $approval);
        DB::transaction(function () use ($approval) {
            $scope = $this->scope($approval->requested_by, $approval->user_id, $approval);
            $this->lockApproval($approval, 'verifying');
            $this->transition($approval, 'verified');
            $this->record($scope, $approval, 'VERIFY ACCOUNT REACTIVATION', 'verified');
        });
        if ($request->session()->has('supabase_access_token')) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
        $this->contexts->create($request, $verified, $approval->id);
    }

    public function passwordContext(Request $request): array
    {
        $this->enabled();
        $context = $this->contexts->read($request);
        if ($request->session()->has('supabase_access_token') || ! $context || ! Str::isUuid($context['reactivation_id'] ?? '')) {
            $this->deny();
        }
        $approval = DB::table('account_reactivations')->where('id', $context['reactivation_id'])->first();
        if (! $approval || $approval->user_id !== $context['auth_user_id'] || $approval->email !== $context['email']) {
            $this->deny();
        }
        DB::transaction(function () use ($approval) {
            $this->scope($approval->requested_by, $approval->user_id, $approval);
            $this->lockApproval($approval, 'verified');
        });
        $this->assertIdentity($context, $approval);

        return [$context, $approval];
    }

    public function complete(Request $request, string $password): void
    {
        [$initial] = $this->passwordContext($request);
        $this->contexts->locked($initial['auth_user_id'], function () use ($request, $password) {
            [$context, $approval] = $this->passwordContext($request);
            DB::transaction(function () use ($request, $context, $approval) {
                $scope = $this->scope($approval->requested_by, $approval->user_id, $approval);
                $this->lockApproval($approval, 'verified');
                $this->record($scope, $approval, 'START ACCOUNT REACTIVATION', 'password_started');
                $this->transition($approval, 'password_started');
                $this->contexts->consume($request, $context);
            });
            $updated = $this->provider->update($context['access_token'], $password);
            if ($updated['auth_user_id'] !== $approval->user_id || $updated['email'] !== $approval->email) {
                $this->deny();
            }
            $this->provider->signOutEverywhere($context['access_token']);
            DB::transaction(function () use ($approval) {
                $scope = $this->scope($approval->requested_by, $approval->user_id, $approval);
                $this->lockApproval($approval, 'password_started');
                $scope['target']->forceFill(['is_active' => true, 'auth_sessions_valid_after' => now()->utc()])->save();
                $this->transition($approval, 'completed');
                $this->record($scope, $approval, 'REACTIVATE USER ACCESS', 'completed');
            });
            $this->contexts->forget($request);
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        });
    }

    private function scope(?string $actorId, string $targetId, ?object $approval = null): array
    {
        $this->enabled();
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('reactivation_transaction_required');
        }
        abort_unless($actorId && $actorId !== $targetId, 403);
        $roles = Role::whereIn('code', ['system_admin', 'field_officer', 'association_account'])->orderBy('id')->sharedLock()->get()->keyBy('id');
        $actor = UserProfile::whereKey($actorId)->sharedLock()->first();
        if ($actor) {
            $actor->setRelation('role', $roles->get($actor->role_id));
        }
        abort_unless($actor?->role?->code === 'system_admin' && $actor->canAccessApplication(throwOnDatabaseError: true), 403);
        $preview = UserProfile::whereKey($targetId)->firstOrFail(['role_id']);
        $linkedAssociations = collect();
        if ($roles->get($preview->role_id)?->code === 'association_account') {
            // Match invitation activation: association before the account-holder profile.
            $linkedIds = AssociationAccount::where('user_id', $targetId)->pluck('association_id')->unique()->all();
            $linkedAssociations = Association::whereIn('id', $linkedIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        }
        $target = UserProfile::whereKey($targetId)->lockForUpdate()->firstOrFail();
        if ((string) $preview->role_id !== (string) $target->role_id) {
            $this->deny();
        }
        $target->setRelation('role', $roles->get($target->role_id));
        if ($target->is_active || ! $target->activated_at || ! $target->role?->is_active) {
            $this->deny();
        }
        $identity = $this->identities->read([$targetId])?->get($targetId);
        $email = is_string($identity?->email) ? mb_strtolower(trim($identity->email)) : null;
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->deny();
        }
        if (! UserInvitation::where('auth_user_id', $targetId)->where('role_id', $target->role_id)
            ->when($target->getAttribute('approved_email') === null, fn ($query) => $query->where('email', $email))
            ->where('status', 'accepted')->whereNotNull('accepted_at')->exists()
            || ($target->getAttribute('approved_email') !== null && $target->approved_email !== $email)) {
            $this->deny();
        }
        if (UserInvitation::whereIn('status', UserInvitation::UNRESOLVED_STATUSES)->where(fn ($query) => $query
            ->where('auth_user_id', $targetId)->orWhere('email', $email)
            ->orWhere('last_error_code', UserInvitation::IDENTITY_CONFLICT_PREFIX.$targetId))->exists()) {
            $this->deny();
        }
        $revocationCount = DB::table('audit_logs')->where('action_type', UserAccessRevocationService::ACTION)
            ->where('entity_type', 'user_profile')->where('entity_id', $targetId)->count();
        if ($revocationCount === 0) {
            $this->deny();
        }
        $probe = clone $target;
        $probe->is_active = true;
        if (! $probe->canAccessApplication(['id' => $targetId, 'email' => $email], true)) {
            $this->deny();
        }
        $links = AssociationAccount::where('user_id', $targetId)->orderBy('id')->lockForUpdate()->get();
        $activeLinks = $links->where('is_active', true);
        $assignments = collect();
        if ($target->role->code === 'association_account') {
            $link = $activeLinks->count() === 1 ? $activeLinks->first() : null;
            if ($activeLinks->isEmpty()) {
                $this->deny(self::ASSOCIATION_APPOINTMENT_REQUIRED);
            }
            $association = $link ? $linkedAssociations->get($link->association_id) : null;
            if (! $link?->is_active || ! $association || $association->status !== 'active' || $association->archived_at !== null
                || AssociationAccount::where('association_id', $link->association_id)->where('is_active', true)->count() !== 1
                || Association::where('primary_field_officer_id', $targetId)->exists()) {
                $this->deny();
            }
        } else {
            if ($links->isNotEmpty()) {
                $this->deny();
            }
            $assignments = Association::where('primary_field_officer_id', $targetId)->orderBy('id')->lockForUpdate()->get(['id', 'name', 'status', 'archived_at']);
        }
        $reviewHash = hash('sha256', json_encode([
            'role_id' => $target->role_id, 'email' => $email, 'revocation_count' => $revocationCount,
            'assignments' => $assignments->map->only(['id', 'status', 'archived_at'])->all(),
            'links' => $links->map->only(['id', 'association_id', 'user_id', 'is_active'])->all(),
        ], JSON_THROW_ON_ERROR));
        if ($approval && ((string) $target->role_id !== (string) $approval->role_id || $email !== $approval->email
            || $revocationCount !== (int) $approval->revocation_count || ! hash_equals($approval->review_hash, $reviewHash))) {
            $this->deny();
        }

        return ['actor' => $actor, 'target' => $target, 'email' => $email, 'assignments' => $assignments,
            'links' => $activeLinks, 'review_hash' => $reviewHash, 'revocation_count' => $revocationCount];
    }

    private function lockApproval(object $approval, string $state): void
    {
        $current = DB::table('account_reactivations')->where('id', $approval->id)->lockForUpdate()->first();
        if (! $current || $current->state !== $state || $current->cancelled_at !== null || Carbon::parse($current->expires_at)->lessThanOrEqualTo(now())) {
            $this->deny();
        }
    }

    private function transition(object $approval, string $state): void
    {
        DB::table('account_reactivations')->where('id', $approval->id)->update(['state' => $state, 'updated_at' => now()]);
    }

    private function assertIdentity(array $context, object $approval): void
    {
        $identity = $this->auth->getInvitationIdentity($context['access_token']);
        if (! $identity || $identity['auth_user_id'] !== $approval->user_id || $identity['email'] !== $approval->email
            || $context['auth_user_id'] !== $approval->user_id || $context['email'] !== $approval->email) {
            $this->deny();
        }
    }

    private function record(array $scope, object $approval, string $action, string $outcome): void
    {
        $request = new Request;
        $request->attributes->set('current_user', $scope['actor']);
        $this->audit->record($request, $action, 'User Management', 'account_reactivation', $approval->id, null,
            ['user_id' => $approval->user_id, 'requested_by' => $approval->requested_by, 'outcome' => $outcome],
            ['user_id', 'requested_by', 'outcome']);
    }

    private function enabled(): void
    {
        abort_unless(config('services.supabase.account_reactivation_enabled') === true, 404);
    }

    private function deny(string $message = 'This account reactivation is unavailable. Ask the Administrator to review the account and request.'): never
    {
        throw ValidationException::withMessages(['reactivation' => $message]);
    }
}
