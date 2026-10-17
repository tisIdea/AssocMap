<?php

namespace Tests\Feature;

use App\Models\MonitoringRecord;
use App\Models\UserProfile;
use App\Services\MaterialsMonitoringService;
use App\Services\MonitoringService;
use App\Services\ProductionMonitoringService;
use App\Services\SupabaseAuthService;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\IsolatedDatabaseManager;
use Tests\TestCase;

class MonitoringMaterialsTest extends TestCase
{
    use \Tests\Support\MonitoringMaterialsFixture;

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

    private function record(): MonitoringRecord { return MonitoringRecord::findOrFail(self::RECORD); }
    private function actor(string $id = self::ADMIN): UserProfile { return UserProfile::with('role')->findOrFail($id); }
    private function url(string $prefix = 'admin.monitoring'): string { return route($prefix.'.section.update', [self::RECORD, 'materials']); }
    private function payload(array $rows = [], array $removed = []): array
    {
        return ['project_id' => self::PROJECT, 'year' => 2026, 'quarter_id' => 11,
            'materials_revision' => app(MaterialsMonitoringService::class)->revision($this->record()),
            'materials' => $rows, 'deleted_material_ids' => $removed];
    }
    private function entry(string $material = self::MATERIAL): array
    {
        return ['project_material_id' => $material, 'condition' => 'Good', 'scheduled_maintenance' => '2026-02-01',
            'actual_maintenance' => '2026-02-02', 'remarks' => 'Synthetic observation'];
    }
    private function saved(): array
    {
        return $this->record()->materials()->orderBy('id')->get()->map(fn ($row) => [
            'id' => $row->id, 'project_material_id' => $row->project_material_id, 'condition' => $row->condition,
            'scheduled_maintenance' => $row->scheduled_maintenance?->format('Y-m-d'),
            'actual_maintenance' => $row->actual_maintenance?->format('Y-m-d'), 'remarks' => $row->remarks,
        ])->all();
    }
    private function full(array $data): array
    {
        return $data + ['project_status' => 'ongoing', 'monitoring_date' => '2026-01-15', 'site_validated' => true];
    }
    private function foreign(): array
    {
        $association = (string) Str::uuid(); $project = (string) Str::uuid(); $record = (string) Str::uuid(); $material = (string) Str::uuid();
        foreach (['associations' => [$association, ['primary_field_officer_id' => self::ADMIN]],
            'projects' => [$project, ['association_id' => $association]], 'monitoring_records' => [$record, ['project_id' => $project]],
            'project_materials' => [$material, ['project_id' => $project]]] as $table => [$id, $changes]) {
            DB::table($table)->insert(array_replace((array) DB::table($table)->first(), $changes, ['id' => $id]));
        }
        $observation = (string) Str::uuid();
        DB::table('monitoring_materials')->insert(['id' => $observation, 'monitoring_record_id' => $record,
            'project_material_id' => $material, 'condition' => 'Foreign']);
        return [$record, $material, $observation];
    }

    public function test_authorized_creation_update_and_explicit_deletion_preserve_inventory_and_other_sections(): void
    {
        $inventory = DB::table('project_materials')->get()->toJson();
        $this->patchJson($this->url(), $this->payload([$this->entry()]))->assertOk()
            ->assertSessionHas('success', 'Materials updated.')->assertJsonPath('redirect', route('admin.monitoring.workspace', [self::RECORD, 'materials']));
        $saved = $this->saved(); $saved[0]['condition'] = 'Needs repair';
        $this->identity = self::OFFICER;
        $this->patchJson($this->url('field-officer.monitoring'), $this->payload($saved))->assertOk();
        $this->assertSame($saved, $this->saved());
        $this->patchJson($this->url('field-officer.monitoring'), $this->payload([], [$saved[0]['id']]))->assertOk();
        $this->assertSame([], $this->saved()); $this->assertSame(3, $this->record()->materials_version);
        $this->assertSame(0, $this->record()->income_version); $this->assertDatabaseCount('monitoring_production', 1);
        $this->assertSame($inventory, DB::table('project_materials')->get()->toJson());
        $this->assertFalse($this->record()->isFillable('materials_version'));
    }

