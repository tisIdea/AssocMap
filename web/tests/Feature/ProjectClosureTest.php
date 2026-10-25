<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\MonitoringRecord;
use App\Models\ProgramActivityRecord;
use App\Models\Project;
use App\Models\Training;
use App\Models\UserProfile;
use App\Services\Audit\AuditLogService;
use App\Services\Auth\SupabaseAuthService;
use App\Services\Monitoring\MonitoringService;
use App\Services\Programs\ProgramActivityService;
use App\Services\Programs\TrainingService;
use App\Services\Projects\ProjectClosureService;
use App\Services\Projects\ProjectService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\IsolatedDatabaseManager;
use Tests\TestCase;

class ProjectClosureTest extends TestCase
{
    private const ADMIN = '11111111-1111-4111-8111-111111111111';
    private const OFFICER = '22222222-2222-4222-8222-222222222222';
    private const ACCOUNT = '33333333-3333-4333-8333-333333333333';
    private const ASSOCIATION = '44444444-4444-4444-8444-444444444444';
    private const PROJECT = '55555555-5555-4555-8555-555555555555';
    private const CHILD = '66666666-6666-4666-8666-666666666666';
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
        // Isolated Laravel decisions only; migration 011 and row locks require PostgreSQL.
        Schema::create('roles', function (Blueprint $table) {
            $table->id(); $table->string('code'); $table->boolean('is_active'); $table->timestamps();
            $table->string('name')->nullable(); $table->text('description')->nullable();
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
            $table->uuid('primary_field_officer_id'); $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::create('saad_activities', function (Blueprint $table) {
            $table->id(); $table->string('code'); $table->string('name'); $table->boolean('is_active'); $table->timestamps();
        });
        Schema::create('fisheries_project_categories', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->boolean('is_active'); $table->timestamps();
        });
        Schema::create('commodities', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('category_id'); $table->string('name');
            $table->boolean('is_active'); $table->timestamps();
        });
        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('association_id');
            $table->unsignedBigInteger('saad_activity_id'); $table->unsignedBigInteger('fisheries_category_id');
            $table->unsignedBigInteger('commodity_id')->nullable(); $table->string('title'); $table->text('description')->nullable();
            $table->integer('program_year'); $table->date('implementation_start_date'); $table->date('implementation_end_date')->nullable();
            $table->string('status'); $table->text('remarks')->nullable();
            $table->timestamp('archived_at')->nullable(); $table->uuid('archived_by')->nullable();
            $table->timestamp('closed_at')->nullable(); $table->text('closure_remarks')->nullable(); $table->uuid('closed_by')->nullable();
            $table->uuid('created_by')->nullable(); $table->uuid('updated_by')->nullable(); $table->timestamps();
        });
        Schema::create('project_products', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('project_id'); $table->string('product_name');
            $table->string('product_type'); $table->string('default_unit')->nullable(); $table->text('description')->nullable();
            $table->boolean('is_active'); $table->timestamps();
        });
        Schema::create('project_materials', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('project_id'); $table->string('item_name');
            $table->decimal('quantity_planned'); $table->decimal('quantity_delivered'); $table->string('unit'); $table->timestamps();
        });
        Schema::create('trainings', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('association_id'); $table->uuid('related_project_id')->nullable();
            $table->unsignedBigInteger('saad_activity_id'); $table->string('title'); $table->text('description')->nullable();
            $table->integer('program_year'); $table->date('start_date'); $table->date('end_date')->nullable();
            $table->string('venue')->nullable(); $table->string('conducted_by')->nullable(); $table->decimal('training_cost')->nullable();
            $table->string('status'); $table->text('remarks')->nullable(); $table->timestamp('archived_at')->nullable();
            $table->uuid('archived_by')->nullable(); $table->uuid('created_by')->nullable(); $table->uuid('updated_by')->nullable(); $table->timestamps();
        });
        Schema::create('training_participants', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('training_id'); $table->uuid('member_id');
            $table->string('attendance_status'); $table->text('remarks')->nullable(); $table->timestamps();
        });
        Schema::create('program_activity_records', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('association_id')->nullable(); $table->uuid('related_project_id')->nullable();
            $table->unsignedBigInteger('saad_activity_id'); $table->string('title'); $table->text('description')->nullable();
            $table->integer('program_year'); $table->date('activity_date'); $table->string('venue')->nullable();
            $table->string('status'); $table->string('conducted_by')->nullable(); $table->uuid('conducted_by_user_id')->nullable();
            $table->text('remarks')->nullable(); $table->timestamp('archived_at')->nullable(); $table->uuid('archived_by')->nullable();
            $table->uuid('created_by')->nullable(); $table->uuid('updated_by')->nullable(); $table->timestamps();
        });
        Schema::create('quarters', function (Blueprint $table) { $table->id(); $table->integer('quarter_number'); });
        Schema::create('monitoring_records', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('project_id'); $table->integer('year'); $table->unsignedBigInteger('quarter_id');
            $table->date('monitoring_date'); $table->string('project_status'); $table->timestamps();
        });
        Schema::create('monitoring_production', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('monitoring_record_id'); $table->uuid('project_product_id');
            $table->decimal('target_output'); $table->decimal('actual_output'); $table->string('unit');
        });
        Schema::create('monitoring_production_monthly', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('monitoring_production_id');
            $table->integer('month'); $table->integer('activity_count'); $table->decimal('total_quantity'); $table->timestamps();
        });
        Schema::create('monitoring_visit_reports', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('monitoring_record_id'); $table->date('monitoring_date'); $table->text('overall_remarks')->nullable();
        });
        Schema::create('monitoring_materials', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('monitoring_record_id'); $table->uuid('project_material_id');
            $table->string('condition'); $table->timestamps();
        });
        Schema::create('monitoring_income', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('monitoring_production_id'); $table->string('period_type');
            $table->integer('month')->nullable(); $table->decimal('gross_income');
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('actor_user_id')->nullable(); $table->string('action_type');
            $table->string('module'); $table->string('entity_type'); $table->text('entity_id');
            $table->json('old_values')->nullable(); $table->json('new_values')->nullable(); $table->timestamp('performed_at');
        });
    }

    private function fixtures(): void
    {
        foreach ([1 => 'system_admin', 2 => 'field_officer', 3 => 'association_account'] as $id => $code) {
            DB::table('roles')->insert(['id' => $id, 'code' => $code, 'is_active' => true]);
        }
        foreach ([self::ADMIN => 1, self::OFFICER => 2, self::ACCOUNT => 3] as $id => $role) {
            DB::table('user_profiles')->insert(['id' => $id, 'role_id' => $role, 'display_name' => 'Synthetic Actor', 'is_active' => true]);
        }
        DB::table('associations')->insert(['id' => self::ASSOCIATION, 'name' => 'Synthetic Association', 'status' => 'active', 'primary_field_officer_id' => self::OFFICER]);
        foreach ([1 => 'FPL_LPD', 2 => 'SP_LT', 3 => 'SYNTHETIC_ACTIVITY'] as $id => $code) {
            DB::table('saad_activities')->insert(['id' => $id, 'code' => $code, 'name' => $code, 'is_active' => true]);
        }
        DB::table('fisheries_project_categories')->insert(['id' => 1, 'name' => 'Synthetic category', 'is_active' => true]);
        DB::table('projects')->insert(['id' => self::PROJECT, 'saad_activity_id' => 1, 'created_at' => '2025-01-01', 'updated_at' => '2025-01-01'] + $this->projectData());
        DB::table('project_products')->insert(['id' => self::CHILD, 'project_id' => self::PROJECT, 'product_name' => 'Historical product', 'product_type' => 'raw', 'is_active' => true]);
        DB::table('trainings')->insert(['id' => self::CHILD, 'created_at' => '2025-01-01', 'updated_at' => '2025-01-01'] + $this->linkedData('training'));
        DB::table('training_participants')->insert(['id' => self::CHILD, 'training_id' => self::CHILD, 'member_id' => self::ACCOUNT, 'attendance_status' => 'present']);
        DB::table('program_activity_records')->insert(['id' => self::CHILD] + $this->linkedData('activity'));
        DB::table('quarters')->insert(['id' => 1, 'quarter_number' => 1]);
        DB::table('monitoring_records')->insert(['id' => self::CHILD, 'project_id' => self::PROJECT, 'year' => 2026, 'quarter_id' => 1, 'monitoring_date' => '2026-01-15', 'project_status' => 'ongoing']);
        DB::table('monitoring_production')->insert(['id' => self::CHILD, 'monitoring_record_id' => self::CHILD, 'project_product_id' => self::CHILD, 'target_output' => 15, 'actual_output' => 12, 'unit' => 'kg']);
        DB::table('project_materials')->insert(['id' => self::CHILD, 'project_id' => self::PROJECT,
            'item_name' => 'Historical material', 'quantity_planned' => 1, 'quantity_delivered' => 1, 'unit' => 'piece']);
        DB::table('monitoring_materials')->insert(['id' => self::CHILD, 'monitoring_record_id' => self::CHILD,
            'project_material_id' => self::CHILD, 'condition' => 'Good']);
        DB::table('monitoring_visit_reports')->insert(['id' => self::CHILD, 'monitoring_record_id' => self::CHILD,
            'monitoring_date' => '2026-01-15', 'overall_remarks' => 'Historical visit']);
        foreach ([1, 2, 3] as $month) DB::table('monitoring_production_monthly')->insert([
            'id' => sprintf('77777777-7777-4777-8777-%012d', $month), 'monitoring_production_id' => self::CHILD,
            'month' => $month, 'activity_count' => 0, 'total_quantity' => 4]);
        DB::table('monitoring_income')->insert(['id' => self::CHILD, 'monitoring_production_id' => self::CHILD, 'period_type' => 'quarterly', 'gross_income' => 500]);
    }

    private function projectData(): array
    {
        return ['association_id' => self::ASSOCIATION, 'fisheries_category_id' => 1, 'title' => 'Synthetic project',
            'program_year' => 2026, 'implementation_start_date' => '2026-01-01', 'status' => 'ongoing', 'remarks' => 'Retain original remarks.'];
    }

    private function linkedData(string $module): array
    {
        return ['association_id' => self::ASSOCIATION, 'related_project_id' => self::PROJECT, 'title' => 'Historical '.$module,
            'program_year' => 2026] + ($module === 'training' ? ['saad_activity_id' => 2, 'start_date' => '2026-01-01', 'status' => 'scheduled']
                : ['saad_activity_id' => 3, 'activity_date' => '2026-01-01', 'status' => 'planned']);
    }

    private function payload(string $outcome = 'completed'): array
    {
        return ['closure_remarks' => "  Implementation concluded.\nPreserve history.  ", ($outcome === 'completed' ? 'completion_confirmation' : 'termination_confirmation') => ($outcome === 'completed' ? 'COMPLETED' : 'TERMINATE'),
            'expected_updated_at' => Project::findOrFail(self::PROJECT)->updated_at->format('Y-m-d\TH:i:s.uP')];
    }

    private function actor(string $id = self::ADMIN): UserProfile { return UserProfile::with('role')->findOrFail($id); }

    private function requestFor(string $id = self::ADMIN): Request
    {
        $request = Request::create('/synthetic/closure', 'POST');
        $request->attributes->set('current_user', $this->actor($id));

        return $request;
    }

    private function close(?array $data = null): Project
    {
        return app(ProjectClosureService::class)->close($this->requestFor(), Project::findOrFail(self::PROJECT), $data ?? $this->payload());
    }

    private function endpoint(string $prefix = 'admin', string $outcome = 'completed'): string
    {
        return route($prefix.($outcome === 'completed' ? '.projects.close' : '.projects.terminate'), self::PROJECT);
    }

    private function finalize(string $outcome): Project
    {
        $service = app(ProjectClosureService::class);
        $project = Project::findOrFail(self::PROJECT);
        return $outcome === 'completed' ? $service->close($this->requestFor(), $project, $this->payload($outcome))
            : $service->terminate($this->requestFor(), $project, $this->payload($outcome));
    }

    public static function outcomes(): array { return [['completed'], ['terminated']]; }

    private function rejected(callable $operation): void
    {
        try { $operation(); $this->fail('This operational change must be rejected.'); }
        catch (ValidationException $exception) { $this->assertNotEmpty($exception->errors()); }
    }

    public static function authorizedActors(): array
    {
        return [[self::ADMIN, 'admin', 'completed'], [self::OFFICER, 'field-officer', 'completed'],
            [self::ADMIN, 'admin', 'terminated'], [self::OFFICER, 'field-officer', 'terminated']];
    }

    #[DataProvider('authorizedActors')]
    public function test_authorized_closure_persists_evidence_and_preserves_all_history(string $actor, string $prefix, string $outcome): void
    {
        $tables = ['project_products', 'project_materials', 'trainings', 'training_participants', 'program_activity_records', 'monitoring_records', 'monitoring_visit_reports', 'monitoring_production', 'monitoring_production_monthly', 'monitoring_income', 'monitoring_materials'];
        $before = [];
        foreach ($tables as $table) $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        $this->identity = $actor;
        $this->withSession(['supabase_access_token' => 'synthetic-test-token'])->postJson($this->endpoint($prefix, $outcome), $this->payload($outcome))->assertOk();
        $project = Project::findOrFail(self::PROJECT);
        $this->assertSame($outcome, $project->status);
        $this->assertNotNull($project->closed_at);
        $this->assertSame($actor, $project->closed_by);
        $this->assertSame("Implementation concluded.\nPreserve history.", $project->closure_remarks);
        $this->assertSame('Retain original remarks.', $project->remarks);
        $this->assertSame(self::ASSOCIATION, $project->association_id);
        $this->assertNull($project->archived_at);
        $this->assertNull($project->implementation_end_date);
        $this->assertSame('active', Association::findOrFail(self::ASSOCIATION)->status);
        foreach ($tables as $table) $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson());
        $event = DB::table('audit_logs')->sole();
        $this->assertSame($outcome === 'completed' ? 'CLOSE PROJECT' : 'TERMINATE PROJECT', $event->action_type);
        $this->assertSame($outcome === 'completed' ? 'project_closure' : 'project_termination', $event->entity_type);
        $this->assertSame($actor, $event->actor_user_id);
        $this->assertSame(self::PROJECT, $event->entity_id);
        $old = json_decode($event->old_values, true, flags: JSON_THROW_ON_ERROR);
        $new = json_decode($event->new_values, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('ongoing', $old['status']);
        $this->assertSame($outcome, $new['status']);
        $this->assertSame($actor, $new['closed_by']);
        $this->assertArrayNotHasKey('closure_remarks', $new);
        $this->assertArrayNotHasKey('closure_remarks', $project->toArray());
    }

    #[DataProvider('outcomes')]
    public function test_unauthenticated_account_and_unassigned_officer_are_denied(string $outcome): void
    {
        $this->post($this->endpoint('admin', $outcome), $this->payload($outcome))->assertRedirect(route('login'));
        $this->withSession(['supabase_access_token' => 'synthetic-test-token']);
        $this->identity = self::ACCOUNT;
        $this->postJson($this->endpoint('admin', $outcome), $this->payload($outcome))->assertForbidden();
        $this->identity = self::OFFICER;
        DB::table('associations')->update(['primary_field_officer_id' => self::ADMIN]);
        $this->postJson($this->endpoint('field-officer', $outcome), $this->payload($outcome))->assertForbidden();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function invalidStates(): array { return [['planned'], ['suspended'], ['cancelled'], ['completed'], ['terminated']]; }

    #[DataProvider('invalidStates')]
    public function test_only_ongoing_projects_can_close(string $status): void
    {
        DB::table('projects')->update(['status' => $status]);
        $this->withSession(['supabase_access_token' => 'synthetic-test-token']);
        foreach (['completed', 'terminated'] as $outcome) {
            $this->postJson($this->endpoint('admin', $outcome), $this->payload($outcome))
                ->assertStatus(in_array($status, ['completed', 'terminated'], true) ? 409 : 422);
        }
        $this->assertNull(Project::findOrFail(self::PROJECT)->closed_at);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function ineligibleParents(): array
    {
        return [['inactive', null], ['active', '2026-01-01']];
    }

    #[DataProvider('ineligibleParents')]
    public function test_parent_lifecycle_blocks_closure(string $status, ?string $archive): void
    {
        DB::table('associations')->update(['status' => $status, 'archived_at' => $archive]);
        $this->withSession(['supabase_access_token' => 'synthetic-test-token']);
        foreach (['completed', 'terminated'] as $outcome) {
            $this->postJson($this->endpoint('admin', $outcome), $this->payload($outcome))->assertUnprocessable();
        }
        $this->assertSame('ongoing', Project::findOrFail(self::PROJECT)->status);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function invalidInput(): array
    {
        return [['closure_remarks', null], ['closure_remarks', " \n\t "], ['closure_remarks', str_repeat('x', 2001)],
            ['closure_remarks', ['invalid']], ['completion_confirmation', null], ['completion_confirmation', 'completed'], ['completion_confirmation', 'TERMINATE'],
            ['completion_confirmation', ' COMPLETED'], ['completion_confirmation', 'COMPLETED '],
            ['completion_confirmation', "COMPLETED\n"], ['completion_confirmation', ['COMPLETED']],
            ['closure_remarks', "\u{200B}\u{2060}\u{FEFF}"],
            ['closure_remarks', "\x01\x1F\x7F\u{0085}"],
            ['closure_remarks', "\u{00AD}\u{061C}\u{202E}\u{E0001}"],
            ['closure_remarks', "\u{034F}\u{115F}\u{17B4}\u{2800}\u{3164}\u{FE0F}\u{E0100}"],
            ['closure_remarks', "\u{E000}\u{F0000}"]];
    }

    #[DataProvider('invalidInput')]
    public function test_validation_is_independent_of_the_frontend(string $field, mixed $value): void
    {
        $this->withSession(['supabase_access_token' => 'synthetic-test-token']);
        foreach (['completed', 'terminated'] as $outcome) {
            $key = $outcome === 'terminated' ? str_replace('completion_confirmation', 'termination_confirmation', $field) : $field;
            $invalid = $outcome === 'terminated' && $field === 'completion_confirmation' && is_string($value)
                ? ($value === 'TERMINATE' ? 'COMPLETED' : str_replace(['COMPLETED', 'completed'], ['TERMINATE', 'terminate'], $value)) : $value;
            $this->postJson($this->endpoint('admin', $outcome), array_replace($this->payload($outcome), [$key => $invalid]))
                ->assertUnprocessable()->assertJsonValidationErrors([$key]);
        }
        $this->assertNull(Project::findOrFail(self::PROJECT)->closed_at);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    #[DataProvider('outcomes')]
    public function test_maximum_remarks_and_duplicate_or_stale_requests(string $outcome): void
    {
        $this->withSession(['supabase_access_token' => 'synthetic-test-token']);
        $stale = $this->payload($outcome);
        DB::table('projects')->update(['updated_at' => '2026-02-01']);
        $this->postJson($this->endpoint('admin', $outcome), $stale)->assertStatus(409);
        $fresh = array_replace($this->payload($outcome), ['closure_remarks' => str_repeat('x', 2000)]);
        $this->postJson($this->endpoint('admin', $outcome), $fresh)->assertOk();
        $this->postJson($this->endpoint('admin', $outcome), $fresh)->assertStatus(409);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public static function visibleUnicodeRemarks(): array
    {
        $cases = [];
        foreach (['completed', 'terminated'] as $outcome) {
            foreach (["\u{00D1}", "Natapos na.\nPreserved history.", "\u{1F41F} Final evidence."] as $remarks) $cases[] = [$outcome, $remarks];
        }
        return $cases;
    }

    #[DataProvider('visibleUnicodeRemarks')]
    public function test_visible_unicode_and_multiline_remarks_remain_supported(string $outcome, string $remarks): void
    {
        $this->withSession(['supabase_access_token' => 'synthetic-test-token']);
        $this->postJson($this->endpoint('admin', $outcome), array_replace($this->payload($outcome), ['closure_remarks' => $remarks]))->assertOk();
        $this->assertSame($remarks, Project::findOrFail(self::PROJECT)->closure_remarks);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_archived_project_and_fresh_actor_assignment_or_role_changes_are_rechecked(): void
    {
        $this->withSession(['supabase_access_token' => 'synthetic-test-token']);
        DB::table('projects')->update(['archived_at' => '2026-01-01']);
        $this->postJson($this->endpoint(), $this->payload())->assertUnprocessable();
        DB::table('projects')->update(['archived_at' => null]);
        $request = $this->requestFor(self::OFFICER);
        DB::table('associations')->update(['primary_field_officer_id' => self::ADMIN]);
        try { app(ProjectClosureService::class)->close($request, Project::findOrFail(self::PROJECT), $this->payload()); $this->fail('A former assignment must not authorize closure.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) { $this->assertSame(403, $exception->getStatusCode()); }
        $request = $this->requestFor();
        DB::table('roles')->where('id', 1)->update(['is_active' => false]);
        try { app(ProjectClosureService::class)->close($request, Project::findOrFail(self::PROJECT), $this->payload()); $this->fail('A revoked role must not authorize closure.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) { $this->assertSame(403, $exception->getStatusCode()); }
        $this->assertDatabaseCount('audit_logs', 0);
    }

    #[DataProvider('outcomes')]
    public function test_audit_failure_rolls_back_the_status_metadata_and_event(string $outcome): void
    {
        $actual = new AuditLogService;
        $audit = Mockery::mock(AuditLogService::class);
        $audit->shouldReceive('record')->once()->andReturnUsing(function (...$args) use ($actual) {
            $this->assertGreaterThan(0, DB::transactionLevel());
            $actual->record(...$args);
            throw new RuntimeException('Synthetic audit failure.');
        });
        $this->app->instance(AuditLogService::class, $audit);
        try { $this->finalize($outcome); $this->fail('Required audit failure must roll back closure.'); }
        catch (RuntimeException $exception) { $this->assertSame('Synthetic audit failure.', $exception->getMessage()); }
        $project = Project::findOrFail(self::PROJECT);
        $this->assertSame('ongoing', $project->status);
        $this->assertNull($project->closed_at);
        $this->assertNull($project->closure_remarks);
        $this->assertNull($project->closed_by);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function completedKinds(): array { return [['completed', true], ['completed', false], ['terminated', true]]; }

    #[DataProvider('completedKinds')]
    public function test_formal_and_legacy_completed_projects_are_permanently_read_only(string $outcome, bool $formal): void
    {
        if ($formal) $this->finalize($outcome); else DB::table('projects')->update(['status' => 'completed']);
        $project = Project::findOrFail(self::PROJECT);
        $before = $project->getRawOriginal();
        $service = app(ProjectService::class);
        foreach (['ongoing', 'planned', 'suspended', 'cancelled'] as $status) {
            $this->rejected(fn () => $service->update($project, array_replace($this->projectData(), ['status' => $status]), $this->actor()));
        }
        $this->rejected(fn () => $service->archive($project, $this->actor()));
        $this->rejected(fn () => $service->unarchive($project, $this->actor()));
        $this->rejected(fn () => DB::transaction(fn () => $service->registerProduct($project,
            ['product_name' => 'Blocked product', 'product_type' => 'raw'], $this->actor(), 'product')));
        $this->assertSame($before, $project->fresh()->getRawOriginal());
        $this->assertDatabaseCount('project_products', 1);
    }

    public function test_ordinary_forms_cannot_create_or_set_completed_and_other_edits_continue(): void
    {
        $service = app(ProjectService::class);
        foreach (['completed', 'terminated'] as $outcome) {
            $final = array_replace($this->projectData(), ['status' => $outcome]);
            $this->rejected(fn () => $service->create($final, $this->actor()));
            $this->rejected(fn () => $service->update(Project::findOrFail(self::PROJECT), $final, $this->actor()));
        }
        $updated = $service->update(Project::findOrFail(self::PROJECT), array_replace($this->projectData(), ['title' => 'Allowed ongoing edit']), $this->actor());
        $this->assertSame('Allowed ongoing edit', $updated->title);
        $this->assertSame('ongoing', $updated->status);
        $this->assertNull($updated->closed_at);
    }

    #[DataProvider('outcomes')]
    public function test_closing_one_project_does_not_block_other_projects_or_unlinked_association_activity(string $outcome): void
    {
        $this->finalize($outcome);
        $actor = $this->actor();
        $other = app(ProjectService::class)->create(array_replace($this->projectData(), ['title' => 'Other ongoing project']), $actor);
        $this->assertSame('ongoing', $other->status);
        app(ProjectService::class)->archive($other, $actor);
        $this->assertNotNull($other->fresh()->archived_at);
        app(ProjectService::class)->unarchive($other, $actor);
        $this->assertNull($other->fresh()->archived_at);
        $training = app(TrainingService::class)->create(array_replace($this->linkedData('training'), ['related_project_id' => null]), $actor);
        $activity = app(ProgramActivityService::class)->create(array_replace($this->linkedData('activity'), ['related_project_id' => null]), $actor);
        $this->assertNull($training->related_project_id);
        $this->assertNull($activity->related_project_id);
        $this->assertSame('active', Association::findOrFail(self::ASSOCIATION)->status);
    }

    public function test_stale_project_ownership_is_rechecked_before_closure(): void
    {
        $project = Project::findOrFail(self::PROJECT);
        $data = $this->payload();
        DB::table('associations')->insert(['id' => self::ACCOUNT, 'name' => 'Other association', 'status' => 'active', 'primary_field_officer_id' => self::OFFICER]);
        DB::table('projects')->update(['association_id' => self::ACCOUNT]);
        try { app(ProjectClosureService::class)->close($this->requestFor(), $project, $data); $this->fail('Stale ownership must reject closure.'); }
        catch (ValidationException $exception) { $this->assertSame(409, $exception->status); }
        $this->assertSame('ongoing', $project->fresh()->status);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    #[DataProvider('outcomes')]
    public function test_completed_parent_blocks_linking_unlinking_participants_and_monitoring(string $outcome): void
    {
        $this->finalize($outcome);
        $actor = $this->actor();
        $training = Training::findOrFail(self::CHILD);
        $activity = ProgramActivityRecord::findOrFail(self::CHILD);
        $before = DB::table('training_participants')->get()->toJson();
        $this->rejected(fn () => app(TrainingService::class)->create($this->linkedData('training'), $actor));
        $this->rejected(fn () => app(ProgramActivityService::class)->create($this->linkedData('activity'), $actor));
        $this->rejected(fn () => app(TrainingService::class)->update($training,
            array_replace($this->linkedData('training'), ['related_project_id' => null, 'participants' => [], 'removed_participant_ids' => [self::CHILD],
                'expected_updated_at' => $training->updated_at->format('Y-m-d\TH:i:s.uP')]), $actor));
        $this->rejected(fn () => app(ProgramActivityService::class)->update($activity,
            array_replace($this->linkedData('activity'), ['related_project_id' => null]), $actor));
        $this->rejected(fn () => app(TrainingService::class)->archive($training, $actor));
        $this->rejected(fn () => app(ProgramActivityService::class)->unarchive($activity, $actor));
        $monitoring = MonitoringRecord::findOrFail(self::CHILD);
        $this->rejected(fn () => app(MonitoringService::class)->updateOverview($monitoring, [], $actor));
        $this->rejected(fn () => app(MonitoringService::class)->createVisitReport($monitoring, [], $actor));
        foreach (['production', 'income', 'materials'] as $section) {
            $this->rejected(fn () => app(MonitoringService::class)->updateSection($monitoring, $section, [], $actor));
        }
        $this->assertSame($before, DB::table('training_participants')->get()->toJson());
        $this->assertSame(self::PROJECT, $training->fresh()->related_project_id);
        $this->assertSame(self::PROJECT, $activity->fresh()->related_project_id);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_historical_detail_escapes_remarks_and_legacy_records_have_no_fabricated_metadata(): void
    {
        $remarks = '<script>alert("synthetic")</script>';
        $this->close(array_replace($this->payload(), ['closure_remarks' => $remarks]));
        $this->withSession(['supabase_access_token' => 'synthetic-test-token']);
        $this->get(route('admin.projects.show', self::PROJECT))->assertOk()->assertSee('COMPLETED')->assertSee($remarks)
            ->assertDontSee($remarks, false)->assertDontSee('data-project-finalization-form', false)->assertSee('View closure audit event');
        $this->identity = self::OFFICER;
        $this->get(route('field-officer.projects.show', self::PROJECT))->assertOk()->assertSee($remarks)->assertDontSee('View closure audit event');
        $this->identity = self::ACCOUNT;
        $this->get(route('admin.projects.show', self::PROJECT))->assertForbidden();
    }

    public function test_legacy_completed_detail_remains_read_only_without_invented_evidence(): void
    {
        DB::table('projects')->update(['status' => 'completed']);
        $this->withSession(['supabase_access_token' => 'synthetic-test-token'])->get(route('admin.projects.show', self::PROJECT))
            ->assertOk()->assertSee('Legacy completed project')->assertDontSee('data-project-finalization-form', false);
        $project = Project::findOrFail(self::PROJECT);
        $this->assertNull($project->closed_at);
        $this->assertNull($project->closure_remarks);
        $this->assertNull($project->closed_by);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    #[DataProvider('outcomes')]
    public function test_first_finalization_wins_duplicate_and_opposite_requests(string $outcome): void
    {
        $stale = Project::findOrFail(self::PROJECT);
        $this->withSession(['supabase_access_token' => 'synthetic-test-token']);
        $opposite = $outcome === 'completed' ? 'terminated' : 'completed';
        $second = $this->payload($opposite);
        $this->postJson($this->endpoint('admin', $outcome), $this->payload($outcome))->assertOk();
        $before = $stale->fresh()->getRawOriginal();
        $this->postJson($this->endpoint('admin', $outcome), $this->payload($outcome))->assertStatus(409);
        $this->postJson($this->endpoint('admin', $opposite), $second)->assertStatus(409);
        $this->rejected(fn () => app(ProjectService::class)->update($stale, $this->projectData(), $this->actor()));
        $this->assertSame($before, $stale->fresh()->getRawOriginal());
        $this->assertDatabaseCount('audit_logs', 1);
        // Actual lock races require separate PostgreSQL connections; SQLite cannot prove them.
    }

    #[DataProvider('outcomes')]
    public function test_inactive_actor_and_changed_role_cannot_finalize(string $outcome): void
    {
        $request = $this->requestFor();
        DB::table('user_profiles')->where('id', self::ADMIN)->update(['is_active' => false]);
        try {
            $service = app(ProjectClosureService::class);
            if ($outcome === 'completed') $service->close($request, Project::findOrFail(self::PROJECT), $this->payload($outcome));
            else $service->terminate($request, Project::findOrFail(self::PROJECT), $this->payload($outcome));
            $this->fail('Stale active actor must be reloaded.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) { $this->assertSame(403, $exception->getStatusCode()); }
        DB::table('user_profiles')->where('id', self::ADMIN)->update(['is_active' => true, 'role_id' => 3]);
        try {
            if ($outcome === 'completed') $service->close($request, Project::findOrFail(self::PROJECT), $this->payload($outcome));
            else $service->terminate($request, Project::findOrFail(self::PROJECT), $this->payload($outcome));
            $this->fail('Stale role must not authorize finalization.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) { $this->assertSame(403, $exception->getStatusCode()); }
        $this->assertSame('ongoing', Project::findOrFail(self::PROJECT)->status);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_termination_validation_uses_only_its_own_modal_error_bag(): void
    {
        $destination = route('admin.projects.show', self::PROJECT);
        $this->withSession(['supabase_access_token' => 'synthetic-test-token'])
            ->post($this->endpoint('admin', 'terminated'), array_replace($this->payload('terminated'), ['closure_remarks' => ['invalid']]))
            ->assertRedirect($destination)->assertSessionHasErrors(['closure_remarks'], null, 'projectTermination');
        $bags = session('errors');
        $this->assertFalse($bags->getBag('projectClosure')->any());
        $this->assertTrue($bags->getBag('projectTermination')->any());
        $this->get($destination)->assertOk()->assertSee('data-open-finalization="true"', false);
    }

    public function test_termination_history_is_escaped_and_authorization_is_unchanged(): void
    {
        $remarks = '<script>synthetic</script>';
        app(ProjectClosureService::class)->terminate($this->requestFor(), Project::findOrFail(self::PROJECT),
            array_replace($this->payload('terminated'), ['closure_remarks' => $remarks]));
        $this->withSession(['supabase_access_token' => 'synthetic-test-token']);
        foreach ([self::ADMIN => 'admin', self::OFFICER => 'field-officer'] as $id => $prefix) {
            $this->identity = $id;
            $this->get(route($prefix.'.projects.show', self::PROJECT))->assertOk()->assertSee('TERMINATED')
                ->assertSee($remarks)->assertDontSee($remarks, false)->assertDontSee('data-project-finalization-form', false);
        }
        $this->identity = self::ACCOUNT;
        $this->get(route('admin.projects.show', self::PROJECT))->assertForbidden();
    }
}
