<?php

namespace App\Http\Middleware;

use App\Models\UserProfile;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AuditRequestGuard
{
    public static function applies(Request $request): bool
    {
        return $request->is('admin/audit-logs', 'admin/audit-logs/*');
    }

    public function handle(Request $request, Closure $next): Response
    {
        abort_if(strlen($request->server('QUERY_STRING', '')) > 8192, 414);

        return self::secure($next($request));
    }

    public static function secure(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    public static function render(Throwable $exception, Request $request): Response
    {
        if ($exception instanceof HttpResponseException) return self::secure($exception->getResponse());
        $status = $exception instanceof ModelNotFoundException ? 404
            : ($exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 503);
        if ($status >= 500) Log::warning('Audit viewer unavailable.', ['code' => 'audit_viewer_unavailable', 'http_status' => $status]);
        $message = match ($status) {
            401, 403 => 'You are not authorized to access Administrator audit logs.',
            404 => 'This audit event or page is unavailable.',
            405 => 'Audit logs support read-only requests.',
            414 => 'The filter request is too long. Clear filters and try again.',
            429 => 'Too many audit requests. Wait a minute before retrying.',
            default => 'Audit logs are temporarily unavailable. Try again later.',
        };
        if ($request->expectsJson()) return self::secure(response()->json(['message' => $message], $status));
        $actor = $request->attributes->get('current_user');
        if ($status === 503 && $actor instanceof UserProfile && $actor->role?->code === 'system_admin') {
            return self::secure(response()->view('admin.audit-logs.index', ['unavailable' => true], 503));
        }

        return self::secure(response()->view('admin.audit-logs.error', compact('status', 'message'), $status));
    }
}
