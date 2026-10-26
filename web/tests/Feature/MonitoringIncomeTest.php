<?php

namespace Tests\Feature;

use App\Models\MonitoringRecord;
use App\Models\UserProfile;
use App\Services\Auth\SupabaseAuthService;
use App\Services\Monitoring\IncomeMonitoringService;
use App\Services\Monitoring\MonitoringService;
use App\Services\Monitoring\ProductionMonitoringService;
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

class MonitoringIncomeTest extends TestCase
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
    private function url(string $prefix = 'admin.monitoring'): string { return route($prefix.'.section.update', [self::RECORD, 'income']); }
    private function payload(array $rows = [], array $removed = []): array
    {
        return ['project_id' => self::PROJECT, 'year' => 2026, 'quarter_id' => 11,
            'income_revision' => app(IncomeMonitoringService::class)->revision($this->record()),
            'production' => [['id' => self::PRODUCTION, 'project_product_id' => self::PRODUCT, 'income' => $rows, 'deleted_income_ids' => $removed]]];
    }
    private function entry(int $month = 1, string $amount = '100.10'): array
    {
        return ['period_type' => 'monthly', 'month' => $month, 'gross_income' => $amount, 'remarks' => 'Synthetic Income'];
    }
    private function saved(): array
    {
        return $this->record()->production()->firstOrFail()->income()->orderBy('month')->get()
            ->map(fn ($row) => $row->only(['id', 'period_type', 'month', 'gross_income', 'remarks']))->all();
    }

    private function foreignIncome(): array
    {
        $association = (string) Str::uuid(); $project = (string) Str::uuid(); $product = (string) Str::uuid();
        $record = (string) Str::uuid(); $production = (string) Str::uuid(); $income = (string) Str::uuid();
        foreach (['associations' => [$association, ['primary_field_officer_id' => self::ADMIN]],
            'projects' => [$project, ['association_id' => $association]], 'project_products' => [$product, ['project_id' => $project]],
            'monitoring_records' => [$record, ['project_id' => $project]],
            'monitoring_production' => [$production, ['monitoring_record_id' => $record, 'project_product_id' => $product]]] as $table => [$id, $changes]) {
            $original = (array) DB::table($table)->first();
            DB::table($table)->insert(array_replace($original, $changes, ['id' => $id]));
        }
        DB::table('monitoring_income')->insert(['id' => $income, 'monitoring_production_id' => $production,
            'period_type' => 'quarterly', 'month' => null, 'gross_income' => '900.00']);
        return [$record, $income];
    }

    public function test_authorized_creation_update_and_explicit_deletion_keep_identifiers_and_precision(): void
    {
        $revision = $this->payload()['income_revision'];
        $this->patchJson($this->url(), $this->payload([$this->entry(1, '999999999999.99')]))->assertOk()->assertSessionHas('success', 'Income updated.');
        $saved = $this->saved();
        $this->assertSame('999999999999.99', $saved[0]['gross_income']);
        $this->assertNotSame($revision, $this->payload()['income_revision']);
        $saved[0]['gross_income'] = '0.00';
        $this->identity = self::OFFICER;
        $this->patchJson($this->url('field-officer.monitoring'), $this->payload($saved))->assertOk();
        $this->assertSame($saved[0]['id'], $this->saved()[0]['id']);
        $this->assertSame('0.00', $this->saved()[0]['gross_income']);
        $this->patchJson($this->url('field-officer.monitoring'), $this->payload([], [$saved[0]['id']]))->assertOk();
        $this->assertDatabaseCount('monitoring_income', 0);
        $this->assertSame(3, $this->record()->income_version);
    }

    public function test_two_editors_addition_conflict_preserves_income_report_facts_and_newer_save_succeeds(): void
    {
        $a = $this->payload([$this->entry(1)]);
        $b = $this->payload([$this->entry(2, '200.20')]);
        $this->assertSame($a['income_revision'], $b['income_revision']);
        $this->patchJson($this->url(), $a)->assertOk();
        $before = $this->saved(); $version = $this->record()->income_version;
        $this->identity = self::OFFICER;
        $this->patchJson($this->url('field-officer.monitoring'), $b)->assertStatus(409)->assertJsonValidationErrors('income_revision')
            ->assertJsonPath('code', 'monitoring_income_conflict')
            ->assertJsonPath('latest_saved_url', route('field-officer.monitoring.section.edit', [self::RECORD, 'income']));
        $this->assertSame($before, $this->saved());
        $this->assertSame('100.10', $this->saved()[0]['gross_income']);
        $this->assertSame($version, $this->record()->income_version);
        $this->patchJson($this->url('field-officer.monitoring'), $this->payload([$this->entry(2, '200.20')]))->assertOk();
        $this->assertSame(['100.10', '200.20'], array_column($this->saved(), 'gross_income'));
    }

    public function test_omission_alone_never_deletes_saved_income(): void
    {
        $this->patchJson($this->url(), $this->payload([$this->entry()]))->assertOk();
        $before = $this->saved();
        $this->patchJson($this->url(), $this->payload())->assertOk();
        $this->assertSame($before, $this->saved());
    }

    public function test_revision_advances_when_values_return_to_the_original_with_a_frozen_clock(): void
    {
        $this->freezeTime();
        $this->patchJson($this->url(), $this->payload([$this->entry()]))->assertOk();
        $original = $this->payload($this->saved());
        $changed = $this->saved(); $changed[0]['gross_income'] = '5.00';
        $this->patchJson($this->url(), $this->payload($changed))->assertOk();
        $this->patchJson($this->url(), $this->payload($original['production'][0]['income']))->assertOk();
        $this->patchJson($this->url(), $original)->assertStatus(409);
        $this->assertSame('100.10', $this->saved()[0]['gross_income']);
    }

    public function test_deleted_rows_make_older_updates_and_removals_conflict_instead_of_mutating(): void
    {
        $this->patchJson($this->url(), $this->payload([$this->entry()]))->assertOk();
        $stale = $this->payload($this->saved());
        $remove = $this->payload([], [$this->saved()[0]['id']]);
        $this->patchJson($this->url(), $remove)->assertOk();
        $this->patchJson($this->url(), $stale)->assertStatus(409);
        $this->patchJson($this->url(), $remove)->assertStatus(409);
        $this->assertDatabaseCount('monitoring_income', 0);
    }

    #[DataProvider('invalidRemovals')]
    public function test_forged_duplicate_and_edit_remove_identifiers_roll_back(string $case): void
    {
        $this->patchJson($this->url(), $this->payload([$this->entry()]))->assertOk();
        $before = $this->saved();
        $id = $before[0]['id'];
        $removed = match ($case) {
            'forged' => ['99999999-9999-4999-8999-999999999999'],
            'foreign' => [$this->foreignIncome()[1]],
            'malformed' => ['invalid'], 'duplicate' => [$id, $id], default => [$id],
        };
        $data = $this->payload($case === 'edit and remove' ? $before : [], $removed);
        $this->patchJson($this->url(), $data)->assertUnprocessable();
        $this->assertSame($before, $this->saved());
        $this->assertSame(1, $this->record()->income_version);
    }
    public static function invalidRemovals(): array { return [['forged'], ['foreign'], ['malformed'], ['duplicate'], ['edit and remove']]; }

    #[DataProvider('restrictedStates')]
    public function test_assignment_and_parent_changes_are_rechecked_inside_the_transaction(string $state): void
    {
        $data = $this->payload([$this->entry()]); $actor = $this->actor(self::OFFICER); $record = $this->record();
        match ($state) {
            'unassigned', 'other association' => DB::table('associations')->update(['primary_field_officer_id' => self::ADMIN]),
            'inactive association' => DB::table('associations')->update(['status' => 'inactive']),
            'archived association' => DB::table('associations')->update(['archived_at' => '2026-01-01']),
            'archived project' => DB::table('projects')->update(['archived_at' => '2026-01-01']),
            'inactive actor' => DB::table('user_profiles')->where('id', self::OFFICER)->update(['is_active' => false]),
            default => DB::table('projects')->update(['status' => $state, 'closed_at' => '2026-01-01']),
        };
        try {
            app(MonitoringService::class)->updateSection($record, 'income', $data, $actor);
            $this->fail('Current authorization and parent state must be enforced.');
        } catch (ValidationException|\Symfony\Component\HttpKernel\Exception\HttpException) {
            $this->assertDatabaseCount('monitoring_income', 0);
            $this->assertSame(0, $this->record()->income_version);
        }
    }
    public static function restrictedStates(): array
    {
        return [['unassigned'], ['inactive association'], ['archived association'], ['archived project'], ['inactive actor'], ['completed'], ['terminated']];
    }

    public function test_cross_association_officer_and_association_account_http_access_is_denied(): void
    {
        $data = $this->payload([$this->entry()]);
        [$foreignRecord, $foreignIncome] = $this->foreignIncome();
        $this->identity = self::OFFICER;
        $foreign = MonitoringRecord::findOrFail($foreignRecord);
        $data['project_id'] = $foreign->project_id;
        $data['income_revision'] = app(IncomeMonitoringService::class)->revision($foreign);
        $parent = $foreign->production()->firstOrFail();
        $data['production'][0]['id'] = $parent->id;
        $data['production'][0]['project_product_id'] = $parent->project_product_id;
        $this->patchJson(route('field-officer.monitoring.section.update', [$foreignRecord, 'income']), $data)->assertNotFound();
        $this->identity = self::ACCOUNT;
        $this->patchJson($this->url(), $this->payload([$this->entry()]))->assertForbidden();
        $this->assertDatabaseCount('monitoring_income', 1);
        $this->assertDatabaseHas('monitoring_income', ['id' => $foreignIncome, 'gross_income' => '900.00']);
    }

    public function test_failure_after_income_writes_rolls_back_amounts_removals_and_version(): void
    {
        $this->patchJson($this->url(), $this->payload([$this->entry()]))->assertOk();
        $before = $this->saved();
        DB::listen(function ($query) {
            if (str_starts_with($query->sql, 'update "monitoring_records"') && str_contains($query->sql, 'income_version')) {
                throw new RuntimeException('Synthetic Income save failure');
            }
        });
        try {
            app(MonitoringService::class)->updateSection($this->record(), 'income', $this->payload([$this->entry(2)], [$before[0]['id']]), $this->actor());
            $this->fail('The failure must abort all Income mutations.');
        } catch (RuntimeException $exception) { $this->assertSame('Synthetic Income save failure', $exception->getMessage()); }
        $this->assertSame($before, $this->saved());
        $this->assertSame(1, $this->record()->income_version);
    }

    public function test_full_update_endpoint_cannot_bypass_the_income_revision(): void
    {
        $stale = $this->payload([$this->entry(2)]);
        $stale['production_revision'] = app(ProductionMonitoringService::class)->revision($this->record());
        $stale += ['project_status' => 'ongoing', 'monitoring_date' => '2026-01-15', 'site_validated' => true];
        $this->patchJson($this->url(), $this->payload([$this->entry()]))->assertOk();
        $this->patchJson(route('admin.monitoring.update', self::RECORD), $stale)->assertStatus(409);
        $this->assertSame(['100.10'], array_column($this->saved(), 'gross_income'));
    }

    public function test_current_full_update_keeps_the_existing_income_workflow(): void
    {
        $data = $this->payload([$this->entry()]);
        $data['production_revision'] = app(ProductionMonitoringService::class)->revision($this->record());
        $data += ['project_status' => 'ongoing', 'monitoring_date' => '2026-01-15', 'site_validated' => true];
        $this->patchJson(route('admin.monitoring.update', self::RECORD), $data)->assertRedirect();
        $this->assertSame(['100.10'], array_column($this->saved(), 'gross_income'));
        $this->assertSame(1, $this->record()->income_version);
    }

    public function test_missing_invalid_and_other_record_revisions_cannot_mutate_income(): void
    {
        $data = $this->payload([$this->entry()]);
        unset($data['income_revision']);
        $this->patchJson($this->url(), $data)->assertUnprocessable()->assertJsonValidationErrors('income_revision');
        $data['income_revision'] = str_repeat('0', 64);
        $this->patchJson($this->url(), $data)->assertStatus(409);
        [$foreign] = $this->foreignIncome();
        $data['income_revision'] = app(IncomeMonitoringService::class)->revision(MonitoringRecord::findOrFail($foreign));
        $this->patchJson($this->url(), $data)->assertStatus(409);
        $this->assertSame([], $this->saved());
        $this->assertSame(0, $this->record()->income_version);
    }

    public function test_foreign_income_identifier_cannot_be_updated_with_a_current_revision(): void
    {
        [, $foreignId] = $this->foreignIncome();
        $entry = $this->entry(); $entry['id'] = $foreignId;
        $this->patchJson($this->url(), $this->payload([$entry]))->assertUnprocessable();
        $this->assertSame([], $this->saved());
        $this->assertDatabaseHas('monitoring_income', ['id' => $foreignId, 'gross_income' => '900.00']);
        $this->assertSame(0, $this->record()->income_version);
    }

    public function test_database_failure_is_generic_and_rolls_back_income_and_revision(): void
    {
        DB::statement("CREATE TRIGGER synthetic_income_failure BEFORE INSERT ON monitoring_income BEGIN SELECT RAISE(ABORT, 'synthetic private database detail'); END");
        $this->patchJson($this->url(), $this->payload([$this->entry()]))->assertStatus(503)
            ->assertJsonValidationErrors('income')->assertDontSee('synthetic private database detail');
        $this->assertSame([], $this->saved());
        $this->assertSame(0, $this->record()->income_version);
    }

    public function test_editor_revision_describes_the_displayed_snapshot_and_html_conflict_preserves_the_draft(): void
    {
        $data = $this->payload([$this->entry()]);
        $this->get(route('admin.monitoring.section.edit', [self::RECORD, 'income']))->assertOk()
            ->assertSee($data['income_revision'])->assertSee('Income Record Updated')
            ->assertSee('data-income-refresh-confirm', false)->assertSee('Cancel / Keep Editing');
        $this->patchJson($this->url(), $data)->assertOk();
        $draft = $data; $draft['production'][0]['income'][0]['remarks'] = '<script>synthetic draft</script>';
        $this->patch($this->url(), $draft)->assertStatus(409)->assertSee('Submitted draft')
            ->assertSee('Open Latest Saved Income')->assertSee('target="_blank"', false)
            ->assertSee(route('admin.monitoring.section.edit', [self::RECORD, 'income']), false)
            ->assertSee('&lt;script&gt;synthetic draft&lt;\/script&gt;', false)->assertDontSee('<script>synthetic draft</script>', false);
        $this->assertSame('Synthetic Income', $this->saved()[0]['remarks']);
    }

    public function test_loaded_snapshot_revision_does_not_silently_refresh_after_another_writer(): void
    {
        $snapshot = $this->record()->load('production.income');
        $revision = app(IncomeMonitoringService::class)->revision($snapshot);
        $this->patchJson($this->url(), $this->payload([$this->entry()]))->assertOk();
        $this->assertSame($revision, app(IncomeMonitoringService::class)->revision($snapshot));
        $data = $this->payload(); $data['income_revision'] = $revision;
        $this->patchJson($this->url(), $data)->assertStatus(409);
    }

    public function test_retained_income_is_included_in_period_validation(): void
    {
        $this->patchJson($this->url(), $this->payload([$this->entry()]))->assertOk();
        foreach ([$this->entry(), $this->entry(4), ['period_type' => 'quarterly', 'gross_income' => '10.00']] as $invalid) {
            $this->patchJson($this->url(), $this->payload([$invalid]))->assertUnprocessable();
        }
        $this->assertSame(['100.10'], array_column($this->saved(), 'gross_income'));
    }
}
