<?php

namespace App\Services;

use App\Exceptions\InvitationActivationException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

class InvitationActivationContext
{
    public const SESSION_KEY = 'invitation_activation_context';

    public function storage(): Repository
    {
        $store = config('services.supabase.activation_cache_store', 'database');
        if (! is_string($store) || ! in_array(config('cache.stores.'.$store.'.driver'), ['database', 'redis', 'file'], true)
            || ! in_array(config('session.driver'), ['database', 'redis', 'file', 'memcached', 'dynamodb'], true)) {
            throw new InvitationActivationException('activation_storage_unavailable');
        }

        return Cache::store($store);
    }

    public function create(Request $request, array $context, int $expiresIn): void
    {
        $this->forget($request);
        $request->session()->regenerate(true);
        $request->session()->regenerateToken();
        $id = Str::random(64);
        $context['expires_at'] = time() + min(600, max(0, $expiresIn - 30));
        $context['session_fingerprint'] = hash('sha256', $request->session()->getId());
        $context['phase'] = 'verified';
        $this->save($id, $context);
        $request->session()->put(self::SESSION_KEY, $id);
    }

    public function checkStorage(): void
    {
        $store = $this->storage();
        $key = 'invitation-activation-probe:'.Str::random(32);
        if (! $store->put($key, 'ready', 30) || $store->get($key) !== 'ready') {
            throw new InvitationActivationException('activation_storage_unavailable');
        }
        $store->forget($key);
    }

    public function id(Request $request): ?string
    {
        $id = $request->session()->get(self::SESSION_KEY);

        return is_string($id) && preg_match('/\A[A-Za-z0-9]{64}\z/D', $id) ? $id : null;
    }

    public function read(Request $request): ?array
    {
        $id = $this->id($request);
        if ($id === null) return null;
        $encrypted = $this->storage()->get('invitation-activation:'.$id);
        if (! is_string($encrypted)) {
            $this->forget($request);
            return null;
        }

        try {
            $context = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            $this->forget($request);
            return null;
        }
        if (! is_array($context) || ! is_int($context['expires_at'] ?? null) || $context['expires_at'] <= time()
            || ! is_string($context['session_fingerprint'] ?? null)
            || ! hash_equals($context['session_fingerprint'], hash('sha256', $request->session()->getId()))) {
            $this->forget($request);
            return null;
        }

        return $context;
    }

    public function save(string $id, array $context): void
    {
        $ttl = $context['expires_at'] - time();
        if ($ttl <= 0 || ! $this->storage()->put('invitation-activation:'.$id,
            Crypt::encryptString(json_encode($context, JSON_THROW_ON_ERROR)), $ttl)) {
            throw new InvitationActivationException('activation_context_expired');
        }
    }

    public function forget(Request $request): void
    {
        $id = $this->id($request);
        $request->session()->forget(self::SESSION_KEY);
        if ($id !== null) {
            try {
                $this->storage()->forget('invitation-activation:'.$id);
            } catch (Throwable $exception) {
                // The capability is removed even when storage is unavailable.
            }
        }
    }

    public function passwordClaim(string $id): string
    {
        return 'activation_password_started:'.hash('sha256', $id);
    }
}
