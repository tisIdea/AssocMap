<?php

namespace App\Http\Middleware;

use App\Services\MobileAssociationAccessService;
use App\Services\SupabaseAuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSupabaseApiAuthenticated
{
    public function __construct(
        private SupabaseAuthService $supabaseAuth,
        private MobileAssociationAccessService $access
    ) {
    }

    public function handle(
        Request $request,
        Closure $next
    ): Response {
        foreach (['current_user', 'supabase_identity', 'mobile_context', 'mobile_association_id'] as $attribute) {
            $request->attributes->remove($attribute);
        }
        $accessToken = $request->bearerToken();

        if (! $accessToken) {
            return ApiRequestGuard::error('unauthenticated', 'Unauthenticated.', 401);
        }

        $result = $this->supabaseAuth->verifyApiIdentity($accessToken);
        if ($result['outcome'] === 'invalid') {
            return ApiRequestGuard::error('unauthenticated', 'Invalid or expired access token.', 401);
        }
        if ($result['outcome'] === 'denied') {
            return ApiRequestGuard::error('account_unavailable', 'This AssocMap account or access is unavailable.', 403);
        }
        if ($result['outcome'] !== 'verified') {
            return ApiRequestGuard::error('authentication_unavailable', 'Authentication verification is temporarily unavailable.', 503);
        }

        $context = $this->access->resolve($result['identity']);
        $request->attributes->set('supabase_identity', $result['identity']);
        $request->attributes->set('current_user', $context['profile']);
        $request->attributes->set('mobile_context', $context['mobile']);

        return ApiRequestGuard::secure($next($request));
    }
}
