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

final class AccountEmailChangeService
{
    public function __construct(private AuditLogService $audit, private EmailChangeContext $contexts,
        private SupabasePasswordRecoveryProvider $recovery, private SupabaseEmailChangeProvider $provider,
        private PasswordRecoveryContext $passwordLocks) {}

    public function review(UserProfile $actor, UserProfile $target, string $destination): array
    {
        $this->enabled();

        return DB::transaction(function () use ($actor, $target, $destination) {
            $scope = $this->scope($actor->id, $target->id);
            $this->destination($destination, $scope['email']);
            $scope['destination'] = $destination;
            $scope['review_hash'] = hash('sha256', $scope['hash'].'|'.$destination);

            return $scope;
        });
    }

    public function requestLink(Request $request, UserProfile $target, string $destination, string $reviewHash): void
    {
        $this->enabled();
        $this->contexts->checkStorage();
        [$row, $reference] = DB::transaction(function () use ($request, $target, $destination, $reviewHash) {
            $scope = $this->scope($request->attributes->get('current_user')?->id, $target->id);
            $this->destination($destination, $scope['email']);
            if (! hash_equals(hash('sha256', $scope['hash'].'|'.$destination), $reviewHash)) {
                $this->deny();
            }
            if (DB::table('email_change_requests')->where('user_id', $target->id)->where('status', 'pending')->exists()) {
                $this->deny();
            }
            // Check the backend's session-metadata contract before placing an access hold.
            DB::table('auth.sessions')->where('user_id', $target->id)->limit(1)->get(['id', 'user_id', 'created_at']);
            $reference = Str::random(64);
            $values = ['id' => (string) Str::uuid(), 'user_id' => $target->id, 'requested_by' => $scope['actor']->id,
                'old_email' => $scope['email'], 'new_email' => $destination, 'role_id' => $scope['target']->role_id,
                'reference_hash' => hash('sha256', $reference), 'scope_hash' => $scope['hash'],
                'status' => 'pending', 'phase' => 'requested', 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()];
            DB::table('email_change_requests')->insert($values);
            $scope['target']->forceFill(['email_change_hold_id' => $values['id']])->save();
            $row = (object) $values;
            $this->record($scope, $row, 'REQUEST VERIFIED EMAIL CHANGE', 'requested');

            return [$row, $reference];
        });
        // Recovery delivery may be uncertain. The issued request is never sent again automatically.
        $this->recovery->requestEmailChange($row->old_email, $reference);
    }

    public function verifyOld(Request $request, string $reference, string $hash): void
    {
        $this->enabled();
        if ($request->session()->has('supabase_access_token')) {
            $this->deny();
        }
        $this->contexts->checkStorage();
        $row = $this->reference($reference);
        $this->step($row, 'requested', 'old_verifying', proof: $hash);
        $verified = $this->recovery->verify($hash);
        $this->identity($verified, $row->user_id, $row->old_email);
        $this->contexts->create($request, $row->id, $verified);
        $context = $this->contexts->read($request);
        $this->step($row, 'old_verifying', 'old_verified', values: ['browser_hash' => $context['browser_hash']]);
    }

    public function current(Request $request, ?string $reference = null): array
    {
        $this->enabled();
        $context = $this->contexts->read($request);
        $row = DB::table('email_change_requests')->where('id', $context['request_id'])->first();
        if (! $row || $row->user_id !== $context['auth_user_id'] || ! is_string($row->browser_hash)
            || ! hash_equals($row->browser_hash, $context['browser_hash'])
            || ($reference !== null && ! hash_equals($row->reference_hash, hash('sha256', $reference)))) {
            $this->deny();
        }
        DB::transaction(function () use ($row) {
            $this->scope($row->requested_by, $row->user_id, $row);
            $this->lock($row, $row->phase);
        });

        return [$context, $row];
    }