    public function test_monitoring_creation_with_materials_still_creates_its_visit_without_edit_revision(): void
    {
        $data = $this->full($this->payload([$this->entry()])); unset($data['materials_revision'], $data['deleted_material_ids']);
        $data['year'] = 2027; $data['monitoring_date'] = '2027-01-15'; $data['conducted_by_user_id'] = self::OFFICER;
        $this->postJson(route('admin.monitoring.store'), $data)->assertRedirect();
        $created = MonitoringRecord::where('year', 2027)->firstOrFail();
        $this->assertSame(1, $created->materials()->count()); $this->assertSame(1, $created->visitReports()->count());
        $this->assertSame(0, $created->materials_version);
    }

    public function test_omission_empty_and_deletion_only_requests_have_explicit_semantics(): void
    {
        $this->patchJson($this->url(), $this->payload([$this->entry(), $this->entry(self::SECOND_MATERIAL)]))->assertOk();
        $before = $this->saved();
        $this->patchJson($this->url(), $this->payload([$before[0]]))->assertOk(); $this->assertSame($before, $this->saved());
        $this->patchJson($this->url(), $this->payload())->assertOk(); $this->assertSame($before, $this->saved());
        $data = $this->payload([], [$before[0]['id']]); unset($data['materials']);
        $this->patchJson($this->url(), $data)->assertOk(); $this->assertSame([$before[1]], $this->saved());
        $this->assertSame(4, $this->record()->materials_version);
    }

    #[DataProvider('prefixes')]
    public function test_legacy_full_update_preserves_absent_materials_and_protects_explicit_changes(string $prefix, string $actor): void
    {
        $this->patchJson($this->url(), $this->payload([$this->entry()]))->assertOk(); $this->identity = $actor;
        $before = $this->saved(); $version = $this->record()->materials_version;
        $identity = ['project_id' => self::PROJECT, 'year' => 2026, 'quarter_id' => 11];
        $this->patchJson(route($prefix.'.update', self::RECORD), $this->full($identity))->assertRedirect();
        $this->assertSame($before, $this->saved()); $this->assertSame($version, $this->record()->materials_version);
        $stale = $this->full($this->payload($before));
        $this->patchJson(route($prefix.'.update', self::RECORD), $this->full($this->payload([], [$before[0]['id']])))->assertRedirect();
        $this->patchJson(route($prefix.'.update', self::RECORD), $stale)->assertStatus(409)->assertJsonPath('code', 'monitoring_materials_conflict');
        $this->assertSame([], $this->saved());
        $this->patchJson(route($prefix.'.update', self::RECORD), $this->full($this->payload()))->assertRedirect();
        $missing = $this->full($this->payload([$this->entry()])); unset($missing['materials_revision']);
        $this->patchJson(route($prefix.'.update', self::RECORD), $missing)->assertUnprocessable();
    }
    public static function prefixes(): array { return [['admin.monitoring', self::ADMIN], ['field-officer.monitoring', self::OFFICER]]; }

    #[DataProvider('races')]
    public function test_two_editors_reject_stale_add_edit_delete_and_deleted_row_submissions(string $case): void
    {
        $this->patchJson($this->url(), $this->payload([$this->entry()]))->assertOk(); $before = $this->saved();
        $stale = $this->payload($case === 'delete' ? [] : $before, $case === 'delete' ? [$before[0]['id']] : []);
        $winner = match ($case) {
            'add' => $this->payload([$this->entry(self::SECOND_MATERIAL)]),
            'deleted row' => $this->payload([], [$before[0]['id']]),
            default => $this->payload([array_replace($before[0], ['condition' => 'Repaired'])]),
        };
        $this->patchJson($this->url(), $winner)->assertOk(); $expected = $this->saved(); $version = $this->record()->materials_version;
        $this->identity = self::OFFICER;
        $this->patchJson($this->url('field-officer.monitoring'), $stale)->assertStatus(409)
            ->assertJsonPath('code', 'monitoring_materials_conflict')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($expected, $this->saved()); $this->assertSame($version, $this->record()->materials_version);
    }
    public static function races(): array { return [['add'], ['edit'], ['delete'], ['deleted row']]; }

