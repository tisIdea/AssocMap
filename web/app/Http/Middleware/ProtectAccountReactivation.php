<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProtectAccountReactivation
{
    public static function applies(Request $request): bool
    {
        return $request->is('account-reactivation', 'account-reactivation/*');
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::applies($request)) {
            return $next($request);
        }
        $request->attributes->set('activation_nonce', base64_encode(random_bytes(24)));
        abort_unless(config('services.supabase.account_reactivation_enabled') === true, 404);
        if ($request->query->count() > 0) {
            $request->query->replace([]);
            $request->server->set('QUERY_STRING', '');
            $request->server->set('REQUEST_URI', '/'.$request->path());

            return ProtectInvitationActivation::secure(redirect('/'.$request->path()), $request);
        }
        $previous = config('session.block_store');
        config(['session.block_store' => config('services.supabase.activation_cache_store', 'database')]);
        try {
            return ProtectInvitationActivation::secure($next($request), $request);
        } finally {
            config(['session.block_store' => $previous]);
        }
    }
}
