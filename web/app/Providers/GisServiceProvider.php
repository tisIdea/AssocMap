<?php

namespace App\Providers;

use App\Http\Middleware\GisRequestGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class GisServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('gis-read', fn (Request $request) => Limit::perMinute(120)->by('gis-read:'.$request->ip()));
        RateLimiter::for('gis-write', fn (Request $request) => [
            Limit::perMinute(15)->by('gis-write:'.($request->session()->get('user_profile_id') ?? $request->ip())),
            Limit::perMinute(60)->by('gis-write-ip:'.$request->ip()),
        ]);
        $handler = app(ExceptionHandler::class);
        $handler->reportable(function (Throwable $exception) {
            if (! GisRequestGuard::applies(request())) return null;
            Log::warning('GIS request failed.', ['code' => 'gis_request_failed']);
            return false;
        });
        $handler->renderable(function (Throwable $exception, Request $request) {
            if (! GisRequestGuard::applies($request)) return null;
            if ($exception instanceof HttpResponseException) return $exception->getResponse();
            $status = $exception instanceof ValidationException ? 422
                : ($exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 503);
            $messages = [403 => 'You are not authorized to access this GIS workspace.', 404 => 'This GIS record or parent is unavailable.',
                409 => 'This record or request has changed. Reload before continuing.', 413 => 'This GIS request is too large.',
                419 => 'Your session has expired. Reload and sign in again.', 422 => 'Review the GIS fields and geometry.',
                429 => 'Too many GIS requests. Wait before trying again.'];
            $message = $messages[$status] ?? 'GIS is temporarily unavailable. Please try again later.';
            $headers = ['Cache-Control' => 'no-store, private, max-age=0', 'X-Content-Type-Options' => 'nosniff'];
            if ($exception instanceof HttpExceptionInterface) $headers += $exception->getHeaders();
            if ($request->expectsJson() || ! $request->isMethod('GET') || $request->is('*/data', '*/parents', 'map/associations')) {
                $body = ['message' => $message];
                if ($exception instanceof ValidationException) $body['errors'] = $exception->errors();
                return response()->json($body, $status, $headers);
            }

            return response()->view('gis-unavailable', ['message' => $message], $status, $headers);
        });
    }
}
