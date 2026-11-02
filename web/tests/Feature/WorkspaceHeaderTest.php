<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkspaceHeaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        $this->withoutVite();
        View::addNamespace('header-tests', base_path('tests/Fixtures'));
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    public static function roles(): array
    {
        return [
            'Admin' => ['admin', 'system_admin', 'admin-sidebar', 'data-open-sidebar', 'Admin workspace'],
            'Field Officer' => ['field-officer', 'field_officer', 'field-officer-sidebar', 'data-fo-open', 'Field Officer workspace'],
            'Association Account' => ['association', 'association_account', 'association-sidebar', 'data-aa-open', 'Association Account workspace'],
        ];
    }

    private function renderLayout(string $prefix, string $routeName, string $name = 'Synthetic Account'): string
    {
        $request = Request::create('http://localhost/_header-test');
        $request->setRouteResolver(fn () => Route::getRoutes()->getByName($routeName));
        $request->setLaravelSession(app('session')->driver());
        $request->session()->start();
        $this->app->instance('request', $request);
        View::share('currentUser', (object) ['display_name' => $name, 'role' => (object) ['name' => 'System Administrator']]);
        View::share('errors', new ViewErrorBag);

        return view('header-tests::workspace-header', [
            'layout' => 'layouts.'.$prefix,
            'catalog' => require resource_path('ui/admin.php'),
            'module' => 'dashboard',
            'workspaceTitle' => 'Dashboard',
        ])->render();
    }

    private function document(string $html): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            $document->loadHTML('<?xml encoding="UTF-8">'.$html);
            return new DOMXPath($document);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    #[DataProvider('roles')]
    public function test_role_header_keeps_controls_account_and_one_feedback_mount(
        string $prefix, string $role, string $sidebar, string $opener, string $workspace
    ): void {
        $html = $this->renderLayout($prefix, $prefix.'.dashboard', 'Synthetic <script>account</script> & Name');
        $xpath = $this->document($html);
        $header = '//header[contains(@class, "workspace-header")]';
        $this->assertSame(1, $xpath->query($header)->length);
        $this->assertSame($workspace, $xpath->evaluate('string('.$header.'//div[@class="context-product"])'));
        $this->assertSame(1, $xpath->query($header.'//button[@'.$opener.' and @aria-controls="'.$sidebar.'" and @aria-expanded="false" and @type="button"]')->length);
        $this->assertSame(1, $xpath->query('//aside[@id="'.$sidebar.'"]')->length);
        $this->assertSame('Synthetic <script>account</script> & Name', $xpath->evaluate('string('.$header.'//div[@class="account-copy"]/strong)'));
        $this->assertSame(0, $xpath->query($header.'//script')->length);
        $this->assertSame(1, $xpath->query($header.'//form[@method="POST" and @action="'.route('logout').'"]')->length);
        $this->assertSame(csrf_token(), $xpath->evaluate('string('.$header.'//input[@name="_token"]/@value)'));
        $this->assertSame('Logout', trim($xpath->evaluate('string('.$header.'//button[@type="submit"])')));
        $this->assertSame(1, $xpath->query('//*[@id="global-feedback-modal"]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-feedback-root]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-feedback-acknowledge]')->length);
        $this->assertSame($prefix === 'admin' ? 1 : 0, $xpath->query('//*[@id="current-context"]')->length);
        $this->assertSame(0, $xpath->query($header.'//button[@aria-label="Notifications preview"]')->length);
        $this->assertSame($prefix === 'admin' ? 'SA' : '', $xpath->evaluate('string('.$header.'//span[@class="avatar"])'));
    }

    public function test_admin_email_placeholder_preserves_constraints_without_inline_handlers(): void
    {
        $xpath = $this->document($this->renderLayout('admin', 'admin.dashboard'));
        $dialog = '//dialog[@id="email-modal" and @aria-labelledby="email-modal-title"]';
        $form = $dialog.'/form[@data-form]';
        $this->assertSame(1, $xpath->query($form)->length);
        $this->assertSame(0, $xpath->query($form.'/@onsubmit')->length);
        $this->assertSame('Change email', $xpath->evaluate('string('.$dialog.'//h2)'));
        $this->assertSame('Email changes are unavailable in this dialog. Use Change Email from the account details page.', $xpath->evaluate('string('.$form.'/p)'));
        $this->assertSame(1, $xpath->query($form.'//input[@name="new_email" and @id="email-change-new_email" and @type="email" and @required]')->length);
        $this->assertSame(1, $xpath->query($form.'//label[@for="email-change-new_email"]')->length);
        $this->assertSame(1, $xpath->query($form.'//button[@type="button" and @data-close-dialog and normalize-space(.)="Cancel"]')->length);
        $this->assertSame(1, $xpath->query($form.'//button[@type="button" and @disabled and normalize-space(.)="Change Email"]')->length);
    }

    public function test_sidebars_keep_their_existing_role_specific_destinations(): void
    {
        $destinations = [
            'admin' => ['dashboard', 'areas.index', 'associations.index', 'members.index', 'projects.index', 'trainings.index', 'program-activities.index', 'monitoring.index', 'gis.index', 'reports.index', 'users.index', 'audit-logs.index'],
            'field-officer' => ['dashboard', 'associations.index', 'association-officers.index', 'members.index', 'member-applications.index', 'projects.index', 'trainings.index', 'program-activities.index', 'monitoring.index', 'gis.index', 'reports.index'],
            'association' => ['dashboard', 'member-applications.index', 'projects.index', 'trainings.index', 'program-activities.index', 'monitoring.index', 'gis.index', 'reports.index'],
        ];
        $labels = [
            'admin' => ['Dashboard', 'Area Management', 'Association Management', 'Registered Members', 'Projects', 'Trainings', 'Program Activities', 'Monitoring', 'GIS Mapping', 'Reports & Analytics', 'User Management', 'Audit Log'],
            'field-officer' => ['Dashboard', 'Assigned Associations', 'Association Officers', 'Member Management', 'Member Applications', 'Projects', 'Trainings', 'Program Activities', 'Monitoring', 'GIS Mapping', 'Reports & Analytics'],
            'association' => ['Dashboard', 'Member Applications', 'Projects', 'Trainings', 'Program Activities', 'Monitoring', 'GIS Mapping', 'Reports & Analytics'],
        ];
        foreach ($destinations as $prefix => $routes) {
            $xpath = $this->document($this->renderLayout($prefix, $prefix.'.dashboard'));
            $links = [];
            foreach ($xpath->query('//aside//nav//a/@href') as $href) $links[] = $href->nodeValue;
            $this->assertSame(array_map(fn ($name) => route($prefix.'.'.$name), $routes), $links, $prefix);
            $actualLabels = [];
            foreach ($xpath->query('//aside//nav//a/span') as $label) $actualLabels[] = $label->textContent;
            $this->assertSame($labels[$prefix], $actualLabels, $prefix);
            $this->assertSame(1, $xpath->query('//aside[@class="sidebar"]/a[@class="brand"]')->length);
            $this->assertSame(1, $xpath->query('//aside/nav[@class="sidebar-nav" and @aria-label="Main navigation"]')->length);
            $this->assertSame('AssocMap Information System', $xpath->evaluate('string(//aside/div[@class="sidebar-footer"]//strong)'));
            $this->assertSame(1, $xpath->query('//aside[@data-workspace-sidebar]')->length);
            $this->assertSame(1, $xpath->query('//aside//button[@data-collapse-sidebar and @type="button" and @aria-expanded="true" and @aria-label="Collapse sidebar" and @aria-controls="'.$prefix.'-sidebar"]')->length);
            $this->assertSame(0, $xpath->query('//aside//*[@data-disclosure]')->length);
            $this->assertSame(1, $xpath->query('//aside//*[@hidden]')->length);
            $this->assertSame(1, $xpath->query('//aside//button[@data-close-workspace-sidebar and @hidden and @type="button" and @aria-controls="'.$prefix.'-sidebar"]')->length);
            $headings = $prefix === 'association'
                ? ['OVERVIEW', 'MEMBERSHIP', 'PROJECTS & ACTIVITIES', 'MONITORING & INSIGHTS']
                : ['OVERVIEW', 'ASSOCIATIONS & MEMBERS', 'PROJECTS & ACTIVITIES', 'MONITORING & INSIGHTS'];
            if ($prefix === 'admin') $headings[] = 'ADMINISTRATION';
            $actualHeadings = [];
            foreach ($xpath->query('//aside/nav/section') as $section) {
                $heading = $section->getElementsByTagName('h2')->item(0);
                $this->assertSame($heading->getAttribute('id'), $section->getAttribute('aria-labelledby'));
                $this->assertSame(1, $xpath->query('//*[@id="'.$heading->getAttribute('id').'"]')->length);
                $actualHeadings[] = $heading->textContent;
            }
            $this->assertSame($headings, $actualHeadings);
            foreach ($xpath->query('//aside/nav//a') as $link) {
                $this->assertSame(trim($link->textContent), $link->getAttribute('aria-label'));
                $this->assertSame($link->getAttribute('aria-label'), $link->getAttribute('title'));
                $this->assertSame(1, $link->getElementsByTagName('svg')->length);
            }
            $this->assertSame(0, $xpath->query('//aside//svg[not(@aria-hidden="true") or not(@focusable="false")]')->length);
            $this->assertSame(1, $xpath->query('//footer[@class="app-footer"]/span')->length);
            $this->assertStringContainsString('GIS-Based Program Monitoring Information System', $xpath->evaluate('string(//footer[@class="app-footer"])'));
            $this->assertStringNotContainsString('BFAR', $xpath->evaluate('string(//aside)'));
        }
    }

    public function test_every_navigation_route_family_keeps_one_active_destination(): void
    {
        foreach (['admin', 'field-officer', 'association'] as $prefix) {
            $dashboard = $this->document($this->renderLayout($prefix, $prefix.'.dashboard'));
            $destinations = [];
            foreach ($dashboard->query('//aside/nav//a') as $link) {
                $destinations[$link->getAttribute('href')] = $link->getAttribute('aria-label');
            }
            foreach (Route::getRoutes() as $route) {
                $name = $route->getName();
                if (! $name || ! str_starts_with($name, $prefix.'.')) continue;
                $parts = explode('.', $name);
                $index = count($parts) === 2 ? $name : $prefix.'.'.$parts[1].'.index';
                if (! Route::has($index) || ! isset($destinations[route($index)])) continue;
                $xpath = $this->document($this->renderLayout($prefix, $name));
                $active = $xpath->query('//aside/nav//a[@aria-current="page"]');
                $this->assertSame(1, $active->length, $name);
                $this->assertSame(route($index), $active->item(0)->getAttribute('href'), $name);
                $this->assertStringContainsString('active', $active->item(0)->getAttribute('class'), $name);
            }
        }
    }

    public static function contexts(): array
    {
        $cases = [];
        foreach (['field-officer', 'association'] as $prefix) {
            foreach (['dashboard' => 'Dashboard', 'reports.index' => 'Reports & Analytics', 'gis.index' => 'GIS Mapping', 'member-applications.index' => 'Member Applications', 'monitoring.index' => 'Monitoring'] as $route => $title) {
                $cases[$prefix.'.'.$route] = [$prefix, $prefix.'.'.$route, $title];
            }
        }
        foreach (['association-officers.index' => 'Association Officers', 'members.index' => 'Member Management', 'projects.index' => 'Projects', 'trainings.index' => 'Trainings', 'program-activities.index' => 'Program Activities'] as $route => $title) {
            $cases['field-officer.'.$route] = ['field-officer', 'field-officer.'.$route, $title];
        }
        return $cases;
    }

    #[DataProvider('contexts')]
    public function test_existing_route_context_and_monitoring_span_target_are_preserved(string $prefix, string $route, string $title): void
    {
        $xpath = $this->document($this->renderLayout($prefix, $route));
        $this->assertSame($title, $xpath->evaluate('string(//header/div[@class="topbar-context"]/span[1])'));
        $this->assertSame($title.' · AssocMap', $xpath->evaluate('string(//title)'));
        $this->assertSame(1, $xpath->query('//aside/nav//a[@aria-current="page"]')->length);
        $this->assertSame(route($route), $xpath->evaluate('string(//aside/nav//a[@aria-current="page"]/@href)'));
        $this->assertSame(1, $xpath->query('//aside/nav//a[@aria-current="page" and contains(concat(" ", @class, " "), " active ")]')->length);
    }

    public static function operations(): array
    {
        $cases = [];
        foreach (['admin', 'field-officer', 'association'] as $prefix) {
            foreach (['projects', 'trainings', 'monitoring', 'program-activities'] as $module) {
                $cases[$prefix.'.'.$module] = [$prefix, $module];
            }
        }
        return $cases;
    }

    #[DataProvider('operations')]
    public function test_active_operational_route_is_visible_in_its_workflow_section(string $prefix, string $module): void
    {
        $xpath = $this->document($this->renderLayout($prefix, $prefix.'.'.$module.'.index'));
        $sectionId = $prefix.($module === 'monitoring' ? '-monitoring-insights' : '-projects-activities');
        $group = '//aside/nav/section[@aria-labelledby="'.$sectionId.'"]';
        $this->assertSame(1, $xpath->query($group)->length);
        $this->assertSame(0, $xpath->query('//aside/nav//*[@hidden or @data-disclosure]')->length);
        $modules = $module === 'monitoring' ? ['monitoring', 'gis', 'reports'] : ['projects', 'trainings', 'program-activities'];
        $destinations = [];
        foreach ($xpath->query($group.'/a/@href') as $href) $destinations[] = $href->nodeValue;
        $this->assertSame(array_map(fn ($key) => route($prefix.'.'.$key.'.index'), $modules), $destinations);
        $this->assertSame(route($prefix.'.'.$module.'.index'), $xpath->evaluate('string('.$group.'/a[@aria-current="page"]/@href)'));
        foreach ($destinations as $href) $this->assertSame(1, $xpath->query('//aside/nav//a[@href="'.$href.'"]')->length);
    }

    #[DataProvider('roles')]
    public function test_existing_logout_invalidates_each_role_session(string $prefix, string $role): void
    {
        $this->withSession(['supabase_access_token' => 'synthetic-token', 'user_profile_id' => 'synthetic-user', 'user_role' => $role]);
        $before = session()->token();
        $this->post(route('logout'))->assertRedirect(route('login'))
            ->assertSessionMissing('supabase_access_token')->assertSessionMissing('user_profile_id')->assertSessionMissing('user_role');
        $this->assertNotSame($before, session()->token());
    }
}
