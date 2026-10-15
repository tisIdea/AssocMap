<?php

namespace Tests\Feature;

use App\Http\Controllers\MonitoringWorkspaceController;
use App\Models\UserProfile;
use App\Services\MonitoringService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedDatabaseManager;
use Tests\TestCase;

class MonitoringPeriodTest extends TestCase
{
    private const ADMIN = '11111111-1111-4111-8111-111111111111';
    private const OFFICER = '22222222-2222-4222-8222-222222222222';
    private const ASSOCIATION = '33333333-3333-4333-8333-333333333333';
    private const OTHER_ASSOCIATION = '44444444-4444-4444-8444-444444444444';
    private const PROJECT = '55555555-5555-4555-8555-555555555555';
    private const OTHER_PROJECT = '66666666-6666-4666-8666-666666666666';
    private const RECORD = '77777777-7777-4777-8777-777777777777';
    private const PERIOD_KEY = 'monitoring_records_project_id_year_quarter_id_key';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertInstanceOf(IsolatedDatabaseManager::class, DB::getFacadeRoot());
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        Http::preventStrayRequests();
        Http::fake();
        $this->withoutVite();
        $this->schema();
        $this->fixtures();
        Route::get('/_test/monitoring-area-errors', fn () => view('admin.areas.modals', [
            'catalog' => ['modules' => ['areas' => ['rows' => []]], 'options' => [
                'municipality_records' => [], 'barangay_records' => [],
            ]],
        ]))->middleware('web');
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            HandleExceptions::flushState($this);
        }
    }

    private function schema(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id(); $table->string('code'); $table->string('name'); $table->boolean('is_active');
            $table->timestamps();
        });
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->unsignedBigInteger('role_id');
            $table->string('display_name'); $table->boolean('is_active'); $table->timestamps();
        });
        Schema::create('associations', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->string('name'); $table->string('status');
            $table->uuid('primary_field_officer_id')->nullable(); $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('association_id'); $table->string('title');
            $table->integer('program_year'); $table->string('status'); $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        foreach (['project_products', 'project_materials'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->uuid('id')->primary(); $table->uuid('project_id'); $table->timestamps();
            });
        }
        Schema::create('quarters', function (Blueprint $table) {
            $table->smallInteger('id')->primary(); $table->smallInteger('quarter_number')->unique();
            $table->string('name');
        });
        Schema::create('monitoring_records', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('project_id'); $table->smallInteger('year');
            $table->smallInteger('quarter_id'); $table->date('monitoring_date');
            $table->uuid('monitored_by')->nullable(); $table->boolean('site_validated')->default(false);
            $table->string('project_status'); $table->text('issues_encountered')->nullable();
            $table->text('actions_taken')->nullable(); $table->text('overall_remarks')->nullable();
            $table->uuid('created_by')->nullable(); $table->uuid('updated_by')->nullable(); $table->timestamps();
            $table->unique(['project_id', 'year', 'quarter_id'], self::PERIOD_KEY);
        });
    }

    private function fixtures(): void
    {
        foreach ([1 => 'system_admin', 2 => 'field_officer'] as $id => $code) {
            DB::table('roles')->insert(['id' => $id, 'code' => $code, 'name' => $code, 'is_active' => true]);
        }
        foreach ([self::ADMIN => 1, self::OFFICER => 2] as $id => $role) {
            DB::table('user_profiles')->insert(['id' => $id, 'role_id' => $role,
                'display_name' => 'Synthetic Actor', 'is_active' => true]);
        }
        foreach ([self::ASSOCIATION, self::OTHER_ASSOCIATION] as $id) {
            DB::table('associations')->insert(['id' => $id, 'name' => 'Synthetic Association', 'status' => 'active',
                'primary_field_officer_id' => $id === self::ASSOCIATION ? self::OFFICER : null]);
        }
        foreach ([self::PROJECT => self::ASSOCIATION, self::OTHER_PROJECT => self::OTHER_ASSOCIATION] as $id => $association) {
            DB::table('projects')->insert(['id' => $id, 'association_id' => $association,
                'title' => 'Same Synthetic Project Title', 'program_year' => 2025, 'status' => 'ongoing']);
        }
        foreach ([11 => 1, 22 => 2, 33 => 3, 44 => 4] as $id => $number) {
            DB::table('quarters')->insert(['id' => $id, 'quarter_number' => $number, 'name' => 'Q'.$number]);
        }
    }

    private function actor(string $id = self::ADMIN): UserProfile
    {
        return UserProfile::with('role')->findOrFail($id);
    }

    private function data(int $quarter = 11): array
    {
        return ['project_id' => self::PROJECT, 'year' => 2026, 'quarter_id' => $quarter,
            'monitoring_date' => '2026-01-15', 'site_validated' => true, 'project_status' => 'ongoing'];
    }

    private function insertPeriod(string $id = self::RECORD, string $project = self::PROJECT, int $quarter = 11): void
    {
        DB::table('monitoring_records')->insert(['id' => $id, 'project_id' => $project, 'year' => 2026,
            'quarter_id' => $quarter, 'monitoring_date' => '2026-01-15', 'project_status' => 'cancelled']);
    }

    public function test_period_choices_use_project_identity_and_current_officer_scope(): void
    {
        $this->insertPeriod();
        $this->insertPeriod('88888888-8888-4888-8888-888888888888', self::OTHER_PROJECT, 22);
        $controller = new MonitoringWorkspaceController;
        $request = Request::create('/admin/monitoring/create');
        $request->attributes->set('current_user', $this->actor());
        $admin = $controller->createPage($request)->getData();
        $this->assertCount(2, $admin['occupiedPeriods']);
        $this->assertSame(['project_id', 'year', 'quarter_id'], array_keys($admin['occupiedPeriods']->first()->toArray()));
        $this->assertSame(11, $admin['occupiedPeriods']->firstWhere('project_id', self::PROJECT)->quarter_id);

        $request->attributes->set('current_user', $this->actor(self::OFFICER));
        $officer = $controller->createPage($request)->getData();
        $this->assertSame([self::PROJECT], $officer['projects']->modelKeys());
        $this->assertSame([self::PROJECT], $officer['occupiedPeriods']->pluck('project_id')->all());
        DB::table('associations')->where('id', self::ASSOCIATION)->update(['primary_field_officer_id' => null]);
        $reassigned = $controller->createPage($request)->getData();
        $this->assertCount(0, $reassigned['projects']);
        $this->assertCount(0, $reassigned['occupiedPeriods']);
        Http::assertNothingSent();
    }

    public function test_service_rejects_existing_period_before_creating_any_resources(): void
    {
        $this->insertPeriod();
        try {
            app(MonitoringService::class)->create($this->data() + ['production' => [[
                'new_product' => ['product_name' => 'Synthetic staged product', 'product_type' => 'raw'],
                'target_output' => 1, 'actual_output' => 1, 'unit' => 'kg',
            ]]], $this->actor());
            $this->fail('An occupied monitoring period must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quarter_id', $exception->errors());
        }
        $this->assertSame(1, DB::table('monitoring_records')->count());
        $this->assertSame(0, DB::table('project_products')->count());
        $this->assertSame(0, DB::table('project_materials')->count());
        Http::assertNothingSent();
    }

    public function test_database_fixture_rejects_duplicate_identity_but_allows_another_project(): void
    {
        $this->insertPeriod();
        $this->insertPeriod('88888888-8888-4888-8888-888888888888', self::OTHER_PROJECT);
        try {
            $this->insertPeriod('99999999-9999-4999-8999-999999999999');
            $this->fail('The period uniqueness constraint must reject a duplicate.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', $exception->errorInfo[0]);
        }
        $this->assertSame(2, DB::table('monitoring_records')->count());
    }

    #[DataProvider('constraintErrors')]
    public function test_only_the_postgresql_period_constraint_is_a_controlled_duplicate(string $state, string $constraint, bool $duplicate): void
    {
        $actor = $this->actor();
        $cause = new \PDOException('Synthetic constraint error.');
        $cause->errorInfo = [$state, 7, 'ERROR:  duplicate key value violates unique constraint "'.$constraint."\"\nDETAIL: Synthetic detail."];
        $exception = new QueryException('synthetic', 'synthetic insert', ['"'.self::PERIOD_KEY.'"'], $cause);
        $manager = DB::getFacadeRoot();
        $double = Mockery::mock();
        $double->shouldReceive('transaction')->once()->andThrow($exception);
        DB::swap($double);
        try {
            try {
                app(MonitoringService::class)->create($this->data(), $actor);
                $this->fail('The synthetic constraint exception must propagate or be classified.');
            } catch (ValidationException $caught) {
                $this->assertTrue($duplicate);
                $this->assertSame(['quarter_id'], array_keys($caught->errors()));
            } catch (QueryException $caught) {
                $this->assertFalse($duplicate);
                $this->assertSame($exception, $caught);
            }
        } finally {
            DB::swap($manager);
        }
        $this->assertSame(0, DB::table('monitoring_records')->count());
        Http::assertNothingSent();
    }

    public static function constraintErrors(): array
    {
        return [
            'period collision' => ['23505', self::PERIOD_KEY, true],
            'unrelated uniqueness with key text in bindings' => ['23505', 'unrelated_unique_key', false],
            'wrong SQLSTATE' => ['23514', self::PERIOD_KEY, false],
        ];
    }

    public function test_monitoring_validation_does_not_restore_the_add_area_modal(): void
    {
        $errors = (new ViewErrorBag)->put('default', new MessageBag(['quarter_id' => 'Synthetic duplicate monitoring period.']));
        $response = $this->withSession(['_old_input' => ['monitoring_form' => 'editor'], 'errors' => $errors])
            ->get('/_test/monitoring-area-errors')->assertOk();
        $response->assertSee('data-has-errors="false"', false)->assertDontSee('data-area-validation', false)
            ->assertDontSee('Synthetic duplicate monitoring period.');
        Http::assertNothingSent();
    }

    public function test_area_validation_still_restores_its_own_modal(): void
    {
        $errors = (new ViewErrorBag)->put('default', new MessageBag(['name' => 'Synthetic area name error.']));
        $this->withSession(['_old_input' => ['area_type' => 'Municipality'], 'errors' => $errors])
            ->get('/_test/monitoring-area-errors')->assertOk()->assertSee('data-has-errors="true"', false)
            ->assertSee('data-area-validation', false)->assertSee('Synthetic area name error.');
        Http::assertNothingSent();
    }
}
