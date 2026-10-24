<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Tests\Support\IsolatedDatabaseManager;
use Tests\Support\TestEnvironmentGuard;

$web = getcwd();
$evidence = getenv('ASSOCMAP_BASELINE_NATIVE');
$baseline = $argv[1] ?? throw new RuntimeException('An approved route baseline is required.');
$approvedHash = 'f782e373299cf19d13d138dcab8293171c7f04e4471a02f826df0883a6bbd06d';
if (hash_file('sha256', $baseline) !== $approvedHash) {
    throw new RuntimeException('Unrecognized route baseline.');
}
require $web.'/vendor/autoload.php';

function normalizedValue(mixed $value): mixed
{
    if ($value instanceof Closure) {
        $reflection = new ReflectionFunction($value);
        $file = $reflection->getFileName();
        $source = $file ? implode('', array_slice(file($file), $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1)) : '';
        $root = str_replace('\\', '/', getcwd()).'/';
        $path = $file ? str_replace('\\', '/', $file) : null;

        return ['kind' => 'closure', 'file' => $path && str_starts_with($path, $root) ? substr($path, strlen($root)) : $path,
            'start_line' => $reflection->getStartLine(), 'end_line' => $reflection->getEndLine(), 'source_sha256' => hash('sha256', $source)];
    }
    if (is_array($value)) {
        return array_map(normalizedValue(...), $value);
    }
    if (is_object($value)) {
        return ['kind' => 'object', 'class' => $value::class];
    }

    return $value;
}

$app = require $web.'/bootstrap/app.php';
TestEnvironmentGuard::install($app);
$app->instance('request', Request::create('http://localhost/'));
$router = $app->make('router');
$recorded = new class extends RouteCollection
{
    public array $registrations = [];

    public function add(Route $route)
    {
        $this->registrations[] = $route;

        return parent::add($route);
    }
};
$router->setRoutes($recorded);
$app->make(Kernel::class)->bootstrap();
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
TestEnvironmentGuard::assertConfiguration($app);
if ($router->getRoutes() !== $recorded) {
    throw new RuntimeException('Route collection changed unexpectedly.');
}
if (file_exists($app->getCachedConfigPath()) || file_exists($app->getCachedRoutesPath())) {
    throw new RuntimeException('Cached configuration or routes are forbidden.');
}
if (realpath($app->storagePath()) !== realpath($evidence.'/storage')) {
    throw new RuntimeException('Storage path crossed isolation boundary.');
}
foreach ([$app->getCachedConfigPath(), $app->getCachedRoutesPath(), $app->getCachedPackagesPath(), $app->getCachedServicesPath(), $app->getCachedEventsPath()] as $path) {
    if (realpath(dirname($path)) !== realpath($evidence.'/cache')) {
        throw new RuntimeException('Cache path crossed isolation boundary.');
    }
}
if (! $app->make(Factory::class)->preventingStrayRequests()) {
    throw new RuntimeException('HTTP guard is missing.');
}
if (! $app->make('db') instanceof IsolatedDatabaseManager) {
    throw new RuntimeException('Database guard is missing.');
}

function routeRows(array $routes, Router $router): array
{
    $rows = [];
    foreach (array_values($routes) as $ordinal => $route) {
        $signature = [];
        foreach ($route->signatureParameters() as $parameter) {
            $signature[] = ['name' => $parameter->getName(), 'type' => $parameter->hasType() ? (string) $parameter->getType() : null,
                'optional' => $parameter->isOptional(), 'default' => $parameter->isDefaultValueAvailable() ? normalizedValue($parameter->getDefaultValue()) : null];
        }
        $custom = [];
        foreach ($route->parameterNames() as $parameter) {
            $callback = $router->getBindingCallback($parameter);
            if ($callback !== null) {
                $custom[$parameter] = normalizedValue($callback);
            }
        }
        $rows[] = ['ordinal' => $ordinal + 1, 'methods' => $route->methods(), 'domain' => $route->getDomain(), 'uri' => $route->uri(),
            'name' => $route->getName(), 'controller_action' => $route->getActionName(), 'action' => normalizedValue($route->getAction()),
            'middleware_declared' => $route->middleware(), 'middleware_gathered' => $route->gatherMiddleware(),
            'middleware_resolved' => $router->gatherRouteMiddleware($route), 'middleware_excluded' => $route->excludedMiddleware(),
            'constraints' => $route->wheres, 'defaults' => normalizedValue($route->defaults), 'fallback' => $route->isFallback,
            'bindings' => ['parameter_names' => $route->parameterNames(), 'fields' => $route->bindingFields(),
                'scope_bindings' => $route->getAction('scope_bindings'), 'enforce_scoped' => $route->enforcesScopedBindings(),
                'prevent_scoped' => $route->preventsScopedBindings(), 'with_trashed' => $route->allowsTrashedBindings(),
                'custom_callbacks' => $custom, 'action_signature' => $signature],
            'session_block' => ['lock_seconds' => $route->locksFor(), 'wait_seconds' => $route->waitsFor()]];
    }

    return $rows;
}
function duplicateNames(array $rows): array
{
    $names = [];
    foreach ($rows as $row) {
        if ($row['name'] !== null) {
            $names[$row['name']][] = $row['ordinal'];
        }
    }

    return array_filter($names, static fn ($ordinals) => count($ordinals) > 1);
}
$routes = routeRows($recorded->getRoutes(), $router);
$registrations = routeRows($recorded->registrations, $router);
$binders = (new ReflectionProperty($router, 'binders'))->getValue($router);
$compatibility = ['routes' => $routes, 'registrations' => $registrations, 'global_middleware' => $kernel->getGlobalMiddleware(),
    'middleware_aliases' => $router->getMiddleware(), 'middleware_groups' => $router->getMiddlewareGroups(),
    'middleware_priority' => $kernel->getMiddlewarePriority(), 'global_patterns' => $router->getPatterns(), 'binding_callbacks' => normalizedValue($binders)];
