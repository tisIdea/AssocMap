<?php

namespace Tests\Feature;

use App\Models\UserProfile;
use App\Services\Auth\SupabaseAuthService;
use App\Exceptions\ReportDatasetLimitException;
use App\Exceptions\ReportingUnavailableException;
use App\Services\Reporting\PythonReportEngine;
use App\Services\Reporting\ReportCatalog;
use App\Services\Reporting\ReportDataset;
use App\Services\Reporting\ReportScope;
use App\Services\Reporting\ReportService;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class ReportAuthorizationTest extends TestCase
{
    private const USER = '11111111-1111-4111-8111-111111111111';
    private const OTHER = '22222222-2222-4222-8222-222222222222';
    private const OWN = '33333333-3333-4333-8333-333333333333';
    private const OUTSIDE = '44444444-4444-4444-8444-444444444444';
    private const TOWN = '55555555-5555-4555-8555-555555555555';
    private const OTHER_TOWN = '66666666-6666-4666-8666-666666666666';

    protected function setUp(): void
    {
        parent::setUp();
        config([
    'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        ]);
        config(['associations.geography_schema_ready' => false, 'associations.direct_geography' => false]);
        config(['database.connections.report_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('report_test');
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        Schema::create('roles', function (Blueprint $table) {
            $table->id(); $table->string('code'); $table->string('name'); $table->boolean('is_active'); $table->timestamps();
            $table->text('description')->nullable();
        });
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->integer('role_id'); $table->string('display_name');
            $table->boolean('is_active'); $table->timestamps();
        });
        Schema::create('user_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->string('email'); $table->uuid('auth_user_id')->nullable();
            $table->string('status'); $table->string('last_error_code')->nullable();
            $table->timestamp('accepted_at')->nullable(); $table->timestamp('last_attempt_at')->nullable(); $table->timestamps();
        });
        Schema::create('municipalities', function (Blueprint $table) { $table->uuid('id')->primary(); $table->string('name'); });
        Schema::create('barangays', function (Blueprint $table) { $table->uuid('id')->primary(); $table->uuid('municipality_id'); $table->string('name'); });
        Schema::create('areas', function (Blueprint $table) { $table->uuid('id')->primary(); $table->uuid('barangay_id'); });
        Schema::create('associations', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('area_id'); $table->uuid('primary_field_officer_id');
            $table->string('name'); $table->string('status'); $table->timestamp('archived_at')->nullable();
        });
        // Duplicate links are permitted only in this fixture to verify fail-closed reads.
        Schema::create('association_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('user_id'); $table->uuid('association_id'); $table->boolean('is_active');
        });
        foreach (['field_officer', 'system_admin', 'association_account'] as $index => $role) {
            DB::table('roles')->insert(['id' => $index + 1, 'code' => $role, 'name' => $role, 'is_active' => true]);
        }
        DB::table('user_profiles')->insert(['id' => self::USER, 'role_id' => 1, 'display_name' => 'Synthetic officer', 'is_active' => true]);
        foreach ([[self::OWN, self::TOWN, self::USER], [self::OUTSIDE, self::OTHER_TOWN, self::OTHER]] as [$association, $town, $officer]) {
            DB::table('municipalities')->insert(['id' => $town, 'name' => 'Synthetic town '.$town]);
            DB::table('barangays')->insert(['id' => $town, 'municipality_id' => $town, 'name' => 'Synthetic barangay']);
            DB::table('areas')->insert(['id' => $town, 'barangay_id' => $town]);
            DB::table('associations')->insert(['id' => $association, 'area_id' => $town, 'primary_field_officer_id' => $officer,
                'name' => 'Synthetic association '.$association, 'status' => 'active']);
        }
    }

    protected function tearDown(): void
    {
        DB::purge('report_test');
        parent::tearDown();
    }

    private function actor(): UserProfile
    {
        return UserProfile::with('role')->findOrFail(self::USER);
    }

    public function test_current_association_report_scope_ignores_history_and_never_restores_ended_access(): void
    {
        DB::table('user_profiles')->where('id', self::USER)->update(['role_id' => 3]);
        DB::table('user_profiles')->insert(['id' => self::OTHER, 'role_id' => 3, 'display_name' => 'Former synthetic holder', 'is_active' => false]);
        DB::table('association_accounts')->insert([
            ['id' => self::OWN, 'user_id' => self::USER, 'association_id' => self::OWN, 'is_active' => true],
            ['id' => self::OUTSIDE, 'user_id' => self::USER, 'association_id' => self::OUTSIDE, 'is_active' => false],
            ['id' => self::OTHER, 'user_id' => self::OTHER, 'association_id' => self::OWN, 'is_active' => false],
        ]);
        $scope = app(ReportScope::class);
        $this->assertSame([self::OWN], $scope->associations($this->actor())->pluck('a.id')->all());
        DB::table('association_accounts')->where('id', self::OWN)->update(['is_active' => false]);
        try {
            $scope->associations($this->actor())->get();
            $this->fail('Historical appointments must not authorize report access.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_cutover_report_scope_retains_area_less_associations_and_exact_assignment_isolation(): void
    {
        Schema::table('associations', function (Blueprint $table) {
            $table->uuid('area_id')->nullable()->change(); $table->uuid('barangay_id')->nullable();
        });
        DB::statement('UPDATE associations SET barangay_id=(SELECT barangay_id FROM areas WHERE areas.id=associations.area_id), area_id=NULL');
        foreach ([false, true] as $direct) {
            config(['associations.geography_schema_ready' => true, 'associations.direct_geography' => $direct]);
            DB::table('associations')->where('id', self::OWN)->update(['primary_field_officer_id' => self::USER]);
            $scope = app(ReportScope::class);
            $this->assertSame([self::OWN], $scope->ids($this->actor(), ['municipality_id' => self::TOWN]));
            $this->forbidden(fn () => $scope->ids($this->actor(), ['municipality_id' => self::OTHER_TOWN]));
            DB::table('associations')->where('id', self::OWN)->update(['primary_field_officer_id' => self::OTHER]);
            $this->assertSame([], $scope->ids($this->actor(), []));
        }
    }

    private function request(): Request
    {
        $request = Request::create('/admin/reports');
        $request->attributes->set('current_user', $this->actor());

        return $request;
    }

    private function forbidden(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected reporting authorization denial.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_officer_scope_uses_current_assignment_and_cannot_expand_by_municipality(): void
    {
        $scope = app(ReportScope::class);
        $this->assertSame([self::OWN], $scope->ids($this->actor(), []));
        $this->forbidden(fn () => $scope->ids($this->actor(), ['municipality_id' => self::OTHER_TOWN]));
        $this->forbidden(fn () => $scope->ids($this->actor(), ['association_id' => self::OUTSIDE]));
        DB::table('associations')->where('id', self::OWN)->update(['primary_field_officer_id' => self::OTHER]);
        $this->assertSame([], $scope->ids($this->actor(), []));
    }

    public function test_reassignment_during_generation_prevents_data_release(): void
    {
        $scope = app(ReportScope::class);
        $request = $this->request();
        $ids = $scope->ids($this->actor(), []);
        DB::table('associations')->where('id', self::OWN)->update(['primary_field_officer_id' => self::OTHER]);
        $this->forbidden(fn () => $scope->recheck($request, [], $ids));
    }

    public function test_admin_retains_authorized_archived_association_visibility(): void
    {
        DB::table('user_profiles')->update(['role_id' => 2]);
        DB::table('associations')->where('id', self::OWN)->update(['archived_at' => '2026-01-01 00:00:00', 'status' => 'inactive']);
        $this->assertSame([self::OWN, self::OUTSIDE], app(ReportScope::class)->ids($this->actor(), []));
    }

    public function test_association_account_is_own_scope_and_cannot_export_before_engine_execution(): void
    {
        DB::table('user_profiles')->update(['role_id' => 3]);
        DB::table('association_accounts')->insert(['id' => self::OWN, 'user_id' => self::USER, 'association_id' => self::OWN, 'is_active' => true]);
        $scope = app(ReportScope::class);
        $this->assertSame([self::OWN], $scope->ids($this->actor(), []));
        $this->forbidden(fn () => $scope->ids($this->actor(), ['association_id' => self::OUTSIDE]));
        foreach (['pdf', 'xlsx'] as $format) {
            $this->forbidden(fn () => app(ReportService::class)->generate($this->request(), ['report_type' => 'members'], $format));
        }
        DB::table('associations')->where('id', self::OWN)->update(['archived_at' => '2026-01-01 00:00:00']);
        $this->forbidden(fn () => $scope->ids($this->actor(), []));
    }

    public function test_duplicate_links_and_role_revocation_fail_closed(): void
    {
        DB::table('user_profiles')->update(['role_id' => 3]);
        foreach ([self::OWN, self::OUTSIDE] as $id) {
            DB::table('association_accounts')->insert(['id' => $id, 'user_id' => self::USER, 'association_id' => $id, 'is_active' => true]);
        }
        $this->forbidden(fn () => app(ReportScope::class)->ids($this->actor(), []));
        $request = $this->request();
        DB::table('roles')->where('id', 3)->update(['is_active' => false]);
        $this->forbidden(fn () => app(ReportScope::class)->actor($request));
    }

    #[DataProvider('associationScopeGeographies')]
    public function test_association_scope_uses_one_eligibility_query_and_keeps_the_geographic_builder(bool $schemaReady, bool $direct): void
    {
        $this->webIdentity(3);
        if ($schemaReady) {
            Schema::table('associations', function (Blueprint $table) {
                $table->uuid('area_id')->nullable()->change(); $table->uuid('barangay_id')->nullable();
            });
            foreach ([[self::OWN, self::TOWN], [self::OUTSIDE, self::OTHER_TOWN]] as [$id, $barangay]) {
                DB::table('associations')->where('id', $id)->update(['area_id' => null, 'barangay_id' => $barangay]);
            }
        }
        config(['associations.geography_schema_ready' => $schemaReady, 'associations.direct_geography' => $direct]);
        $actor = $this->actor();
        $this->assertTrue($actor->canAccessApplication(throwOnDatabaseError: true));
        $connection = DB::connection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();
        try {
            $query = app(ReportScope::class)->associations($actor);
            $this->assertCount(1, $connection->getQueryLog());
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }

        $this->assertInstanceOf(Builder::class, $query);
        $this->assertTrue($query->useWritePdo);
        $association = $query->first(['a.id', 'ar.id as area_id', 'b.id as barangay_id', 'mu.id as municipality_id']);
        $this->assertNotNull($association);
        $this->assertSame(self::OWN, $association->id);
        $this->assertSame($schemaReady ? null : self::TOWN, $association->area_id);
        $this->assertSame(self::TOWN, $association->barangay_id);
        $this->assertSame(self::TOWN, $association->municipality_id);
        $this->assertArrayNotHasKey('association_link_count', (array) $association);
        $this->assertArrayNotHasKey('eligible_association_id', (array) $association);
        $this->assertFalse((clone $query)->where('a.id', self::OUTSIDE)->exists());
    }

    public static function associationScopeGeographies(): array
    {
        return [
            'legacy geography' => [false, false],
            'schema ready with optional Area disabled' => [true, false],
            'optional Area enabled' => [true, true],
        ];
    }

    #[DataProvider('associationScopeRestrictions')]
    public function test_association_scope_rechecks_current_links_and_parents_without_granting_historical_access(string $restriction): void
    {
        $this->webIdentity(3);
        $scope = app(ReportScope::class);
        $request = $this->request();
        $actor = $request->attributes->get('current_user');
        $ids = $scope->ids($actor, []);
        $this->assertSame([self::OWN], $ids);

        match ($restriction) {
            'missing_link' => DB::table('association_accounts')->where('user_id', self::USER)->delete(),
            'inactive_link' => DB::table('association_accounts')->where('user_id', self::USER)->update(['is_active' => false]),
            'duplicate_user_active', 'duplicate_user_inactive', 'duplicate_user_missing_parent' => DB::table('association_accounts')->insert([
                'id' => self::OUTSIDE, 'user_id' => self::USER,
                'association_id' => $restriction === 'duplicate_user_missing_parent' ? self::OTHER : self::OUTSIDE,
                'is_active' => $restriction !== 'duplicate_user_inactive',
            ]),
            'duplicate_association_active', 'duplicate_association_inactive' => DB::table('association_accounts')->insert([
                'id' => self::OUTSIDE, 'user_id' => self::OTHER, 'association_id' => self::OWN,
                'is_active' => $restriction === 'duplicate_association_active',
            ]),
            'missing_association' => DB::table('association_accounts')->where('user_id', self::USER)->update(['association_id' => self::OTHER]),
            'inactive_association' => DB::table('associations')->where('id', self::OWN)->update(['status' => 'inactive']),
            'archived_association' => DB::table('associations')->where('id', self::OWN)->update(['archived_at' => '2026-01-01 00:00:00']),
            'missing_area' => DB::table('areas')->where('id', self::TOWN)->delete(),
            'missing_barangay' => DB::table('barangays')->where('id', self::TOWN)->delete(),
            'missing_municipality' => DB::table('municipalities')->where('id', self::TOWN)->delete(),
        };

        if (in_array($restriction, ['duplicate_user_inactive', 'duplicate_association_inactive'], true)) {
            $scope->recheck($request, [], $ids);
            $this->assertSame($ids, $scope->ids($actor, []));
            return;
        }
        $this->forbidden(fn () => $scope->associations($actor));
        $this->forbidden(fn () => $scope->recheck($request, [], $ids));
    }

    public static function associationScopeRestrictions(): array
    {
        return array_map(fn ($case) => [$case], [
            'missing_link', 'inactive_link', 'duplicate_user_active', 'duplicate_user_inactive', 'duplicate_user_missing_parent',
            'duplicate_association_active', 'duplicate_association_inactive', 'missing_association',
            'inactive_association', 'archived_association', 'missing_area', 'missing_barangay', 'missing_municipality',
        ]);
    }

    private function webIdentity(int $role = 1): void
    {
        DB::table('user_profiles')->where('id', self::USER)->update(['role_id' => $role]);
        if ($role === 3) DB::table('association_accounts')->insert([
            'id'=>self::OWN,'user_id'=>self::USER,'association_id'=>self::OWN,'is_active'=>true,
        ]);
        $auth = Mockery::mock(SupabaseAuthService::class);
        $auth->shouldReceive('getUser')->andReturn(['id'=>self::USER]);
        $this->app->instance(SupabaseAuthService::class,$auth);
        $this->withSession(['supabase_access_token'=>'synthetic-local-report-token']);
        $this->withoutVite();
    }

    public function test_guest_report_data_and_downloads_never_reach_the_engine(): void
    {
        $engine = Mockery::mock(PythonReportEngine::class);
        $engine->shouldNotReceive('run');
        $this->app->instance(PythonReportEngine::class,$engine);
        foreach (['admin','field-officer','association'] as $prefix) {
            $this->get('/'.$prefix.'/reports')->assertRedirect(route('login'));
            $this->getJson('/'.$prefix.'/reports/data')->assertRedirect(route('login'));
            foreach (['pdf','xlsx'] as $format) $this->postJson('/'.$prefix.'/reports/export/'.$format)->assertRedirect(route('login'));
        }
        $this->getJson('/api/v1/association/reports')->assertUnauthorized();
        $this->getJson('/api/v1/association/reports/options')->assertUnauthorized();
    }

    public static function invalidFilters(): array
    {
        return [
            'array report type'=>[['report_type'=>['projects']]],
            'array year'=>[['year'=>[2026]]],
            'array association'=>[['association_id'=>[self::OWN]]],
            'invalid UUID'=>[['project_id'=>'invalid']],
            'unknown filter'=>[['date_from'=>'2026-01-01']],
            'unimplemented status filter'=>[['status'=>'terminated']],
            'outside year range'=>[['year'=>2101]],
            'invalid quarter'=>[['quarter'=>5]],
            'invalid month'=>[['report_type'=>'production','month'=>0]],
            'mismatched quarter and month'=>[['report_type'=>'production','quarter'=>1,'month'=>4]],
            'irrelevant month'=>[['report_type'=>'projects','month'=>1]],
            'invalid component'=>[['component_id'=>-1]],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filter_shapes_and_periods_are_422_without_python(array $filters): void
    {
        $this->webIdentity(2);
        $engine=Mockery::mock(PythonReportEngine::class);
        $engine->shouldNotReceive('run');
        $this->app->instance(PythonReportEngine::class,$engine);
        $this->getJson('/admin/reports/data?'.http_build_query($filters))->assertUnprocessable()
            ->assertHeader('X-Content-Type-Options','nosniff');
    }

    public function test_real_http_role_scope_and_account_download_denials(): void
    {
        $this->webIdentity();
        $engine=Mockery::mock(PythonReportEngine::class);
        $engine->shouldNotReceive('run');
        $this->app->instance(PythonReportEngine::class,$engine);
        $this->getJson('/admin/reports/data')->assertForbidden();
        $this->getJson('/field-officer/reports/data?association_id='.self::OUTSIDE)->assertForbidden();
        DB::table('user_profiles')->where('id',self::USER)->update(['role_id'=>3]);
        DB::table('association_accounts')->insert(['id'=>self::OWN,'user_id'=>self::USER,'association_id'=>self::OWN,'is_active'=>true]);
        $this->getJson('/association/reports/data?association_id='.self::OUTSIDE)->assertForbidden();
        foreach (['pdf','xlsx'] as $format) $this->postJson('/association/reports/export/'.$format)->assertForbidden();
        $this->postJson('/association/reports/export/csv')->assertNotFound();
    }

    public static function contextualExports(): array
    {
        $cases = [];
        foreach (['admin' => 2, 'field-officer' => 1] as $prefix => $role) {
            foreach (['associations', 'projects'] as $type) {
                foreach (['pdf', 'xlsx'] as $format) {
                    $cases[$prefix.'.'.$type.'.'.$format] = [$prefix, $role, $type, $format];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('contextualExports')]
    public function test_contextual_post_keeps_validated_filters_scope_and_binary_response(string $prefix, int $role, string $type, string $format): void
    {
        $this->webIdentity($role);
        $filters = ['report_type' => $type, 'association_id' => self::OWN];
        if ($type === 'projects') {
            $filters += ['project_id' => self::OWN, 'program_year' => 2026];
        }
        $artifact = $format === 'pdf' ? '%PDF-1.7 synthetic transport fixture' : 'PK synthetic transport fixture';
        $service = Mockery::mock(ReportService::class);
        $service->shouldReceive('generate')->once()->withArgs(function (Request $request, array $actual, string $action) use ($filters, $format) {
            $this->assertSame($filters, $actual);
            $scope = app(ReportScope::class);
            $this->assertSame([self::OWN], $scope->ids($scope->actor($request, true), $actual));

            return $action === $format;
        })->andReturn(['artifact' => $artifact]);
        $this->app->instance(ReportService::class, $service);
        $this->post('/'.$prefix.'/reports/export/'.$format, $filters)->assertOk()->assertContent($artifact)
            ->assertHeader('Content-Type', $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public static function contextualFormats(): array
    {
        return [['pdf'], ['xlsx']];
    }

    #[DataProvider('contextualFormats')]
    public function test_contextual_export_posts_reject_unsupported_filters_and_outside_associations_before_python(string $format): void
    {
        $this->webIdentity();
        $engine = Mockery::mock(PythonReportEngine::class);
        $engine->shouldNotReceive('run');
        $this->app->instance(PythonReportEngine::class, $engine);
        foreach ([['status' => 'active'], ['q' => 'search'], ['year' => 2026], ['program_year' => 1900], ['project_id' => 'invalid']] as $extra) {
            $this->postJson('/field-officer/reports/export/'.$format, ['report_type' => 'projects'] + $extra)->assertUnprocessable();
        }
        $this->postJson('/field-officer/reports/export/'.$format, ['report_type' => 'associations', 'association_id' => self::OUTSIDE])->assertForbidden();
    }

    public function test_contextual_single_project_extraction_cannot_cross_association_or_assignment_scope(): void
    {
        DB::unprepared('CREATE TABLE saad_components (id INTEGER PRIMARY KEY, name TEXT);
            CREATE TABLE saad_subcomponents (id INTEGER PRIMARY KEY, component_id INTEGER);
            CREATE TABLE saad_activities (id INTEGER PRIMARY KEY, subcomponent_id INTEGER);
            CREATE TABLE fisheries_project_categories (id INTEGER PRIMARY KEY, name TEXT);
            CREATE TABLE projects (id TEXT PRIMARY KEY, association_id TEXT, saad_activity_id INTEGER, fisheries_category_id INTEGER, title TEXT, program_year INTEGER, status TEXT, archived_at TEXT);');
        DB::table('saad_components')->insert(['id' => 1, 'name' => 'Synthetic component']);
        DB::table('saad_subcomponents')->insert(['id' => 1, 'component_id' => 1]);
        DB::table('saad_activities')->insert(['id' => 1, 'subcomponent_id' => 1]);
        DB::table('fisheries_project_categories')->insert(['id' => 1, 'name' => 'Synthetic category']);
        foreach ([self::OWN, self::OUTSIDE] as $id) {
            DB::table('projects')->insert([
                'id' => $id, 'association_id' => $id, 'saad_activity_id' => 1, 'fisheries_category_id' => 1,
                'title' => 'Synthetic project', 'program_year' => 2026, 'status' => 'ongoing',
            ]);
        }
        $actor = $this->actor();
        $dataset = app(ReportDataset::class);
        $filters = ['report_type' => 'projects', 'project_id' => self::OWN, 'program_year' => 2026];
        $this->assertSame([self::OWN], array_column($dataset->extract($actor, $filters, [self::OWN])['projects'], 'id'));
        $this->assertSame([], $dataset->extract($actor, array_replace($filters, ['program_year' => 2025]), [self::OWN])['projects']);
        $this->forbidden(fn () => $dataset->extract($actor, array_replace($filters, ['project_id' => self::OUTSIDE]), [self::OWN]));
    }

    public function test_contextual_exports_enforce_csrf_expiry_and_revocation_before_generation(): void
    {
        $this->webIdentity(2);
        $engine = Mockery::mock(PythonReportEngine::class);
        $engine->shouldNotReceive('run');
        $this->app->instance(PythonReportEngine::class, $engine);
        $csrf = Mockery::mock(ValidateCsrfToken::class, [$this->app, $this->app['encrypter']])
            ->makePartial()->shouldAllowMockingProtectedMethods();
        $csrf->shouldReceive('runningUnitTests')->andReturn(false);
        $this->app->instance(ValidateCsrfToken::class, $csrf);
        $this->postJson('/admin/reports/export/pdf', ['report_type' => 'associations'])->assertStatus(419);
        $token = session()->token();
        $service = Mockery::mock(ReportService::class);
        $service->shouldReceive('generate')->once()->andReturn(['artifact' => '%PDF synthetic CSRF transport fixture']);
        $this->app->instance(ReportService::class, $service);
        $this->post('/admin/reports/export/pdf', ['report_type' => 'associations', '_token' => $token])->assertOk();
        DB::table('user_profiles')->where('id', self::USER)->update(['is_active' => false]);
        $this->postJson('/admin/reports/export/xlsx', ['report_type' => 'associations', '_token' => $token])->assertRedirect(route('login'))
            ->assertSessionMissing('supabase_access_token');
        $auth = Mockery::mock(SupabaseAuthService::class);
        $auth->shouldReceive('getUser')->andReturn(null);
        $this->app->instance(SupabaseAuthService::class, $auth);
        $this->withSession(['supabase_access_token' => 'expired-synthetic-session']);
        $this->postJson('/admin/reports/export/xlsx', ['report_type' => 'associations', '_token' => session()->token()])->assertRedirect(route('login'));
    }

    public static function reportRoles(): array
    {
        return [['admin',2,true],['field-officer',1,true],['association',3,false]];
    }

    public function test_preview_requests_do_not_consume_the_download_rate_limit(): void
    {
        $this->webIdentity(2);
        $service=Mockery::mock(ReportService::class);
        $service->shouldReceive('generate')->andReturn(['result'=>[],'artifact'=>'%PDF-1.7 synthetic route response']);
        $this->app->instance(ReportService::class,$service);
        for ($index=0;$index<7;$index++) $this->getJson('/admin/reports/data')->assertOk();
        $this->post('/admin/reports/export/pdf')->assertOk()->assertHeader('Content-Type','application/pdf');
    }

    public function test_downloads_still_enforce_their_own_six_request_limit(): void
    {
        $this->webIdentity(2);
        $service=Mockery::mock(ReportService::class);
        $service->shouldReceive('generate')->times(6)->andReturn(['result'=>[],'artifact'=>'%PDF-1.7 synthetic route response']);
        $this->app->instance(ReportService::class,$service);
        for ($index=0;$index<6;$index++) $this->post('/admin/reports/export/pdf')->assertOk();
        $this->postJson('/admin/reports/export/pdf')->assertStatus(429);
    }

    public function test_mobile_reports_use_bearer_eligibility_and_deny_cross_association_scope(): void
    {
        $this->webIdentity(3);
        $auth=$this->app->make(SupabaseAuthService::class);
        $auth->shouldReceive('verifyApiIdentity')->andReturn(['outcome'=>'verified',
            'identity'=>['id'=>self::USER,'email'=>'synthetic-report@example.test']]);
        $this->withHeader('Authorization','Bearer synthetic-local-api-token');
        $this->getJson('/api/v1/association/reports?association_id='.self::OUTSIDE)->assertForbidden();
        $service=Mockery::mock(ReportService::class);
        $service->shouldReceive('generate')->once()->andReturn(['result'=>['version'=>'assocmap.reports.v1'],'artifact'=>null]);
        $service->shouldReceive('options')->once()->andReturn(['years'=>[2026],'program_years'=>[2026]]);
        $this->app->instance(ReportService::class,$service);
        $this->getJson('/api/v1/association/reports')->assertOk()->assertJsonPath('data.version','assocmap.reports.v1');
        $this->getJson('/api/v1/association/reports/options')->assertOk()->assertJsonPath('data.years.0',2026);
        DB::table('association_accounts')->update(['is_active'=>false]);
        $this->getJson('/api/v1/association/reports')->assertForbidden();
        DB::table('user_profiles')->where('id',self::USER)->update(['role_id'=>1]);
        $this->getJson('/api/v1/association/reports')->assertForbidden();
    }

    public function test_python_contract_failure_returns_generic_503_without_process_details(): void
    {
        $this->webIdentity(2);
        $service=Mockery::mock(ReportService::class);
        $service->shouldReceive('generate')->once()->andThrow(new ReportingUnavailableException(
            'input_contract_failed','input_contract','activities','status'));
        $this->app->instance(ReportService::class,$service);
        $this->getJson('/admin/reports/data')->assertStatus(503)
            ->assertHeader('X-Content-Type-Options','nosniff')
            ->assertDontSee('Traceback')->assertDontSee('input_contract_failed')->assertDontSee('activities');
    }

    #[DataProvider('reportRoles')]
    public function test_role_report_pages_render_escaped_labels_and_correct_export_controls(string $prefix,int $role,bool $export): void
    {
        $this->webIdentity($role);
        $label='<script>synthetic-report-label</script>';
        $result=['version'=>'assocmap.reports.v1','title'=>$label,'empty'=>true,'notes'=>[], 'sections'=>[],
            'meta'=>['filters'=>['report_type'=>'comprehensive','year'=>(int)now('Asia/Manila')->format('Y')],
                'generated_at'=>now('Asia/Manila')->toIso8601String(),'timezone'=>'Asia/Manila','currency'=>'PHP',
                'scope'=>['Synthetic association'],'scope_basis'=>'Synthetic authorized fixture']];
        $options=array_fill_keys(['municipalities','associations','projects','products','components','program_years'],[]);
        $options['years']=[(int)now('Asia/Manila')->format('Y')];
        $service=Mockery::mock(ReportService::class);
        $service->shouldReceive('options')->once()->andReturn($options);
        $service->shouldReceive('generate')->once()->andReturn(['result'=>$result,'artifact'=>null]);
        $this->app->instance(ReportService::class,$service);
        $response=$this->get('/'.$prefix.'/reports')->assertOk()->assertSee($label)->assertDontSee($label,false);
        $export ? $response->assertSee('Export PDF')->assertSee('Export XLSX') : $response->assertDontSee('Export PDF')->assertDontSee('Export XLSX');
    }

    private function uiResult(array $filters): array
    {
        return ['version' => ReportCatalog::VERSION, 'title' => ReportCatalog::TYPES[$filters['report_type']],
            'empty' => false, 'notes' => ['Synthetic interpretation note'], 'sections' => [],
            'meta' => ['filters' => $filters, 'generated_at' => now('Asia/Manila')->toIso8601String(),
                'timezone' => 'Asia/Manila', 'currency' => 'PHP', 'scope' => ['Synthetic association'], 'scope_basis' => 'Synthetic authorized scope']];
    }

    private function mockUiReport(array $result, bool $unavailable = false): void
    {
        $options = array_fill_keys(['municipalities', 'associations', 'projects', 'products', 'components', 'program_years'], []);
        $options['years'] = [(int) now('Asia/Manila')->format('Y'), 2024];
        $service = Mockery::mock(ReportService::class);
        $service->shouldReceive('options')->once()->andReturn($options);
        $generation = $service->shouldReceive('generate')->once();
        $unavailable ? $generation->andThrow(new ReportingUnavailableException('synthetic_unavailable'))
            : $generation->andReturn(['result' => $result, 'artifact' => null]);
        $this->app->instance(ReportService::class, $service);
    }

    private function htmlQuery(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($document);
    }

    #[DataProvider('reportRoles')]
    public function test_report_toolbar_groups_supported_actions_and_preserves_get_post_contracts(string $prefix, int $role, bool $export): void
    {
        $this->webIdentity($role);
        $filters = ['report_type' => 'comprehensive', 'year' => 2024, 'quarter' => 2];
        $this->mockUiReport($this->uiResult($filters));
        $html = $this->get('/'.$prefix.'/reports?'.http_build_query($filters))->assertOk()->getContent();
        $query = $this->htmlQuery($html);
        $this->assertSame(1, $query->query('//header//div[@aria-label="Report actions"]/button[@data-report-filter-toggle]')->length);
        $this->assertSame('report-filter-panel', $query->query('//button[@data-report-filter-toggle]')->item(0)->getAttribute('aria-controls'));
        $this->assertSame('2', $query->query('//*[@data-report-filter-count]')->item(0)->textContent);
        $this->assertSame(1, $query->query('//*[@id="report-filter-panel" and not(@hidden)]')->length, 'Filters remain usable without JavaScript.');
        $this->assertSame(1, $query->query('//form[@data-report-form and @method="GET"]')->length);
        $this->assertSame('2024', $query->query('//select[@name="year"]/option[@selected]')->item(0)->getAttribute('value'));
        $this->assertSame('2', $query->query('//select[@name="quarter"]/option[@selected]')->item(0)->getAttribute('value'));
        $this->assertSame(1, $query->query('//a[@href="'.url('/'.$prefix.'/reports').'" and contains(., "Reset filters")]')->length);
        $this->assertSame((int) $export, $query->query('//header//details[@data-report-export-menu]/form[@method="POST"]')->length);
        if ($export) {
            $this->assertSame(1, $query->query('//form[@data-report-export]/input[@name="_token"]')->length);
            foreach ($filters as $name => $value) {
                $this->assertSame((string) $value, $query->query('//*[@data-export-filters]/input[@name="'.$name.'"]')->item(0)->getAttribute('value'));
            }
            foreach (['pdf', 'xlsx'] as $format) {
                $this->assertSame(1, $query->query('//form[@data-report-export]/button[@formaction="'.url('/'.$prefix.'/reports/export/'.$format).'" and not(@disabled)]')->length);
            }
        } else {
            $this->assertSame(0, $query->query('//select[@name="association_id" or @name="municipality_id"]')->length);
        }
    }

    #[DataProvider('reportRoles')]
    public function test_report_organization_retains_scoped_no_script_summary_notes_and_data(string $prefix, int $role, bool $export): void
    {
        $this->webIdentity($role);
        $result = $this->uiResult(['report_type' => 'projects', 'program_year' => 2026]);
        $result['sections'] = [[
            'key' => 'projects', 'title' => 'Retained project portfolio',
            'measures' => [['label' => 'Recorded zero', 'value' => 0], ['label' => 'Unobserved', 'value' => null]],
            'notes' => ['Archived records retained; current statuses are not historical trends.'], 'chart' => [],
            'columns' => [['key' => 'status', 'label' => 'Current status', 'type' => 'text']],
            'rows' => [['status' => 'completed']],
        ]];
        $this->mockUiReport($result);
        $html = $this->get('/'.$prefix.'/reports?report_type=projects&program_year=2026')->assertOk()->getContent();
        $query = $this->htmlQuery($html);
        foreach (['Reporting scope and period', 'Summary indicators', 'Visual analytics', 'Data interpretation and quality', 'Detailed data tables'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertSame(1, $query->query('//*[@id="report-scope"]')->length);
        $this->assertSame('0', trim($query->query('//section[contains(@class,"report-summary")]//dd[1]')->item(0)->textContent));
        $this->assertSame('Unobserved', trim($query->query('//section[contains(@class,"report-summary")]//div[2]/dd')->item(0)->textContent));
        $this->assertSame('completed', $query->query('//*[@id="report-table-projects"]//tbody/tr/td')->item(0)->textContent);
        $this->assertStringContainsString('Synthetic authorized scope', $html);
        $this->assertStringContainsString('Synthetic association', $html);
        $this->assertStringContainsString('program year: 2026', $html);
        $this->assertStringContainsString('Enable JavaScript for charts', $html);
        $this->assertStringContainsString('chart images are shown on this page', $html);
        $this->assertSame($export ? 2 : 0, $query->query('//form[@data-report-export]/button')->length);
    }

    public static function reportTypes(): array
    {
        return array_map(fn ($type) => [$type], array_keys(ReportCatalog::TYPES));
    }

    #[DataProvider('reportTypes')]
    public function test_every_existing_report_keeps_only_its_supported_filter_controls_enabled(string $type): void
    {
        $this->webIdentity(2);
        $filters = ['report_type' => $type];
        if (in_array('year', ReportCatalog::filters($type), true)) $filters['year'] = (int) now('Asia/Manila')->format('Y');
        $this->mockUiReport($this->uiResult($filters));
        $query = $this->htmlQuery($this->get('/admin/reports?report_type='.$type)->assertOk()->getContent());
        foreach (['program_year', 'year', 'quarter', 'month', 'project_id', 'product_id', 'component_id'] as $name) {
            $select = $query->query('//select[@name="'.$name.'"]')->item(0);
            $this->assertSame(!in_array($name, ReportCatalog::filters($type), true), $select->hasAttribute('disabled'), $type.': '.$name);
        }
        $this->assertSame(0, $query->query('//select[@name="sort"] | //input[@name="search"]')->length);
    }

    public function test_report_tables_keep_exact_zero_null_text_and_no_script_row_fallback(): void
    {
        $this->webIdentity(2);
        $result = $this->uiResult(['report_type' => 'income', 'year' => (int) now('Asia/Manila')->format('Y')]);
        $result['sections'] = [['title' => 'Synthetic income', 'measures' => [['label' => 'Recorded zero', 'value' => '0.00']],
            'notes' => [], 'chart' => [], 'columns' => [['key' => 'label', 'label' => 'Association', 'type' => 'text'],
                ['key' => 'gross', 'label' => 'Recorded gross income (PHP)', 'type' => 'decimal']],
            'rows' => array_merge([['label' => '<script>unsafe</script>', 'gross' => '0.00'], ['label' => 'Missing', 'gross' => null]],
                array_fill(0, 99, ['label' => 'Retained history', 'gross' => '1234567890.12']))]];
        $this->mockUiReport($result);
        $response = $this->get('/admin/reports?report_type=income')->assertOk()->assertSee('<script>unsafe</script>')->assertDontSee('<script>unsafe</script>', false)
            ->assertSee('0.00')->assertSee('Unobserved / N/A')->assertSee('1234567890.12')->assertSee('Showing the first 100 rows');
        $query = $this->htmlQuery($response->getContent());
        $this->assertSame(100, $query->query('//div[@role="region"]/table/tbody/tr')->length);
        $this->assertSame(100, $query->query('//tbody/tr/td[@class="report-numeric"]')->length);
        $this->assertSame('0.00', $query->query('//tbody/tr[1]/td[2]')->item(0)->textContent);
        $this->assertSame('Unobserved / N/A', $query->query('//tbody/tr[2]/td[2]')->item(0)->textContent);
    }

    public function test_unavailable_report_keeps_filters_and_disables_both_existing_exports(): void
    {
        $this->webIdentity(2);
        $this->mockUiReport($this->uiResult(['report_type' => 'comprehensive']), true);
        $query = $this->htmlQuery($this->get('/admin/reports')->assertOk()->assertSee('Reporting is temporarily unavailable.')->getContent());
        $this->assertSame(2, $query->query('//form[@data-report-export]/button[@disabled]')->length);
        $this->assertSame(1, $query->query('//button[@data-report-submit and not(@disabled)]')->length);
    }

    public static function datasetSizeLimits(): array
    {
        return ['dataset limit' => [1, 20000], 'total limit' => [5000, 1]];
    }

    #[DataProvider('datasetSizeLimits')]
    public function test_dataset_size_limits_raise_the_recoverable_validation_exception(int $rows, int $total): void
    {
        DB::table('user_profiles')->where('id', self::USER)->update(['role_id' => 2]);
        config(['reporting.max_rows' => $rows, 'reporting.max_total_rows' => $total]);
        $actor = $this->actor();
        $scope = app(ReportScope::class);
        try {
            app(ReportDataset::class)->extract($actor, ['report_type' => 'associations'], $scope->ids($actor, []));
            $this->fail('Expected a report dataset size rejection.');
        } catch (ReportDatasetLimitException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertArrayHasKey('filters', $exception->errors());
        }
        $this->assertSame(2, DB::table('associations')->count());
    }

    private function recoveryOptions(): array
    {
        $options = array_fill_keys(['municipalities', 'associations', 'projects', 'products', 'components', 'program_years'], []);
        $options['years'] = [2024];
        $options['municipalities'] = [['id' => self::TOWN, 'name' => 'Synthetic authorized municipality']];
        $options['associations'] = [['id' => self::OWN, 'name' => 'Synthetic authorized association', 'municipality_id' => self::TOWN]];

        return $options;
    }

    #[DataProvider('reportRoles')]
    public function test_oversized_initial_report_retains_filters_and_disables_exports(string $prefix, int $role, bool $export): void
    {
        $this->webIdentity($role);
        $filters = ['report_type' => 'comprehensive', 'year' => 2024, 'quarter' => 2,
            'municipality_id' => self::TOWN, 'association_id' => self::OWN];
        $service = Mockery::mock(ReportService::class);
        $service->shouldReceive('options')->twice()->andReturn($this->recoveryOptions());
        $service->shouldReceive('generate')->once()->andThrow(ReportDatasetLimitException::withMessages([
            'filters' => 'Synthetic internal dataset detail that must not appear in the page.',
        ]));
        $this->app->instance(ReportService::class, $service);

        $response = $this->get('/'.$prefix.'/reports?'.http_build_query($filters))->assertUnprocessable()
            ->assertViewIs('reports.page')->assertViewHas('result', fn ($result) => $result === null)
            ->assertSee('This report is too large.')->assertDontSee('Synthetic internal dataset detail');
        $query = $this->htmlQuery($response->getContent());
        $this->assertSame(1, $query->query('//form[@data-report-form and @method="GET"]')->length);
        $this->assertSame(1, $query->query('//button[@data-report-submit and not(@disabled)]')->length);
        $this->assertSame(1, $query->query('//*[@data-report-status and @role="alert"]')->length);
        $this->assertSame('2024', $query->query('//select[@name="year"]/option[@selected]')->item(0)->getAttribute('value'));
        $this->assertSame('2', $query->query('//select[@name="quarter"]/option[@selected]')->item(0)->getAttribute('value'));
        $this->assertSame($export ? 2 : 0, $query->query('//form[@data-report-export]/button[@disabled]')->length);
        $this->assertSame((int) $export, $query->query('//form[@data-report-export]')->length);
        if ($export) {
            $this->assertSame(self::OWN, $query->query('//select[@name="association_id"]/option[@selected]')->item(0)->getAttribute('value'));
        } else {
            $this->assertSame(0, $query->query('//select[@name="association_id" or @name="municipality_id"]')->length);
        }
        $response->assertDontSee(self::OUTSIDE)->assertDontSee(self::OTHER_TOWN);
    }

    public function test_oversized_initial_report_can_be_narrowed_and_loaded_again(): void
    {
        $this->webIdentity(2);
        $filters = ['report_type' => 'comprehensive', 'year' => 2024];
        $narrowed = $filters + ['association_id' => self::OWN];
        $service = Mockery::mock(ReportService::class);
        $service->shouldReceive('options')->twice()->andReturn($this->recoveryOptions());
        $service->shouldReceive('generate')->once()
            ->withArgs(fn (Request $request, array $selection) => ! isset($selection['association_id']))
            ->andThrow(ReportDatasetLimitException::withMessages(['filters' => 'Select a smaller report.']));
        $service->shouldReceive('generate')->once()
            ->withArgs(fn (Request $request, array $selection) => $selection == $narrowed)
            ->andReturn(['result' => $this->uiResult($narrowed), 'artifact' => null]);
        $this->app->instance(ReportService::class, $service);

        $this->get('/admin/reports?'.http_build_query($filters))->assertUnprocessable()->assertViewIs('reports.page');
        $this->getJson('/admin/reports/data?'.http_build_query($narrowed))->assertOk()
            ->assertJsonPath('data.meta.filters.association_id', self::OWN);
    }

    public function test_size_recovery_denies_filters_when_authorization_is_revoked(): void
    {
        $this->webIdentity(2);
        $reads = 0;
        $service = Mockery::mock(ReportService::class);
        $service->shouldReceive('options')->twice()->andReturnUsing(function () use (&$reads) {
            abort_if(++$reads === 2, 403);

            return $this->recoveryOptions();
        });
        $service->shouldReceive('generate')->once()
            ->andThrow(ReportDatasetLimitException::withMessages(['filters' => 'Select a smaller report.']));
        $this->app->instance(ReportService::class, $service);

        $this->get('/admin/reports')->assertForbidden()->assertViewIs('reports.error')->assertDontSee('data-report-form', false);
    }

    public static function nonRecoverableReportStatuses(): array
    {
        return [[403], [419], [422]];
    }

    #[DataProvider('nonRecoverableReportStatuses')]
    public function test_non_size_report_errors_keep_existing_error_handling(int $status): void
    {
        $this->webIdentity(2);
        $failure = $status === 422
            ? ValidationException::withMessages(['component_id' => 'Choose a recorded program component.'])
            : new HttpException($status, 'Synthetic internal exception detail.');
        $service = Mockery::mock(ReportService::class);
        $service->shouldReceive('options')->once()->andReturn($this->recoveryOptions());
        $service->shouldReceive('generate')->once()->andThrow($failure);
        $this->app->instance(ReportService::class, $service);

        $this->get('/admin/reports')->assertStatus($status)->assertViewIs('reports.error')
            ->assertDontSee('data-report-form', false)->assertDontSee('Synthetic internal exception detail.');
    }

    public function test_size_rejections_keep_json_and_export_validation_responses(): void
    {
        $this->webIdentity(2);
        $service = Mockery::mock(ReportService::class);
        $service->shouldReceive('options')->once()->andReturn($this->recoveryOptions());
        $service->shouldReceive('generate')->times(4)
            ->andThrow(ReportDatasetLimitException::withMessages(['filters' => 'Select a smaller report.']));
        $this->app->instance(ReportService::class, $service);

        foreach (['/admin/reports', '/admin/reports/data'] as $url) {
            $this->getJson($url)->assertUnprocessable()->assertJsonValidationErrors('filters')
                ->assertJsonPath('error.code', 'reporting_request_failed');
        }
        foreach (['pdf', 'xlsx'] as $format) {
            $this->postJson('/admin/reports/export/'.$format)->assertUnprocessable()
                ->assertJsonValidationErrors('filters')->assertJsonPath('error.code', 'reporting_request_failed');
        }
    }
}
