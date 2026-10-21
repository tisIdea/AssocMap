<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class LocalRequestTiming
{
    private const ATTRIBUTE = 'assocmap.local_request_timing';

    private array $milliseconds = ['supabase_auth' => 0.0, 'profile_role' => 0.0];
    private ?string $phase = null;
    private int $queryCount = 0;
    private float $queryMilliseconds = 0.0;
    private float $otherQueryMilliseconds = 0.0;
    /** @var list<float> */
    private array $queryDurations = [];

    public function handle(Request $request, Closure $next): Response
    {
        if (!app()->environment('local') || !$request->isMethod('GET') || $request->query('__timing') !== '1'
            || !$request->is('dashboard', 'admin/*', 'field-officer/*', 'association/*')) {
            return $next($request);
        }

        $started = hrtime(true);
        $wallStarted = microtime(true);
        $requestStarted = $request->server('REQUEST_TIME_FLOAT');
        $beforeMiddleware = is_numeric($requestStarted) && (float) $requestStarted <= $wallStarted
            ? ($wallStarted - (float) $requestStarted) * 1000 : null;
        $timing = new self;
        $request->attributes->set(self::ATTRIBUTE, $timing);
        $id = bin2hex(random_bytes(8));
        $response = null;
        $status = 500;

        try {
            $response = $next($request);
            $status = $response->getStatusCode();

            return $response;
        } catch (Throwable $exception) {
            if ($exception instanceof HttpExceptionInterface) $status = $exception->getStatusCode();
            throw $exception;
        } finally {
            $total = (hrtime(true) - $started) / 1_000_000 + ($beforeMiddleware ?? 0);
            $auth = $timing->milliseconds['supabase_auth'];
            $profile = $timing->milliseconds['profile_role'];
            // Profile/auth spans may include queries; subtract only queries outside those spans.
            $remaining = max(0, $total - $auth - $profile - $timing->otherQueryMilliseconds);
            $distribution = $timing->queryDistribution();
            $summary = [
                'timing_id' => $id,
                'route' => $request->route()?->getName(),
                'status' => $status,
                'total_ms' => round($total, 2),
                'pre_middleware_ms' => $beforeMiddleware === null ? null : round($beforeMiddleware, 2),
                'supabase_auth_ms' => round($auth, 2),
                'profile_role_ms' => round($profile, 2),
                'db_query_count' => $timing->queryCount,
                'db_query_ms' => round($timing->queryMilliseconds, 2),
                'db_query_distribution' => $distribution,
                'db_query_scope' => 'completed_queries_after_middleware_entry',
                'db_outside_auth_profile_ms' => round($timing->otherQueryMilliseconds, 2),
                // Lazy connection setup may be included in the first query's duration.
                'db_connection_ms' => null,
                'remaining_laravel_ms' => round($remaining, 2),
            ];
            $request->attributes->remove(self::ATTRIBUTE);

            if ($response) {
                $metrics = ['laravel' => $total, 'auth' => $auth, 'profile' => $profile,
                    'db' => $timing->queryMilliseconds, 'remaining' => $remaining];
                $header = [];
                foreach ($metrics as $name => $milliseconds) {
                    $header[] = $name.';dur='.number_format($milliseconds, 2, '.', '');
                }
                $response->headers->set('Server-Timing', implode(', ', $header), false);
                $response->headers->set('X-AssocMap-Timing-Id', $id);
                $response->headers->set('X-AssocMap-DB-Queries', (string) $timing->queryCount);
                $response->headers->set('X-AssocMap-DB-Distribution', json_encode($distribution, JSON_THROW_ON_ERROR));
            }

            try {
                // Use a local file directly, regardless of the application's default log channel.
                Log::build(['driver' => 'single', 'path' => storage_path('logs/laravel.log')])
                    ->info('Local request timing', $summary);
            } catch (Throwable) {
                // Diagnostic logging must not change request behavior.
            }
        }
    }

    public static function measure(Request $request, string $phase, Closure $operation): mixed
    {
        $timing = $request->attributes->get(self::ATTRIBUTE);
        if (!$timing instanceof self) return $operation();
        $started = hrtime(true);
        $previousPhase = $timing->phase;
        $timing->phase = $phase;

        try {
            return $operation();
        } finally {
            $timing->milliseconds[$phase] += (hrtime(true) - $started) / 1_000_000;
            $timing->phase = $previousPhase;
        }
    }

    private function queryDistribution(): array
    {
        $durations = $this->queryDurations;
        sort($durations, SORT_NUMERIC);
        $count = count($durations);
        $middle = intdiv($count, 2);
        $buckets = ['under_100_ms' => 0, 'from_100_to_under_500_ms' => 0, 'at_least_500_ms' => 0];
        foreach ($durations as $milliseconds) {
            if ($milliseconds < 100) $buckets['under_100_ms']++;
            elseif ($milliseconds < 500) $buckets['from_100_to_under_500_ms']++;
            else $buckets['at_least_500_ms']++;
        }

        return [
            'count' => $count,
            'total_ms' => round($this->queryMilliseconds, 2),
            'min_ms' => $count === 0 ? null : round($durations[0], 2),
            'median_ms' => $count === 0 ? null : round($count % 2 === 0
                ? ($durations[$middle - 1] + $durations[$middle]) / 2 : $durations[$middle], 2),
            'max_ms' => $count === 0 ? null : round($durations[$count - 1], 2),
            // Nearest-rank percentile; the first duration retains observation order.
            'p95_ms' => $count === 0 ? null : round($durations[(int) ceil($count * 0.95) - 1], 2),
            'first_ms' => $count === 0 ? null : round($this->queryDurations[0], 2),
            'buckets' => $buckets,
        ];
    }

    public static function recordQuery(Request $request, float $milliseconds): void
    {
        $timing = $request->attributes->get(self::ATTRIBUTE);
        if (!$timing instanceof self) return;
        $timing->queryCount++;
        $timing->queryMilliseconds += $milliseconds;
        $timing->queryDurations[] = $milliseconds;
        if ($timing->phase === null) $timing->otherQueryMilliseconds += $milliseconds;
    }
}