if ($app->make('db')->getConnections() !== []) {
    throw new RuntimeException('Route inventory opened a database connection.');
}
$expected = [];
foreach (['admin', 'field-officer', 'association'] as $prefix) {
    foreach (['index', 'data', 'options', 'export'] as $action) {
        $expected[] = $prefix.'.reports.'.$action;
    }
}
$expected[] = 'api.association.reports';
$expected[] = 'api.association.reports.options';
$reports = array_values(array_filter($routes, static fn ($row) => in_array($row['name'], $expected, true)));
foreach ($expected as $name) {
    if (count(array_filter($reports, static fn ($row) => $row['name'] === $name)) !== 1) {
        throw new RuntimeException('Missing or duplicated Reports route: '.$name);
    }
}
$signatures = [];
foreach ($registrations as $row) {
    $signatures[implode('|', $row['methods']).' '.$row['domain'].' '.$row['uri']][] = $row['ordinal'];
}
$summary = ['effective_route_count' => count($routes),
    'registration_count' => count($registrations), 'named_route_count' => count(array_filter($routes, static fn ($row) => $row['name'] !== null)),
    'unnamed_route_count' => count(array_filter($routes, static fn ($row) => $row['name'] === null)),
    'duplicate_effective_names' => duplicateNames($routes), 'duplicate_registration_names' => duplicateNames($registrations),
    'repeated_registration_signatures' => array_filter($signatures, static fn ($positions) => count($positions) > 1),
    'reports_web_count' => count(array_filter($reports, static fn ($row) => ! str_starts_with($row['name'], 'api.'))),
    'reports_api_count' => count(array_filter($reports, static fn ($row) => str_starts_with($row['name'], 'api.'))),
    'database_connections_opened' => count($app->make('db')->getConnections()), 'http_stray_requests_blocked' => true,
    'database_manager' => $app->make('db')::class, 'environment_path' => $app->environmentPath(),
    'cache_driver' => config('cache.default'), 'session_driver' => config('session.driver'), 'mail_driver' => config('mail.default'),
    'queue_driver' => config('queue.default'), 'database_driver' => config('database.default'), 'database' => config('database.connections.sqlite.database'),
    'configuration_cached' => $app->configurationIsCached(), 'routes_cached' => $app->routesAreCached(), 'storage_path' => $app->storagePath()];
$options = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
file_put_contents($evidence.'/routes.compatibility.json', json_encode($compatibility, $options).PHP_EOL);
file_put_contents($evidence.'/routes.reports.json', json_encode($reports, $options).PHP_EOL);
$summary['compatibility_sha256'] = hash_file('sha256', $evidence.'/routes.compatibility.json');
$summary['baseline_sha256'] = $approvedHash;
$summary['byte_for_byte_identical'] = file_get_contents($baseline) === file_get_contents($evidence.'/routes.compatibility.json');
$passed = $summary['byte_for_byte_identical']
    && count($routes) === 247 && count($registrations) === 247
    && $summary['named_route_count'] === 244 && $summary['unnamed_route_count'] === 3
    && duplicateNames($routes) === [] && duplicateNames($registrations) === [];
$summary['result'] = $passed ? 'PASS' : 'FAIL';
file_put_contents($evidence.'/routes.summary.json', json_encode($summary, $options).PHP_EOL);
echo json_encode($summary, $options),PHP_EOL;
exit($passed ? 0 : 1);