    public function begin(Request $request, string $reference): void
    {
        [$context, $row] = $this->current($request, $reference);
        $this->step($row, 'old_verified', 'email_started');
        $this->identity($context, $row->user_id, $row->old_email);
        $receipt = $this->provider->begin($context['access_token'], $row->new_email, $reference);
        // Under Secure Email Change, starting the operation must leave the old email current.
        if ($receipt['auth_user_id'] !== $row->user_id || $receipt['email'] !== $row->old_email) {
            $this->deny();
        }
        $this->step($row, 'email_started', 'awaiting_confirmations');
    }

    public function confirm(Request $request, string $reference, string $hash): void
    {
        [$context, $row] = $this->current($request, $reference);
        $this->step($row, 'awaiting_confirmations', 'confirming', proof: $hash);
        $verified = $this->provider->verify($hash);
        if ($verified === null) {
            if ($row->first_confirmation_at !== null) {
                $this->deny();
            }
            $this->step($row, 'confirming', 'awaiting_confirmations', values: ['first_confirmation_at' => now()]);

            return;
        }
        if ($row->first_confirmation_at === null) {
            $this->deny();
        }
        $this->identity($verified, $row->user_id, $row->new_email);
        $this->step($row, 'confirming', 'verified');
        $this->contexts->replaceToken($request, $verified);
    }

    public function complete(Request $request, string $password): void
    {
        [$initial] = $this->current($request);
        $this->passwordLocks->locked($initial['auth_user_id'], function () use ($request, $password) {
            [$context, $row] = $this->current($request);
            $this->step($row, 'verified', 'password_started');
            $this->identity($context, $row->user_id, $row->new_email);
            $receipt = $this->recovery->update($context['access_token'], $password);
            if ($receipt['auth_user_id'] !== $row->user_id || $receipt['email'] !== $row->new_email) {
                $this->deny();
            }
            $this->step($row, 'password_started', 'password_confirmed', values: ['password_confirmed_at' => now()]);
            $this->step($row, 'password_confirmed', 'signout_started');
            $this->recovery->signOutEverywhere($context['access_token']);
            DB::transaction(function () use ($row) {
                $scope = $this->scope($row->requested_by, $row->user_id, $row);
                $this->lock($row, 'signout_started');
                $scope['target']->forceFill(['approved_email' => $row->new_email, 'email_change_hold_id' => null,
                    'auth_sessions_valid_after' => now()->utc()])->save();
                $this->transition($row, 'completed', ['status' => 'confirmed', 'confirmed_at' => now(), 'signout_confirmed_at' => now()]);
                $this->record($scope, $row, 'COMPLETE VERIFIED EMAIL CHANGE', 'completed');
            });
            $this->contexts->forget($request);
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        });
    }

    public function cancel(Request $request, UserProfile $target): void
    {
        $this->enabled();
        DB::transaction(function () use ($request, $target) {
            $row = DB::table('email_change_requests')->where('user_id', $target->id)->where('status', 'pending')->first();
            if (! $row) {
                $this->deny();
            }
            $scope = $this->scope($request->attributes->get('current_user')?->id, $target->id, $row);
            // Only a request with no proof consumption or email/password write can be cancelled safely.
            $current = DB::table('email_change_requests')->where('id', $row->id)->lockForUpdate()->first();
            if ($current->phase !== 'requested' || $current->status !== 'pending') {
                $this->deny();
            }
            DB::table('email_change_requests')->where('id', $row->id)->update([
                'status' => 'cancelled', 'cancelled_at' => now(), 'updated_at' => now()]);
            $scope['target']->forceFill(['email_change_hold_id' => null])->save();
            $this->record($scope, $row, 'CANCEL VERIFIED EMAIL CHANGE', 'cancelled');
        });
    }

