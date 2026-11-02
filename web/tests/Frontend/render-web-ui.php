<?php

// Render real Blade views with synthetic data and no application credential file.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Support\ViewErrorBag;
use Tests\Support\TestEnvironmentGuard;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$directory = $argv[1] ?? '';
if (! preg_match('/^assocmap-ui-f1-[a-f0-9]{32}$/', basename($directory)) || ! is_dir($directory)) {
    throw new RuntimeException('Supply a task-owned assocmap-ui-f1 directory.');
}
foreach (array_keys(getenv()) as $name) {
    if (preg_match('/^(SUPABASE_|DB_|PG|REDIS_|AWS_|MAIL_|REPORTING_|MEDIA_PRIVATE_)/', $name)) {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }
}
$root = str_replace('\\', '/', $directory);
$cacheRoot = preg_replace('/^[A-Za-z]:/', '', $root);
foreach (['cache', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs'] as $path) {
    if (! is_dir($root.'/'.$path) && ! mkdir($root.'/'.$path, 0777, true)) {
        throw new RuntimeException('Unable to create isolated UI rendering directory.');
    }
}
$settings = [
    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://localhost',
    'APP_KEY' => 'base64:'.base64_encode(str_repeat('u', 32)),
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array', 'LOG_CHANNEL' => 'null', 'LARAVEL_STORAGE_PATH' => $root.'/storage',
    'VIEW_COMPILED_PATH' => $root.'/storage/framework/views',
];
foreach (['CONFIG' => 'config.php', 'ROUTES' => 'routes-v7.php', 'EVENTS' => 'events.php', 'PACKAGES' => 'packages.php', 'SERVICES' => 'services.php'] as $key => $file) {
    $settings['APP_'.$key.'_CACHE'] = $cacheRoot.'/cache/'.$file;
}
foreach ($settings as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
TestEnvironmentGuard::install($app);
$app->make(Kernel::class)->bootstrap();
$app->instance(Vite::class, new class extends Vite
{
    public function __invoke($entrypoints, $buildDirectory = null): HtmlString
    {
        return new HtmlString('');
    }
});
$session = $app['session']->driver();
$session->start();
$app['view']->share('errors', new ViewErrorBag);
$app['view']->share('currentUser', (object) ['display_name' => 'Synthetic reviewer', 'role' => (object) ['name' => 'System Administrator']]);
$types = [['id' => 1, 'code' => 'fish_cage', 'name' => 'Fish Cage']];
$gis = ['gisSiteTypes' => $types, 'gisPublic' => false, 'gisManager' => true, 'gisAdministrator' => false,
    'gisBaseUrl' => '/admin/gis', 'gisLayout' => 'layouts.admin', 'gisTitle' => 'Administrator GIS Management',
    'gisDescription' => 'Manage eligible project locations and boundaries.', 'gisFeature' => null,
    'gisRequestId' => '11111111-1111-4111-8111-111111111111'];
$render = function ($name, $url, $view, $data = []) use ($app, $session, $root) {
    $request = Request::create('http://localhost'.$url);
    $request->setLaravelSession($session);
    $route = $app['router']->getRoutes()->match($request);
    $request->setRouteResolver(fn () => $route);
    $app->instance('request', $request);
    $app['url']->setRequest($request);
    $html = str_starts_with($view, '@') ? Blade::render($view, $data) : view($view, $data)->render();
    file_put_contents($root.'/'.$name.'.html', $html);
};
$render('home', '/', 'public.landing');
$render('login', '/login', 'auth.login');
$render('public', '/map', 'public.gis', array_replace($gis, ['gisPublic' => true, 'gisManager' => false, 'gisBaseUrl' => '/map', 'gisTitle' => 'Public GIS Map', 'gisDescription' => 'Explore approved project locations and boundaries.']));
$render('staff', '/admin/gis', 'gis-workspace', $gis);
$render('officer', '/field-officer/gis', 'gis-workspace', array_replace($gis, ['gisLayout' => 'layouts.field-officer', 'gisBaseUrl' => '/field-officer/gis', 'gisTitle' => 'Field Officer GIS Management', 'gisDescription' => 'Manage project GIS locations for your currently assigned associations.']));
$render('association', '/association/gis', 'gis-workspace', array_replace($gis, ['gisLayout' => 'layouts.association', 'gisBaseUrl' => '/association/gis', 'gisManager' => false, 'gisTitle' => 'Association GIS Viewer', 'gisDescription' => 'View project locations and boundaries for your active linked association.']));
$render('editor', '/admin/gis/create', 'gis-editor', $gis);
$areaFilters = ['tab' => 'municipalities', 'status' => '', 'q' => '', 'sort' => 'az', 'municipality_id' => '', 'source' => '', 'type' => ''];
$areaData = ['filters' => $areaFilters, 'summary' => ['municipalities' => 4, 'associations' => 4, 'current_barangays' => 3, 'inactive_barangays' => 1],
    'records' => new \Illuminate\Pagination\LengthAwarePaginator([], 0, 25, 1), 'municipalityChoices' => collect()];
$render('areas', '/admin/areas', 'admin.areas.register', $areaData);
$render('areas-filtered', '/admin/areas?tab=barangays&status=active', 'admin.areas.register', array_replace($areaData, ['filters' => array_replace($areaFilters, ['tab' => 'barangays', 'status' => 'active'])]));
$render('members', '/admin/members', 'admin.members.register', ['filters' => ['q' => '', 'association' => '', 'sort' => 'az', 'status' => ''],
    'summary' => ['current' => 4, 'active' => 3, 'inactive' => 1, 'archived' => 1], 'associations' => collect(),
    'members' => new \Illuminate\Pagination\LengthAwarePaginator([], 0, 25, 1)]);
$render('controls', '/admin/areas', <<<'BLADE'
@extends('layouts.admin')
@section('content')
<section data-workspace-module="areas" data-workspace-title="Area Management">
@include('partials.page-header', ['title' => 'Area Management', 'description' => 'Review and maintain registered areas.', 'actions' => new \Illuminate\Support\HtmlString('<a class="button button-primary" href="#example-form">Add Area</a>')])
<section class="card"><div class="panel-heading"><h2>Registered areas</h2><span class="badge badge-warning">Pending</span></div>
<div class="table-scroll" tabindex="0" role="region" aria-label="Synthetic areas"><table class="data-table"><thead><tr><th scope="col">Name</th><th scope="col">Municipality</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead><tbody><tr><td class="primary-cell">Synthetic area</td><td>Synthetic municipality</td><td><span class="badge badge-success">Active</span></td><td><button type="button" class="button" data-dialog="review-dialog">View details</button></td></tr></tbody></table></div>
<div class="table-footer"><span>1 matching area</span><nav aria-label="Pages"><button class="button" disabled>Previous</button><button class="button" disabled>Next</button></nav></div></section>
<form id="example-form" class="card form-section" style="margin-top:20px" data-form><h2>Area details</h2><div class="form-grid"><x-field :field="['name' => 'name', 'label' => 'Area name', 'required' => true]" prefix="example" /><x-field :field="['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea']" prefix="example" /></div><div class="form-actions"><button class="button" type="reset">Clear</button><button class="button button-primary" type="submit">Save area</button></div></form>
<section class="card"><x-empty-state /></section>
<x-modal id="review-dialog" title="Review area"><p>Review the selected area before continuing.</p><p>{{ str_repeat('Long review information. ', 200) }}</p><div class="modal-actions"><button class="button" type="button" data-close-dialog>Cancel</button><button class="button button-primary" type="button" data-close-dialog>Continue</button></div></x-modal>
</section>
@endsection
BLADE);
if ($app['db']->getConnections() !== []) {
    throw new RuntimeException('UI rendering must not open a database connection.');
}
echo "Rendered 11 synthetic Blade pages; no database connections or provider calls.\n";
