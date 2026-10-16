<?php

namespace Tests\Feature;

use App\Models\UserProfile;
use App\Services\DashboardService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class DashboardServiceTest extends TestCase
{
    private const USER = '11111111-1111-4111-8111-111111111111';
    private const OTHER = '22222222-2222-4222-8222-222222222222';
    private const OWN = '33333333-3333-4333-8333-333333333333';
    private const OUTSIDE = '44444444-4444-4444-8444-444444444444';
    private const ARCHIVED = '55555555-5555-4555-8555-555555555555';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.dashboard_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('dashboard_test');
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        Schema::create('roles', function (Blueprint $table) { $table->id(); $table->string('code'); $table->boolean('is_active'); });
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->integer('role_id'); $table->string('display_name'); $table->boolean('is_active'); $table->timestamps();
        });
        Schema::create('user_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('auth_user_id')->nullable(); $table->string('email'); $table->string('status');
            $table->string('last_error_code')->nullable(); $table->timestamp('accepted_at')->nullable(); $table->timestamp('last_attempt_at')->nullable(); $table->timestamps();
        });
        Schema::create('municipalities', function (Blueprint $table) { $table->integer('id')->primary(); $table->string('name'); });
        Schema::create('barangays', function (Blueprint $table) { $table->integer('id')->primary(); $table->integer('municipality_id'); });
        Schema::create('areas', function (Blueprint $table) { $table->integer('id')->primary(); $table->integer('barangay_id'); });
        Schema::create('associations', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->integer('area_id'); $table->uuid('primary_field_officer_id');
            $table->string('name'); $table->string('status'); $table->date('saad_phase2_join_date'); $table->timestamp('archived_at')->nullable();
        });
        Schema::create('association_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('user_id'); $table->uuid('association_id'); $table->boolean('is_active');
        });
        Schema::create('members', function (Blueprint $table) {
            $table->integer('id')->primary(); $table->uuid('association_id'); $table->string('member_status'); $table->timestamp('archived_at')->nullable();
        });
        Schema::create('projects', function (Blueprint $table) {
            $table->integer('id')->primary(); $table->uuid('association_id'); $table->integer('saad_activity_id');
            $table->string('title'); $table->string('status'); $table->integer('program_year'); $table->timestamp('archived_at')->nullable(); $table->timestamps();
        });
        Schema::create('trainings', function (Blueprint $table) { $table->integer('id')->primary(); $table->uuid('association_id'); $table->string('status'); });
        Schema::create('training_participants', function (Blueprint $table) {
            $table->integer('training_id'); $table->integer('member_id'); $table->string('attendance_status');
        });
        Schema::create('member_applications', function (Blueprint $table) { $table->id(); $table->uuid('association_id'); $table->string('status'); });
        Schema::create('quarters', function (Blueprint $table) { $table->integer('id'); $table->integer('quarter_number'); });
        Schema::create('monitoring_records', function (Blueprint $table) {
            $table->integer('id')->primary(); $table->integer('project_id'); $table->integer('year'); $table->integer('quarter_id');
            $table->date('monitoring_date'); $table->string('project_status');
        });
        foreach (['field_officer', 'system_admin', 'association_account'] as $index => $role) {
            DB::table('roles')->insert(['id' => $index + 1, 'code' => $role, 'is_active' => true]);
        }
        foreach ([self::USER, self::OTHER] as $id) DB::table('user_profiles')->insert(['id' => $id, 'role_id' => 1, 'display_name' => 'Synthetic officer', 'is_active' => true]);
        DB::table('municipalities')->insert(['id' => 1, 'name' => 'Synthetic municipality']);
        DB::table('barangays')->insert(['id' => 1, 'municipality_id' => 1]);
        DB::table('areas')->insert(['id' => 1, 'barangay_id' => 1]);
        foreach ([[self::OWN, self::USER, null], [self::OUTSIDE, self::OTHER, null], [self::ARCHIVED, self::USER, '2026-01-01']] as [$id, $officer, $archived]) {
            DB::table('associations')->insert(['id' => $id, 'area_id' => 1, 'primary_field_officer_id' => $officer,
                'name' => 'Synthetic '.$id, 'status' => 'active', 'saad_phase2_join_date' => '2024-01-01', 'archived_at' => $archived]);
        }
        foreach ([[1, self::OWN, 'active', null], [2, self::OWN, 'inactive', '2026-01-01'], [3, self::OUTSIDE, 'active', null]] as [$id, $association, $status, $archived]) {
            DB::table('members')->insert(['id' => $id, 'association_id' => $association, 'member_status' => $status, 'archived_at' => $archived]);
        }
        foreach ([[1, self::OWN, null], [2, self::OWN, '2026-01-01'], [3, self::OUTSIDE, null]] as [$id, $association, $archived]) {
            DB::table('projects')->insert(['id' => $id, 'association_id' => $association, 'saad_activity_id' => 1,
                'title' => 'Synthetic project '.$id, 'status' => 'ongoing', 'program_year' => 2026, 'created_at' => '2026-01-01', 'archived_at' => $archived]);
            DB::table('trainings')->insert(['id' => $id, 'association_id' => $association, 'status' => 'completed']);
            DB::table('training_participants')->insert(['training_id' => $id, 'member_id' => $id === 3 ? 3 : 1, 'attendance_status' => 'present']);
            DB::table('monitoring_records')->insert(['id' => $id, 'project_id' => $id, 'year' => 2026, 'quarter_id' => 1,
                'monitoring_date' => '2026-01-01', 'project_status' => 'planned']);
        }
        DB::table('quarters')->insert(['id' => 1, 'quarter_number' => 1]);
        foreach ([self::OWN, self::OWN, self::OUTSIDE, self::ARCHIVED] as $association) {
            DB::table('member_applications')->insert(['association_id' => $association, 'status' => 'pending']);
        }
    }

    protected function tearDown(): void
    {
        DB::purge('dashboard_test');
        parent::tearDown();
    }

    private function request(): Request
    {
        $request = Request::create('/association/dashboard?association_id='.self::OUTSIDE);
        $request->attributes->set('current_user', UserProfile::with('role')->findOrFail(self::USER));

        return $request;
    }

    private function metrics(array $dashboard): array
    {
        return array_column($dashboard['metrics'], 'value', 'key');
    }

    public function test_officer_scope_archives_distinct_attendees_and_reassignment(): void
    {
        $service = app(DashboardService::class);
        $request = $this->request();
        $data = $service->load($request);
        $metrics = $this->metrics($data);
        $this->assertSame(1, $data['scope_count']);
        $this->assertSame(2, $metrics['members_total']);
        $this->assertSame(1, $metrics['members_active']);
        $this->assertSame(2, $metrics['projects_total']);
        $this->assertSame(1, $metrics['projects_unarchived']);
        $this->assertSame(2, $data['training']['records']);
        $this->assertSame(1, $data['training']['distinct_present_members']);
        $this->assertSame(1, $data['monitoring']['records']);
        $this->assertSame('planned', $data['monitoring']['recent'][0]['project_status']);
        $this->assertSame(2, $data['applications']['pending']);
        $this->assertSame([self::OWN], array_column($data['associations'], 'id'));
        DB::table('associations')->where('id', self::OWN)->update(['primary_field_officer_id' => self::OTHER]);
        $next = $service->load($request);
        $this->assertSame(0, $next['scope_count']);
        $this->assertSame(0, $this->metrics($next)['members_total']);
        $this->assertSame([], $next['recent_projects']);
    }

    public function test_association_scope_ignores_client_id_and_denies_revoked_link(): void
    {
        DB::table('user_profiles')->where('id', self::USER)->update(['role_id' => 3]);
        DB::table('association_accounts')->insert(['id' => self::USER, 'user_id' => self::USER, 'association_id' => self::OWN, 'is_active' => true]);
        $service = app(DashboardService::class);
        $request = $this->request();
        $data = $service->load($request);
        $this->assertSame(self::OWN, $data['association']['id']);
        $this->assertSame(2, $this->metrics($data)['members_total']);
        DB::table('association_accounts')->update(['is_active' => false]);
        try {
            $service->load($request);
            $this->fail('Inactive association links must be denied.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_admin_lifecycle_categories_are_disjoint(): void
    {
        DB::table('user_profiles')->where('id', self::USER)->update(['role_id' => 2]);
        $data = app(DashboardService::class)->load($this->request());
        $metrics = $this->metrics($data);
        $this->assertSame(3, $metrics['associations_total']);
        $this->assertSame(2, $metrics['associations_active']);
        $this->assertSame(1, $metrics['associations_archived']);
        $this->assertSame(0, $metrics['associations_inactive']);
        $this->assertSame(3, $metrics['members_total']);
        $this->assertNull($data['applications']);
    }

    public function test_optional_failure_is_unavailable_and_core_failure_is_not_zero(): void
    {
        Schema::drop('trainings');
        $data = app(DashboardService::class)->load($this->request());
        $this->assertNull($data['training']);
        $this->assertNull($this->metrics($data)['training_records']);
        $this->assertSame(2, $this->metrics($data)['members_total']);
        $this->assertNull($data['monitoring']['visits']);
        Schema::drop('members');
        $this->expectException(QueryException::class);
        app(DashboardService::class)->load($this->request());
    }

    public function test_api_dashboard_requires_bearer_authentication(): void
    {
        $this->getJson('/api/v1/association/dashboard')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_assignment_revocation_during_assembly_prevents_dashboard_release(): void
    {
        $changed = false;
        DB::listen(function ($query) use (&$changed) {
            if (! $changed && str_contains($query->sql, 'from "members" as "m"')) {
                $changed = true;
                DB::table('associations')->where('id', self::OWN)->update(['primary_field_officer_id' => self::OTHER]);
            }
        });
        try {
            app(DashboardService::class)->load($this->request());
            $this->fail('A revoked assignment must prevent release of already assembled statistics.');
        } catch (HttpExceptionInterface $error) {
            $this->assertTrue($changed);
            $this->assertSame(403, $error->getStatusCode());
        }
    }

    public function test_transient_scope_expansion_cannot_enter_dashboard_aggregates(): void
    {
        $stage = 0;
        DB::listen(function ($query) use (&$stage) {
            if ($stage === 0 && str_contains($query->sql, 'from "members" as "m"')) {
                $stage = 1;
                DB::table('associations')->where('id', self::OUTSIDE)->update(['primary_field_officer_id' => self::USER]);
            } elseif ($stage === 1 && str_contains($query->sql, 'from "trainings" as "t"')) {
                $stage = 2;
                DB::table('associations')->where('id', self::OUTSIDE)->update(['primary_field_officer_id' => self::OTHER]);
            }
        });
        $data = app(DashboardService::class)->load($this->request());
        $this->assertSame(2, $stage);
        $this->assertSame(2, $this->metrics($data)['projects_total']);
        $this->assertSame(2, $data['training']['records']);
    }
    public function test_final_project_outcomes_are_separate_without_changing_history_or_totals(): void
    {
        DB::table('projects')->where('id', 1)->update(['status' => 'completed']);
        DB::table('projects')->where('id', 2)->update(['status' => 'terminated', 'archived_at' => null]);
        $data = app(DashboardService::class)->load($this->request());
        $chart = collect($data['charts'])->firstWhere('key', 'project_status');
        $this->assertSame(['Completed' => 1, 'Terminated' => 1], array_column($chart['rows'], 'value', 'label'));
        $this->assertSame(2, $this->metrics($data)['projects_total']);
        $this->assertSame('planned', $data['monitoring']['recent'][0]['project_status']);
        $this->assertSame(['planned', 'planned', 'planned'], DB::table('monitoring_records')->orderBy('id')->pluck('project_status')->all());
    }
}

