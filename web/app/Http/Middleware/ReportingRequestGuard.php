<?php

namespace App\Http\Middleware;

use App\Exceptions\ReportingUnavailableException;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ReportingRequestGuard
{
    public static function applies(Request $request): bool
    {
        return $request->is('admin/reports', 'admin/reports/*', 'field-officer/reports', 'field-officer/reports/*',
            'association/reports', 'association/reports/*');
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            abort_if(strlen($request->server('QUERY_STRING', '')) > 4096 || strlen($request->getContent()) > 8192, 413);

            return ApiRequestGuard::secure($next($request));
        } catch (Throwable $exception) {
            return self::render($exception, $request);
        }
    }

    public static function render(Throwable $exception, Request $request): Response
    {
        $status = $exception instanceof ValidationException ? 422
            : ($exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 503);
        if ($status >= 500) {
            $status = 503;
            $context = ['code' => 'reporting_unavailable', 'stage' => $request->attributes->get('reporting_stage', 'request')];
            if ($exception instanceof ReportingUnavailableException) {
                $context = array_merge($context, $exception->diagnostics());
            } elseif ($exception instanceof QueryException) {
                $context['reason'] = 'database_query_failed';
                $sqlState = $exception->errorInfo[0] ?? null;
                if (is_string($sqlState) && preg_match('/\A[A-Z0-9]{5}\z/', $sqlState)) $context['sql_state'] = $sqlState;
            } else {
                $context['reason'] = 'unexpected_failure';
            }
            Log::warning('Reporting request unavailable.', $context);
        } elseif ($status === 422) {
            Log::notice('Reporting filters rejected.', ['code' => 'reporting_validation_failed',
                'stage' => $request->attributes->get('reporting_stage', 'filter_validation')]);
        }
        $message = match ($status) {
            401, 419 => 'Your session has expired. Sign in again to view reports.',
            403 => 'This report scope or export is not available to your account.',
            404 => 'This report is unavailable.',
            422 => 'Review the report filters or select a smaller scope.',
            429 => 'Reporting is busy. Wait a minute and retry.',
            413 => 'The report filter request is too large.',
            default => 'Reporting is temporarily unavailable. Contact the administrator if this continues.',
        };
        $errors = $exception instanceof ValidationException ? $exception->errors() : [];
        if ($request->expectsJson()) {
            return ApiRequestGuard::secure(response()->json(['message' => $message, 'errors' => $errors,
                'error' => ['code' => 'reporting_request_failed']], $status));
        }

        return ApiRequestGuard::secure(response()->view('reports.error', compact('message', 'errors', 'status'), $status));
    }
}
