<?php

namespace Tests\Feature;

use App\Models\UserProfile;
use App\Services\SupabaseAuthService;
use App\Exceptions\ReportingUnavailableException;
use App\Services\Reporting\PythonReportEngine;
use App\Services\Reporting\ReportScope;
use App\Services\Reporting\ReportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
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
        config(['database.connections.report_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('report_test');
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        Schema::create('roles', function (Blueprint $table) {
            $table->id(); $table->string('code'); $table->string('name'); $table->boolean('is_active'); $table->timestamps();
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
}
