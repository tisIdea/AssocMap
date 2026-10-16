<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trimStrings(except: ['completion_confirmation', 'termination_confirmation']);
        $middleware->prepend(\App\Http\Middleware\ProtectInvitationActivation::class);
        // Trust the local Cloudflare Tunnel reverse proxy.
        $middleware->trustProxies(
            at: ['127.0.0.1', '::1'],
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->alias([
            'supabase.auth' => \App\Http\Middleware\EnsureSupabaseAuthenticated::class,
            'role' => \App\Http\Middleware\EnsureRole::class,
            'supabase.api.auth' => \App\Http\Middleware\EnsureSupabaseApiAuthenticated::class,
            'mobile.association' => \App\Http\Middleware\EnsureMobileAssociationAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReportWhen(fn (\Throwable $exception) => \App\Http\Middleware\ReportingRequestGuard::applies(request()));
        $exceptions->render(function (\Throwable $exception, Request $request) {
            if (! \App\Http\Middleware\ReportingRequestGuard::applies($request)) return null;

            return \App\Http\Middleware\ReportingRequestGuard::render($exception, $request);
        });
        $exceptions->dontReportWhen(fn (\Throwable $exception) => \App\Http\Middleware\ApiRequestGuard::applies(request()));
        $exceptions->render(function (\Throwable $exception, Request $request) {
            if (! \App\Http\Middleware\ApiRequestGuard::applies($request)) return null;

            return \App\Http\Middleware\ApiRequestGuard::render($exception, $request);
        });
        $exceptions->dontReportWhen(fn (\Throwable $exception) => \App\Http\Middleware\AuditRequestGuard::applies(request()));
        $exceptions->render(function (\Throwable $exception, Request $request) {
            if (! \App\Http\Middleware\AuditRequestGuard::applies($request)) return null;

            return \App\Http\Middleware\AuditRequestGuard::render($exception, $request);
        });
        $exceptions->dontFlash(['token_hash', 'access_token', 'refresh_token']);
        $exceptions->dontReportWhen(fn (\Throwable $exception) => \App\Http\Middleware\ProtectInvitationActivation::applies(request()));
        $exceptions->render(function (\Throwable $exception, Request $request) {
            if (! \App\Http\Middleware\ProtectInvitationActivation::applies($request)) return null;
            if ($exception instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
                return \App\Http\Middleware\ProtectInvitationActivation::secure($exception->getResponse(), $request);
            }
            $status = $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                ? $exception->getStatusCode() : 503;
            \Illuminate\Support\Facades\Log::notice('Invitation request stopped.', ['code' => 'activation_request_failed', 'http_status' => $status]);

            return \App\Http\Middleware\ProtectInvitationActivation::secure(
                response()->view('auth.invitation-result', ['succeeded' => false], $status), $request
            );
        });
    })
    ->create();
