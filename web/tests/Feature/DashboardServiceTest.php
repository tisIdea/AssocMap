<?php

namespace Tests\Feature;

use App\Models\UserProfile;
use App\Services\DashboardService;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class DashboardServiceTest extends TestCase
{
    private const USER = '11111111-1111-4111-8111-111111111111';
    private const OTHER = '22222222-2222-4222-8222-222222222222';
    private const OWN = '33333333-3333-4333-8333-333333333333';
    private const OUTSIDE = '44444444-4444-4444-8444-444444444444';
    private const ARCHIVED = '55555555-5555-4555-8555-555555555555';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.dashboard_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('dashboard_test');
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        Schema::create('roles', function (Blueprint $table) { $table->id(); $table->string('code'); $table->boolean('is_active'); });
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->integer('role_id'); $table->string('display_name'); $table->boolean('is_active'); $table->timestamps();
        });
        Schema::create('user_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('auth_user_id')->nullable(); $table->string('email'); $table->string('status');
            $table->string('last_error_code')->nullable(); $table->timestamp('accepted_at')->nullable(); $table->timestamp('last_attempt_at')->nullable(); $table->timestamps();
        });
        Schema::create('municipalities', function (Blueprint $table) { $table->integer('id')->primary(); $table->string('name'); });
        Schema::create('barangays', function (Blueprint $table) { $table->integer('id')->primary(); $table->integer('municipality_id'); });
        Schema::create('areas', function (Blueprint $table) { $table->integer('id')->primary(); $table->integer('barangay_id'); });
        Schema::create('associations', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->integer('area_id'); $table->uuid('primary_field_officer_id');
            $table->string('name'); $table->string('status'); $table->date('saad_phase2_join_date'); $table->timestamp('archived_at')->nullable();
        });
        Schema::create('association_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('user_id'); $table->uuid('association_id'); $table->boolean('is_active');
        });
        Schema::create('members', function (Blueprint $table) {
            $table->integer('id')->primary(); $table->uuid('association_id'); $table->string('member_status'); $table->timestamp('archived_at')->nullable();
        });
        Schema::create('projects', function (Blueprint $table) {
            $table->integer('id')->primary(); $table->uuid('association_id'); $table->integer('saad_activity_id');
            $table->string('title'); $table->string('status'); $table->integer('program_year'); $table->timestamp('archived_at')->nullable(); $table->timestamps();
        });
        Schema::create('trainings', function (Blueprint $table) { $table->integer('id')->primary(); $table->uuid('association_id'); $table->string('status'); });
        Schema::create('training_participants', function (Blueprint $table) {
            $table->integer('training_id'); $table->integer('member_id'); $table->string('attendance_status');
        });
        Schema::create('member_applications', function (Blueprint $table) { $table->id(); $table->uuid('association_id'); $table->string('status'); });
        Schema::create('quarters', function (Blueprint $table) { $table->integer('id'); $table->integer('quarter_number'); });
        Schema::create('monitoring_records', function (Blueprint $table) {
            $table->integer('id')->primary(); $table->integer('project_id'); $table->integer('year'); $table->integer('quarter_id');
            $table->date('monitoring_date'); $table->string('project_status');
        });
        foreach (['field_officer', 'system_admin', 'association_account'] as $index => $role) {
            DB::table('roles')->insert(['id' => $index + 1, 'code' => $role, 'is_active' => true]);
        }
        foreach ([self::USER, self::OTHER] as $id) DB::table('user_profiles')->insert(['id' => $id, 'role_id' => 1, 'display_name' => 'Synthetic officer', 'is_active' => true]);
        DB::table('municipalities')->insert(['id' => 1, 'name' => 'Synthetic municipality']);
        DB::table('barangays')->insert(['id' => 1, 'municipality_id' => 1]);
        DB::table('areas')->insert(['id' => 1, 'barangay_id' => 1]);
        foreach ([[self::OWN, self::USER, null], [self::OUTSIDE, self::OTHER, null], [self::ARCHIVED, self::USER, '2026-01-01']] as [$id, $officer, $archived]) {
            DB::table('associations')->insert(['id' => $id, 'area_id' => 1, 'primary_field_officer_id' => $officer,
                'name' => 'Synthetic '.$id, 'status' => 'active', 'saad_phase2_join_date' => '2024-01-01', 'archived_at' => $archived]);
        }
        foreach ([[1, self::OWN, 'active', null], [2, self::OWN, 'inactive', '2026-01-01'], [3, self::OUTSIDE, 'active', null]] as [$id, $association, $status, $archived]) {
            DB::table('members')->insert(['id' => $id, 'association_id' => $association, 'member_status' => $status, 'archived_at' => $archived]);
        }
        foreach ([[1, self::OWN, null], [2, self::OWN, '2026-01-01'], [3, self::OUTSIDE, null]] as [$id, $association, $archived]) {
            DB::table('projects')->insert(['id' => $id, 'association_id' => $association, 'saad_activity_id' => 1,
                'title' => 'Synthetic project '.$id, 'status' => 'ongoing', 'program_year' => 2026, 'created_at' => '2026-01-01', 'archived_at' => $archived]);
            DB::table('trainings')->insert(['id' => $id, 'association_id' => $association, 'status' => 'completed']);
            DB::table('training_participants')->insert(['training_id' => $id, 'member_id' => $id === 3 ? 3 : 1, 'attendance_status' => 'present']);
            DB::table('monitoring_records')->insert(['id' => $id, 'project_id' => $id, 'year' => 2026, 'quarter_id' => 1,
                'monitoring_date' => '2026-01-01', 'project_status' => 'planned']);
        }
        DB::table('quarters')->insert(['id' => 1, 'quarter_number' => 1]);
        foreach ([self::OWN, self::OWN, self::OUTSIDE, self::ARCHIVED] as $association) {
            DB::table('member_applications')->insert(['association_id' => $association, 'status' => 'pending']);
        }
    }

    protected function tearDown(): void
    {
        DB::purge('dashboard_test');
        parent::tearDown();
    }

    private function request(): Request
    {
        $request = Request::create('/association/dashboard?association_id='.self::OUTSIDE);
        $request->attributes->set('current_user', UserProfile::with('role')->findOrFail(self::USER));

        return $request;
    }

    private function metrics(array $dashboard): array
    {
        return array_column($dashboard['metrics'], 'value', 'key');
    }

    public function test_officer_scope_archives_distinct_attendees_and_reassignment(): void
    {
        $service = app(DashboardService::class);
        $request = $this->request();
        $data = $service->load($request);
        $metrics = $this->metrics($data);
        $this->assertSame(1, $data['scope_count']);
        $this->assertSame(2, $metrics['members_total']);
        $this->assertSame(1, $metrics['members_active']);
        $this->assertSame(2, $metrics['projects_total']);
        $this->assertSame(1, $metrics['projects_unarchived']);
        $this->assertSame(2, $data['training']['records']);
        $this->assertSame(1, $data['training']['distinct_present_members']);
        $this->assertSame(1, $data['monitoring']['records']);
        $this->assertSame('planned', $data['monitoring']['recent'][0]['project_status']);
        $this->assertSame(2, $data['applications']['pending']);
        $this->assertSame([self::OWN], array_column($data['associations'], 'id'));
        DB::table('associations')->where('id', self::OWN)->update(['primary_field_officer_id' => self::OTHER]);
        $next = $service->load($request);
        $this->assertSame(0, $next['scope_count']);
        $this->assertSame(0, $this->metrics($next)['members_total']);
        $this->assertSame([], $next['recent_projects']);
    }

    public function test_association_scope_ignores_client_id_and_denies_revoked_link(): void
    {
        DB::table('user_profiles')->where('id', self::USER)->update(['role_id' => 3]);
        DB::table('association_accounts')->insert(['id' => self::USER, 'user_id' => self::USER, 'association_id' => self::OWN, 'is_active' => true]);
        $service = app(DashboardService::class);
        $request = $this->request();
        $data = $service->load($request);
        $this->assertSame(self::OWN, $data['association']['id']);
        $this->assertSame(2, $this->metrics($data)['members_total']);
        DB::table('association_accounts')->update(['is_active' => false]);
        try {
            $service->load($request);
            $this->fail('Inactive association links must be denied.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_admin_lifecycle_categories_are_disjoint(): void
    {
        DB::table('user_profiles')->where('id', self::USER)->update(['role_id' => 2]);
        $data = app(DashboardService::class)->load($this->request());
        $metrics = $this->metrics($data);
        $this->assertSame(3, $metrics['associations_total']);
        $this->assertSame(2, $metrics['associations_active']);
        $this->assertSame(1, $metrics['associations_archived']);
        $this->assertSame(0, $metrics['associations_inactive']);
        $this->assertSame(3, $metrics['members_total']);
        $this->assertNull($data['applications']);
    }

    private function completeDashboardTables(): void
    {
        Schema::create('saad_components', function (Blueprint $table) { $table->integer('id')->primary(); $table->string('name'); });
        Schema::create('saad_subcomponents', function (Blueprint $table) { $table->integer('id')->primary(); $table->integer('component_id'); });
        Schema::create('saad_activities', function (Blueprint $table) { $table->integer('id')->primary(); $table->integer('subcomponent_id'); });
        Schema::create('monitoring_visit_reports', function (Blueprint $table) {
            $table->id(); $table->integer('monitoring_record_id'); $table->boolean('is_legacy_import');
        });
        Schema::create('program_activity_records', function (Blueprint $table) {
            $table->id(); $table->uuid('association_id')->nullable(); $table->string('title'); $table->date('activity_date');
            $table->string('status'); $table->timestamp('archived_at')->nullable();
        });
        DB::table('saad_components')->insert(['id' => 1, 'name' => 'Synthetic component']);
        DB::table('saad_subcomponents')->insert(['id' => 1, 'component_id' => 1]);
        DB::table('saad_activities')->insert(['id' => 1, 'subcomponent_id' => 1]);
        foreach ([self::OWN, self::OUTSIDE, null] as $association) {
            DB::table('program_activity_records')->insert(['association_id' => $association, 'title' => 'Synthetic activity',
                'activity_date' => '2026-01-01', 'status' => 'completed']);
        }
    }

    #[DataProvider('dashboardRoles')]
    public function test_consolidated_counts_preserve_scopes_history_and_query_budget(string $role, string $prefix, int $roleId): void
    {
        $this->completeDashboardTables();
        DB::table('user_profiles')->where('id', self::USER)->update(['role_id' => $roleId]);
        if ($roleId === 3) {
            DB::table('association_accounts')->insert(['id' => self::USER, 'user_id' => self::USER,
                'association_id' => self::OWN, 'is_active' => true]);
        }
        DB::table('associations')->insert(['id' => '66666666-6666-4666-8666-666666666666', 'area_id' => 1,
            'primary_field_officer_id' => self::USER, 'name' => 'Synthetic inactive association', 'status' => 'inactive',
            'saad_phase2_join_date' => '2024-01-01']);
        DB::table('trainings')->where('id', 2)->update(['status' => 'planned']);
        DB::table('training_participants')->insert([
            ['training_id' => 1, 'member_id' => 1, 'attendance_status' => 'present'],
            ['training_id' => 1, 'member_id' => 2, 'attendance_status' => 'absent'],
            ['training_id' => 2, 'member_id' => 2, 'attendance_status' => 'present'],
            ['training_id' => 1, 'member_id' => 3, 'attendance_status' => 'present'],
        ]);
        DB::table('quarters')->insert(['id' => 2, 'quarter_number' => 2]);
        foreach ([[4, 2026, 1], [5, 2025, 1], [6, 2026, 2]] as [$id, $year, $quarter]) {
            DB::table('monitoring_records')->insert(['id' => $id, 'project_id' => 1, 'year' => $year,
                'quarter_id' => $quarter, 'monitoring_date' => $year.'-01-01', 'project_status' => 'planned']);
        }
        foreach ([[1, false], [1, true], [2, false], [3, false]] as [$record, $legacy]) {
            DB::table('monitoring_visit_reports')->insert(['monitoring_record_id' => $record, 'is_legacy_import' => $legacy]);
        }
        $request = $this->request();
        $queryCount = 0;
        DB::listen(static function () use (&$queryCount) { $queryCount++; });
        $dashboard = app(DashboardService::class)->load($request);
        $this->assertSame(match ($roleId) { 2 => 20, 1 => 19, 3 => 26 }, $queryCount);
        $metrics = $this->metrics($dashboard);
        $admin = $roleId === 2;
        $this->assertSame($admin ? 4 : 1, $dashboard['scope_count']);
        $this->assertSame($admin ? 3 : 2, $metrics['members_total']);
        $this->assertSame($admin ? 2 : 1, $metrics['members_active']);
        $this->assertSame($admin ? 3 : 2, $metrics['projects_total']);
        $this->assertSame($admin ? 2 : 1, $metrics['projects_unarchived']);
        if ($admin) {
            $this->assertSame([4, 2, 1, 1, 1], array_map(fn ($key) => $metrics[$key],
                ['associations_total', 'associations_active', 'associations_inactive', 'associations_archived', 'field_officers']));
        }
        $this->assertSame(['records' => $admin ? 3 : 2, 'completed' => $admin ? 2 : 1,
            'distinct_present_members' => $admin ? 3 : 2], $dashboard['training']);
        $this->assertSame($admin ? 5 : 4, $dashboard['monitoring']['records']);
        $this->assertSame(3, $dashboard['monitoring']['periods']);
        $this->assertSame(['total' => $admin ? 3 : 2, 'legacy_imports' => 1], $dashboard['monitoring']['visits']);
        $this->assertNotContains(null, $dashboard['charts']);
        $this->assertCount($admin ? 3 : 1, $dashboard['activities']);
        $this->assertStringContainsString('Synthetic project 1', $this->dashboardPage($role, $prefix, $dashboard));
    }

    public function test_consolidated_counts_return_zero_for_an_empty_officer_scope(): void
    {
        $this->completeDashboardTables();
        DB::table('associations')->where('primary_field_officer_id', self::USER)->update(['primary_field_officer_id' => self::OTHER]);
        $dashboard = app(DashboardService::class)->load($this->request());
        $this->assertSame(0, $dashboard['scope_count']);
        $this->assertSame([0], array_values(array_unique($this->metrics($dashboard))));
        $this->assertSame(['records' => 0, 'completed' => 0, 'distinct_present_members' => 0], $dashboard['training']);
        $this->assertSame(0, $dashboard['monitoring']['records']);
        $this->assertSame(0, $dashboard['monitoring']['periods']);
        $this->assertSame(['total' => 0, 'legacy_imports' => 0], $dashboard['monitoring']['visits']);
    }

    public function test_optional_failure_is_unavailable_and_core_failure_is_not_zero(): void
    {
        Schema::drop('trainings');
        $data = app(DashboardService::class)->load($this->request());
        $this->assertNull($data['training']);
        $this->assertNull($this->metrics($data)['training_records']);
        $this->assertSame(2, $this->metrics($data)['members_total']);
        $this->assertNull($data['monitoring']['visits']);
        Schema::drop('members');
        $this->expectException(QueryException::class);
        app(DashboardService::class)->load($this->request());
    }

    public function test_api_dashboard_requires_bearer_authentication(): void
    {
        $this->getJson('/api/v1/association/dashboard')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_assignment_revocation_during_assembly_prevents_dashboard_release(): void
    {
        $changed = false;
        DB::listen(function ($query) use (&$changed) {
            if (! $changed && str_contains($query->sql, 'from "members" as "m"')) {
                $changed = true;
                DB::table('associations')->where('id', self::OWN)->update(['primary_field_officer_id' => self::OTHER]);
            }
        });
        try {
            app(DashboardService::class)->load($this->request());
            $this->fail('A revoked assignment must prevent release of already assembled statistics.');
        } catch (HttpExceptionInterface $error) {
            $this->assertTrue($changed);
            $this->assertSame(403, $error->getStatusCode());
        }
    }

    public function test_transient_scope_expansion_cannot_enter_dashboard_aggregates(): void
    {
        $stage = 0;
        DB::listen(function ($query) use (&$stage) {
            if ($stage === 0 && str_contains($query->sql, 'from "members" as "m"')) {
                $stage = 1;
                DB::table('associations')->where('id', self::OUTSIDE)->update(['primary_field_officer_id' => self::USER]);
            } elseif ($stage === 1 && str_contains($query->sql, 'from "trainings" as "t"')) {
                $stage = 2;
                DB::table('associations')->where('id', self::OUTSIDE)->update(['primary_field_officer_id' => self::OTHER]);
            }
        });
        $data = app(DashboardService::class)->load($this->request());
        $this->assertSame(2, $stage);
        $this->assertSame(2, $this->metrics($data)['projects_total']);
        $this->assertSame(2, $data['training']['records']);
    }
    public function test_final_project_outcomes_are_separate_without_changing_history_or_totals(): void
    {
        DB::table('projects')->where('id', 1)->update(['status' => 'completed']);
        DB::table('projects')->where('id', 2)->update(['status' => 'terminated', 'archived_at' => null]);
        $data = app(DashboardService::class)->load($this->request());
        $chart = collect($data['charts'])->firstWhere('key', 'project_status');
        $this->assertSame(['Completed' => 1, 'Terminated' => 1], array_column($chart['rows'], 'value', 'label'));
        $this->assertSame(2, $this->metrics($data)['projects_total']);
        $this->assertSame('planned', $data['monitoring']['recent'][0]['project_status']);
        $this->assertSame(['planned', 'planned', 'planned'], DB::table('monitoring_records')->orderBy('id')->pluck('project_status')->all());
    }

    public static function dashboardRoles(): array
    {
        return [
            'Admin' => ['system_admin', 'admin', 2],
            'Field Officer' => ['field_officer', 'field-officer', 1],
            'Association Account' => ['association_account', 'association', 3],
        ];
    }

    private function roleDashboard(int $roleId): array
    {
        DB::table('user_profiles')->where('id', self::USER)->update(['role_id' => $roleId]);
        if ($roleId === 3) {
            DB::table('association_accounts')->insert(['id' => self::USER, 'user_id' => self::USER,
                'association_id' => self::OWN, 'is_active' => true]);
        }

        return app(DashboardService::class)->load($this->request());
    }

    private function dashboardPage(string $role, string $prefix, ?array $dashboard, string $state = 'ready'): string
    {
        $this->withoutVite();
        $request = $this->request();
        $request->setRouteResolver(fn () => Route::getRoutes()->getByName($prefix.'.dashboard'));
        $request->setLaravelSession(app('session')->driver());
        $request->session()->start();
        $this->app->instance('request', $request);
        $profile = $request->attributes->get('current_user');
        $profile->role->setAttribute('name', $role);
        View::share('currentUser', $profile);
        View::share('errors', new ViewErrorBag);

        return view('dashboard.page', compact('role', 'prefix', 'dashboard', 'state') + ['layout' => 'layouts.'.$prefix])->render();
    }

    private function dashboardDocument(string $html): DOMXPath
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

    #[DataProvider('dashboardRoles')]
    public function test_dashboard_record_titles_link_to_the_exact_authorized_records_without_changing_metrics(string $role, string $prefix, int $roleId): void
    {
        $dashboard = $this->roleDashboard($roleId);
        $metrics = $dashboard['metrics'];
        $dashboard['activities'] = [
            ['id' => '66666666-6666-4666-8666-666666666666', 'title' => 'First dated activity', 'activity_date' => '2026-01-15', 'status' => 'planned'],
            ['id' => '77777777-7777-4777-8777-777777777777', 'title' => 'Second dated activity', 'activity_date' => '2026-01-15', 'status' => 'completed'],
        ];
        $xpath = $this->dashboardDocument($this->dashboardPage($role, $prefix, $dashboard));
        if ($role === 'association_account') {
            $this->assertSame(0, $xpath->query('//section[@aria-labelledby="dashboard-projects-title" or @aria-labelledby="dashboard-activities-title"]//strong/a')->length);
        }
        foreach ($dashboard['recent_projects'] as $project) {
            if ($role === 'association_account') continue;
            $links = $xpath->query('//section[@aria-labelledby="dashboard-projects-title"]//strong/a[@href="'.route($prefix.'.projects.show', $project['id']).'"]');
            $this->assertSame(1, $links->length);
            if ($links->length) $this->assertSame($project['title'], $links->item(0)->textContent);
        }
        foreach ($dashboard['activities'] as $activity) {
            if ($role === 'association_account') continue;
            $links = $xpath->query('//section[@aria-labelledby="dashboard-activities-title"]//strong/a[@href="'.route($prefix.'.program-activities.show', $activity['id']).'"]');
            $this->assertSame(1, $links->length);
            if ($links->length) {
                $this->assertSame($activity['title'], $links->item(0)->textContent);
                $this->assertSame($activity['activity_date'], $xpath->evaluate('string(../following-sibling::p)', $links->item(0)));
            }
        }
        foreach ($dashboard['monitoring']['recent'] as $record) {
            $this->assertSame(1, $xpath->query('//section[@aria-labelledby="dashboard-recent-monitoring-title"]//strong/a[@href="'.route($prefix.'.monitoring.workspace', $record['id']).'"]')->length);
        }
        $this->assertSame(0, $xpath->query('//main//a//a')->length);
        $cards = $xpath->query('//section[@aria-label="Dashboard summary"]/a');
        $this->assertSame($role === 'association_account' ? 1 : 3, $cards->length);
        $keys = match ($role) {
            'system_admin' => ['associations_total', 'members_total', 'projects_total'],
            'field_officer' => ['associations_active', 'members_total', 'applications_pending'],
            default => ['applications_pending'],
        };
        foreach ($keys as $index => $key) {
            $metric = collect($metrics)->firstWhere('key', $key);
            $this->assertSame($metric['value'] === null ? 'Unavailable' : number_format($metric['value']),
                $xpath->evaluate('string(.//*[@data-summary-counter])', $cards->item($index)));
        }
        $this->assertSame($metrics, $dashboard['metrics']);
    }

    #[DataProvider('dashboardRoles')]
    public function test_dashboard_presentation_preserves_scoped_metrics_shortcuts_and_shared_shell(string $role, string $prefix, int $roleId): void
    {
        $dashboard = $this->roleDashboard($roleId);
        $html = $this->dashboardPage($role, $prefix, $dashboard);
        $xpath = $this->dashboardDocument($html);
        $metrics = $xpath->query('//main//section[@aria-label="Dashboard summary"]/article');
        $this->assertSame(count($dashboard['metrics']), $metrics->length);
        foreach ($dashboard['metrics'] as $index => $metric) {
            $card = $metrics->item($index);
            $this->assertSame($metric['label'], $xpath->evaluate('string(./div/span[1])', $card));
            $this->assertSame($metric['value'] === null ? 'Unavailable' : number_format($metric['value']),
                $xpath->evaluate('string(./strong/span[@data-summary-counter])', $card));
            $this->assertSame($metric['note'], $xpath->evaluate('string(./small)', $card));
        }
        $shortcuts = match ($role) {
            'system_admin' => ['associations.index', 'users.index', 'association-officers.index', 'projects.index', 'trainings.index', 'monitoring.index', 'reports.index', 'gis.index'],
            'field_officer' => ['association-officers.index', 'members.index', 'member-applications.index', 'projects.index', 'trainings.index', 'monitoring.index', 'reports.index', 'gis.index'],
            default => ['member-applications.index', 'monitoring.index', 'reports.index', 'gis.index'],
        };
        $expected = array_map(fn ($name) => route($prefix.'.'.$name), $shortcuts);
        if ($role === 'field_officer') $expected[] = '#assigned-associations';
        $actual = [];
        foreach ($xpath->query('//main//nav[@aria-label="Dashboard quick actions"]/a') as $link) {
            $actual[] = $link->getAttribute('href');
            $this->assertNotSame('', trim($link->textContent));
            $this->assertSame(2, $link->getElementsByTagName('svg')->length);
        }
        $this->assertSame($expected, $actual);
        $this->assertSame(1, $xpath->query('//main//h1')->length);
        $this->assertSame(match ($role) {
            'field_officer' => 'Field Officer Dashboard', 'association_account' => 'Association Dashboard', default => 'Dashboard',
        }, $xpath->evaluate('string(//main//h1)'));
        $this->assertSame(match ($role) {
            'field_officer' => 'Field workspace', 'association_account' => 'Your workspace', default => 'Quick access',
        }, $xpath->evaluate('string(//*[@id="dashboard-actions-title"])'));
        $this->assertSame(1, $xpath->query('//header[contains(@class, "workspace-header")]')->length);
        $this->assertSame(1, $xpath->query('//aside[@data-workspace-sidebar]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="global-feedback-modal"]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-feedback-root]')->length);
        $this->assertSame($role === 'system_admin' ? 1 : 0, $xpath->query('//*[@id="dashboard-attention-title"]')->length);
        $this->assertSame($role === 'system_admin' ? 0 : 1, $xpath->query('//*[@id="dashboard-applications-title"]')->length);
        $this->assertSame($role === 'association_account' ? 0 : 1, $xpath->query('//*[@id="assigned-associations"]')->length);
        if ($role === 'association_account') {
            $this->assertStringContainsString($dashboard['association']['name'], $html);
            $this->assertSame($dashboard['association']['name'], $xpath->evaluate('string(//*[@id="dashboard-association-title"])'));
            $this->assertSame(ucfirst($dashboard['association']['status']), $xpath->evaluate('string(//section[@aria-labelledby="dashboard-association-title"]/span[contains(@class, "badge")])'));
            $this->assertStringContainsString($dashboard['association']['municipality'], $html);
            $this->assertStringContainsString($dashboard['association']['joined_on'], $html);
            $this->assertStringNotContainsString(route('admin.users.index'), $html);
            $this->assertStringNotContainsString(route('field-officer.projects.index'), $html);
            $this->assertStringNotContainsString('Review pending applications', $html);
        }
        foreach (['dashboard-projects-title', 'dashboard-training-title', 'dashboard-monitoring-title', 'dashboard-activities-title', 'dashboard-recent-monitoring-title'] as $id) {
            $this->assertSame(1, $xpath->query('//section[@aria-labelledby="'.$id.'"]//h2[@id="'.$id.'"]')->length, $id);
        }
        foreach ($dashboard['charts'] as $chart) {
            if ($chart === null) continue;
            $this->assertSame(1, $xpath->query('//section[@aria-labelledby="dashboard-chart-'.$chart['key'].'"]')->length);
            foreach ($chart['rows'] as $row) $this->assertStringContainsString(e($row['label']), $html);
        }
        foreach ($dashboard['recent_projects'] as $project) $this->assertStringContainsString(e($project['title']), $html);
        $this->assertStringNotContainsString('Synthetic '.self::OUTSIDE, $role === 'system_admin' ? '' : $html);
        $this->assertStringNotContainsString('BFAR Region VII', $html);
        $this->assertStringNotContainsString('UI preview', $xpath->evaluate('string(//main)'));
    }

    #[DataProvider('dashboardRoles')]
    public function test_dashboard_empty_and_unavailable_widgets_remain_distinct(string $role, string $prefix, int $roleId): void
    {
        $dashboard = $this->roleDashboard($roleId);
        $dashboard['metrics'][0]['value'] = null;
        $dashboard['scope_count'] = 0;
        $dashboard['associations'] = $dashboard['recent_projects'] = $dashboard['activities'] = [];
        $dashboard['applications'] = $dashboard['training'] = $dashboard['monitoring'] = $dashboard['officer_attention'] = null;
        $dashboard['charts'] = [null, ['key' => 'empty', 'title' => 'Empty chart', 'note' => 'No matching source rows.', 'rows' => []]];
        $html = $this->dashboardPage($role, $prefix, $dashboard);
        $this->assertStringContainsString('>Unavailable</span>', $html);
        $this->assertStringContainsString('Training summaries are temporarily unavailable.', $html);
        $this->assertStringContainsString('Monitoring summaries are temporarily unavailable.', $html);
        $this->assertStringContainsString('No unarchived projects in this scope.', $html);
        $this->assertStringContainsString('No recorded activities in this scope.', $html);
        $this->assertStringContainsString('Chart unavailable', $html);
        $this->assertStringContainsString('No matching records in this scope.', $html);
        $this->assertSame($role === 'field_officer', str_contains($html, 'No active, unarchived associations are currently assigned to you.'));
        $this->assertStringContainsString($role === 'system_admin' ? 'Officer assignment checks are temporarily unavailable.' : 'Application counts are temporarily unavailable.', $html);
    }

    #[DataProvider('dashboardRoles')]
    public function test_denied_and_failed_dashboards_do_not_render_records_or_shortcuts(string $role, string $prefix, int $roleId): void
    {
        $dashboard = $this->roleDashboard($roleId);
        foreach (['denied', 'unavailable'] as $state) {
            $html = $this->dashboardPage($role, $prefix, $dashboard, $state);
            $xpath = $this->dashboardDocument($html);
            $this->assertSame(1, $xpath->query('//main//section[@role="alert"]')->length);
            $this->assertSame(0, $xpath->query('//main//*[@data-summary-counter]')->length);
            $this->assertSame(0, $xpath->query('//main//nav')->length);
            $this->assertStringNotContainsString('Synthetic project', $html);
            $this->assertStringNotContainsString('Synthetic '.self::OWN, $html);
            $this->assertStringContainsString(route($prefix.'.dashboard'), $xpath->evaluate('string(//main//a/@href)'));
        }
    }

    public function test_association_identity_card_escapes_record_content_and_preserves_the_supplied_status(): void
    {
        $dashboard = $this->roleDashboard(3);
        $dashboard['association']['name'] = 'Synthetic <script>alert(1)</script> association & partners';
        $dashboard['association']['municipality'] = 'Synthetic <img src=x onerror=alert(1)> municipality';
        $dashboard['association']['status'] = 'inactive';
        $html = $this->dashboardPage('association_account', 'association', $dashboard);
        $xpath = $this->dashboardDocument($html);
        $this->assertSame($dashboard['association']['name'], $xpath->evaluate('string(//*[@id="dashboard-association-title"])'));
        $this->assertSame('Inactive', $xpath->evaluate('string(//section[@aria-labelledby="dashboard-association-title"]/span[contains(@class, "badge")])'));
        $this->assertStringContainsString(e($dashboard['association']['municipality']), $html);
        $this->assertSame(0, $xpath->query('//section[@aria-labelledby="dashboard-association-title"]//script | //section[@aria-labelledby="dashboard-association-title"]//img')->length);
    }
}