    private function scope(?string $actorId, string $targetId, ?object $row = null): array
    {
        $this->enabled();
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('email_change_transaction_required');
        }
        abort_unless($actorId && $actorId !== $targetId, 403);
        // Use revocation's serialization gate before taking any profile or association locks.
        $adminRole = Role::where('code', 'system_admin')->lockForUpdate()->first();
        abort_unless($adminRole?->is_active, 403);
        $admins = UserProfile::where('role_id', $adminRole->id)->where('is_active', true)->orderBy('id')->lockForUpdate()->get();
        $actor = $admins->firstWhere('id', $actorId);
        if ($actor) {
            $actor->setRelation('role', $adminRole);
        }
        abort_unless($actor?->canAccessApplication(throwOnDatabaseError: true), 403);
        $associationIds = AssociationAccount::where('user_id', $targetId)->pluck('association_id')
            ->merge(Association::where('primary_field_officer_id', $targetId)->pluck('id'))->unique()->sort()->values()->all();
        $associations = Association::whereIn('id', $associationIds)->orderBy('id')->lockForUpdate()->get();
        $target = UserProfile::whereKey($targetId)->lockForUpdate()->firstOrFail();
        $target->setRelation('role', Role::whereKey($target->role_id)->sharedLock()->first());
        if (! array_key_exists('email_change_hold_id', $target->getAttributes()) || ! $target->is_active || ! $target->activated_at
            || ! $target->role?->is_active || ! in_array($target->role->code, ['system_admin', 'field_officer', 'association_account'], true)
            || ($target->email_change_hold_id !== null && $target->email_change_hold_id !== $row?->id)) {
            $this->deny();
        }
        $links = AssociationAccount::where('user_id', $targetId)->orderBy('id')->lockForUpdate()->get();
        if ($links->pluck('association_id')->diff($associationIds)->isNotEmpty()) {
            $this->deny();
        }
        $active = $links->where('is_active', true);
        if ($target->role->code === 'association_account') {
            $association = $active->count() === 1 ? $associations->firstWhere('id', $active->first()->association_id) : null;
            if (! $association || $association->status !== 'active' || $association->archived_at !== null
                || AssociationAccount::where('association_id', $association->id)->where('is_active', true)->count() !== 1
                || Association::where('primary_field_officer_id', $targetId)->exists()) {
                $this->deny();
            }
        } elseif ($links->isNotEmpty()) {
            $this->deny();
        }
        $identity = DB::table('auth.users')->where('id', $targetId)->first(['id', 'email']);
        $email = $identity ? mb_strtolower(trim($identity->email ?? '')) : '';
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->deny();
        }
        $original = $row?->old_email ?? $email;
        if ($row && $email !== $row->old_email && (! in_array($row->phase,
            ['email_started', 'awaiting_confirmations', 'confirming', 'verified', 'password_started', 'password_confirmed', 'signout_started'], true)
            || $email !== $row->new_email)) {
            $this->deny();
        }
        $probe = clone $target;
        $probe->email_change_hold_id = null;
        if (! $probe->canAccessApplication(['id' => $targetId, 'email' => $original], true)) {
            $this->deny();
        }
        if ($target->approved_email === null && UserInvitation::where('auth_user_id', $targetId)->where('status', 'accepted')
            ->where('email', '!=', $original)->exists()) {
            $this->deny();
        }
        if (UserInvitation::whereIn('status', UserInvitation::UNRESOLVED_STATUSES)->where(fn ($query) => $query
            ->where('auth_user_id', $targetId)->orWhere('email', $original)
            ->orWhere('last_error_code', UserInvitation::IDENTITY_CONFLICT_PREFIX.$targetId))->exists()) {
            $this->deny();
        }
        if (DB::table('account_reactivations')->where('user_id', $targetId)->where('state', '!=', 'completed')->whereNull('cancelled_at')->exists()) {
            $this->deny();
        }
        $hash = hash('sha256', json_encode([$targetId, (string) $target->role_id, $target->activated_at, $target->approved_email,
            $target->auth_sessions_valid_after, $original, $associations->map->only(['id', 'status', 'archived_at', 'primary_field_officer_id'])->all(),
            $links->map->only(['id', 'user_id', 'association_id', 'is_active', 'ended_at'])->all()], JSON_THROW_ON_ERROR));
        if ($row && (! hash_equals($row->scope_hash, $hash) || $target->email_change_hold_id !== $row->id)) {
            $this->deny();
        }

        return ['actor' => $actor, 'target' => $target, 'email' => $email, 'hash' => $hash, 'links' => $links, 'associations' => $associations];
    }

    private function destination(string $email, string $old): void
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254 || $email !== mb_strtolower(trim($email)) || $email === $old) {
            $this->deny();
        }
        if (DB::table('auth.users')->whereRaw('lower(email) = ?', [$email])->orWhereRaw('lower(email_change) = ?', [$email])->exists()
            || UserInvitation::whereRaw('lower(email) = ?', [$email])->exists()
            || DB::table('email_change_requests')->where('status', 'pending')->whereRaw('lower(new_email) = ?', [$email])->exists()
            || UserProfile::where('approved_email', $email)->exists()) {
            $this->deny();
        }
    }

    private function step(object $row, string $expected, string $next, ?string $proof = null, array $values = []): void
    {
        DB::transaction(function () use ($row, $expected, $next, $proof, $values) {
            $scope = $this->scope($row->requested_by, $row->user_id, $row);
            $this->lock($row, $expected);
            if ($proof !== null) {
                if (! preg_match('/\A[a-fA-F0-9]{56}\z/D', $proof)) {
                    $this->deny();
                }
                DB::table('email_change_proofs')->insert(['proof_hash' => hash('sha256', strtolower($proof)), 'request_id' => $row->id, 'created_at' => now()]);
            }
            $this->transition($row, $next, $values);
            $this->record($scope, $row, 'VERIFY EMAIL CHANGE', $next);
        });
        $row->phase = $next;
        foreach ($values as $key => $value) {
            $row->$key = $value;
        }
    }

    private function lock(object $row, string $expected): void
    {
        $current = DB::table('email_change_requests')->where('id', $row->id)->lockForUpdate()->first();
        if (! $current || $current->status !== 'pending' || $current->phase !== $expected || Carbon::parse($current->expires_at)->lte(now())) {
            $this->deny();
        }
    }

    private function transition(object $row, string $phase, array $values = []): void
    {
        DB::table('email_change_requests')->where('id', $row->id)->update($values + ['phase' => $phase, 'updated_at' => now()]);
    }

    private function identity(array $verified, string $id, string $email): void
    {
        if ($verified['auth_user_id'] !== $id || $verified['email'] !== $email) {
            $this->deny();
        }
        $fresh = $this->recovery->identity($verified['access_token']);
        if ($fresh['auth_user_id'] !== $id || $fresh['email'] !== $email) {
            $this->deny();
        }
    }

    private function reference(string $reference): object
    {
        if (! preg_match('/\A[A-Za-z0-9]{64}\z/D', $reference)) {
            $this->deny();
        }
        $row = DB::table('email_change_requests')->where('reference_hash', hash('sha256', $reference))->first();
        if (! $row) {
            $this->deny();
        }

        return $row;
    }

    private function record(array $scope, object $row, string $action, string $phase): void
    {
        $request = new Request;
        $request->attributes->set('current_user', $scope['actor']);
        $this->audit->record($request, $action, 'User Management', 'email_change_request', $row->id, null,
            ['user_id' => $row->user_id, 'requested_by' => $row->requested_by, 'phase' => $phase], ['user_id', 'requested_by', 'phase']);
    }

    private function enabled(): void
    {
        abort_unless(config('services.supabase.account_email_change_enabled') === true, 404);
    }

    private function deny(): never
    {
        throw ValidationException::withMessages(['email_change' => 'Email change is unavailable, expired, already started, or requires Administrator reconciliation. Do not repeat provider operations.']);
    }
}
