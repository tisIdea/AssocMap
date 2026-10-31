<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ApiRequestGuard
{
    public static function applies(Request $request): bool
    {
        return $request->is('api/v1', 'api/v1/*');
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST') && $request->is('api/v1/association/member-applications')) {
            abort_if((int) $request->server('CONTENT_LENGTH', 0) > 2 * 1024 * 1024 + 65536
                || strlen($request->getContent()) > 2 * 1024 * 1024 + 65536, 413);
        }
        return self::secure($next($request));
    }

    public static function secure(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    public static function error(string $code, string $message, int $status, array $headers = []): JsonResponse
    {
        $response = response()->json(['message' => $message, 'error' => ['code' => $code]], $status, $headers);
        if ($status === 401) $response->headers->set('WWW-Authenticate', 'Bearer');
        self::secure($response);

        return $response;
    }

    public static function render(Throwable $exception, Request $request): JsonResponse
    {
        $status = $exception instanceof ModelNotFoundException ? 404
            : ($exception instanceof ValidationException ? 422
                : ($exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 503));
        if ($status >= 500) $status = 503;
        [$code, $message] = match ($status) {
            401 => ['unauthenticated', 'Unauthenticated.'],
            403 => [($request->attributes->get('mobile_access_required') === true
                ? 'mobile_access_denied' : 'account_unavailable'), 'This AssocMap account or access is unavailable.'],
            404 => ['not_found', 'This resource is unavailable.'],
            405 => ['method_not_allowed', 'This request method is unavailable.'],
            422 => ['validation_failed', 'The submitted information is invalid.'],
            429 => ['rate_limited', 'Too many requests. Wait before retrying.'],
            default => ['service_unavailable', 'This service is temporarily unavailable.'],
        };
        $headers = [];
        if ($status === 429 && $exception instanceof HttpExceptionInterface) {
            foreach ($exception->getHeaders() as $name => $value) {
                if (strtolower($name) === 'retry-after' && is_scalar($value) && ctype_digit((string) $value)) {
                    $headers['Retry-After'] = (string) $value;
                }
            }
        }
        if ($status >= 500) Log::warning('API request unavailable.', ['code' => $code, 'http_status' => $status]);

        $response = self::error($code, $message, $status, $headers);
        if ($exception instanceof ValidationException && $request->routeIs('api.association.member-applications.*')) {
            $response->setData($response->getData(true) + ['errors' => $exception->errors()]);
        }

        return $response;
    }
}
