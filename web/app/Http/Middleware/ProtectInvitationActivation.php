<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProtectInvitationActivation
{
    public static function applies(Request $request): bool
    {
        return $request->is('invitation', 'invitation/*');
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::applies($request)) return $next($request);
        $request->attributes->set('activation_nonce', base64_encode(random_bytes(24)));
        if ($request->query->count() > 0) {
            $request->query->replace([]);
            $request->server->set('QUERY_STRING', '');
            $request->server->set('REQUEST_URI', '/'.$request->path());
            return self::secure(redirect('/invitation'), $request);
        }

        // Session blocking must use the same persistent store as the context.
        $previousStore = config('session.block_store');
        config(['session.block_store' => config('services.supabase.activation_cache_store', 'database')]);
        try {
            return self::secure($next($request), $request);
        } finally {
            config(['session.block_store' => $previousStore]);
        }
    }

    public static function secure(Response $response, Request $request): Response
    {
        $nonce = $request->attributes->get('activation_nonce', '');
        $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; script-src 'nonce-{$nonce}'; style-src 'nonce-{$nonce}'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'; connect-src 'none'; img-src 'none'; font-src 'none'");

        return $response;
    }
}
