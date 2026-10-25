<?php

namespace App\Services\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class EmailChangeContext
{
    public function __construct(private InvitationActivationContext $storage) {}

    public function checkStorage(): void
    {
        $this->storage->checkStorage();
    }

    public function create(Request $request, string $requestId, array $verified): void
    {
        $this->forget($request);
        $request->session()->regenerate(true);
        $request->session()->regenerateToken();
        $id = Str::random(64);
        $request->session()->put('email_change_context', $id);
        $this->save($request, ['request_id' => $requestId, 'auth_user_id' => $verified['auth_user_id'],
            'email' => $verified['email'], 'access_token' => $verified['access_token'],
            'expires_at' => time() + min(3600, $verified['expires_in'] - 30),
            'browser_hash' => hash('sha256', $request->session()->getId())]);
    }

    public function read(Request $request): array
    {
        if ($request->session()->has('supabase_access_token')) {
            throw new RuntimeException('email_change_session_conflict');
        }
        $id = $request->session()->get('email_change_context');
        if (! is_string($id) || ! preg_match('/\A[A-Za-z0-9]{64}\z/D', $id)) {
            throw new RuntimeException('email_change_context_unavailable');
        }
        try {
            $context = json_decode(Crypt::decryptString($this->storage->storage()->get('email-change:'.$id)), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException('email_change_context_unavailable');
        }
        if (! is_array($context) || ! is_int($context['expires_at'] ?? null) || $context['expires_at'] <= time()
            || ! is_string($context['browser_hash'] ?? null)
            || ! hash_equals($context['browser_hash'], hash('sha256', $request->session()->getId()))) {
            throw new RuntimeException('email_change_context_unavailable');
        }

        return $context;
    }

    public function replaceToken(Request $request, array $verified): void
    {
        $context = $this->read($request);
        $context['email'] = $verified['email'];
        $context['access_token'] = $verified['access_token'];
        $context['expires_at'] = min($context['expires_at'], time() + $verified['expires_in'] - 30);
        $this->save($request, $context);
    }

    public function forget(Request $request): void
    {
        $id = $request->session()->pull('email_change_context');
        if (is_string($id) && preg_match('/\A[A-Za-z0-9]{64}\z/D', $id)) {
            try {
                $this->storage->storage()->forget('email-change:'.$id);
            } catch (Throwable $exception) {
                // Durable request state still prevents replay when cache cleanup fails.
            }
        }
    }

    private function save(Request $request, array $context): void
    {
        if (! $this->storage->storage()->put('email-change:'.$request->session()->get('email_change_context'),
            Crypt::encryptString(json_encode($context, JSON_THROW_ON_ERROR)), $context['expires_at'] - time())) {
            throw new RuntimeException('email_change_storage_unavailable');
        }
    }
}
