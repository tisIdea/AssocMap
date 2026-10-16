<?php

namespace Tests\Feature;

use App\Models\MonitoringRecord;
use App\Models\UserProfile;
use App\Services\MonitoringService;
use App\Services\ProductionMonitoringService;
use App\Services\SupabaseAuthService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\IsolatedDatabaseManager;
use Tests\TestCase;

class ProductionMonitoringTest extends TestCase
{
    private const ADMIN = '11111111-1111-4111-8111-111111111111';
    private const OFFICER = '22222222-2222-4222-8222-222222222222';
    private const ACCOUNT = '33333333-3333-4333-8333-333333333333';
    private const ASSOCIATION = '44444444-4444-4444-8444-444444444444';
    private const PROJECT = '55555555-5555-4555-8555-555555555555';
    private const PRODUCT = '66666666-6666-4666-8666-666666666666';
    private const OTHER_PRODUCT = '77777777-7777-4777-8777-777777777777';
    private string $identity = self::ADMIN;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertInstanceOf(IsolatedDatabaseManager::class, DB::getFacadeRoot());
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        Http::swap((new Factory)->preventStrayRequests());
        $this->withoutVite();
        $this->schema();
        $this->fixtures();
        $auth = Mockery::mock(SupabaseAuthService::class);
        $auth->shouldReceive('getUser')->andReturnUsing(fn () => ['id' => $this->identity]);
        $this->app->instance(SupabaseAuthService::class, $auth);
    }

    protected function tearDown(): void
    {
        try { parent::tearDown(); } finally { HandleExceptions::flushState($this); }
    }

    private function schema(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id(); $table->string('code'); $table->string('name'); $table->boolean('is_active'); $table->timestamps();
        });
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->unsignedBigInteger('role_id');
            $table->string('display_name'); $table->boolean('is_active'); $table->timestamps();
        });
        Schema::create('user_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('auth_user_id')->nullable(); $table->string('status');
            $table->string('last_error_code')->nullable(); $table->timestamp('accepted_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable(); $table->timestamps();
        });
        Schema::create('associations', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->string('name'); $table->string('status');
            $table->uuid('primary_field_officer_id')->nullable(); $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::create('association_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('association_id'); $table->uuid('user_id'); $table->boolean('is_active');
        });
        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('association_id'); $table->string('title');
            $table->integer('program_year'); $table->string('status'); $table->timestamp('archived_at')->nullable();
            $table->timestamp('closed_at')->nullable(); $table->timestamps();
        });
        Schema::create('project_products', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('project_id'); $table->string('product_name');
            $table->string('product_type'); $table->string('default_unit'); $table->boolean('is_active'); $table->timestamps();
        });
        Schema::create('project_materials', function (Blueprint $table) { $table->uuid('id')->primary(); $table->uuid('project_id'); });
        Schema::create('quarters', function (Blueprint $table) { $table->smallInteger('id')->primary(); $table->integer('quarter_number'); $table->string('name'); });
        Schema::create('monitoring_records', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('project_id'); $table->integer('year'); $table->smallInteger('quarter_id');
            $table->date('monitoring_date'); $table->uuid('monitored_by'); $table->boolean('site_validated');
            $table->string('project_status'); $table->text('issues_encountered')->nullable(); $table->text('actions_taken')->nullable();
            $table->text('overall_remarks')->nullable(); $table->uuid('created_by'); $table->uuid('updated_by'); $table->timestamps();
            $table->unique(['project_id', 'year', 'quarter_id']);
        });
        Schema::create('monitoring_visit_reports', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('monitoring_record_id'); $table->date('monitoring_date');
            $table->boolean('site_validated'); $table->uuid('monitored_by'); $table->uuid('created_by'); $table->uuid('updated_by');
            $table->uuid('conducted_by_user_id')->nullable(); $table->string('conducted_by')->nullable();
            $table->text('issues_encountered')->nullable(); $table->text('actions_taken')->nullable();
            $table->text('overall_remarks')->nullable(); $table->boolean('is_legacy_import'); $table->timestamps();
        });
        Schema::create('monitoring_production', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('monitoring_record_id'); $table->uuid('project_product_id');
            $table->decimal('target_output', 14, 2); $table->decimal('actual_output', 14, 2); $table->string('unit');
            $table->text('remarks')->nullable(); $table->timestamps(); $table->unique(['monitoring_record_id', 'project_product_id']);
        });
        Schema::create('monitoring_production_monthly', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('monitoring_production_id'); $table->smallInteger('month');
            $table->integer('activity_count'); $table->decimal('total_quantity', 14, 2); $table->text('remarks')->nullable();
            $table->timestamps(); $table->unique(['monitoring_production_id', 'month']);
        });
        Schema::create('monitoring_income', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('monitoring_production_id'); $table->string('period_type');
            $table->smallInteger('month')->nullable(); $table->decimal('gross_income', 14, 2); $table->text('remarks')->nullable(); $table->timestamps();
        });
        Schema::create('monitoring_materials', function (Blueprint $table) { $table->uuid('id')->primary(); $table->uuid('monitoring_record_id'); $table->uuid('project_material_id'); });
    }

    private function fixtures(): void
    {
        foreach ([1 => 'system_admin', 2 => 'field_officer', 3 => 'association_account'] as $id => $code) {
            DB::table('roles')->insert(['id' => $id, 'code' => $code, 'name' => $code, 'is_active' => true]);
        }
        foreach ([self::ADMIN => 1, self::OFFICER => 2, self::ACCOUNT => 3] as $id => $role) {
            DB::table('user_profiles')->insert(['id' => $id, 'role_id' => $role, 'display_name' => 'Synthetic Actor', 'is_active' => true]);
        }
        DB::table('associations')->insert(['id' => self::ASSOCIATION, 'name' => 'Synthetic Association', 'status' => 'active', 'primary_field_officer_id' => self::OFFICER]);
        DB::table('association_accounts')->insert(['id' => self::ACCOUNT, 'association_id' => self::ASSOCIATION, 'user_id' => self::ACCOUNT, 'is_active' => true]);
        DB::table('projects')->insert(['id' => self::PROJECT, 'association_id' => self::ASSOCIATION, 'title' => 'Synthetic Project', 'program_year' => 2026, 'status' => 'ongoing']);
        foreach ([self::PRODUCT => 'kg', self::OTHER_PRODUCT => 'pieces'] as $id => $unit) {
            DB::table('project_products')->insert(['id' => $id, 'project_id' => self::PROJECT, 'product_name' => $unit.' product', 'product_type' => 'raw', 'default_unit' => $unit, 'is_active' => true]);
        }
        foreach ([11 => 1, 22 => 2, 33 => 3, 44 => 4] as $id => $quarter) {
            DB::table('quarters')->insert(['id' => $id, 'quarter_number' => $quarter, 'name' => 'Q'.$quarter]);
        }
    }

    private function actor(string $id = self::ADMIN): UserProfile { return UserProfile::with('role')->findOrFail($id); }

    private function production(string $product = self::PRODUCT, string $unit = 'kg'): array
    {
        return ['project_product_id' => $product, 'target_output' => '500', 'actual_output' => '999999', 'unit' => $unit,
            'monthly_details' => [['month' => 1, 'activity_count' => 0, 'total_quantity' => '0'],
                ['month' => 2, 'activity_count' => 2, 'total_quantity' => '125.10'],
                ['month' => 3, 'activity_count' => 3, 'total_quantity' => '175.20']]];
    }

    private function payload(): array
    {
        return ['project_id' => self::PROJECT, 'year' => 2026, 'quarter_id' => 11, 'monitoring_date' => '2026-02-15',
            'site_validated' => true, 'project_status' => 'suspended', 'conducted_by' => 'Synthetic Observer',
            'production' => [$this->production()]];
    }

    private function create(): MonitoringRecord { return app(MonitoringService::class)->create($this->payload(), $this->actor()); }

    private function edit(MonitoringRecord $record): array
    {
        $row = $record->production()->with('monthlyDetails')->firstOrFail();
        return ['production_revision' => app(ProductionMonitoringService::class)->revision($record->fresh()), 'project_status' => $record->project_status,
            'production' => [['id' => $row->id, 'project_product_id' => $row->project_product_id, 'unit' => $row->unit,
                'target_output' => $row->target_output, 'monthly_details' => $row->monthlyDetails->map(fn ($month) =>
                    $month->only(['id', 'month', 'activity_count', 'total_quantity', 'remarks']))->all()]]];
    }

    public function test_creation_calculates_products_independently_and_ignores_submitted_totals(): void
    {
        $data = $this->payload();
        $data['production'][] = $this->production(self::OTHER_PRODUCT, 'pieces');
        $data['production'][1]['monthly_details'][1]['total_quantity'] = '4';
        $record = app(MonitoringService::class)->create($data, $this->actor(self::OFFICER));
        $this->assertSame('300.30', $record->production->firstWhere('project_product_id', self::PRODUCT)->actual_output);
        $this->assertSame('179.20', $record->production->firstWhere('project_product_id', self::OTHER_PRODUCT)->actual_output);
        $this->assertSame('pieces', $record->production->firstWhere('project_product_id', self::OTHER_PRODUCT)->unit);
        $this->assertDatabaseCount('monitoring_production_monthly', 6);
        $this->assertDatabaseHas('projects', ['id' => self::PROJECT, 'status' => 'ongoing']);
        $this->assertSame('suspended', $record->project_status);
    }

    public function test_monthly_edit_recalculates_and_stale_second_edit_is_rejected(): void
    {
        $record = $this->create(); $data = $this->edit($record); $stale = $data;
        $data['production'][0]['monthly_details'][1]['total_quantity'] = '5.10';
        $data['production'][0]['actual_output'] = '9000';
        app(MonitoringService::class)->updateSection($record, 'production', $data, $this->actor());
        $this->assertSame('180.30', $record->production()->first()->actual_output);
        try {
            app(MonitoringService::class)->updateSection($record, 'production', $stale, $this->actor());
            $this->fail('Stale Production must not overwrite a newer result.');
        } catch (ValidationException $exception) { $this->assertSame(409, $exception->status); }
        $this->assertSame('180.30', $record->production()->first()->actual_output);
        $this->assertSame('suspended', $record->fresh()->project_status);
        $this->assertDatabaseHas('projects', ['id' => self::PROJECT, 'status' => 'ongoing']);
    }

    public function test_all_zero_quarter_is_saved_and_full_update_uses_the_same_calculation(): void
    {
        $data = $this->payload();
        foreach ($data['production'][0]['monthly_details'] as &$month) {
            $month['activity_count'] = 0; $month['total_quantity'] = '0';
        }
        unset($month);
        $record = app(MonitoringService::class)->create($data, $this->actor());
        $this->assertSame('0.00', $record->production()->first()->actual_output);
        $edit = $this->edit($record);
        $edit['production'][0]['monthly_details'][2]['activity_count'] = 1;
        $edit['production'][0]['monthly_details'][2]['total_quantity'] = '0.25';
        $edit += ['project_id' => self::PROJECT, 'year' => 2026, 'quarter_id' => 11];
        app(MonitoringService::class)->update($record, $edit, $this->actor());
        $this->assertSame('0.25', $record->production()->first()->actual_output);
    }

    public function test_unreconciled_monthly_record_cannot_be_silently_converted(): void
    {
        $record = $this->create();
        $record->production()->first()->update(['actual_output' => '888.00']);
        $data = $this->edit($record);
        try {
            app(MonitoringService::class)->updateSection($record, 'production', $data, $this->actor());
            $this->fail('Unreconciled historical values must remain read-only.');
        } catch (ValidationException) { $this->assertSame('888.00', $record->production()->first()->actual_output); }
        $this->assertDatabaseCount('monitoring_production_monthly', 3);
    }

    public function test_legacy_quarterly_values_are_preserved_without_backfill(): void
    {
        $record = $this->create(); $production = $record->production()->first();
        DB::table('monitoring_production_monthly')->where('monitoring_production_id', $production->id)->delete();
        $before = $production->getRawOriginal();
        $data = ['production_revision' => app(ProductionMonitoringService::class)->revision($record->fresh()),
            'project_status' => $record->project_status, 'production' => [['id' => $production->id, 'project_product_id' => self::PRODUCT]]];
        app(MonitoringService::class)->updateSection($record, 'production', $data, $this->actor());
        $this->assertSame($before, $production->fresh()->getRawOriginal());
        $this->assertDatabaseCount('monitoring_production_monthly', 0);
        $data['production_revision'] = app(ProductionMonitoringService::class)->revision($record->fresh());
        $data['production'][0] += ['monthly_details' => $this->production()['monthly_details'], 'unit' => 'kg', 'target_output' => 500];
        $this->expectException(ValidationException::class);
        app(MonitoringService::class)->updateSection($record, 'production', $data, $this->actor());
    }

    public function test_omitted_production_preserves_months_and_income_and_income_periods_still_work(): void
    {
        $record = $this->create(); $production = $record->production()->first();
        $income = [['period_type' => 'quarterly', 'gross_income' => '1200.50']];
        app(MonitoringService::class)->updateSection($record, 'income', ['production' => [[
            'id' => $production->id, 'project_product_id' => self::PRODUCT, 'income' => $income]]], $this->actor());
        $before = DB::table('monitoring_income')->get()->toArray();
        app(MonitoringService::class)->updateSection($record, 'production', [
            'production_revision' => app(ProductionMonitoringService::class)->revision($record->fresh()),
            'production' => [], 'project_status' => $record->project_status], $this->actor());
        $this->assertDatabaseCount('monitoring_production', 1); $this->assertDatabaseCount('monitoring_production_monthly', 3);
        $this->assertEquals($before, DB::table('monitoring_income')->get()->toArray());
        $this->assertSame('300.30', $production->fresh()->actual_output);
    }

    public static function invalidEdits(): array
    {
        return [['missing month'], ['wrong monthly id'], ['missing monthly id'], ['different product'], ['different unit'], ['deleted months']];
    }

    #[DataProvider('invalidEdits')]
    public function test_invalid_identity_and_incomplete_edits_roll_back(string $case): void
    {
        $record = $this->create(); $data = $this->edit($record);
        match ($case) {
            'missing month' => array_pop($data['production'][0]['monthly_details']),
            'wrong monthly id' => $data['production'][0]['monthly_details'][0]['id'] = self::OTHER_PRODUCT,
            'missing monthly id' => $data['production'][0]['monthly_details'][0]['id'] = null,
            'different product' => $data['production'][0]['project_product_id'] = self::OTHER_PRODUCT,
            'different unit' => $data['production'][0]['unit'] = 'pieces',
            'deleted months' => $data['production'][0]['deleted_monthly_ids'] = [$data['production'][0]['monthly_details'][0]['id']],
        };
        $before = DB::table('monitoring_production_monthly')->get()->toArray();
        try {
            app(MonitoringService::class)->updateSection($record, 'production', $data, $this->actor());
            $this->fail('Invalid monthly edits must fail.');
        } catch (ValidationException) { $this->assertEquals($before, DB::table('monitoring_production_monthly')->get()->toArray()); }
        $this->assertSame('300.30', $record->production()->first()->actual_output);
    }

    public function test_failure_after_a_month_is_written_rolls_back_the_entire_creation(): void
    {
        DB::listen(function ($query) {
            if (str_starts_with($query->sql, 'insert into "monitoring_production_monthly"')
                && DB::table('monitoring_production_monthly')->count() === 2) throw new RuntimeException('Synthetic monthly failure');
        });
        try { $this->create(); $this->fail('The synthetic failure must abort creation.'); }
        catch (RuntimeException $exception) { $this->assertSame('Synthetic monthly failure', $exception->getMessage()); }
        foreach (['monitoring_records', 'monitoring_visit_reports', 'monitoring_production', 'monitoring_production_monthly'] as $table) $this->assertDatabaseCount($table, 0);
    }

    public static function restrictedStates(): array
    {
        return [['inactive'], ['archived association'], ['terminated'], ['completed'], ['legacy completed'], ['archived project'], ['unassigned']];
    }

    public function test_failure_during_monthly_edit_restores_saved_months_and_quarterly_total(): void
    {
        $record = $this->create(); $data = $this->edit($record);
        $data['production'][0]['monthly_details'][0]['total_quantity'] = '25';
        $before = DB::table('monitoring_production_monthly')->get()->toArray();
        DB::listen(function ($query) {
            if (str_starts_with($query->sql, 'update "monitoring_production_monthly"')) {
                throw new RuntimeException('Synthetic edit failure');
            }
        });
        try {
            app(MonitoringService::class)->updateSection($record, 'production', $data, $this->actor());
            $this->fail('The synthetic edit failure must abort the transaction.');
        } catch (RuntimeException $exception) { $this->assertSame('Synthetic edit failure', $exception->getMessage()); }
        $this->assertEquals($before, DB::table('monitoring_production_monthly')->get()->toArray());
        $this->assertSame('300.30', $record->production()->first()->actual_output);
    }

    #[DataProvider('restrictedStates')]
    public function test_lifecycle_and_assignment_rechecked_before_writes(string $state): void
    {
        $record = $this->create(); $data = $this->edit($record);
        match ($state) {
            'inactive' => DB::table('associations')->update(['status' => 'inactive']),
            'archived association' => DB::table('associations')->update(['archived_at' => '2026-01-01']),
            'terminated' => DB::table('projects')->update(['status' => 'terminated', 'closed_at' => '2026-01-01']),
            'completed' => DB::table('projects')->update(['status' => 'completed', 'closed_at' => '2026-01-01']),
            'legacy completed' => DB::table('projects')->update(['status' => 'completed']),
            'archived project' => DB::table('projects')->update(['archived_at' => '2026-01-01']),
            'unassigned' => DB::table('associations')->update(['primary_field_officer_id' => null]),
        };
        $before = DB::table('monitoring_production_monthly')->get()->toArray();
        try {
            app(MonitoringService::class)->updateSection($record, 'production', $data, $this->actor(self::OFFICER));
            $this->fail('Ineligible parents must reject production edits.');
        } catch (ValidationException) { $this->assertEquals($before, DB::table('monitoring_production_monthly')->get()->toArray()); }
        $this->assertSame('300.30', $record->production()->first()->actual_output);
    }

    public function test_product_from_another_project_is_rejected_atomically(): void
    {
        DB::table('project_products')->where('id', self::PRODUCT)->update(['project_id' => self::OTHER_PRODUCT]);
        try { $this->create(); $this->fail('A product must belong to the monitored project.'); }
        catch (ValidationException) { $this->assertDatabaseCount('monitoring_records', 0); }
    }

    public function test_actor_deactivated_after_form_load_is_rejected_at_transaction_boundary(): void
    {
        $record = $this->create(); $data = $this->edit($record); $actor = $this->actor();
        DB::table('user_profiles')->where('id', self::ADMIN)->update(['is_active' => false]);
        try {
            app(MonitoringService::class)->updateSection($record, 'production', $data, $actor);
            $this->fail('An actor deactivated after form load must be rejected.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame('300.30', $record->production()->first()->actual_output);
    }

    public function test_direct_http_completed_and_account_mutations_are_denied_but_history_is_preserved(): void
    {
        $record = $this->create(); $data = $this->edit($record) + ['project_id' => self::PROJECT, 'year' => 2026, 'quarter_id' => 11];
        DB::table('projects')->update(['status' => 'completed']);
        $this->withSession(['supabase_access_token' => 'synthetic-token'])
            ->patchJson(route('admin.monitoring.section.update', [$record, 'production']), $data)->assertStatus(422);
        $this->identity = self::ACCOUNT;
        $this->withSession(['supabase_access_token' => 'synthetic-token'])
            ->patchJson(route('admin.monitoring.section.update', [$record, 'production']), $data)->assertForbidden();
        $this->get(route('association.monitoring.workspace', [$record, 'production']))
            ->assertOk()->assertSee('300.30')->assertDontSee('Manage Production');
        $this->assertDatabaseCount('monitoring_production_monthly', 3);
        $this->assertSame('suspended', $record->fresh()->project_status);
    }

    public function test_http_validation_does_not_accept_missing_month_values_or_arbitrary_actual_output(): void
    {
        $data = $this->payload(); $data['production'][0]['monthly_details'][0]['total_quantity'] = '';
        $this->withSession(['supabase_access_token' => 'synthetic-token'])->postJson(route('admin.monitoring.store'), $data)
            ->assertUnprocessable()->assertJsonValidationErrors('production.0.monthly_details.0.total_quantity');
        $this->assertDatabaseCount('monitoring_records', 0);
        $valid = $this->payload(); $valid['production'][0]['actual_output'] = ['untrusted' => '999999'];
        $this->postJson(route('admin.monitoring.store'), $valid)->assertRedirect();
        $this->assertDatabaseHas('monitoring_production', ['actual_output' => '300.30']);
    }
}
