<?php

namespace Tests\Feature;

use App\Models\MonitoringRecord;
use App\Models\UserProfile;
use App\Services\Monitoring\DailyHarvestMonitoringService;
use App\Services\Monitoring\IncomeMonitoringService;
use App\Services\Monitoring\MaterialsMonitoringService;
use App\Services\Monitoring\MonitoringService;
use App\Services\Monitoring\ProductionMonitoringService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\DailyHarvestFixture;
use Tests\TestCase;

class MonitoringTransactionalAuthorizationTest extends TestCase
{
    use DailyHarvestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['associations.geography_schema_ready' => false, 'associations.direct_geography' => false]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-15 12:00:00', 'Asia/Manila'));
        $this->harvestSchema();
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->timestampTz('auth_sessions_valid_after')->nullable();
            $table->uuid('email_change_hold_id')->nullable();
        });
        Schema::table('monitoring_records', function (Blueprint $table) {
            $table->bigInteger('materials_version')->default(0);
            $table->uuid('created_by')->nullable();
            foreach (['issues_encountered', 'actions_taken', 'overall_remarks'] as $field) {
                $table->text($field)->nullable();
            }
        });
        Schema::table('monitoring_visit_reports', function (Blueprint $table) {
            $table->boolean('site_validated')->default(false);
            $table->boolean('is_legacy_import')->default(false);
            foreach (['monitored_by', 'conducted_by_user_id', 'created_by', 'updated_by'] as $field) {
                $table->uuid($field)->nullable();
            }
            foreach (['conducted_by', 'issues_encountered', 'actions_taken', 'overall_remarks'] as $field) {
                $table->text($field)->nullable();
            }
            $table->timestamps();
        });
        $this->harvestFixtures();
        DB::table('monitoring_visit_reports')->insert(['id' => self::RECORD, 'monitoring_record_id' => self::RECORD,
            'monitoring_date' => '2026-01-15', 'conducted_by' => 'Synthetic visitor']);
    }

    protected function tearDown(): void
    {
        try {
            CarbonImmutable::setTestNow();
            Http::assertNothingSent();
            parent::tearDown();
        } finally {
            HandleExceptions::flushState($this);
        }
    }

    public static function writes(): array
    {
        $cases = [];
        foreach (['create', 'update', 'overview', 'production', 'materials', 'income', 'visit_create', 'visit_update',
            'daily_initialize', 'daily_create', 'daily_edit', 'daily_void', 'daily_goal'] as $operation) {
            foreach (['admin', 'officer'] as $role) {
                $cases[$operation.' '.$role] = [$operation, $role];
            }
        }

        return $cases;
    }

    public static function staleActors(): array
    {
        $cases = [];
        foreach (self::writes() as $name => [$operation, $role]) {
            foreach (['revoked', 'inactive_role', 'changed_role', 'session_cutoff', 'advanced_cutoff', 'email_hold', 'invitation_hold'] as $state) {
                $cases[$name.' '.$state] = [$operation, $role, $state];
            }
        }

        return $cases;
    }

    public static function changedParents(): array
    {
        $cases = [];
        foreach (self::writes() as $name => [$operation, $role]) {
            foreach (['reassigned', 'project_archived', 'completed', 'terminated', 'association_archived', 'association_inactive'] as $state) {
                if ($state !== 'reassigned' || $role === 'officer') {
                    $cases[$name.' '.$state] = [$operation, $role, $state];
                }
            }
        }

        return $cases;
    }

    private function prepare(string $operation, string $role): UserProfile
    {
        if (str_starts_with($operation, 'daily_') && $operation !== 'daily_initialize') {
            DB::table('monitoring_production')->where('id', self::PRODUCTION)->update(['quantity_source' => 'daily_harvest']);
            DB::table('monitoring_harvest_entries')->insert(['id' => self::PRODUCTION, 'monitoring_production_id' => self::PRODUCTION,
                'harvest_date' => '2026-01-01', 'quantity' => '5.00', 'created_by' => self::ADMIN, 'updated_by' => self::ADMIN]);
        }

        return UserProfile::with('role')->findOrFail($role === 'admin' ? self::ADMIN : self::OFFICER);
    }

    private function mutate(string $operation, UserProfile $actor): void
    {
        $record = MonitoringRecord::findOrFail(self::RECORD);
        $monitoring = app(MonitoringService::class);
        $data = ['project_id' => self::PROJECT, 'year' => 2026, 'quarter_id' => 11,
            'monitoring_date' => '2026-01-15', 'site_validated' => false, 'project_status' => 'suspended',
            'monitored_by' => self::OFFICER, 'conducted_by' => 'Synthetic visitor'];
        if (str_starts_with($operation, 'daily_')) {
            $input = match ($operation) {
                'daily_initialize' => ['project_product_id' => self::DAILY_PRODUCT],
                'daily_void' => ['void_reason' => 'Synthetic correction'],
                'daily_goal' => ['target_output' => '10', 'reason' => 'Synthetic plan'],
                default => ['harvest_date' => '2026-02-01', 'quantity' => '2.25'],
            };
            $request = Request::create('/synthetic-harvest', 'POST', $input + [
                'production_revision' => app(ProductionMonitoringService::class)->revision($record),
            ]);
            $request->attributes->set('current_user', $actor);
            $daily = app(DailyHarvestMonitoringService::class);
            match ($operation) {
                'daily_initialize' => $daily->initialize($record, $request),
                'daily_create' => $daily->saveHarvest($record, self::PRODUCTION, $request),
                'daily_edit' => $daily->saveHarvest($record, self::PRODUCTION, $request, self::PRODUCTION),
                'daily_void' => $daily->voidHarvest($record, self::PRODUCTION, self::PRODUCTION, $request),
                'daily_goal' => $daily->reviseGoal($record, self::PRODUCTION, $request),
            };

            return;
        }
        match ($operation) {
            'create' => $monitoring->create(array_replace($data, ['year' => 2025, 'monitoring_date' => '2025-01-15']), $actor),
            'update' => $monitoring->update($record, $data, $actor),
            'overview' => $monitoring->updateOverview($record, $data, $actor),
            'visit_create' => $monitoring->createVisitReport($record, $data, $actor),
            'visit_update' => $monitoring->updateVisitReport($record, self::RECORD, $data, $actor),
            default => $monitoring->updateSection($record, $operation, $data + [
                'production_revision' => app(ProductionMonitoringService::class)->revision($record),
                'materials_revision' => app(MaterialsMonitoringService::class)->revision($record),
                'income_revision' => app(IncomeMonitoringService::class)->revision($record),
            ], $actor),
        };
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['projects', 'project_products', 'project_materials', 'monitoring_records', 'monitoring_visit_reports',
            'monitoring_production', 'monitoring_production_monthly', 'monitoring_materials', 'monitoring_income',
            'monitoring_harvest_entries', 'audit_logs'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $result;
    }

    #[DataProvider('staleActors')]
    public function test_every_write_rejects_an_actor_changed_after_authentication(string $operation, string $role, string $state): void
    {
        if ($state === 'advanced_cutoff') {
            DB::table('user_profiles')->update(['auth_sessions_valid_after' => '2026-01-01 00:00:00']);
        }
        $actor = $this->prepare($operation, $role);
        $this->assertTrue($actor->canAccessApplication());
        match ($state) {
            'revoked' => DB::table('user_profiles')->where('id', $actor->id)->update(['is_active' => false]),
            'inactive_role' => DB::table('roles')->where('id', $actor->role_id)->update(['is_active' => false]),
            'changed_role' => DB::table('user_profiles')->where('id', $actor->id)->update(['role_id' => $role === 'admin' ? 2 : 1]),
            'session_cutoff', 'advanced_cutoff' => DB::table('user_profiles')->where('id', $actor->id)->update(['auth_sessions_valid_after' => now()]),
            'email_hold' => DB::table('user_profiles')->where('id', $actor->id)->update(['email_change_hold_id' => self::ACCOUNT]),
            'invitation_hold' => DB::table('user_invitations')->insert(['id' => self::ACCOUNT, 'auth_user_id' => $actor->id,
                'status' => 'pending', 'created_at' => now()]),
        };
        $before = $this->snapshot();
        try {
            $this->mutate($operation, $actor);
            $this->fail('A stale actor must not commit a mutation.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, DB::transactionLevel());
    }

    #[DataProvider('writes')]
    public function test_valid_writes_check_actor_eligibility_once_per_transaction(string $operation, string $role): void
    {
        $actor = $this->prepare($operation, $role);
        $this->assertTrue($actor->canAccessApplication());
        $before = $this->snapshot();
        $authorization = [];
        DB::listen(function (QueryExecuted $query) use (&$authorization) {
            if (DB::transactionLevel() > 0 && preg_match('/^select .* from "(roles|user_profiles|user_invitations)"/', $query->sql, $match)
                && ($match[1] === 'user_invitations' || str_contains($query->sql, 'limit 1'))) {
                $authorization[] = $match[1];
            }
        });
        $this->mutate($operation, $actor);
        $this->assertNotSame($before, $this->snapshot());
        $this->assertSame(['roles', 'user_profiles', 'user_invitations'], $authorization);
        $this->assertSame(0, DB::transactionLevel());
    }

    #[DataProvider('changedParents')]
    public function test_every_write_preserves_assignment_and_parent_lifecycle_restrictions(string $operation, string $role, string $state): void
    {
        $actor = $this->prepare($operation, $role);
        match ($state) {
            'reassigned' => DB::table('associations')->update(['primary_field_officer_id' => null]),
            'project_archived' => DB::table('projects')->update(['archived_at' => now()]),
            'completed', 'terminated' => DB::table('projects')->update(['status' => $state]),
            'association_archived' => DB::table('associations')->update(['archived_at' => now()]),
            'association_inactive' => DB::table('associations')->update(['status' => 'inactive']),
        };
        $before = $this->snapshot();
        try {
            $this->mutate($operation, $actor);
            $this->fail('An ineligible parent must not allow a mutation.');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status);
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, DB::transactionLevel());
    }
}
