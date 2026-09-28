<?php

// Isolated Blade verification. Does not bootstrap routes, sessions, or databases.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__, 2).'/vendor/autoload.php';

$base = dirname(__DIR__, 2);
$cache = __DIR__.'/generated/cache';
if (!is_dir($cache)) {
    mkdir($cache, 0777, true);
}
$app = new Illuminate\Foundation\Application($base);
$app->instance('config', new Illuminate\Config\Repository([
    'view' => ['paths' => [$base.'/resources/views'], 'compiled' => $cache],
]));
$app->register(Illuminate\Events\EventServiceProvider::class);
$app->register(Illuminate\Filesystem\FilesystemServiceProvider::class);
$app->register(Illuminate\View\ViewServiceProvider::class);
Illuminate\Support\Facades\Facade::setFacadeApplication($app);

set_error_handler(static function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$html = $app['view']->make('dashboard.index')->render();
file_put_contents(__DIR__.'/generated/index.html', $html);
$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML($html);
$xpath = new DOMXPath($dom);
$checks = [
    '31 preview pages render' => $xpath->query('//*[@data-page]')->length === 31,
    'Member list and details have no write actions' => $xpath->query('//*[@data-page="members" or @data-page="members/show"]//a[contains(@href,"/create") or contains(@href,"/edit")] | //*[@data-page="members" or @data-page="members/show"]//button[@data-confirm]')->length === 0,
    'Audit views have no write actions' => $xpath->query('//*[starts-with(@data-page,"audit-logs")]//a[contains(@href,"/create") or contains(@href,"/edit")] | //*[starts-with(@data-page,"audit-logs")]//button[@data-confirm]')->length === 0,
    'Program Operations has four links' => $xpath->query('//*[@id="program-navigation"]//a')->length === 4,
    'No password controls' => $xpath->query('//input[@type="password"]')->length === 0,
    'Three report scopes' => $xpath->query('//input[@name="scope"]')->length === 3,
    'Two report formats' => $xpath->query('//input[@name="format"]')->length === 2,
];
$ids = [];
foreach ($xpath->query('//*[@id and not(ancestor::template)]') as $element) {
    $ids[] = $element->getAttribute('id');
}
$checks['Unique live element IDs'] = count($ids) === count(array_unique($ids));
$pageKeys = [];
foreach ($xpath->query('//*[@data-page]') as $page) {
    $pageKeys[] = $page->getAttribute('data-page');
}
$checks['Every preview link resolves'] = true;
foreach ($xpath->query('//a[starts-with(@href,"#")]') as $link) {
    $target = substr($link->getAttribute('href'), 1);
    if ($target !== 'main-content' && !in_array($target, $pageKeys, true)) {
        $checks['Every preview link resolves'] = false;
    }
}
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ').$label.PHP_EOL;
}
echo 'Rendered '.strlen($html).' bytes to resources/preview/generated/index.html'.PHP_EOL;
exit(in_array(false, $checks, true) ? 1 : 0);
