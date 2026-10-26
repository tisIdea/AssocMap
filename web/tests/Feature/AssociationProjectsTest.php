<?php

namespace Tests\Feature;

use App\Services\Auth\SupabaseAuthService;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedDatabaseManager;
use Tests\TestCase;

class AssociationProjectsTest extends TestCase
{
    private string $identity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertInstanceOf(IsolatedDatabaseManager::class, DB::getFacadeRoot());
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        config(['app.key' => 'base64:'.base64_encode(str_repeat('p', 32)), 'app.debug' => false,
            'associations.geography_schema_ready' => true, 'associations.direct_geography' => true]);
        $this->withoutVite();
        $this->schema();
        $this->fixtures();
        $this->identity = $this->id(1);
        $auth = Mockery::mock(SupabaseAuthService::class);
        $auth->shouldReceive('getUser')->andReturnUsing(fn () => ['id' => $this->identity, 'email' => 'synthetic@example.test']);
        $this->app->instance(SupabaseAuthService::class, $auth);
        $this->withSession(['supabase_access_token' => 'synthetic-project-session']);
    }

    protected function tearDown(): void
    {
        try { Http::assertNothingSent(); }
        finally { parent::tearDown(); }
    }

    private function id(int $number): string
    {
        return sprintf('99999999-9999-4999-8999-%012d', $number);
    }

    private function schema(): void
    {
        Schema::create('roles', function (Blueprint $table) { $table->id(); $table->string('code'); $table->boolean('is_active'); $table->string('name')->nullable(); $table->text('description')->nullable(); $table->timestamps(); });
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->integer('role_id'); $table->string('display_name');
            $table->boolean('is_active'); $table->timestamp('activated_at')->nullable(); $table->timestamps();
        });
        Schema::create('user_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('auth_user_id')->nullable(); $table->string('email');
            $table->string('status'); $table->string('last_error_code')->nullable(); $table->timestamp('accepted_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable(); $table->timestamps();
        });
        Schema::create('municipalities', function (Blueprint $table) { $table->id(); $table->string('name'); });
        Schema::create('barangays', function (Blueprint $table) { $table->id(); $table->integer('municipality_id'); $table->string('name'); });
        Schema::create('areas', function (Blueprint $table) { $table->id(); $table->integer('barangay_id'); $table->string('address'); });
        Schema::create('associations', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->string('name'); $table->integer('barangay_id'); $table->integer('area_id')->nullable();
            $table->uuid('primary_field_officer_id'); $table->string('status'); $table->timestamp('archived_at')->nullable();
        });
        // Missing uniqueness constraints allow inconsistent-link denial to be exercised.
        Schema::create('association_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('user_id'); $table->uuid('association_id'); $table->boolean('is_active');
        });
        foreach (['saad_activities', 'fisheries_project_categories', 'commodities'] as $name) {
            Schema::create($name, function (Blueprint $table) { $table->id(); $table->string('name'); });
        }
        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('association_id'); $table->integer('saad_activity_id')->default(1);
            $table->integer('fisheries_category_id')->default(1); $table->integer('commodity_id')->nullable();
            $table->string('title'); $table->text('description')->nullable(); $table->integer('program_year')->default(2026);
            $table->date('implementation_start_date')->nullable(); $table->date('implementation_end_date')->nullable();
            $table->string('status')->default('ongoing'); $table->timestamp('archived_at')->nullable(); $table->timestamp('closed_at')->nullable();
            $table->text('closure_remarks')->nullable(); $table->timestamps();
        });
        Schema::create('project_products', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('project_id'); $table->string('product_name'); $table->string('product_type')->default('raw');
            $table->string('default_unit')->nullable(); $table->text('description')->nullable(); $table->boolean('is_active')->default(true);
        });
        Schema::create('project_materials', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('project_id'); $table->string('item_name');
            $table->decimal('quantity_planned', 14, 2)->default(0); $table->decimal('quantity_delivered', 14, 2)->default(0);
            $table->string('unit'); $table->string('delivery_status')->default('planned'); $table->date('delivery_date')->nullable();
        });
        Schema::create('quarters', function (Blueprint $table) { $table->id(); $table->integer('quarter_number'); });
        Schema::create('monitoring_records', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('project_id'); $table->integer('year'); $table->integer('quarter_id');
            $table->date('monitoring_date'); $table->string('project_status'); $table->boolean('site_validated')->default(false);
            $table->uuid('monitored_by')->nullable(); $table->text('issues_encountered')->nullable();
            $table->text('actions_taken')->nullable(); $table->text('overall_remarks')->nullable(); $table->timestamps();
        });
    }

    private function fixtures(): void
    {
        foreach ([1 => 'association_account', 2 => 'system_admin', 3 => 'field_officer'] as $id => $role) {
            DB::table('roles')->insert(['id' => $id, 'code' => $role, 'is_active' => true]);
        }
        foreach ([1 => 1, 2 => 1, 3 => 2, 4 => 3] as $id => $role) {
            DB::table('user_profiles')->insert(['id' => $this->id($id), 'role_id' => $role,
                'display_name' => 'Synthetic account '.$id, 'is_active' => true, 'activated_at' => now()]);
        }
        DB::table('municipalities')->insert(['id' => 1, 'name' => 'Synthetic municipality']);
        DB::table('barangays')->insert(['id' => 1, 'municipality_id' => 1, 'name' => 'Synthetic barangay']);
        DB::table('areas')->insert(['id' => 1, 'barangay_id' => 1, 'address' => 'Synthetic Area reference']);
        foreach ([10 => 'Own association', 11 => 'Foreign association'] as $id => $name) {
            DB::table('associations')->insert(['id' => $this->id($id), 'name' => $name, 'barangay_id' => 1,
                'area_id' => null, 'primary_field_officer_id' => $this->id(4), 'status' => 'active']);
        }
        foreach ([1 => 10, 2 => 11] as $user => $association) {
            DB::table('association_accounts')->insert(['id' => $this->id(20 + $user), 'user_id' => $this->id($user),
                'association_id' => $this->id($association), 'is_active' => true]);
        }
        foreach (['saad_activities' => 'Synthetic program', 'fisheries_project_categories' => 'Synthetic category', 'commodities' => 'Synthetic commodity'] as $table => $name) {
            DB::table($table)->insert(['id' => 1, 'name' => $name]);
        }
        $this->project(101, ['title' => 'With Monitoring', 'commodity_id' => 1, 'description' => '<b>Escaped description</b>', 'closure_remarks' => 'Private closure text']);
        $this->project(102, ['title' => 'Without Monitoring']);
        $this->project(103, ['title' => 'Archived own project', 'archived_at' => '2026-01-01']);
        $this->project(104, ['title' => 'Foreign project', 'association_id' => $this->id(11)]);
        foreach ([101 => 'Own', 104 => 'Foreign'] as $project => $label) {
            DB::table('project_products')->insert(['id' => $this->id(200 + $project), 'project_id' => $this->id($project), 'product_name' => $label.' product']);
            DB::table('project_materials')->insert(['id' => $this->id(300 + $project), 'project_id' => $this->id($project), 'item_name' => $label.' assistance', 'unit' => 'pieces']);
        }
        foreach ([1, 2, 3, 4] as $quarter) DB::table('quarters')->insert(['id' => $quarter, 'quarter_number' => $quarter]);
        foreach ([101, 104] as $project) {
            DB::table('monitoring_records')->insert(['id' => $this->id(400 + $project), 'project_id' => $this->id($project), 'year' => 2026,
                'quarter_id' => 1, 'monitoring_date' => '2026-01-15', 'project_status' => 'planned', 'created_at' => '2026-01-15']);
        }
    }

    private function project(int $id, array $changes = []): void
    {
        DB::table('projects')->insert(array_replace(['id' => $this->id($id), 'association_id' => $this->id(10),
            'title' => 'Synthetic project '.$id], $changes));
    }

    public function test_directory_includes_projects_without_monitoring_and_archives_but_ignores_client_ownership(): void
    {
        $page = $this->get('/association/projects?association_id='.$this->id(11))->assertOk()
            ->assertSee('With Monitoring')->assertSee('Without Monitoring')->assertSee('Archived own project')->assertDontSee('Foreign project');
        $this->assertSame(3, $page->viewData('projects')->total());
        $this->assertStringContainsString('no-store', $page->headers->get('Cache-Control'));
    }

    public function test_area_less_details_are_scoped_escape_text_and_preserve_zero_and_missing_values(): void
    {
        $before = DB::table('projects')->orderBy('id')->get()->toJson();
        $page = $this->get('/association/projects/'.$this->id(101))->assertOk()->assertSee('Synthetic barangay')
            ->assertSee('Synthetic municipality')->assertSee('No Area assigned')->assertSee('Own product')->assertSee('Own assistance')
            ->assertSee('0.00')->assertSee('Not recorded')->assertDontSee('Foreign project')->assertDontSee('Foreign product')
            ->assertDontSee('Foreign assistance')->assertDontSee('Private closure text')->assertDontSee('<b>Escaped description</b>', false)
            ->assertSee('<b>Escaped description</b>')->assertSee(route('association.monitoring.visits', $this->id(501)), false);
        foreach (['overview', 'production', 'income', 'materials'] as $section) {
            $parameters = $section === 'overview' ? [$this->id(501)] : [$this->id(501), $section];
            $page->assertSee(route('association.monitoring.workspace', $parameters), false);
        }
        $dom = new DOMDocument();
        @$dom->loadHTML($page->getContent());
        $this->assertSame(0, (new DOMXPath($dom))->query('//*[@data-association-projects]//form')->length);
        $this->assertSame($before, DB::table('projects')->orderBy('id')->get()->toJson());
    }

    public function test_empty_products_assistance_and_monitoring_have_explicit_empty_states(): void
    {
        $this->get('/association/projects/'.$this->id(102))->assertOk()->assertSee('No products recorded for this project.')
            ->assertSee('No assistance / materials recorded for this project.')->assertSee('No Monitoring records recorded for this project.');
    }

    public function test_an_association_without_projects_has_an_empty_directory_and_guests_are_redirected(): void
    {
        DB::table('associations')->insert(['id' => $this->id(12), 'name' => 'Empty association', 'barangay_id' => 1,
            'area_id' => null, 'primary_field_officer_id' => $this->id(4), 'status' => 'active']);
        DB::table('association_accounts')->where('user_id', $this->id(1))->update(['association_id' => $this->id(12)]);
        $page = $this->get('/association/projects')->assertOk()->assertSee('Empty association')->assertSee('No projects on this page');
        $this->assertSame(0, $page->viewData('projects')->total());
        $this->app['session.store']->forget('supabase_access_token');
        $this->get('/association/projects')->assertRedirect(route('login'));
    }

    public function test_directory_filters_are_literal_and_paginate_twenty_five_projects(): void
    {
        for ($id = 1; $id <= 27; $id++) $this->project(1000 + $id, ['title' => sprintf('Match %02d', $id), 'program_year' => 2025, 'status' => 'planned']);
        $page = $this->get('/association/projects?search=Match&year=2025&status=planned')->assertOk();
        $this->assertSame(27, $page->viewData('projects')->total());
        $this->assertCount(25, $page->viewData('projects'));
        $next = $page->viewData('projects')->nextPageUrl();
        parse_str(parse_url($next, PHP_URL_QUERY), $query);
        $this->assertSame(['search' => 'Match', 'year' => '2025', 'status' => 'planned', 'page' => '2'], $query);
        $this->assertCount(2, $this->get($next)->assertOk()->viewData('projects'));
        $this->project(1100, ['title' => 'Literal 100%_! project']);
        $this->assertSame(1, $this->get('/association/projects?'.http_build_query(['search' => '100%_!']))->assertOk()->viewData('projects')->total());
        $this->assertSame(0, $this->get('/association/projects?'.http_build_query(['search' => "' OR 1=1 --"]))->assertOk()->viewData('projects')->total());
        $this->assertSame(1, $this->get('/association/projects?status=archived')->assertOk()->viewData('projects')->total());
    }

    public function test_each_detail_register_is_bounded_and_has_independent_pagination(): void
    {
        for ($number = 1; $number <= 26; $number++) {
            DB::table('project_products')->insert(['id' => $this->id(2000 + $number), 'project_id' => $this->id(101), 'product_name' => 'Product '.$number]);
            DB::table('project_materials')->insert(['id' => $this->id(3000 + $number), 'project_id' => $this->id(101), 'item_name' => 'Material '.$number, 'unit' => 'pieces']);
            DB::table('monitoring_records')->insert(['id' => $this->id(4000 + $number), 'project_id' => $this->id(101), 'year' => 2000 + $number,
                'quarter_id' => 2, 'monitoring_date' => (2000 + $number).'-04-15', 'project_status' => 'ongoing']);
        }
        $origin = '/association/projects?search=With&year=2026&page=1';
        $page = $this->get('/association/projects/'.$this->id(101).'?'.http_build_query(['products_page' => 2, 'monitoring_page' => 2, 'association_id' => $this->id(11), 'return_to' => $origin]))->assertOk();
        $page->assertSee('href="'.e(url($origin)).'" data-workflow-back', false);
        $this->assertCount(2, $page->viewData('products'));
        $this->assertCount(25, $page->viewData('materials'));
        $this->assertCount(2, $page->viewData('monitoring'));
        $next = $page->viewData('materials')->nextPageUrl();
        $this->assertStringContainsString('products_page=2', $next);
        $this->assertStringContainsString('monitoring_page=2', $next);
        $this->assertStringContainsString('#project-assistance', $next);
        $this->assertStringNotContainsString('association_id', $next);
        parse_str(parse_url($next, PHP_URL_QUERY), $query);
        $this->assertSame($origin, $query['return_to']);
    }

    public function test_foreign_and_nonexistent_projects_and_foreign_monitoring_are_not_found(): void
    {
        foreach ([104, 999999] as $id) $this->get('/association/projects/'.$this->id($id))->assertNotFound();
        $this->get('/association/monitoring/'.$this->id(504).'/workspace')->assertNotFound();
    }

    public function test_archived_and_finalized_projects_keep_history_without_enabling_writes(): void
    {
        DB::table('projects')->where('id', $this->id(101))->update(['status' => 'terminated', 'closed_at' => '2026-02-01']);
        $this->get('/association/projects/'.$this->id(101))->assertOk()->assertSee('Project finalized — Read Only.')
            ->assertSee('Terminated')->assertSee('Planned')->assertDontSee('Edit Project')->assertDontSee('Complete Project');
        $this->get('/association/projects/'.$this->id(103))->assertOk()->assertSee('Project Archived — Read Only.');
        $this->get('/association/monitoring/'.$this->id(501).'/workspace')->assertOk()->assertViewHas('canManage', false);
        $this->post('/association/projects', [])->assertStatus(405);
        $this->patch('/association/projects/'.$this->id(101), [])->assertStatus(405);
    }

    #[DataProvider('ineligibleLinks')]
    public function test_new_pages_follow_dashboard_eligibility(string $restriction): void
    {
        match ($restriction) {
            'inactive_link' => DB::table('association_accounts')->where('user_id', $this->id(1))->update(['is_active' => false]),
            'missing_link' => DB::table('association_accounts')->where('user_id', $this->id(1))->delete(),
            'duplicate_user' => DB::table('association_accounts')->insert(['id' => $this->id(30), 'user_id' => $this->id(1), 'association_id' => $this->id(11), 'is_active' => true]),
            'duplicate_association' => DB::table('association_accounts')->insert(['id' => $this->id(30), 'user_id' => $this->id(3), 'association_id' => $this->id(10), 'is_active' => true]),
            'inactive_association' => DB::table('associations')->where('id', $this->id(10))->update(['status' => 'inactive']),
            'archived_association' => DB::table('associations')->where('id', $this->id(10))->update(['archived_at' => '2026-01-01']),
        };
        $this->get('/association/projects')->assertForbidden();
        $this->get('/association/projects/'.$this->id(101))->assertForbidden();
    }

    public static function ineligibleLinks(): array
    {
        return array_map(fn ($case) => [$case], ['inactive_link', 'missing_link', 'duplicate_user', 'duplicate_association', 'inactive_association', 'archived_association']);
    }

    public function test_new_parent_policy_preserves_the_existing_monitoring_history_exception(): void
    {
        DB::table('associations')->where('id', $this->id(10))->update(['archived_at' => '2026-01-01']);
        $this->get('/association/projects/'.$this->id(101))->assertForbidden();
        $this->get('/association/monitoring/'.$this->id(501).'/workspace')->assertOk()->assertViewHas('canManage', false);
    }

    public function test_revoked_users_and_staff_roles_cannot_access_the_new_pages(): void
    {
        foreach ([3, 4] as $user) {
            $this->identity = $this->id($user);
            $this->get('/association/projects')->assertForbidden();
        }
        $this->identity = $this->id(1);
        DB::table('user_profiles')->where('id', $this->identity)->update(['is_active' => false]);
        $this->get('/association/projects')->assertRedirect(route('login'))->assertSessionMissing('supabase_access_token');
    }

    public function test_invalid_filters_are_rejected_and_database_errors_return_a_safe_unavailable_page(): void
    {
        foreach (['year=1900', 'page=-1', 'search[]=x', 'status=unknown'] as $query) $this->getJson('/association/projects?'.$query)->assertUnprocessable();
        $this->getJson('/association/projects/'.$this->id(101).'?products_page=0')->assertUnprocessable();
        Schema::drop('project_materials');
        $this->get('/association/projects/'.$this->id(101))->assertStatus(503)->assertSee('Project information is temporarily unavailable')
            ->assertDontSee('SQLSTATE')->assertDontSee('Own product');
    }

    public function test_directory_query_count_stays_constant_as_rows_increase(): void
    {
        $count = function (): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $this->get('/association/projects')->assertOk();
                return count(DB::getQueryLog());
            } finally { DB::disableQueryLog(); }
        };
        $before = $count();
        for ($number = 1; $number <= 30; $number++) $this->project(5000 + $number, ['commodity_id' => 1]);
        $this->assertSame($before, $count());
    }
}
