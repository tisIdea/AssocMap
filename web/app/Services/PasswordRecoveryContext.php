<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PasswordRecoveryContext
{
    private const SESSION_KEY = 'password_recovery_context';

    public function __construct(private InvitationActivationContext $storage)
    {
    }

    public function checkStorage(): void
    {
        $this->storage->checkStorage();
    }

    public function create(Request $request, array $verified, ?string $reactivationId = null): void
    {
        $this->forget($request);
        $request->session()->regenerate(true);
        $request->session()->regenerateToken();
        $id = Str::random(64);
        $context = ['auth_user_id' => $verified['auth_user_id'], 'email' => $verified['email'],
            'access_token' => $verified['access_token'], 'phase' => 'verified',
            'expires_at' => time() + min(600, $verified['expires_in'] - 30),
            'session_fingerprint' => hash('sha256', $request->session()->getId())];
        if ($reactivationId !== null) $context['reactivation_id'] = $reactivationId;
        $this->save($id, $context);
        $request->session()->put(self::SESSION_KEY, $id);
    }

    public function read(Request $request): ?array
    {
        $request->attributes->set('recovery_context_failure', 'context_missing');
        $id = $this->id($request);
        if ($id === null) return null;
        $request->attributes->set('recovery_context_failure', 'context_cache_missing_or_expired');
        $encrypted = $this->storage->storage()->get('password-recovery:'.$id);
        if (! is_string($encrypted)) return null;
        $request->attributes->set('recovery_context_failure', 'context_invalid');
        try {
            $context = json_decode(Crypt::decryptString($encrypted), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            return null;
        }
        if (! is_array($context) || ! is_int($context['expires_at'] ?? null)
            || ! is_string($context['session_fingerprint'] ?? null)) return null;
        if (($context['phase'] ?? null) !== 'verified') {
            $request->attributes->set('recovery_context_failure', 'context_consumed_or_invalid_phase');
            return null;
        }
        if ($context['expires_at'] <= time()) {
            $request->attributes->set('recovery_context_failure', 'context_expired');
            return null;
        }
        if (! hash_equals($context['session_fingerprint'], hash('sha256', $request->session()->getId()))) {
            $request->attributes->set('recovery_context_failure', 'context_session_mismatch');
            return null;
        }
        $request->attributes->remove('recovery_context_failure');

        return $context;
    }

    public function consume(Request $request, array $context): void
    {
        $id = $this->id($request);
        if ($id === null || $context['expires_at'] <= time()) throw new RuntimeException('recovery_context_expired');
        $context['phase'] = 'password_update_started';
        $this->save($id, $context);
    }

    public function locked(string $identityId, callable $callback): mixed
    {
        return $this->storage->storage()->lock('password-recovery-write:'.$identityId, 120)->block(3, $callback);
    }

    public function forget(Request $request): void
    {
        $id = $this->id($request);
        $request->session()->forget(self::SESSION_KEY);
        if ($id !== null) {
            try {
                $this->storage->storage()->forget('password-recovery:'.$id);
            } catch (Throwable $exception) {
                // The session capability is removed even if cache cleanup is unavailable.
            }
        }
    }

    private function id(Request $request): ?string
    {
        $id = $request->session()->get(self::SESSION_KEY);

        return is_string($id) && preg_match('/\A[A-Za-z0-9]{64}\z/D', $id) ? $id : null;
    }

    private function save(string $id, array $context): void
    {
        $ttl = $context['expires_at'] - time();
        if ($ttl <= 0 || ! $this->storage->storage()->put('password-recovery:'.$id,
            Crypt::encryptString(json_encode($context, JSON_THROW_ON_ERROR)), $ttl)) {
            throw new RuntimeException('recovery_storage_unavailable');
        }
    }
}
