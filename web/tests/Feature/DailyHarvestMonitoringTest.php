<?php

namespace Tests\Feature;

use App\Models\MonitoringProduction;
use App\Models\MonitoringRecord;
use App\Models\UserProfile;
use App\Services\Audit\AuditLogService;
use App\Services\Auth\SupabaseAuthService;
use App\Services\Monitoring\DailyHarvestMonitoringService;
use App\Services\Monitoring\MonitoringService;
use App\Services\Monitoring\ProductionMonitoringService;
use App\Services\Reporting\ReportDataset;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\IsolatedDatabaseManager;
use Tests\TestCase;

class DailyHarvestMonitoringTest extends TestCase
{
    use \Tests\Support\DailyHarvestFixture;

    private string $identity = self::ADMIN;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertInstanceOf(IsolatedDatabaseManager::class, DB::getFacadeRoot());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'associations.geography_schema_ready' => false, 'associations.direct_geography' => false]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-15 12:00:00', 'Asia/Manila'));
        Http::swap((new Factory)->preventStrayRequests());
        $this->withoutVite();
        $this->harvestSchema(); $this->harvestFixtures();
        $auth = Mockery::mock(SupabaseAuthService::class);
        $auth->shouldReceive('getUser')->andReturnUsing(fn () => ['id' => $this->identity]);
        $this->app->instance(SupabaseAuthService::class, $auth);
        $this->withSession(['supabase_access_token' => 'synthetic-token']);
    }

    protected function tearDown(): void
    {
        try { CarbonImmutable::setTestNow(); Http::assertNothingSent(); parent::tearDown(); }
        finally { HandleExceptions::flushState($this); }
    }

    private function record(): MonitoringRecord { return MonitoringRecord::findOrFail(self::RECORD); }
    private function actor(string $id = self::ADMIN): UserProfile { return UserProfile::with('role')->findOrFail($id); }
    private function revision(): string { return app(ProductionMonitoringService::class)->revision($this->record()); }
    private function request(array $data = [], string $actor = self::ADMIN): Request
    {
        $request = Request::create('/synthetic-harvest', 'POST', $data + ['production_revision' => $this->revision()]);
        $request->attributes->set('current_user', $this->actor($actor));
        return $request;
    }
    private function initialize(string $actor = self::ADMIN, string $product = self::DAILY_PRODUCT): MonitoringProduction
    {
        return app(DailyHarvestMonitoringService::class)->initialize($this->record(), $this->request(['project_product_id' => $product], $actor));
    }
    private function save(MonitoringProduction $row, string $date, string $quantity, ?string $id = null, string $actor = self::ADMIN): void
    {
        app(DailyHarvestMonitoringService::class)->saveHarvest($this->record(), $row->id,
            $this->request(['harvest_date' => $date, 'quantity' => $quantity, 'remarks' => 'Synthetic harvest'], $actor), $id);
    }
    private function rejected(callable $operation, int $status = 422): void
    {
        try { $operation(); $this->fail('The invalid mutation must be rejected.'); }
        catch (ValidationException $exception) { $this->assertSame($status, $exception->status); }
    }

    public function test_admin_initialization_and_edit_recalculate_exact_days_and_totals_across_months(): void
    {
        $row = $this->initialize();
        $this->assertSame('daily_harvest', $row->quantity_source);
        $this->assertSame('pieces', $row->unit); $this->assertNull($row->target_output);
        $this->assertSame('0.00', $row->actual_output);
        $this->assertDatabaseCount('monitoring_harvest_entries', 0);
        $this->save($row, '2026-01-01', '0.10'); $this->save($row, '2026-01-02', '0.20');
        $this->save($row, '2026-02-03', '3.40');
        $this->assertSame('3.70', $row->fresh()->actual_output);
        $january = $row->monthlyDetails()->where('month', 1)->firstOrFail();
        $this->assertSame(2, $january->activity_count); $this->assertSame('0.30', $january->total_quantity);
        $id = $row->harvestEntries()->whereDate('harvest_date', '2026-01-01')->value('id');
        $this->save($row, '2026-03-01', '4.05', $id);
        $this->assertSame('7.65', $row->fresh()->actual_output);
        $this->assertDatabaseHas('monitoring_production_monthly', ['monitoring_production_id' => $row->id, 'month' => 1, 'activity_count' => 1, 'total_quantity' => '0.20']);
        $this->assertDatabaseHas('monitoring_production_monthly', ['monitoring_production_id' => $row->id, 'month' => 3, 'activity_count' => 1, 'total_quantity' => '4.05']);
        $this->assertDatabaseHas('audit_logs', ['action_type' => 'EDIT DAILY HARVEST', 'entity_id' => $id, 'actor_user_id' => self::ADMIN]);
    }

    public function test_assigned_officer_can_initialize_create_edit_void_and_revise_goal(): void
    {
        $row = $this->initialize(self::OFFICER);
        $this->save($row, '2026-01-01', '5', null, self::OFFICER);
        $entry = $row->harvestEntries()->firstOrFail();
        $this->save($row, '2026-02-02', '7.25', $entry->id, self::OFFICER);
        app(DailyHarvestMonitoringService::class)->reviseGoal($this->record(), $row->id,
            $this->request(['target_output' => '10', 'reason' => 'Approved plan'], self::OFFICER));
        app(DailyHarvestMonitoringService::class)->voidHarvest($this->record(), $row->id, $entry->id,
            $this->request(['void_reason' => 'Entered against the wrong date'], self::OFFICER));
        $this->assertSame('0.00', $row->fresh()->actual_output);
        $this->assertSame(0, $row->monthlyDetails()->sum('activity_count'));
        $this->assertSame(self::OFFICER, $entry->fresh()->voided_by);
        $this->assertSame('7.25', $entry->fresh()->quantity);
        $this->get(route('admin.monitoring.workspace', [self::RECORD, 'production']))->assertOk()
            ->assertSee('No harvest records submitted')->assertSee('None recorded')
            ->assertDontSee('% of target achieved');
        $this->rejected(fn () => $this->save($row, '2026-02-02', '8', $entry->id));
        $this->save($row, '2026-02-02', '2');
        $this->assertDatabaseCount('monitoring_harvest_entries', 2);
        $this->assertSame('2.00', $row->fresh()->actual_output);
    }

    public static function cardRoles(): array
    {
        return [[self::ADMIN, 'admin', true], [self::OFFICER, 'field-officer', true], [self::ACCOUNT, 'association', false]];
    }

    #[DataProvider('cardRoles')]
    public function test_production_workspace_shows_progress_without_changing_role_actions(string $identity, string $prefix, bool $canManage): void
    {
        $row = $this->initialize(); $this->save($row, '2026-01-10', '200.00');
        app(DailyHarvestMonitoringService::class)->reviseGoal($this->record(), $row->id,
            $this->request(['target_output' => '500.00', 'reason' => 'Synthetic approved goal']));
        $this->identity = $identity;
        $page = $this->get(route($prefix.'.monitoring.workspace', [self::RECORD, 'production']))->assertOk()
            ->assertSee('40.00% of target achieved')->assertSee('Harvesting Days')->assertSee('1 day')
            ->assertSee(route($prefix.'.monitoring.harvest.index', [self::RECORD, $row]), false);
        if ($canManage) $page->assertSee('Manage Production');
        else $page->assertDontSee('Manage Production')->assertDontSee('Initialize Daily Harvest');
    }

    public static function operations(): array { return [['create'], ['edit'], ['void'], ['goal'], ['initialize']]; }

    #[DataProvider('operations')]
    public function test_assignment_is_rechecked_for_every_mutation(string $operation): void
    {
        $row = $this->initialize(); $this->save($row, '2026-01-01', '1');
        $entry = $row->harvestEntries()->firstOrFail(); $actor = $this->actor(self::OFFICER);
        DB::table('associations')->update(['primary_field_officer_id' => null]);
        $request = $this->request(['harvest_date' => '2026-02-01', 'quantity' => '2', 'void_reason' => 'Incorrect',
            'target_output' => '5', 'reason' => 'Plan', 'project_product_id' => self::DAILY_PRODUCT]);
        $request->attributes->set('current_user', $actor);
        $service = app(DailyHarvestMonitoringService::class);
        $this->rejected(fn () => match ($operation) {
            'create' => $service->saveHarvest($this->record(), $row->id, $request),
            'edit' => $service->saveHarvest($this->record(), $row->id, $request, $entry->id),
            'void' => $service->voidHarvest($this->record(), $row->id, $entry->id, $request),
            'goal' => $service->reviseGoal($this->record(), $row->id, $request),
            'initialize' => $service->initialize($this->record(), $request),
        });
        $this->assertSame('1.00', $row->fresh()->actual_output);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_account_is_read_only_own_association_is_scoped_and_guest_has_no_access(): void
    {
        $row = $this->initialize(); $this->identity = self::ACCOUNT;
        $url = route('association.monitoring.harvest.index', [self::RECORD, $row]);
        $this->get($url)->assertOk()->assertSee('No harvest records submitted')->assertDontSee('Save Harvest')->assertDontSee('Save Quarterly Goal');
        $this->postJson(route('admin.monitoring.harvest.store', [self::RECORD, $row]), [])->assertForbidden();
        $this->postJson(route('field-officer.monitoring.harvest.store', [self::RECORD, $row]), [])->assertForbidden();
        $this->postJson('/association/monitoring/'.self::RECORD.'/production/'.$row->id.'/harvests', [])->assertStatus(405);
        DB::table('association_accounts')->update(['association_id' => self::DAILY_PRODUCT]);
        $this->get($url)->assertForbidden();
        $this->flushSession(); $this->get($url)->assertRedirect();
    }

    public function test_duplicate_date_is_rejected_but_different_product_same_day_is_allowed(): void
    {
        $row = $this->initialize(); $this->save($row, '2026-01-01', '1');
        $this->rejected(fn () => $this->save($row, '2026-01-01', '2'), 409);
        $id = (string) Str::uuid();
        DB::table('project_products')->insert(['id' => $id, 'project_id' => self::PROJECT,
            'product_name' => 'Another daily product', 'product_type' => 'raw', 'default_unit' => 'kg', 'is_active' => true]);
        $second = $this->initialize(self::ADMIN, $id); $this->save($second, '2026-01-01', '2.25');
        $this->assertSame('1.00', $row->fresh()->actual_output); $this->assertSame('2.25', $second->fresh()->actual_output);
        $this->save($row, '2026-02-01', '3');
        $this->rejected(fn () => $this->save($row, '2026-01-01', '4', $row->harvestEntries()->whereDate('harvest_date', '2026-02-01')->value('id')), 409);
        $this->assertSame('4.00', $row->fresh()->actual_output);
    }

    public function test_web_admin_and_officer_forms_persist_corrections_and_validation_feedback(): void
    {
        $this->post(route('admin.monitoring.harvest.initialize', self::RECORD),
            ['production_revision' => $this->revision(), 'project_product_id' => self::DAILY_PRODUCT])->assertRedirect();
        $row = MonitoringProduction::where('project_product_id', self::DAILY_PRODUCT)->firstOrFail();
        $this->post(route('admin.monitoring.harvest.store', [self::RECORD, $row]),
            ['production_revision' => $this->revision(), 'harvest_date' => '2026-01-10', 'quantity' => '2.50'])->assertRedirect();
        $entry = $row->harvestEntries()->firstOrFail();
        $this->identity = self::OFFICER;
        $this->patch(route('field-officer.monitoring.harvest.update', [self::RECORD, $row, $entry]),
            ['production_revision' => $this->revision(), 'harvest_date' => '2026-02-10', 'quantity' => '3.25'])->assertRedirect();
        $this->postJson(route('field-officer.monitoring.harvest.store', [self::RECORD, $row]),
            ['production_revision' => $this->revision(), 'harvest_date' => '2026-02-11', 'quantity' => '1.001'])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->get(route('field-officer.monitoring.harvest.index', [self::RECORD, $row]))
            ->assertOk()->assertSee('Recorded to date')->assertSee('3.25')->assertSee('No harvest records');
        $this->assertSame('3.25', $row->fresh()->actual_output);
    }

    public function test_entry_identity_void_reason_and_client_derived_values_are_protected(): void
    {
        $row = $this->initialize(); $this->save($row, '2026-01-01', '1');
        $entry = $row->harvestEntries()->firstOrFail();
        $service = app(DailyHarvestMonitoringService::class);
        $this->rejected(fn () => $service->voidHarvest($this->record(), $row->id, $entry->id, $this->request(['void_reason' => '   '])));
        $this->rejected(fn () => $service->saveHarvest($this->record(), $row->id,
            $this->request(['harvest_date' => '2026-01-02', 'quantity' => '2', 'actual_output' => '999'])));
        $this->rejected(fn () => $service->saveHarvest($this->record(), self::PRODUCTION,
            $this->request(['harvest_date' => '2026-01-02', 'quantity' => '2'])));
        $this->patchJson(route('admin.monitoring.harvest.update', [self::RECORD, $row, Str::uuid()]),
            ['production_revision' => $this->revision(), 'harvest_date' => '2026-01-02', 'quantity' => '2'])->assertNotFound();
        $this->assertSame('1.00', $row->fresh()->actual_output); $this->assertNull($entry->fresh()->voided_at);
        $request = $this->request(['harvest_date' => '2026-01-02', 'quantity' => '2']);
        $actor = $this->actor(); $request->attributes->set('current_user', $actor);
        DB::table('user_profiles')->where('id', self::ADMIN)->update(['is_active' => false]);
        try {
            $service->saveHarvest($this->record(), $row->id, $request);
            $this->fail('A deactivated actor must be rejected even with a previously loaded profile.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('monitoring_harvest_entries', 1);
    }

    public function test_past_date_from_another_quarter_is_rejected_using_quarter_number_not_lookup_id(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-21 12:00:00', 'Asia/Manila'));
        $row = $this->initialize();
        $this->rejected(fn () => $this->save($row, '2026-04-01', '1'));
        $this->save($row, '2026-03-31', '0.01');
        $this->assertSame('0.01', $row->fresh()->actual_output);
    }

    public static function invalidHarvests(): array
    {
        return [['2026-02-30', '1'], ['not-a-date', '1'], ['2026-03-16', '1'], ['2026-04-01', '1'],
            ['2025-01-01', '1'], ['2026-01-01', '0'], ['2026-01-01', '-1'], ['2026-01-01', '1.001'],
            ['2026-01-01', '1000000000000'], ['2026-01-01', '1e2']];
    }

    #[DataProvider('invalidHarvests')]
    public function test_invalid_dates_quantities_and_precision_are_rejected_without_partial_writes(string $date, string $quantity): void
    {
        $row = $this->initialize();
        $this->rejected(fn () => $this->save($row, $date, $quantity));
        $this->assertSame('0.00', $row->fresh()->actual_output);
        $this->assertDatabaseCount('monitoring_harvest_entries', 0); $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_sum_overflow_rolls_back_entry_months_and_audit(): void
    {
        $row = $this->initialize(); $this->save($row, '2026-01-01', '999999999999.99');
        $this->rejected(fn () => $this->save($row, '2026-02-01', '0.01'));
        $this->assertSame('999999999999.99', $row->fresh()->actual_output);
        $this->assertDatabaseCount('monitoring_harvest_entries', 1); $this->assertDatabaseCount('audit_logs', 2);
        $this->rejected(fn () => $this->save($row, '2026-01-02', '0.01'));
        $this->assertDatabaseCount('monitoring_harvest_entries', 1);
    }

    public function test_goal_creation_revision_audit_missing_zero_and_over_100_percent(): void
    {
        $row = $this->initialize(); $calculator = app(ProductionMonitoringService::class);
        $this->assertNull($calculator->goalAttainment($row, 0));
        $service = app(DailyHarvestMonitoringService::class);
        $service->reviseGoal($this->record(), $row->id, $this->request(['target_output' => '0', 'reason' => 'No target approved']));
        $this->save($row, '2026-01-01', '15');
        $this->assertNull($calculator->goalAttainment($row->fresh(), 1));
        $service->reviseGoal($this->record(), $row->id, $this->request(['target_output' => '10', 'reason' => 'Revised approved plan']));
        $this->assertSame('150.00', $calculator->goalAttainment($row->fresh(), 1));
        $audit = DB::table('audit_logs')->where('entity_type', 'monitoring_production_goal')->orderBy('performed_at')->get();
        $this->assertCount(2, $audit);
        $revision = $audit->first(fn ($event) => json_decode($event->new_values, true)['target_output'] === '10.00');
        $this->assertSame(self::ADMIN, $revision->actor_user_id);
        $this->assertSame(['target_output' => '0.00'], json_decode($revision->old_values, true));
        $this->assertSame(['target_output' => '10.00', 'reason' => 'Revised approved plan'], json_decode($revision->new_values, true));
        $this->assertNotNull($revision->performed_at);
        $this->rejected(fn () => $service->reviseGoal($this->record(), $row->id, $this->request(['target_output' => '20', 'reason' => '   '])));
        $this->assertSame('10.00', $row->fresh()->target_output);
    }

    public function test_stale_repeated_submission_and_equal_quantity_edit_invalidate_revisions(): void
    {
        $row = $this->initialize(); $request = $this->request(['harvest_date' => '2026-01-01', 'quantity' => '1']);
        $service = app(DailyHarvestMonitoringService::class);
        $service->saveHarvest($this->record(), $row->id, $request);
        $this->rejected(fn () => $service->saveHarvest($this->record(), $row->id, $request), 409);
        $revision = $this->revision();
        $this->save($row, '2026-01-01', '1', $row->harvestEntries()->firstOrFail()->id);
        $this->assertNotSame($revision, $this->revision());
        $this->assertDatabaseCount('monitoring_harvest_entries', 1);
    }

    public static function restrictedStates(): array
    {
        return [['completed'], ['terminated'], ['legacy completed'], ['archived project'], ['inactive association'], ['archived association']];
    }

    #[DataProvider('restrictedStates')]
    public function test_project_and_association_history_restrictions_remain_in_force(string $state): void
    {
        $row = $this->initialize();
        match ($state) {
            'completed', 'terminated' => DB::table('projects')->update(['status' => $state, 'closed_at' => '2026-03-01']),
            'legacy completed' => DB::table('projects')->update(['status' => 'completed']),
            'archived project' => DB::table('projects')->update(['archived_at' => '2026-03-01']),
            'inactive association' => DB::table('associations')->update(['status' => 'inactive']),
            'archived association' => DB::table('associations')->update(['archived_at' => '2026-03-01']),
        };
        $this->rejected(fn () => $this->save($row, '2026-01-01', '1'));
        $this->assertDatabaseCount('monitoring_harvest_entries', 0);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_legacy_section_and_full_record_forms_cannot_overwrite_daily_fields_or_goals(): void
    {
        $row = $this->initialize(); $this->save($row, '2026-01-01', '1.25');
        foreach (['monthly_details' => [['month' => 1, 'activity_count' => 99, 'total_quantity' => '100']],
            'actual_output' => '100', 'target_output' => '100', 'unit' => 'kg'] as $field => $value) {
            $data = ['project_id' => self::PROJECT, 'year' => 2026, 'quarter_id' => 11, 'project_status' => 'ongoing',
                'production_revision' => $this->revision(), 'production' => [['id' => $row->id, 'project_product_id' => self::DAILY_PRODUCT, $field => $value]]];
            $this->rejected(fn () => app(MonitoringService::class)->updateSection($this->record(), 'production', $data, $this->actor()));
            $this->rejected(fn () => app(MonitoringService::class)->update($this->record(), $data, $this->actor()));
        }
        $this->assertSame('1.25', $row->fresh()->actual_output); $this->assertNull($row->fresh()->target_output);
        $this->assertSame('5.00', MonitoringProduction::findOrFail(self::PRODUCTION)->actual_output);
        $this->rejected(fn () => $this->initialize(self::ADMIN, self::PRODUCT), 409);
        $this->rejected(fn () => $this->initialize(), 409);
        app(MonitoringService::class)->updateSection($this->record(), 'production', ['project_status' => 'ongoing',
            'production_revision' => $this->revision(), 'production' => []], $this->actor());
        $this->assertSame('1.25', $row->fresh()->actual_output);
    }

    #[DataProvider('operations')]
    public function test_required_audit_failure_rolls_back_every_kind_of_mutation(string $operation): void
    {
        $row = $this->initialize(); $this->save($row, '2026-01-01', '2');
        $entry = $row->harvestEntries()->firstOrFail(); $revision = $this->revision();
        $audit = Mockery::mock(AuditLogService::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Synthetic audit failure'));
        $this->app->instance(AuditLogService::class, $audit);
        $service = app(DailyHarvestMonitoringService::class);
        if ($operation === 'initialize') {
            $id = (string) Str::uuid();
            DB::table('project_products')->insert(['id' => $id, 'project_id' => self::PROJECT, 'product_name' => 'Rollback product',
                'product_type' => 'raw', 'default_unit' => 'kg', 'is_active' => true]);
        }
        try {
            match ($operation) {
                'create' => $service->saveHarvest($this->record(), $row->id, $this->request(['harvest_date' => '2026-02-01', 'quantity' => '3'])),
                'edit' => $service->saveHarvest($this->record(), $row->id, $this->request(['harvest_date' => '2026-02-01', 'quantity' => '4']), $entry->id),
                'void' => $service->voidHarvest($this->record(), $row->id, $entry->id, $this->request(['void_reason' => 'Incorrect'])),
                'goal' => $service->reviseGoal($this->record(), $row->id, $this->request(['target_output' => '8', 'reason' => 'Plan'])),
                'initialize' => $service->initialize($this->record(), $this->request(['project_product_id' => $id])),
            };
            $this->fail('Audit failure must roll back all related writes.');
        } catch (RuntimeException $exception) { $this->assertSame('Synthetic audit failure', $exception->getMessage()); }
        $this->assertSame($revision, $this->revision()); $this->assertSame('2.00', $row->fresh()->actual_output);
        $this->assertNull($row->fresh()->target_output); $this->assertNull($entry->fresh()->voided_at);
        $this->assertSame('2026-01-01', $entry->fresh()->harvest_date->toDateString());
        $this->assertDatabaseCount('monitoring_harvest_entries', 1); $this->assertDatabaseCount('audit_logs', 2);
        $this->assertDatabaseCount('monitoring_production', 2);
    }

    public function test_reporting_preserves_aggregate_zero_and_avoids_no_record_zero_and_income_fanout(): void
    {
        $this->harvestReportFixtures(); $row = $this->initialize();
        $filters = ['report_type' => 'production', 'year' => 2026];
        $extract = fn () => app(ReportDataset::class)->extract($this->actor(), $filters, [self::ASSOCIATION]);
        $data = $extract(); $byId = collect($data['production'])->keyBy('id');
        $this->assertNull($byId[$row->id]['actual_output']);
        $this->assertSame('5.00', number_format((float) $byId[self::PRODUCTION]['actual_output'], 2, '.', ''));
        $this->assertCount(0, $data['monthly']);
        DB::table('monitoring_production_monthly')->insert(['id' => (string) Str::uuid(), 'monitoring_production_id' => self::PRODUCTION,
            'month' => 1, 'activity_count' => 0, 'total_quantity' => '0.00']);
        $this->save($row, '2026-01-01', '3.25');
        foreach ([1, 2] as $month) DB::table('monitoring_income')->insert(['id' => (string) Str::uuid(),
            'monitoring_production_id' => $row->id, 'period_type' => 'monthly', 'month' => $month, 'gross_income' => '5.00']);
        $data = $extract();
        $this->assertCount(2, $data['production']); $this->assertCount(2, $data['monthly']);
        $this->assertEquals('3.25', collect($data['production'])->keyBy('id')[$row->id]['actual_output']);
        $income = app(ReportDataset::class)->extract($this->actor(), ['report_type' => 'income', 'year' => 2026], [self::ASSOCIATION]);
        $this->assertCount(2, $income['production']); $this->assertCount(2, $income['income']);
    }
}
