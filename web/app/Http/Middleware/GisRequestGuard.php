<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GisRequestGuard
{
    public static function applies(Request $request): bool
    {
        return $request->is('map', 'map/*', 'admin/gis', 'admin/gis/*', 'field-officer/gis', 'field-officer/gis/*', 'association/gis', 'association/gis/*');
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            abort_if((int) $request->server('CONTENT_LENGTH', 0) > 196608 || strlen($request->getContent()) > 196608, 413);
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}
