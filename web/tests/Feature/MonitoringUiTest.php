<?php

namespace Tests\Feature;

use App\Models\MonitoringRecord;
use App\Services\ProductionMonitoringService;
use App\Services\SupabaseAuthService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Support\IsolatedDatabaseManager;
use Tests\TestCase;

class MonitoringUiTest extends TestCase
{
    use \Tests\Support\MonitoringIncomeFixture;

    private string $identity = self::ADMIN;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertInstanceOf(IsolatedDatabaseManager::class, DB::getFacadeRoot());
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        Http::swap((new Factory)->preventStrayRequests());
        $this->withoutVite();
        $this->schema();
        $this->fixtures();
        DB::connection()->getPdo()->sqliteCreateFunction('gen_random_uuid', fn () => (string) \Illuminate\Support\Str::uuid());
        Schema::table('monitoring_visit_reports', function (Blueprint $table) {
            $table->uuid('conducted_by_user_id')->nullable(); $table->string('conducted_by')->nullable();
            $table->uuid('monitored_by')->nullable(); $table->uuid('created_by')->nullable(); $table->uuid('updated_by')->nullable();
            $table->boolean('site_validated')->default(false); $table->boolean('is_legacy_import')->default(false);
            $table->text('issues_encountered')->nullable(); $table->text('actions_taken')->nullable();
            $table->text('overall_remarks')->nullable(); $table->timestamps();
        });
        Schema::create('municipalities', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('(gen_random_uuid())')); $table->string('name'); $table->string('psgc_code')->nullable();
            $table->string('province'); $table->string('region'); $table->string('source');
            $table->boolean('is_active'); $table->uuid('created_by'); $table->timestamps();
        });
        foreach (['barangays', 'areas'] as $table) Schema::create($table, fn (Blueprint $schema) => $schema->uuid('id')->primary());
        Route::get('/_test/area-modal', fn () => view('admin.areas.modals', [
            'catalog' => ['modules' => ['areas' => ['rows' => []]],
                'options' => ['municipality_records' => [], 'barangay_records' => []]],
        ]))->middleware('web');
        $auth = Mockery::mock(SupabaseAuthService::class);
        $auth->shouldReceive('getUser')->andReturnUsing(fn () => ['id' => $this->identity]);
        $this->app->instance(SupabaseAuthService::class, $auth);
        $this->withSession(['supabase_access_token' => 'synthetic-token']);
    }

    protected function tearDown(): void
    {
        try { Http::assertNothingSent(); parent::tearDown(); }
        finally { HandleExceptions::flushState($this); }
    }

    private function visit(array $changes = []): array
    {
        return array_replace(['monitoring_date' => '2026-01-16', 'site_validated' => '1',
            'conducted_by_user_id' => self::OFFICER, 'conducted_by' => null,
            'issues_encountered' => 'Synthetic draft issue', 'actions_taken' => 'Synthetic draft action',
            'overall_remarks' => 'Synthetic draft remarks'], $changes);
    }

    private function visitUrl(string $operation, string $prefix = 'admin.monitoring'): string
    {
        return route($prefix.'.visit.'.$operation, self::RECORD);
    }

    public function test_visit_conductor_choices_store_the_correct_attribution(): void
    {
        foreach ([['conducted_by_user_id' => self::OFFICER, 'conducted_by' => null],
            ['conducted_by_user_id' => null, 'conducted_by' => 'Synthetic Observer']] as $choice) {
            $this->post($this->visitUrl('store'), $this->visit($choice))->assertRedirect()
                ->assertSessionHasNoErrors();
            $this->assertDatabaseHas('monitoring_visit_reports', $choice + ['monitored_by' => self::ADMIN,
                'created_by' => self::ADMIN, 'updated_by' => self::ADMIN, 'is_legacy_import' => false]);
        }
        $this->assertDatabaseCount('monitoring_visit_reports', 2);
    }

    public function test_both_or_neither_conductor_options_preserve_the_visit_draft_and_never_restore_area(): void
    {
        foreach ([['conducted_by_user_id' => self::OFFICER, 'conducted_by' => 'Synthetic Alternative'],
            ['conducted_by_user_id' => null, 'conducted_by' => null]] as $choice) {
            $this->from($this->visitUrl('create'))->post($this->visitUrl('store'), $this->visit($choice))
                ->assertRedirect($this->visitUrl('create'))->assertSessionHasErrors(['conducted_by'], null, 'monitoringVisit')
                ->assertSessionHas('_old_input.issues_encountered', 'Synthetic draft issue');
            $page = $this->get($this->visitUrl('create'))->assertOk()
                ->assertSee('data-visit-validation', false)->assertSee('using exactly one option.')
                ->assertSee('Synthetic draft issue')->assertSee('Synthetic draft action')->assertSee('Synthetic draft remarks')
                ->assertSee('data-has-errors="false"', false)->assertDontSee('data-area-validation', false);
            if ($choice['conducted_by']) $page->assertSee('value="Synthetic Alternative"', false);
        }
        foreach (['areas', 'barangays', 'municipalities', 'monitoring_visit_reports'] as $table) $this->assertDatabaseCount($table, 0);
    }

    public function test_request_validation_uses_the_visit_bag_and_preserves_a_rejected_officer_selection(): void
    {
        $this->from($this->visitUrl('create'))->post($this->visitUrl('store'), $this->visit([
            'monitoring_date' => 'not-a-date', 'conducted_by_user_id' => 'invalid-officer',
        ]))->assertSessionHasErrors(['monitoring_date', 'conducted_by_user_id'], null, 'monitoringVisit');
        $this->get($this->visitUrl('create'))->assertOk()->assertSee('value="invalid-officer"', false)
            ->assertSee('Synthetic draft remarks')->assertSee('data-has-errors="false"', false)
            ->assertDontSee('data-area-validation', false);
        $this->assertDatabaseCount('monitoring_visit_reports', 0);
    }

    public function test_visit_date_and_changed_officer_validation_remain_authoritative(): void
    {
        foreach ([['monitoring_date' => '2026-04-01'], ['conducted_by_user_id' => self::ADMIN]] as $change) {
            $this->postJson($this->visitUrl('store'), $this->visit($change))->assertUnprocessable();
        }
        $this->assertDatabaseCount('monitoring_visit_reports', 0);
    }

    public function test_historical_visit_attribution_and_date_can_be_retained_but_changed_choices_are_validated(): void
    {
        $id = '99999999-9999-4999-8999-999999999999';
        DB::table('monitoring_visit_reports')->insert(['id' => $id, 'monitoring_record_id' => self::RECORD,
            'monitoring_date' => '2025-12-01', 'conducted_by_user_id' => self::OFFICER,
            'site_validated' => true, 'is_legacy_import' => true]);
        DB::table('associations')->where('id', self::ASSOCIATION)->update(['primary_field_officer_id' => null]);
        $url = route('admin.monitoring.visit.update', [self::RECORD, $id]);
        $this->patch($url, $this->visit(['monitoring_date' => '2025-12-01']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('monitoring_visit_reports', ['id' => $id, 'monitoring_date' => '2025-12-01',
            'conducted_by_user_id' => self::OFFICER, 'overall_remarks' => 'Synthetic draft remarks']);
        $this->patchJson($url, $this->visit(['conducted_by' => 'Conflicting alternative']))->assertUnprocessable();
    }

    public function test_visit_role_assignment_and_finalization_guards_remain_intact(): void
    {
        $this->identity = self::OFFICER;
        $this->post($this->visitUrl('store', 'field-officer.monitoring'), $this->visit())->assertRedirect();
        DB::table('associations')->where('id', self::ASSOCIATION)->update(['primary_field_officer_id' => self::ADMIN]);
        $this->postJson($this->visitUrl('store', 'field-officer.monitoring'), $this->visit())->assertNotFound();
        $this->identity = self::ADMIN;
        DB::table('projects')->where('id', self::PROJECT)->update(['status' => 'completed']);
        $this->postJson($this->visitUrl('store'), $this->visit())->assertUnprocessable();
        $this->identity = self::ACCOUNT;
        $this->postJson($this->visitUrl('store'), $this->visit())->assertForbidden();
        $this->assertDatabaseCount('monitoring_visit_reports', 1);
    }

    public function test_legitimate_area_request_and_service_errors_restore_only_the_area_editor(): void
    {
        $this->from('/_test/area-modal')->post(route('admin.municipalities.store'), [
            'area_form' => 'editor', 'area_type' => 'Municipality', 'name' => '',
        ])->assertSessionHasErrors('name');
        $this->get('/_test/area-modal')->assertOk()->assertSee('data-has-errors="true"', false)
            ->assertSee('data-area-validation', false)->assertSee('Municipality name is required.');
        $this->post(route('admin.municipalities.store'), ['area_form' => 'editor',
            'area_type' => 'Municipality', 'name' => 'Synthetic Locality'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('municipalities', 1);
        $this->from('/_test/area-modal')->post(route('admin.municipalities.store'), ['area_form' => 'editor',
            'area_type' => 'Municipality', 'name' => 'Synthetic Locality'])->assertSessionHasErrors('name');
        $this->get('/_test/area-modal')->assertSee('This municipality already exists in Cebu.')
            ->assertSee('data-has-errors="true"', false);
        $this->assertDatabaseCount('municipalities', 1);
    }

    public function test_unrelated_default_errors_do_not_populate_or_reopen_area(): void
    {
        $errors = (new \Illuminate\Support\ViewErrorBag)->put('default', new \Illuminate\Support\MessageBag(['name' => 'Unrelated error']));
        $this->withSession(['errors' => $errors, '_old_input' => ['name' => 'Unrelated name', 'area_type' => 'Barangay']])
            ->get('/_test/area-modal')->assertOk()->assertDontSee('Unrelated error')->assertDontSee('Unrelated name')
            ->assertSee('data-has-errors="false"', false);
    }

    public function test_production_save_renders_one_success_for_each_writer_role_and_failure_has_no_success(): void
    {
        foreach ([self::ADMIN => 'admin.monitoring', self::OFFICER => 'field-officer.monitoring'] as $identity => $prefix) {
            $this->identity = $identity;
            $record = MonitoringRecord::findOrFail(self::RECORD);
            $data = ['project_id' => self::PROJECT, 'year' => 2026, 'quarter_id' => 11, 'project_status' => 'ongoing',
                'production_revision' => app(ProductionMonitoringService::class)->revision($record),
                'production' => [['id' => self::PRODUCTION, 'project_product_id' => self::PRODUCT]]];
            $this->patch(route($prefix.'.section.update', [self::RECORD, 'production']), $data)->assertRedirect()
                ->assertSessionHasNoErrors()->assertSessionHas('success', 'Production updated.');
            $page = $this->get(route($prefix.'.workspace', [self::RECORD, 'production']))->assertOk();
            $this->assertSame(1, substr_count($page->getContent(), 'id="global-feedback-modal"'));
            preg_match('/<script[^>]*data-feedback-payload[^>]*>(.*?)<\/script>/s', $page->getContent(), $match);
            $notification = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR)['notification'];
            $this->assertSame('success', $notification['type']);
            $this->assertSame('Production updated.', $notification['message']);
            $data['production_revision'] = str_repeat('0', 64);
            $this->patch(route($prefix.'.section.update', [self::RECORD, 'production']), $data)
                ->assertSessionHasErrors('production_revision')->assertSessionMissing('success');
        }
    }

    public function test_archived_monitoring_history_is_read_only_and_cannot_be_enumerated_by_officers(): void
    {
        DB::table('associations')->update(['archived_at' => '2026-01-20']);
        DB::table('monitoring_visit_reports')->insert(['id' => self::PRODUCT, 'monitoring_record_id' => self::RECORD,
            'monitoring_date' => '2026-01-16', 'conducted_by' => 'Historical observer']);
        $tables = ['associations', 'projects', 'monitoring_records', 'monitoring_production', 'monitoring_income',
            'monitoring_materials', 'monitoring_visit_reports'];
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), $tables);
        $before = $snapshot();
        foreach (['overview', 'production', 'income', 'materials'] as $section) {
            $page = $this->get(route('admin.monitoring.workspace', [self::RECORD, $section]))->assertOk()
                ->assertSee('Association Archived — Read Only')->assertDontSee('Edit Overview')->assertDontSee('Manage '.$section);
            $this->assertFalse($page->viewData('canManage'));
            if ($section !== 'overview') $this->patchJson(route('admin.monitoring.section.update', [self::RECORD, $section]), [])->assertUnprocessable();
        }
        $this->get(route('admin.monitoring.visits', self::RECORD))->assertOk()->assertSee('Historical observer')
            ->assertDontSee('Add Visit Report')->assertDontSee('Edit Visit Report');
        $this->get(route('admin.monitoring.visit.create', self::RECORD))->assertRedirect()->assertSessionHasErrors('association_id');
        $this->identity = self::OFFICER;
        $this->get(route('field-officer.monitoring.index'))->assertOk()->assertDontSee('Synthetic Project');
        foreach (['overview', 'production', 'income', 'materials'] as $section) {
            $this->get(route('field-officer.monitoring.workspace', [self::RECORD, $section]))->assertNotFound();
        }
        $this->get(route('field-officer.monitoring.visits', self::RECORD))->assertNotFound();
        $this->identity = self::ACCOUNT;
        DB::table('association_accounts')->insert(['id' => self::RECORD, 'association_id' => self::ASSOCIATION,
            'user_id' => self::ACCOUNT, 'is_active' => true]);
        $this->get(route('association.monitoring.workspace', [self::RECORD, 'income']))->assertOk();
        $this->patchJson(route('admin.monitoring.section.update', [self::RECORD, 'income']), [])->assertForbidden();
        $this->assertSame($before, $snapshot());
    }

    public function test_monitoring_history_and_visits_are_bounded_and_search_applies_before_pagination(): void
    {
        DB::table('monitoring_records')->update(['created_at' => '2026-01-15 00:00:00']);
        for ($number = 1; $number <= 27; $number++) {
            $id = sprintf('99999999-9999-4999-8999-%012d', $number);
            DB::table('projects')->insert(['id' => $id, 'association_id' => self::ASSOCIATION,
                'title' => $number === 27 ? 'Unique 100%_! Project' : 'Extra Project '.$number, 'program_year' => 2026, 'status' => 'ongoing']);
            DB::table('monitoring_records')->insert(['id' => $id, 'project_id' => $id, 'year' => 2026, 'quarter_id' => 11,
                'monitoring_date' => '2026-01-15', 'monitored_by' => self::OFFICER, 'updated_by' => self::ADMIN,
                'site_validated' => true, 'project_status' => 'ongoing', 'created_at' => '2026-01-15 00:00:00']);
            DB::table('monitoring_visit_reports')->insert(['id' => $id, 'monitoring_record_id' => self::RECORD,
                'monitoring_date' => '2026-01-16', 'conducted_by' => 'Visit '.$number]);
        }
        foreach ([self::ADMIN => 'admin', self::OFFICER => 'field-officer'] as $identity => $prefix) {
            $this->identity = $identity;
            $url = route($prefix.'.monitoring.index', ['year' => 2026]);
            $page = $this->get($url)->assertOk();
            $this->assertSame(28, $page->viewData('projects')->total());
            $this->assertCount(25, $page->viewData('projects'));
            $this->assertCount(3, $this->get($page->viewData('projects')->nextPageUrl())->assertOk()->viewData('projects'));
            foreach (['100%_!', 'Project Synthetic Association', 'Jan 15, 2026', 'Q1', 'Synthetic Actor', '2026'] as $search) {
                $filtered = $this->get($url.'&'.http_build_query(['search' => $search]))->assertOk();
                $expected = match ($search) { '100%_!' => 1, 'Project Synthetic Association' => 2, default => 28 };
                $this->assertSame($expected, $filtered->viewData('projects')->total(), $search);
                if ($filtered->viewData('projects')->hasMorePages()) {
                    $this->assertStringContainsString('search=', $filtered->viewData('projects')->nextPageUrl());
                }
            }
            $visits = $this->get(route($prefix.'.monitoring.visits', self::RECORD))->assertOk();
            $this->assertCount(25, $visits->viewData('reports'));
            $this->assertSame(27, $visits->viewData('reports')->total());
            $this->assertCount(2, $this->get($visits->viewData('reports')->nextPageUrl())->assertOk()->viewData('reports'));
        }
    }

    public function test_archived_monitoring_tolerates_empty_optional_history_relations(): void
    {
        DB::table('associations')->update(['archived_at' => '2026-01-20']);
        DB::table('project_products')->delete();
        DB::table('user_profiles')->where('id', self::OFFICER)->delete();
        foreach (['overview', 'production', 'income', 'materials'] as $section) {
            $page = $this->get(route('admin.monitoring.workspace', [self::RECORD, $section]))->assertOk();
            $this->assertFalse($page->viewData('canManage'));
        }
        $this->get(route('admin.monitoring.visits', self::RECORD))->assertOk()->assertSee('No Visit Reports recorded.');
        $this->assertDatabaseCount('monitoring_production', 1);
    }

    public function test_monitoring_pages_have_unique_dom_identifiers_and_income_dialog_is_scoped(): void
    {
        foreach (['income', 'production'] as $section) {
            $page = $this->get(route('admin.monitoring.section.edit', [self::RECORD, $section]))->assertOk();
            preg_match_all('/\bid="([^"]+)"/', $page->getContent(), $matches);
            $this->assertSame($matches[1], array_values(array_unique($matches[1])));
            $this->assertSame($section === 'income' ? 1 : 0, substr_count($page->getContent(), 'id="monitoring-income-conflict-modal"'));
        }
    }
}
