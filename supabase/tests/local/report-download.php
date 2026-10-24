<?php

declare(strict_types=1);

// Full HTTP-kernel checks against an explicitly verified synthetic scratch database.
require __DIR__.'/ScratchDatabase.php';
require __DIR__.'/LocalTestRun.php';
require dirname(__DIR__, 3).'/web/vendor/autoload.php';

use App\Services\Reporting\PythonReportEngine;
use App\Services\Reporting\ReportCatalog;
use App\Services\SupabaseAuthService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\Concerns\InteractsWithSession;
use Illuminate\Foundation\Testing\Concerns\MakesHttpRequests;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

function assertDownloadStatus($response, int $expected, ?string $mime = null): void
{
    LocalTestRun::assert($response->getStatusCode() === $expected,
        'Expected HTTP '.$expected.', received '.$response->getStatusCode().'.');
    if ($mime !== null) LocalTestRun::assert($response->headers->get('Content-Type') === $mime, 'Unexpected response MIME type.');
}

$scratch = new ScratchDatabase($argv[1] ?? '');
$pdo = $scratch->pdo;
$identities = $pdo->query('SELECT label,id FROM assocmap_test.identities')->fetchAll(PDO::FETCH_KEY_PAIR);
foreach (['admin', 'officer', 'account', 'association', 'other_association'] as $label) {
    if (!isset($identities[$label])) throw new RuntimeException('Prepare the existing synthetic reporting fixtures first.');
}
$root = dirname(__DIR__, 3);
$storage = $scratch->directory.'/download-storage';
foreach (['framework/views', 'framework/sessions', 'logs'] as $directory) {
    if (!is_dir($storage.'/'.$directory)) mkdir($storage.'/'.$directory, 0700, true);
}
$app = require $root.'/web/bootstrap/app.php';
$app->useEnvironmentPath($scratch->directory.'/no-project-environment')->loadEnvironmentFrom('.env.testing');
$app->useStoragePath($storage);
$app->beforeBootstrapping(LoadEnvironmentVariables::class, static function ($app): void {
    if ($app->configurationIsCached() || file_exists($app->getCachedRoutesPath())) throw new RuntimeException('Refusing cached application configuration/routes.');
});
$app->afterBootstrapping(LoadConfiguration::class, static function ($app) use ($scratch, $storage, $root): void {
    $app['config']->set([
        'app.env'=>'testing', 'app.debug'=>false, 'app.url'=>'http://localhost',
        'app.key'=>'base64:QUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUE=',
        'database'=>['default'=>'scratch', 'connections'=>['scratch'=>[
            'driver'=>'pgsql', 'host'=>'127.0.0.1', 'port'=>$scratch->run['Port'],
            'database'=>$scratch->run['Database'], 'username'=>$scratch->run['User'], 'password'=>$scratch->password(),
            'charset'=>'utf8', 'prefix'=>'', 'search_path'=>'public', 'sslmode'=>'disable',
        ]]],
        'cache.default'=>'array', 'session.driver'=>'array', 'session.secure'=>false,
        'queue.default'=>'sync', 'mail.default'=>'array', 'broadcasting.default'=>'null',
        'logging.default'=>'null', 'view.compiled'=>$storage.'/framework/views',
        'associations'=>['geography_schema_ready'=>false, 'direct_geography'=>false],
        'reporting'=>['python'=>$root.'/reporting/.venv/Scripts/python.exe', 'timeout'=>45,
            'max_rows'=>5000, 'max_total_rows'=>20000, 'max_json_bytes'=>8*1024*1024,
            'max_artifact_bytes'=>12*1024*1024, 'concurrency'=>2, 'font_regular'=>'', 'font_bold'=>''],
    ]);
});
$app->extend(Factory::class, static fn (Factory $http) => $http->preventStrayRequests());
$app->instance('request', Illuminate\Http\Request::create('http://localhost'));
$app->make(Kernel::class)->bootstrap();
$identity = DB::selectOne("SELECT current_database() AS db, host(inet_server_addr()) AS host,
    inet_server_port() AS port, current_setting('data_directory') AS data");
LocalTestRun::assert($identity->db === $scratch->run['Database'] && $identity->host === '127.0.0.1'
    && (int)$identity->port === $scratch->run['Port'] && realpath($identity->data) === realpath($scratch->run['Data']),
    'HTTP application database identity mismatch.');

// Keep real session/auth middleware; replace only the external identity provider.
$auth = Mockery::mock(SupabaseAuthService::class);
$actorId = null;
$auth->shouldReceive('getUser')->andReturnUsing(static function () use (&$actorId) {
    return $actorId === null ? null : ['id'=>$actorId];
});
$app->instance(SupabaseAuthService::class, $auth);
$app->bind(ValidateCsrfToken::class, static fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken {
    protected function runningUnitTests() { return false; }
});
$client = new class($app) {
    use MakesHttpRequests, InteractsWithSession;
    public function __construct(public $app) {}
};
$engine = new class extends PythonReportEngine {
    public array $last = [];
    public int $calls = 0;
    public ?Closure $after = null;
    public function run(array $payload, string $actorId): array {
        $this->calls++;
        $started = hrtime(true);
        $result = parent::run($payload, $actorId);
        $this->last = ['result'=>$result['result'], 'python_bridge_ms'=>(hrtime(true)-$started)/1e6,
            'artifact_sha256'=>is_string($result['artifact']) ? hash('sha256', $result['artifact']) : null,
            'association_ids'=>array_column($payload['datasets']['associations'], 'id')];
        if ($this->after) ($this->after)();
        return $result;
    }
};
$app->instance(PythonReportEngine::class, $engine);
$tests = new LocalTestRun($scratch->directory.'/report-download-results.json', $argv[2] ?? '');
$directory = $scratch->directory.'/http-export-evidence';
if (!is_dir($directory)) mkdir($directory, 0700);
$snapshot = static fn () => $pdo->query('SELECT assocmap_test.core_snapshot()::text')->fetchColumn();
$before = $snapshot();
$queryMs = 0.0;
$queryCount = 0;
DB::listen(static function ($query) use (&$queryMs, &$queryCount) { $queryMs += $query->time; $queryCount++; });
$signIn = static function (string $role) use ($client, $identities, &$actorId): void {
    $actorId = $identities[$role];
    $client->flushSession()->withSession(['supabase_access_token'=>'synthetic-local-only', '_token'=>'synthetic-csrf']);
};
$post = static function (string $prefix, string $format, array $filters = [], bool $csrf = true) use ($client) {
    return $client->post('/'.$prefix.'/reports/export/'.$format,
        $filters + ($csrf ? ['_token'=>'synthetic-csrf'] : []), ['Accept'=>'application/json']);
};
$clean = static function () use ($storage): void {
    LocalTestRun::assert((glob($storage.'/app/private/reporting/*') ?: []) === [], 'Private workspace was retained.');
};
$export = static function (string $prefix, string $type, string $format, array $filters, string $stem, array $expectedIds)
    use ($post, $engine, $directory, $clean, &$queryMs, &$queryCount): array {
    $queryMs = 0.0; $queryCount = 0;
    $started = hrtime(true);
    ob_start();
    try { $response = $post($prefix, $format, $filters); }
    finally { $unexpectedOutput = ob_get_clean(); }
    $elapsed = (hrtime(true)-$started)/1e6;
    LocalTestRun::assert($unexpectedOutput === '', 'Unexpected output preceded the binary response.');
    assertDownloadStatus($response, 200, $format === 'pdf'
        ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    LocalTestRun::assert($response->headers->get('X-Content-Type-Options') === 'nosniff', 'Missing nosniff header.');
    $disposition = $response->headers->get('Content-Disposition');
    LocalTestRun::assert(preg_match('/\Aattachment; filename="assocmap-'.preg_quote($type, '/').'-\d{8}-\d{6}\.'.$format.'"\z/', $disposition) === 1,
        'Incorrect attachment filename.');
    $cache = $response->headers->get('Cache-Control');
    LocalTestRun::assert(str_contains($cache, 'private') && str_contains($cache, 'no-store'), 'Missing private no-store policy.');
    $body = $response->getContent();
    $sendStarted = hrtime(true);
    ob_start();
    try { $response->baseResponse->sendContent(); }
    finally { $sent = ob_get_clean(); }
    $sendMs = (hrtime(true)-$sendStarted)/1e6;
    LocalTestRun::assert(is_string($body) && strlen($body) > 100
        && (string)strlen($body) === $response->headers->get('Content-Length')
        && str_starts_with($body, $format === 'pdf' ? '%PDF-' : "PK\x03\x04")
        && $sent === $body && hash('sha256', $body) === $engine->last['artifact_sha256'], 'HTTP bytes differ from the generated file.');
    sort($expectedIds); $actualIds = $engine->last['association_ids']; sort($actualIds);
    LocalTestRun::assert($expectedIds === $actualIds, 'Export association scope differs from the authorized dataset.');
    LocalTestRun::assert($engine->last['result']['meta']['filters'] === $filters, 'Export filters changed.');
    file_put_contents($directory.'/'.$stem.'.'.$format, $body);
    file_put_contents($directory.'/'.$stem.'.json', json_encode($engine->last['result'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    $clean();
    return ['bytes'=>strlen($body), 'sha256'=>hash('sha256', $body), 'http_kernel_ms'=>round($elapsed, 2),
        'db_ms'=>round($queryMs, 2), 'queries'=>$queryCount, 'python_bridge_ms'=>round($engine->last['python_bridge_ms'], 2),
        'response_send_ms'=>round($sendMs, 3)];
};

foreach (['admin', 'officer'] as $role) foreach (array_keys(ReportCatalog::TYPES) as $type) foreach (['pdf', 'xlsx'] as $format) {
    $tests->check($role.' HTTP '.$type.' '.$format, static function () use ($signIn, $role, $type, $format, $export, $pdo, $identities) {
        Cache::flush();
        $signIn($role);
        $filters = ['report_type'=>$type];
        if (in_array('year', ReportCatalog::filters($type), true)) $filters['year'] = 2026;
        $expected = $role === 'admin' ? $pdo->query('SELECT id FROM public.associations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)
            : [$identities['association']];
        return $export($role === 'admin' ? 'admin' : 'field-officer', $type, $format, $filters, $role.'-'.$type, $expected);
    });
}
$tests->check('HTTP exports preserve operational records', static function () use ($snapshot, $before, $clean) {
    LocalTestRun::assert($before === $snapshot(), 'HTTP reporting changed operational records.');
    $clean();
    return ['operational_snapshot_unchanged'=>true, 'private_workspace_empty'=>true];
});
$tests->check('empty authorized datasets remain readable in all sixteen HTTP exports', static function () use ($pdo, $identities, $signIn, $export, $engine) {
    $id = $pdo->quote($identities['association']);
    $officer = $pdo->quote($identities['officer']);
    $other = $pdo->quote($identities['other_officer']);
    $pdo->exec('UPDATE public.associations SET primary_field_officer_id='.$other.' WHERE id='.$id);
    try {
        $signIn('officer'); $count = 0;
        foreach (array_keys(ReportCatalog::TYPES) as $type) foreach (['pdf', 'xlsx'] as $format) {
            Cache::flush();
            $filters = ['report_type'=>$type];
            if (in_array('year', ReportCatalog::filters($type), true)) $filters['year'] = 2026;
            $export('field-officer', $type, $format, $filters, 'empty-'.$type, []);
            LocalTestRun::assert($engine->last['result']['empty'] === true, 'Empty scope was presented as populated.');
            $count++;
        }
        return ['valid_empty_exports'=>$count];
    } finally { $pdo->exec('UPDATE public.associations SET primary_field_officer_id='.$officer.' WHERE id='.$id); }
});
$tests->check('filtered HTTP exports retain exact association project and period selections', static function () use ($pdo, $signIn, $export, $identities) {
    $case = json_decode($pdo->query("SELECT ids::text FROM assocmap_test.cases WHERE label='reporting_main'")->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    $signIn('officer');
    $filters = ['report_type'=>'income', 'association_id'=>$identities['association'], 'project_id'=>$case['project'], 'year'=>2026, 'quarter'=>1, 'month'=>1];
    $evidence = [];
    foreach (['pdf', 'xlsx'] as $format) {
        Cache::flush();
        $evidence[$format] = $export('field-officer', 'income', $format, $filters, 'filtered-income', [$identities['association']]);
    }
    return $evidence;
});
$tests->check('five hundred additional synthetic projects download in both HTTP formats', static function () use ($pdo, $signIn, $export, $identities, $engine, $snapshot) {
    $before = $snapshot();
    $ids = $pdo->query("WITH added AS (
        INSERT INTO public.projects(association_id, saad_activity_id, fisheries_category_id, commodity_id,
            title, program_year, implementation_start_date, status, created_by, updated_by)
        SELECT association_id, saad_activity_id, fisheries_category_id, commodity_id,
            'Synthetic W1 volume project '||n, program_year, implementation_start_date, 'ongoing', created_by, updated_by
        FROM public.projects CROSS JOIN generate_series(1,500) n
        WHERE id=(SELECT (ids->>'project')::uuid FROM assocmap_test.cases WHERE label='reporting_main') RETURNING id)
        SELECT id FROM added")->fetchAll(PDO::FETCH_COLUMN);
    LocalTestRun::assert(count($ids) === 500, 'Volume fixture was not fully prepared.');
    try {
        $signIn('officer'); $evidence = [];
        foreach (['pdf', 'xlsx'] as $format) {
            Cache::flush();
            $evidence[$format] = $export('field-officer', 'projects', $format, ['report_type'=>'projects'], 'volume-projects', [$identities['association']]);
            $projects = collect($engine->last['result']['sections'])->firstWhere('key', 'projects');
            LocalTestRun::assert(count($projects['rows']) >= 500, 'Volume export lost projects.');
        }
    } finally {
        $pdo->exec('DELETE FROM public.projects WHERE id IN ('.implode(',', array_map($pdo->quote(...), $ids)).')');
    }
    LocalTestRun::assert($before === $snapshot(), 'Volume fixture did not restore its operational snapshot.');
    return $evidence;
});
$tests->check('HTTP export denials preserve roles filters CSRF method and safe errors', static function () use ($signIn, $post, $client, $identities, $engine, $app, $clean) {
    $signIn('account');
    foreach (['pdf', 'xlsx'] as $format) assertDownloadStatus($post('association', $format), 403);
    assertDownloadStatus($client->getJson('/association/reports/data?association_id='.$identities['other_association']), 403);
    assertDownloadStatus($client->getJson('/association/reports/data?report_type=members'), 200);
    $calls = $engine->calls;
    $signIn('officer');
    foreach (['pdf', 'xlsx'] as $format) {
        Cache::flush();
        assertDownloadStatus($post('admin', $format), 403);
        assertDownloadStatus($post('field-officer', $format, ['association_id'=>$identities['other_association']]), 403);
        assertDownloadStatus($post('field-officer', $format, ['report_type'=>'../../private']), 422);
        assertDownloadStatus($post('field-officer', $format, ['report_type'=>'income', 'year'=>[2026]]), 422);
        assertDownloadStatus($post('field-officer', $format, [], false), 419);
        assertDownloadStatus($client->get('/field-officer/reports/export/'.$format), 405);
        Cache::flush();
    }
    LocalTestRun::assert($calls === $engine->calls, 'Rejected exports reached Python.');
    $signIn('admin');
    $app['config']->set('reporting.python', '');
    try {
        $response = $post('admin', 'pdf');
        assertDownloadStatus($response, 503, 'application/json');
        foreach (['python_not_configured', 'Traceback', $app->basePath()] as $private) {
            LocalTestRun::assert(!str_contains($response->getContent(), $private), 'Engine error exposed internal details.');
        }
    } finally { $app['config']->set('reporting.python', dirname(__DIR__, 3).'/reporting/.venv/Scripts/python.exe'); }
    $client->flushSession()->withSession(['_token'=>'synthetic-csrf']);
    assertDownloadStatus($post('admin', 'pdf'), 302);
    $clean();
    return ['account_export_denied'=>true, 'own_account_preview'=>true, 'cross_scope_denied'=>true,
        'invalid_filters'=>422, 'missing_csrf'=>419, 'wrong_method'=>405, 'engine_failure'=>503, 'guest_redirect'=>true];
});
$tests->check('HTTP release rechecks assignment and profile revocation after real Python', static function () use ($signIn, $post, $engine, $pdo, $identities, $clean) {
    foreach (['pdf', 'xlsx'] as $format) foreach (['assignment', 'profile'] as $revocation) {
        Cache::flush(); $signIn('officer');
        $id = $pdo->quote($identities['association']);
        $officer = $pdo->quote($identities['officer']);
        $other = $pdo->quote($identities['other_officer']);
        $engine->after = static function () use ($pdo, $id, $officer, $other, $revocation): void {
            $pdo->exec($revocation === 'assignment' ? 'UPDATE public.associations SET primary_field_officer_id='.$other.' WHERE id='.$id
                : 'UPDATE public.user_profiles SET is_active=false WHERE id='.$officer);
        };
        try { assertDownloadStatus($post('field-officer', $format), 403, 'application/json'); }
        finally {
            $engine->after = null;
            $pdo->exec('UPDATE public.user_profiles SET is_active=true WHERE id='.$officer);
            $pdo->exec('UPDATE public.associations SET primary_field_officer_id='.$officer.' WHERE id='.$id);
        }
        $clean();
    }
    return ['assignment_revoked_after_python'=>true, 'profile_revoked_after_python'=>true, 'formats'=>['pdf', 'xlsx']];
});
$tests->check('all HTTP success and rejection paths leave no private workspace', static function () use ($clean) {
    $clean();
    return ['private_workspace_empty'=>true];
});
Mockery::close();
$tests->finish();