    public function test_returning_to_original_values_does_not_revive_a_stale_token(): void
    {
        $this->travelTo(now()->startOfSecond()); $this->patchJson($this->url(), $this->payload([$this->entry()]))->assertOk();
        $before = $this->saved(); $stale = $this->payload($before);
        $this->patchJson($this->url(), $this->payload([array_replace($before[0], ['condition' => 'Repair'])]))->assertOk();
        $this->patchJson($this->url(), $this->payload($before))->assertOk(); $this->assertSame($before, $this->saved());
        $this->patchJson($this->url(), $stale)->assertStatus(409); $this->travelBack();
    }

    #[DataProvider('invalidCases')]
    public function test_invalid_identifiers_and_retained_state_cannot_mutate_records(string $case): void
    {
        $this->patchJson($this->url(), $this->payload([$this->entry()]))->assertOk();
        $before = $this->saved(); $id = $before[0]['id']; [, $material, $foreign] = $this->foreign();
        $data = match ($case) {
            'duplicate updates' => $this->payload([$before[0], $before[0]]),
            'foreign update' => $this->payload([array_replace($before[0], ['id' => $foreign])]),
            'foreign removal' => $this->payload([], [$foreign]), 'duplicate removal' => $this->payload([], [$id, $id]),
            'malformed removal' => $this->payload([], ['invalid']), 'missing removal' => $this->payload([], [(string) Str::uuid()]),
            'edit and remove' => $this->payload($before, [$id]), 'foreign reference' => $this->payload([$this->entry($material)]),
            'missing reference' => $this->payload([$this->entry((string) Str::uuid())]), 'retained duplicate' => $this->payload([$this->entry()]),
            'invalid date' => $this->payload([array_replace($before[0], ['actual_maintenance' => 'invalid'])]),
            default => $this->payload([array_replace($before[0], ['condition' => str_repeat('a', 51)])]),
        };
        $this->patchJson($this->url(), $data)->assertUnprocessable();
        $this->assertSame($before, $this->saved()); $this->assertSame(1, $this->record()->materials_version);
    }
    public static function invalidCases(): array
    {
        return array_map(fn ($case) => [$case], ['duplicate updates', 'foreign update', 'foreign removal', 'duplicate removal',
            'malformed removal', 'missing removal', 'edit and remove', 'foreign reference', 'missing reference', 'retained duplicate', 'invalid date', 'condition length']);
    }

    public function test_missing_forged_and_other_record_revisions_fail_without_mutation(): void
    {
        $data = $this->payload([$this->entry()]); unset($data['materials_revision']);
        $this->patchJson($this->url(), $data)->assertUnprocessable()->assertJsonValidationErrors('materials_revision');
        $data['materials_revision'] = str_repeat('0', 64); $this->patchJson($this->url(), $data)->assertStatus(409);
        [$foreign] = $this->foreign(); $data['materials_revision'] = app(MaterialsMonitoringService::class)->revision(MonitoringRecord::findOrFail($foreign));
        $this->patchJson($this->url(), $data)->assertStatus(409);
        $this->assertSame([], $this->saved()); $this->assertSame(0, $this->record()->materials_version);
    }

