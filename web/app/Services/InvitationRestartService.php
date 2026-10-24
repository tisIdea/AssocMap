<?php

namespace App\Services;

use App\Exceptions\InvitationActivationException;
use App\Models\AuditLog;
use App\Models\UserInvitation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class InvitationRestartService
{
    public const LIFETIME_SECONDS = 600;

    public function __construct(private InvitationActivationContext $contexts, private InvitationActivationService $activation,
        private SupabaseInvitationProvider $invitations, private SupabasePasswordRecoveryProvider $provider,
        private SupabaseAuthService $auth, private InvitationRestartAudit $audit)
    {
    }

    public function requestLink(string $email): void
    {
        if (config('services.supabase.invitation_restart_enabled') !== true) return;
        $invitation = null;
        try {
            $invitation = UserInvitation::where('email', $email)->where('status', 'sent')->sole();
            $scope = $this->scope($invitation);
            $this->eligible($scope);
            $this->contexts->checkStorage();
            $this->audit->record($invitation, 'requested');
            if (! $this->invitations->confirmedInvitationIdentity($scope['auth_user_id'], $email)) {
                $this->audit->record($invitation, 'denied');
                return;
            }
            $this->eligible($scope);
            $reference = Str::random(64);
            $record = ['purpose' => 'invitation_restart', 'scope' => $scope, 'phase' => 'issued',
                'expires_at' => time() + self::LIFETIME_SECONDS];
            $this->save($reference, $record);
            try {
                $this->provider->requestInvitationRestart($email, $reference);
            } catch (Throwable $exception) {
                // An email may have been sent; its proof must still pass all callback checks.
                $this->audit->record($invitation, 'provider_uncertain');
                return;
            }
            $this->audit->record($invitation, 'provider_accepted');
        } catch (Throwable $exception) {
            $this->denied($invitation);
        }
    }

    public function verify(Request $request, string $reference, string $hash): void
    {
        $invitation = null;
        try {
            if (config('services.supabase.invitation_restart_enabled') !== true
                || ! preg_match('/\A[A-Za-z0-9]{64}\z/D', $reference)
                || ($request->session()->has('supabase_access_token') && ! $request->boolean('sign_out_current_account'))) $this->deny();
            $this->contexts->checkStorage();
            $this->contexts->storage()->lock('invitation-restart-verify:'.$reference, 120)->block(3, function () use ($request, $reference, $hash, &$invitation) {
                $record = $this->read($reference);
                $scope = $record['scope'];
                $invitation = UserInvitation::find($scope['invitation_id']);
                $this->eligible($scope);
                // Consume before single-use provider verification; a timeout cannot replay it.
                $record['phase'] = 'consumed';
                $this->save($reference, $record);
                $verified = $this->provider->verify($hash);
                $identity = $this->auth->getInvitationIdentity($verified['access_token']);
                if ($identity === null || $identity['auth_user_id'] !== $scope['auth_user_id']
                    || $identity['email'] !== $scope['email'] || $verified['auth_user_id'] !== $scope['auth_user_id']
                    || $verified['email'] !== $scope['email'] || $record['expires_at'] <= time()) $this->deny();
                $this->eligible($scope);
                $this->audit->record($invitation, 'verification_completed', true);
                if ($request->session()->has('supabase_access_token')) {
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }
                $this->contexts->create($request, $scope + ['access_token' => $verified['access_token'],
                    'recovery_origin' => 'invitation_restart'], $verified['expires_in']);
            });
        } catch (Throwable $exception) {
            $this->denied($invitation);
            throw $exception;
        }
    }

    private function eligible(array $scope): void
    {
        $this->activation->eligible($scope);
        if (AuditLog::where('action_type', UserAccessRevocationService::ACTION)->where('entity_type', 'user_profile')
            ->where('entity_id', $scope['auth_user_id'])->exists()) $this->deny();
    }

    private function scope(UserInvitation $invitation): array
    {
        if (! Str::isUuid($invitation->auth_user_id ?? '') || ! filter_var($invitation->email, FILTER_VALIDATE_EMAIL)
            || mb_strtolower(trim($invitation->email)) !== $invitation->email) $this->deny();

        return ['invitation_id' => $invitation->id, 'auth_user_id' => $invitation->auth_user_id,
            'email' => $invitation->email, 'role_id' => (string) $invitation->role_id, 'association_id' => $invitation->association_id];
    }

    private function read(string $reference): array
    {
        if (! preg_match('/\A[A-Za-z0-9]{64}\z/D', $reference)) $this->deny();
        $encrypted = $this->contexts->storage()->get('invitation-restart:'.$reference);
        if (! is_string($encrypted)) $this->deny();
        $record = json_decode(Crypt::decryptString($encrypted), true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($record) || ($record['purpose'] ?? null) !== 'invitation_restart'
            || ($record['phase'] ?? null) !== 'issued' || ! is_int($record['expires_at'] ?? null)
            || $record['expires_at'] <= time() || ! is_array($record['scope'] ?? null)) $this->deny();

        return $record;
    }

    private function save(string $reference, array $record): void
    {
        $ttl = $record['expires_at'] - time();
        if ($ttl <= 0 || ! $this->contexts->storage()->put('invitation-restart:'.$reference,
            Crypt::encryptString(json_encode($record, JSON_THROW_ON_ERROR)), $ttl)) $this->deny();
    }

    private function denied(?UserInvitation $invitation): void
    {
        try {
            if ($invitation !== null) $invitation = UserInvitation::find($invitation->id);
            $hold = $invitation?->last_error_code !== null;
            $this->audit->record($invitation, $hold ? 'reconciliation_hold' : 'denied');
        } catch (Throwable $exception) {
            Log::warning('Invitation recovery audit unavailable.', ['code' => 'invitation_restart_audit_unavailable']);
        }
    }

    private function deny(): never
    {
        throw new InvitationActivationException('invitation_restart_unavailable');
    }
}
