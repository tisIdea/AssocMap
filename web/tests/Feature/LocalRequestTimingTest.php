<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureSupabaseAuthenticated;
use App\Http\Middleware\LocalRequestTiming;
use App\Providers\AppServiceProvider;
use App\Services\SupabaseAuthService;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route as Routes;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class LocalRequestTimingTest extends TestCase
{
    use \Tests\Support\MonitoringIncomeFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        $this->schema();
        $this->fixtures();
    }

    protected function tearDown(): void
    {
        try { Http::assertNothingSent(); parent::tearDown(); }
        finally { HandleExceptions::flushState($this); }
    }

    private function environment(string $environment): void
    {
        // Keep the isolated testing configuration; simulate only the instrumentation gate.
        $this->app->instance('env', $environment);
        (new AppServiceProvider($this->app))->boot();
    }

    private function request(string $query = '?__timing=1', string $path = '/association/dashboard', string $method = 'GET'): Request
    {
        $request = Request::create($path.$query, $method, server: ['REQUEST_TIME_FLOAT' => microtime(true)]);
        $route = new Route('GET', '/association/dashboard', fn () => null);
        $route->name('association.dashboard');
        $request->setRouteResolver(fn () => $route);
        $request->setLaravelSession($this->app['session']->driver());
        $request->session()->put('supabase_access_token', 'synthetic-secret-access-token');
        $this->app->instance('request', $request);

        return $request;
    }

    private function captureLogs(array &$summaries, int $times = 1): void
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->times($times)->withArgs(function ($message, $summary) use (&$summaries) {
            $this->assertSame('Local request timing', $message);
            $summaries[] = $summary;
            return true;
        });
        Log::shouldReceive('build')->times($times)
            ->with(['driver' => 'single', 'path' => storage_path('logs/laravel.log')])->andReturn($logger);
    }

    private function authentication(): EnsureSupabaseAuthenticated
    {
        $auth = Mockery::mock(SupabaseAuthService::class);
        $auth->shouldReceive('getUser')->once()->with('synthetic-secret-access-token')->andReturn(['id' => self::ACCOUNT]);

        return new EnsureSupabaseAuthenticated($auth);
    }

    public function test_opt_in_records_real_query_counts_without_secrets_or_double_counting_profile_time(): void
    {
        $this->environment('local');
        $summaries = [];
        $this->captureLogs($summaries);
        $request = $this->request('?__timing=1&password=synthetic-password&token=synthetic-url-token');
        $authentication = $this->authentication();
        $response = (new LocalRequestTiming)->handle($request, fn ($request) => $authentication->handle($request,
            fn ($request) => (new EnsureRole)->handle($request, function () {
                DB::table('projects')->count();
                return response('unchanged response');
            }, 'association_account')));

        $this->assertSame('unchanged response', $response->getContent());
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(4, $summaries[0]['db_query_count']);
        $this->assertSame('4', $response->headers->get('X-AssocMap-DB-Queries'));
        $this->assertSame($summaries[0]['timing_id'], $response->headers->get('X-AssocMap-Timing-Id'));
        $this->assertStringContainsString('laravel;dur=', $response->headers->get('Server-Timing'));
        $this->assertStringContainsString('auth;dur=', $response->headers->get('Server-Timing'));
        $this->assertGreaterThan(0, $summaries[0]['profile_role_ms']);
        $this->assertGreaterThanOrEqual(0, $summaries[0]['db_query_ms']);
        $this->assertEqualsWithDelta($summaries[0]['total_ms'], $summaries[0]['supabase_auth_ms']
            + $summaries[0]['profile_role_ms'] + $summaries[0]['db_outside_auth_profile_ms']
            + $summaries[0]['remaining_laravel_ms'], 0.05);
        $this->assertNull($summaries[0]['db_connection_ms']);
        $this->assertSame('association.dashboard', $summaries[0]['route']);
        $serialized = json_encode([$summaries, $response->headers->all()], JSON_THROW_ON_ERROR);
        foreach (['synthetic-secret', 'synthetic-password', 'synthetic-url-token', self::ACCOUNT, 'select', 'bindings'] as $private) {
            $this->assertStringNotContainsString($private, $serialized);
        }
        $this->assertFalse($request->attributes->has('assocmap.local_request_timing'));
    }

    public static function inactiveRequests(): array
    {
        return [
            ['production', '?__timing=1', '/association/dashboard', 'GET'],
            ['testing', '?__timing=1', '/association/dashboard', 'GET'],
            ['local', '', '/association/dashboard', 'GET'],
            ['local', '?__timing=1', '/association/dashboard', 'POST'],
            ['local', '?__timing=1', '/invitation', 'GET'],
            ['local', '?__timing=1', '/api/association/dashboard', 'GET'],
        ];
    }

    #[DataProvider('inactiveRequests')]
    public function test_instrumentation_is_inactive_outside_opted_in_local_page_requests(string $environment, string $query, string $path, string $method): void
    {
        $this->environment($environment);
        Log::shouldReceive('build')->never();
        $request = $this->request($query, $path, $method);
        $calls = 0;
        $response = (new LocalRequestTiming)->handle($request, function () use (&$calls) {
            return response(LocalRequestTiming::measure(request(), 'supabase_auth', function () use (&$calls) {
                $calls++;
                return 'original result';
            }));
        });
        $this->assertSame(1, $calls);
        $this->assertSame('original result', $response->getContent());
        $this->assertFalse($response->headers->has('Server-Timing'));
        $this->assertFalse($response->headers->has('X-AssocMap-Timing-Id'));
    }

    public function test_request_counters_reset_and_do_not_add_queries(): void
    {
        $this->environment('local');
        $summaries = [];
        $this->captureLogs($summaries, 2);
        foreach ([1, 2] as $number) {
            $request = $this->request();
            (new LocalRequestTiming)->handle($request, function () {
                DB::table('projects')->count();
                return response('ok');
            });
            $this->assertSame(1, $summaries[$number - 1]['db_query_count']);
        }
        $this->assertNotSame($summaries[0]['timing_id'], $summaries[1]['timing_id']);
    }

    public function test_registered_middleware_emits_headers_for_an_authenticated_page_request(): void
    {
        $this->environment('local');
        $summaries = [];
        $this->captureLogs($summaries);
        $auth = Mockery::mock(SupabaseAuthService::class);
        $auth->shouldReceive('getUser')->once()->with('synthetic-secret-access-token')->andReturn(['id' => self::ACCOUNT]);
        $this->app->instance(SupabaseAuthService::class, $auth);
        Routes::get('/association/_timing-check', function () {
            DB::table('projects')->count();
            return response('original page');
        })->middleware(['web', 'supabase.auth', 'role:association_account'])->name('association.timing-check');

        $response = $this->withSession(['supabase_access_token' => 'synthetic-secret-access-token'])
            ->withServerVariables(['REQUEST_TIME_FLOAT' => microtime(true)])
            ->get('/association/_timing-check?__timing=1');
        $response->assertOk()->assertSee('original page')->assertHeader('X-AssocMap-DB-Queries', '4');
        $response->assertHeader('X-AssocMap-Timing-Id', $summaries[0]['timing_id']);
        $this->assertSame('association.timing-check', $summaries[0]['route']);
        $this->assertSame(4, $summaries[0]['db_query_count']);
    }

    public function test_diagnostic_log_failure_does_not_break_the_page(): void
    {
        $this->environment('local');
        Log::shouldReceive('build')->once()->andThrow(new \RuntimeException('synthetic log failure'));
        $response = (new LocalRequestTiming)->handle($this->request(), fn () => response('original page'));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('original page', $response->getContent());
        $this->assertTrue($response->headers->has('Server-Timing'));
    }

    public function test_missing_session_still_redirects_without_calling_supabase(): void
    {
        $this->environment('local');
        $summaries = [];
        $this->captureLogs($summaries);
        $request = $this->request();
        $request->session()->forget('supabase_access_token');
        $auth = Mockery::mock(SupabaseAuthService::class);
        $auth->shouldNotReceive('getUser');
        $response = (new LocalRequestTiming)->handle($request, fn ($request) => (new EnsureSupabaseAuthenticated($auth))
            ->handle($request, fn () => response('must not reach handler')));
        $this->assertSame(route('login'), $response->headers->get('Location'));
        $this->assertSame(302, $summaries[0]['status']);
        $this->assertSame(0, $summaries[0]['db_query_count']);
    }

    public function test_role_denial_is_still_thrown_and_logged_without_changing_authorization(): void
    {
        $this->environment('local');
        $summaries = [];
        $this->captureLogs($summaries);
        $request = $this->request();
        $authentication = $this->authentication();
        try {
            (new LocalRequestTiming)->handle($request, fn ($request) => $authentication->handle($request,
                fn ($request) => (new EnsureRole)->handle($request, fn () => response('must not reach handler'), 'system_admin')));
            $this->fail('Role denial was bypassed.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(403, $summaries[0]['status']);
        $this->assertFalse($request->attributes->has('assocmap.local_request_timing'));
    }
}