    #[DataProvider('restrictedStates')]
    public function test_current_assignment_actor_and_parent_state_are_rechecked_inside_transaction(string $state): void
    {
        $data = $this->payload([$this->entry()]); $record = $this->record(); $actor = $this->actor(self::OFFICER);
        match ($state) {
            'unassigned' => DB::table('associations')->update(['primary_field_officer_id' => self::ADMIN]),
            'inactive association' => DB::table('associations')->update(['status' => 'inactive']),
            'archived association' => DB::table('associations')->update(['archived_at' => '2026-01-01']),
            'archived project' => DB::table('projects')->update(['archived_at' => '2026-01-01']),
            'inactive actor' => DB::table('user_profiles')->where('id', self::OFFICER)->update(['is_active' => false]),
            'inactive role' => DB::table('roles')->where('id', 2)->update(['is_active' => false]),
            default => DB::table('projects')->update(['status' => $state, 'closed_at' => '2026-01-01']),
        };
        try { app(MonitoringService::class)->updateSection($record, 'materials', $data, $actor); $this->fail('Current eligibility must be checked.'); }
        catch (ValidationException|\Symfony\Component\HttpKernel\Exception\HttpException) {
            $this->assertSame([], $this->saved()); $this->assertSame(0, $this->record()->materials_version);
        }
    }
    public static function restrictedStates(): array
    {
        return array_map(fn ($state) => [$state], ['unassigned', 'inactive association', 'archived association', 'archived project', 'inactive actor', 'inactive role', 'completed', 'terminated']);
    }

    public function test_cross_association_and_association_account_writes_are_denied(): void
    {
        [$foreign] = $this->foreign(); $record = MonitoringRecord::findOrFail($foreign);
        $data = $this->payload(); $data['project_id'] = $record->project_id;
        $data['materials_revision'] = app(MaterialsMonitoringService::class)->revision($record); $this->identity = self::OFFICER;
        $this->patchJson(route('field-officer.monitoring.section.update', [$foreign, 'materials']), $data)->assertNotFound();
        $this->patchJson(route('field-officer.monitoring.update', $foreign), $this->full($data))->assertUnprocessable();
        $this->identity = self::ACCOUNT;
        $this->patchJson($this->url(), $this->payload())->assertForbidden();
        $this->patchJson(route('field-officer.monitoring.update', self::RECORD), $this->full($this->payload()))->assertForbidden();
        $this->get(route('association.monitoring.workspace', [self::RECORD, 'materials']))->assertOk();
    }

    public function test_late_failure_rolls_back_observations_removals_and_version(): void
    {
        $this->patchJson($this->url(), $this->payload([$this->entry()]))->assertOk();
        $before = $this->saved(); $data = $this->payload([$this->entry(self::SECOND_MATERIAL)], [$before[0]['id']]);
        DB::listen(function ($query) {
            if (str_starts_with($query->sql, 'update "monitoring_records"') && str_contains($query->sql, 'materials_version')) throw new RuntimeException('Synthetic Materials failure');
        });
        try { app(MonitoringService::class)->updateSection($this->record(), 'materials', $data, $this->actor()); $this->fail('Expected failure'); }
        catch (RuntimeException $exception) { $this->assertSame('Synthetic Materials failure', $exception->getMessage()); }
        $this->assertSame($before, $this->saved()); $this->assertSame(1, $this->record()->materials_version);
    }

    public function test_database_failure_response_hides_sql_and_preserves_data(): void
    {
        DB::unprepared("CREATE TRIGGER reject_materials BEFORE INSERT ON monitoring_materials BEGIN SELECT RAISE(ABORT, 'SYNTHETIC PRIVATE SQL DETAILS'); END");
        $this->patchJson($this->url(), $this->payload([$this->entry()]))->assertStatus(503)->assertDontSee('SYNTHETIC PRIVATE SQL DETAILS');
        $this->assertSame([], $this->saved()); $this->assertSame(0, $this->record()->materials_version);
    }

