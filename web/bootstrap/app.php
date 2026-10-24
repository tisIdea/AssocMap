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
        $middleware->prepend(\App\Http\Middleware\ProtectAccountEmailChange::class);
        $middleware->prepend(\App\Http\Middleware\ProtectAccountReactivation::class);
        $middleware->trimStrings(except: ['completion_confirmation', 'termination_confirmation']);
        $middleware->prepend(\App\Http\Middleware\ProtectInvitationActivation::class);
        $middleware->prepend(\App\Http\Middleware\ProtectPasswordRecovery::class);
        $middleware->prepend(\App\Http\Middleware\LocalRequestTiming::class);
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
        $exceptions->dontReportWhen(fn (\Throwable $exception) => \App\Http\Middleware\ProtectAccountEmailChange::applies(request()));
        $exceptions->render(function (\Throwable $exception, Request $request) {
            if (! \App\Http\Middleware\ProtectAccountEmailChange::applies($request)) return null;
            $status = $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $exception->getStatusCode() : 503;
            if ($exception instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
                return \App\Http\Middleware\ProtectInvitationActivation::secure($exception->getResponse(), $request);
            }
            return \App\Http\Middleware\ProtectInvitationActivation::secure(response()->view('auth.email-change',
                ['step' => 'unavailable'], $status), $request);
        });
        $exceptions->dontReportWhen(fn (\Throwable $exception) => \App\Http\Middleware\ProtectAccountReactivation::applies(request()));
        $exceptions->render(function (\Throwable $exception, Request $request) {
            if (! \App\Http\Middleware\ProtectAccountReactivation::applies($request)) return null;
            if ($exception instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
                return \App\Http\Middleware\ProtectInvitationActivation::secure($exception->getResponse(), $request);
            }
            $status = $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $exception->getStatusCode() : 503;
            $message = $status === 429 ? 'Too many reactivation requests. Wait before trying again.' : 'Account reactivation is unavailable. Contact the Administrator.';
            return \App\Http\Middleware\ProtectInvitationActivation::secure(response()->view('auth.password-recovery',
                ['reactivation' => true, 'step' => 'invalid', 'recoveryFailureMessage' => $message], $status), $request);
        });
        $exceptions->render(function (\App\Exceptions\MonitoringMaterialsConflictException $exception, Request $request) {
            $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
            $prefix = $request->routeIs('admin.monitoring.*') ? 'admin.monitoring'
                : ($request->routeIs('field-officer.monitoring.*') ? 'field-officer.monitoring' : null);
            $record = $request->route('monitoringRecord');
            $latestMaterialsUrl = $prefix && $record instanceof \App\Models\MonitoringRecord
                ? route($prefix.'.section.edit', [$record, 'materials']) : null;
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage(),
                    'code' => 'monitoring_materials_conflict', 'latest_saved_url' => $latestMaterialsUrl,
                    'errors' => ['materials_revision' => [$exception->getMessage()]]], 409, $headers);
            }
            $draft = $request->only(['project_id', 'year', 'quarter_id', 'project_status', 'monitoring_date', 'site_validated',
                'issues_encountered', 'actions_taken', 'overall_remarks', 'production_revision', 'income_revision',
                'production', 'materials_revision', 'materials', 'deleted_material_ids']);
            return response()->view('monitoring.materials-conflict', ['message' => $exception->getMessage(), 'draft' => $draft,
                'latestMaterialsUrl' => $latestMaterialsUrl], 409, $headers);
        });
        $exceptions->render(function (\App\Exceptions\MonitoringIncomeConflictException $exception, Request $request) {
            $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
            $prefix = $request->routeIs('admin.monitoring.*') ? 'admin.monitoring'
                : ($request->routeIs('field-officer.monitoring.*') ? 'field-officer.monitoring' : null);
            $record = $request->route('monitoringRecord');
            $latestIncomeUrl = $prefix && $record instanceof \App\Models\MonitoringRecord
                ? route($prefix.'.section.edit', [$record, 'income']) : null;
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage(),
                    'code' => 'monitoring_income_conflict', 'latest_saved_url' => $latestIncomeUrl,
                    'errors' => ['income_revision' => [$exception->getMessage()]]], 409, $headers);
            }
            $draft = $request->only(['project_id', 'year', 'quarter_id', 'income_revision', 'production']);
            return response()->view('monitoring.income-conflict', ['message' => $exception->getMessage(), 'draft' => $draft,
                'latestIncomeUrl' => $latestIncomeUrl], 409, $headers);
        });
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
        $exceptions->dontReportWhen(fn (\Throwable $exception) => \App\Http\Middleware\ProtectPasswordRecovery::applies(request()));
        $exceptions->render(function (\Throwable $exception, Request $request) {
            if (! \App\Http\Middleware\ProtectPasswordRecovery::applies($request)) return null;
            if ($exception instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
                return \App\Http\Middleware\ProtectInvitationActivation::secure($exception->getResponse(), $request);
            }
            $status = $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                ? $exception->getStatusCode() : 503;
            $recoveryFailureMessage = match ($status) {
                429 => 'Too many recovery requests. Wait before trying again.',
                419 => 'This form has expired. Reload the page and try again.',
                default => 'Password recovery is temporarily unavailable. Please try again later.',
            };
            $headers = $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $exception->getHeaders() : [];
            return \App\Http\Middleware\ProtectInvitationActivation::secure(
                response()->view('auth.password-recovery', ['step' => 'forgot', 'recoveryFailureMessage' => $recoveryFailureMessage], $status, $headers), $request);
        });
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
        $exceptions->render(function (\Throwable $exception, Request $request) {
            if ($request->expectsJson() || ! $request->is('admin/*', 'dashboard', 'field-officer/*', 'association/*', 'login')
                || $exception instanceof \Illuminate\Validation\ValidationException
                || $exception instanceof \Illuminate\Auth\AuthenticationException
                || $exception instanceof \Illuminate\Http\Exceptions\HttpResponseException) return null;
            $status = $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                ? $exception->getStatusCode() : 500;
            $headers = $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $exception->getHeaders() : [];
            return response()->view('errors.feedback', compact('status'), $status,
                array_merge($headers, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']));
        });
    })
    ->create();