    public function test_display_snapshot_html_recovery_and_validation_restore_keep_the_original_revision(): void
    {
        $data = $this->payload([$this->entry()]);
        $this->get(route('admin.monitoring.section.edit', [self::RECORD, 'materials']))->assertOk()->assertSee($data['materials_revision'])
            ->assertSee('Materials Record Updated')->assertSee('data-materials-refresh-confirm', false)->assertDontSee('id="monitoring-income-conflict-modal"', false);
        $this->patchJson($this->url(), $data)->assertOk();
        $draft = $data; $draft['materials'][0]['remarks'] = '<script>synthetic draft</script>';
        $this->patch($this->url(), $draft)->assertStatus(409)->assertSee('Complete submitted Materials draft')
            ->assertSee('&lt;script&gt;synthetic draft&lt;\/script&gt;', false)->assertDontSee('<script>synthetic draft</script>', false)
            ->assertSee('data-feedback-root', false)->assertSee('target="_blank"', false);
        $invalid = $this->payload($this->saved()); $invalid['monitoring_form'] = 'editor'; $invalid['materials'][0]['condition'] = '';
        $this->from(route('admin.monitoring.section.edit', [self::RECORD, 'materials']))->patch($this->url(), $invalid)->assertRedirect();
        $this->get(route('admin.monitoring.section.edit', [self::RECORD, 'materials']))->assertOk()->assertSee($invalid['materials_revision']);
    }

    public function test_loaded_snapshot_remains_stable_and_unrelated_sections_do_not_change_its_revision(): void
    {
        $snapshot = $this->record()->load('materials'); $revision = app(MaterialsMonitoringService::class)->revision($snapshot);
        DB::table('monitoring_records')->where('id', self::RECORD)->update(['income_version' => 7, 'updated_at' => '2026-03-01']);
        $this->assertSame($revision, app(MaterialsMonitoringService::class)->revision($this->record()));
        $this->patchJson($this->url(), $this->payload([$this->entry()]))->assertOk();
        $this->assertSame($revision, app(MaterialsMonitoringService::class)->revision($snapshot));
        $this->assertNotSame($revision, app(MaterialsMonitoringService::class)->revision($this->record()));
    }

    public function test_mixed_full_update_rolls_back_income_and_status_when_materials_validation_fails(): void
    {
        $this->patchJson($this->url(), $this->payload([$this->entry()]))->assertOk();
        $data = $this->full($this->payload([$this->entry()]));
        $data['project_status'] = 'suspended';
        $data['production_revision'] = app(ProductionMonitoringService::class)->revision($this->record());
        $data['income_revision'] = app(\App\Services\IncomeMonitoringService::class)->revision($this->record());
        $data['production'] = [['id' => self::PRODUCTION, 'project_product_id' => self::PRODUCT,
            'income' => [['period_type' => 'quarterly', 'gross_income' => '10.00', 'remarks' => 'Unsaved Income']]]];
        $before = $this->saved();
        $this->patchJson(route('admin.monitoring.update', self::RECORD), $data)->assertUnprocessable();
        $this->assertDatabaseCount('monitoring_income', 0); $this->assertSame('ongoing', $this->record()->project_status);
        $this->assertSame(0, $this->record()->income_version); $this->assertSame(1, $this->record()->materials_version);
        $this->assertSame($before, $this->saved());
    }

    public function test_full_update_html_conflict_keeps_other_submitted_section_drafts(): void
    {
        $data = $this->full($this->payload([$this->entry()]));
        $data['production_revision'] = app(ProductionMonitoringService::class)->revision($this->record());
        $data['production'] = [['id' => self::PRODUCTION, 'project_product_id' => self::PRODUCT, 'remarks' => 'Preserved Production draft']];
        $this->patchJson($this->url(), $this->payload())->assertOk();
        $this->patch(route('admin.monitoring.update', self::RECORD), $data)->assertStatus(409)->assertSee('Preserved Production draft');
        $this->assertSame([], $this->saved());
    }

    public function test_success_destination_displays_exactly_one_global_result(): void
    {
        $this->patchJson($this->url(), $this->payload([$this->entry()]))->assertOk();
        $page = $this->get(route('admin.monitoring.workspace', [self::RECORD, 'materials']))->assertOk()->assertSee('Materials updated.');
        $this->assertSame(1, substr_count($page->getContent(), 'id="global-feedback-modal"'));
        $this->assertStringNotContainsString('id="monitoring-materials-conflict-modal"', $page->getContent());
        $this->get(route('admin.monitoring.workspace', [self::RECORD, 'materials']))->assertOk()->assertDontSee('Materials updated.');
    }
}

