<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\ProgramActivityRecord;
use App\Models\Project;
use App\Models\Training;
use App\Models\UserProfile;
use App\Services\AssociationLifecycleService;
use App\Services\AssociationService;
use App\Services\AuditLogService;
use App\Services\ProgramActivityService;
use App\Services\ProjectService;
use App\Services\TrainingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LogicException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\IsolatedDatabaseManager;
use Tests\TestCase;

class AssociationLifecycleTest extends TestCase
{
    private const ADMIN = '11111111-1111-4111-8111-111111111111';
    private const OFFICER = '22222222-2222-4222-8222-222222222222';
    private const ASSOCIATION = '33333333-3333-4333-8333-333333333333';
    private const DESTINATION = '44444444-4444-4444-8444-444444444444';
    private const PROJECT = '55555555-5555-4555-8555-555555555555';
    private const TRAINING = '66666666-6666-4666-8666-666666666666';
    private const ACTIVITY = '77777777-7777-4777-8777-777777777777';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertInstanceOf(IsolatedDatabaseManager::class, DB::getFacadeRoot());
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        Http::fake();
        Http::preventStrayRequests();
        $this->schema();
        $this->fixtures();
    }

    protected function tearDown(): void
    {
        try {
            Http::assertNothingSent();
            parent::tearDown();
        } finally {
            HandleExceptions::flushState($this);
        }
    }

    private function schema(): void
    {
        // Isolated fixtures exercise service decisions, not PostgreSQL row-lock behavior.
        Schema::create('roles', function (Blueprint $table) {
            $table->id(); $table->string('code'); $table->boolean('is_active'); $table->timestamps();
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
            $table->uuid('id')->primary(); $table->string('name'); $table->string('acronym')->nullable();
            $table->uuid('area_id')->nullable(); $table->date('saad_phase2_join_date')->nullable();
            $table->uuid('primary_field_officer_id'); $table->string('status');
            $table->timestamp('archived_at')->nullable(); $table->uuid('archived_by')->nullable();
            $table->uuid('created_by')->nullable(); $table->uuid('updated_by')->nullable(); $table->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('actor_user_id'); $table->string('action_type');
            $table->string('module'); $table->string('entity_type'); $table->string('entity_id');
            $table->text('old_values')->nullable(); $table->text('new_values')->nullable(); $table->timestamp('performed_at');
        });
        Schema::create('saad_activities', function (Blueprint $table) {
            $table->id(); $table->string('code'); $table->string('name'); $table->boolean('is_active'); $table->timestamps();
        });
        Schema::create('fisheries_project_categories', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->boolean('is_active'); $table->timestamps();
        });
        Schema::create('commodities', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->unsignedBigInteger('category_id'); $table->string('name'); $table->timestamps();
        });
        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('association_id');
            $table->unsignedBigInteger('saad_activity_id'); $table->unsignedBigInteger('fisheries_category_id');
            $table->uuid('commodity_id')->nullable(); $table->string('title'); $table->text('description')->nullable();
            $table->integer('program_year'); $table->date('implementation_start_date');
            $table->date('implementation_end_date')->nullable(); $table->string('status'); $table->text('remarks')->nullable();
            $table->timestamp('archived_at')->nullable(); $table->uuid('archived_by')->nullable();
            $table->uuid('created_by')->nullable(); $table->uuid('updated_by')->nullable(); $table->timestamps();
        });
        foreach (['project_products', 'project_materials'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->uuid('id')->primary(); $table->uuid('project_id'); $table->timestamps();
            });
        }
        Schema::create('monitoring_records', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('project_id');
        });
        Schema::create('trainings', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('association_id'); $table->uuid('related_project_id')->nullable();
            $table->unsignedBigInteger('saad_activity_id'); $table->string('title'); $table->text('description')->nullable();
            $table->integer('program_year'); $table->date('start_date'); $table->date('end_date')->nullable();
            $table->string('venue')->nullable(); $table->string('conducted_by')->nullable();
            $table->decimal('training_cost', 14, 2)->nullable(); $table->string('status'); $table->text('remarks')->nullable();
            $table->timestamp('archived_at')->nullable(); $table->uuid('archived_by')->nullable();
            $table->uuid('created_by')->nullable(); $table->uuid('updated_by')->nullable(); $table->timestamps();
        });
        Schema::create('training_participants', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('training_id'); $table->uuid('member_id');
            $table->string('attendance_status'); $table->text('remarks')->nullable(); $table->timestamps();
        });
        Schema::create('members', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('association_id');
            $table->string('member_status'); $table->timestamp('archived_at')->nullable();
        });
        Schema::create('program_activity_records', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('association_id')->nullable(); $table->uuid('related_project_id')->nullable();
            $table->unsignedBigInteger('saad_activity_id'); $table->string('title'); $table->text('description')->nullable();
            $table->integer('program_year'); $table->date('activity_date'); $table->string('venue')->nullable();
            $table->string('status'); $table->string('conducted_by')->nullable(); $table->uuid('conducted_by_user_id')->nullable();
            $table->text('remarks')->nullable(); $table->timestamp('archived_at')->nullable(); $table->uuid('archived_by')->nullable();
            $table->uuid('created_by')->nullable(); $table->uuid('updated_by')->nullable(); $table->timestamps();
        });
        Schema::create('registration_agencies', function (Blueprint $table) {
            $table->id(); $table->boolean('is_active');
        });
        Schema::create('association_registrations', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('association_id'); $table->unsignedBigInteger('agency_id');
            $table->string('registration_number'); $table->date('registration_date'); $table->date('expiration_date')->nullable();
            $table->boolean('is_primary'); $table->text('remarks')->nullable(); $table->timestamps();
        });
    }

    private function fixtures(): void
    {
        foreach ([1 => 'system_admin', 2 => 'field_officer'] as $id => $code) {
            DB::table('roles')->insert(['id' => $id, 'code' => $code, 'is_active' => true]);
        }
        foreach ([self::ADMIN => 1, self::OFFICER => 2] as $id => $role) {
            DB::table('user_profiles')->insert(['id' => $id, 'role_id' => $role, 'display_name' => 'Synthetic Actor', 'is_active' => true]);
        }
        foreach ([self::ASSOCIATION, self::DESTINATION] as $id) {
            DB::table('associations')->insert(['id' => $id, 'name' => $id, 'status' => 'active',
                'primary_field_officer_id' => self::OFFICER, 'saad_phase2_join_date' => '2025-01-01']);
        }
        foreach ([1 => 'FPL_LPD', 2 => 'SP_LT', 3 => 'SYNTHETIC_PROGRAM_ACTIVITY'] as $id => $code) {
            DB::table('saad_activities')->insert(['id' => $id, 'code' => $code, 'name' => $code, 'is_active' => true]);
        }
        DB::table('fisheries_project_categories')->insert(['id' => 1, 'name' => 'Synthetic category', 'is_active' => true]);
        DB::table('registration_agencies')->insert(['id' => 1, 'is_active' => true]);
        DB::table('projects')->insert(['id' => self::PROJECT] + $this->payload('project'));
        DB::table('trainings')->insert(['id' => self::TRAINING, 'created_at' => now(), 'updated_at' => now()] + $this->payload('training'));
        DB::table('program_activity_records')->insert(['id' => self::ACTIVITY] + $this->payload('activity'));
    }

    private function actor(): UserProfile
    {
        return UserProfile::with('role')->findOrFail(self::ADMIN);
    }

    private function payload(string $module, string $associationId = self::ASSOCIATION): array
    {
        $base = ['association_id' => $associationId, 'title' => 'Synthetic record', 'program_year' => 2026];
        return $base + match ($module) {
            'project' => ['saad_activity_id' => 1, 'fisheries_category_id' => 1, 'implementation_start_date' => '2026-01-01', 'status' => 'planned'],
            'training' => ['saad_activity_id' => 2, 'start_date' => '2026-01-01', 'status' => 'scheduled'],
            default => ['saad_activity_id' => 3, 'activity_date' => '2026-01-01', 'status' => 'planned'],
        };
    }

    private function service(string $module): ProjectService|TrainingService|ProgramActivityService
    {
        return app(match ($module) {
            'project' => ProjectService::class, 'training' => TrainingService::class, default => ProgramActivityService::class,
        });
    }

    private function record(string $module): Project|Training|ProgramActivityRecord
    {
        return match ($module) {
            'project' => Project::findOrFail(self::PROJECT),
            'training' => Training::findOrFail(self::TRAINING),
            default => ProgramActivityRecord::findOrFail(self::ACTIVITY),
        };
    }

    private function reject(callable $operation): void
    {
        try {
            $operation();
            $this->fail('An ineligible association must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('association_id', $exception->errors());
            $this->assertSame(422, $exception->status);
        }
    }

    public static function readOnlyStates(): array
    {
        return ['inactive' => ['inactive', null], 'archived' => ['active', '2026-01-01'],
            'both' => ['inactive', '2026-01-01']];
    }

    #[DataProvider('associationStatuses')]
    public function test_admin_archive_and_restore_audit_each_transition_without_changing_history(string $status): void
    {
        DB::table('associations')->where('id', self::ASSOCIATION)->update(['status' => $status]);
        DB::table('members')->insert(['id' => self::ADMIN, 'association_id' => self::ASSOCIATION, 'member_status' => 'active']);
        DB::table('monitoring_records')->insert(['id' => self::ADMIN, 'project_id' => self::PROJECT]);
        $tables = ['members', 'projects', 'trainings', 'training_participants', 'program_activity_records',
            'monitoring_records', 'association_registrations', 'project_products', 'project_materials'];
        $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
        $before = $snapshot();
        $service = app(AssociationService::class);
        $association = Association::findOrFail(self::ASSOCIATION);
        $archived = $service->archive($association, $this->actor());
        $this->assertSame($status, $archived->status);
        $this->assertNotNull($archived->archived_at);
        $this->assertSame(self::ADMIN, $archived->archived_by);
        $this->assertSame(self::ADMIN, $archived->updated_by);
        $restored = $service->unarchive($association, $this->actor());
        $this->assertSame($status, $restored->status);
        $this->assertNull($restored->archived_at);
        $this->assertNull($restored->archived_by);
        $this->assertSame($before, $snapshot());
        $events = DB::table('audit_logs')->orderBy('performed_at')->get();
        $this->assertSame(['ARCHIVE ASSOCIATION', 'RESTORE ASSOCIATION'], $events->pluck('action_type')->all());
        foreach ($events as $event) {
            $this->assertSame(self::ADMIN, $event->actor_user_id);
            $this->assertSame(self::ASSOCIATION, $event->entity_id);
            $this->assertSame('association_lifecycle', $event->entity_type);
            $this->assertNotEmpty($event->performed_at);
            $new = json_decode($event->new_values, true);
            $this->assertSame(['association_id', 'status', 'archived_at', 'archived_by', 'updated_by'], array_keys($new));
            $this->assertSame($status, $new['status']);
            $this->assertNotNull(app(AuditLogService::class)->formatSnapshot($new, $event->entity_type)['json']);
        }
        $this->assertNull(json_decode($events[0]->old_values, true)['archived_at']);
        $this->assertNotNull(json_decode($events[1]->old_values, true)['archived_at']);
        $this->assertNull(json_decode($events[1]->new_values, true)['archived_at']);
    }

    public static function associationStatuses(): array
    {
        return [['active'], ['inactive']];
    }

    #[DataProvider('revokedLifecycleActors')]
    public function test_archive_and_restore_revalidate_the_actor_inside_the_transaction(string $operation, string $revocation): void
    {
        if ($operation === 'unarchive') DB::table('associations')->where('id', self::ASSOCIATION)
            ->update(['archived_at' => '2026-01-01', 'archived_by' => self::ADMIN]);
        $actor = $this->actor();
        $actor->canAccessApplication();
        match ($revocation) {
            'inactive profile' => DB::table('user_profiles')->where('id', self::ADMIN)->update(['is_active' => false]),
            'inactive role' => DB::table('roles')->where('id', 1)->update(['is_active' => false]),
            'changed role' => DB::table('user_profiles')->where('id', self::ADMIN)->update(['role_id' => 2]),
            'missing profile' => DB::table('user_profiles')->where('id', self::ADMIN)->delete(),
            'unaccepted invitation' => DB::table('user_invitations')->insert(['id' => self::ADMIN,
                'auth_user_id' => self::ADMIN, 'status' => 'sent']),
        };
        $before = Association::findOrFail(self::ASSOCIATION)->getRawOriginal();
        try {
            app(AssociationService::class)->{$operation}(Association::findOrFail(self::ASSOCIATION), $actor);
            $this->fail('Revoked administrators must not change association lifecycle.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame($before, Association::findOrFail(self::ASSOCIATION)->getRawOriginal());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function revokedLifecycleActors(): array
    {
        $cases = [];
        foreach (['archive', 'unarchive'] as $operation) foreach (['inactive profile', 'inactive role', 'changed role',
            'missing profile', 'unaccepted invitation'] as $revocation) $cases[] = [$operation, $revocation];

        return $cases;
    }

    #[DataProvider('lifecycleOperations')]
    public function test_lifecycle_audit_failure_rolls_back_the_association(string $operation): void
    {
        if ($operation === 'unarchive') DB::table('associations')->where('id', self::ASSOCIATION)
            ->update(['archived_at' => '2026-01-01', 'archived_by' => self::ADMIN]);
        $before = Association::findOrFail(self::ASSOCIATION)->getRawOriginal();
        $audit = Mockery::mock(AuditLogService::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Synthetic audit failure'));
        $this->app->instance(AuditLogService::class, $audit);
        try {
            app(AssociationService::class)->{$operation}(Association::findOrFail(self::ASSOCIATION), $this->actor());
            $this->fail('Lifecycle changes require a successful audit.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure', $exception->getMessage());
        }
        $this->assertSame($before, Association::findOrFail(self::ASSOCIATION)->getRawOriginal());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function lifecycleOperations(): array
    {
        return [['archive'], ['unarchive']];
    }

    public function test_repeated_lifecycle_requests_keep_existing_errors_and_do_not_add_audits(): void
    {
        $service = app(AssociationService::class);
        $association = Association::findOrFail(self::ASSOCIATION);
        foreach (['archive', 'unarchive'] as $operation) {
            $service->{$operation}($association, $this->actor());
            $before = $association->fresh()->getRawOriginal();
            try {
                $service->{$operation}($association, $this->actor());
                $this->fail('Repeated lifecycle requests must preserve existing rejection behavior.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('association', $exception->errors());
                $this->assertSame(422, $exception->status);
            }
            $this->assertSame($before, $association->fresh()->getRawOriginal());
        }
        $this->assertDatabaseCount('audit_logs', 2);
    }

    #[DataProvider('readOnlyStates')]
    public function test_creation_and_resources_are_blocked_for_each_read_only_state(string $status, ?string $archivedAt): void
    {
        $project = Project::findOrFail(self::PROJECT);
        DB::table('associations')->where('id', self::ASSOCIATION)->update(['status' => $status, 'archived_at' => $archivedAt]);
        foreach (['project', 'training', 'activity'] as $module) {
            $this->reject(fn () => $this->service($module)->create($this->payload($module), $this->actor()));
        }
        foreach (['registerProduct', 'registerMaterial'] as $method) {
            $data = $method === 'registerProduct'
                ? ['product_name' => 'Synthetic product', 'product_type' => 'raw', 'default_unit' => 'kg']
                : ['item_name' => 'Synthetic material', 'quantity_planned' => 1, 'unit' => 'piece', 'delivery_status' => 'planned'];
            $this->reject(fn () => DB::transaction(fn () => app(ProjectService::class)->{$method}(
                $project, $data, $this->actor(), 'resource')));
        }
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('trainings', 1);
        $this->assertDatabaseCount('program_activity_records', 1);
        $this->assertDatabaseCount('project_products', 0);
        $this->assertDatabaseCount('project_materials', 0);
    }

    #[DataProvider('readOnlyStates')]
    public function test_updates_cannot_transfer_from_or_into_a_read_only_association(string $status, ?string $archivedAt): void
    {
        foreach (['project', 'training', 'activity'] as $module) {
            foreach ([self::ASSOCIATION, self::DESTINATION] as $blockedId) {
                $record = $this->record($module);
                $before = $record->getRawOriginal();
                DB::table('associations')->where('id', $blockedId)->update(['status' => $status, 'archived_at' => $archivedAt]);
                $data = $this->payload($module, self::DESTINATION);
                if ($module === 'training') $data['expected_updated_at'] = $record->updated_at->format('Y-m-d\TH:i:s.uP');
                if ($module === 'project') $data['new_commodity_name'] = 'Must not be created';
                $this->reject(fn () => $this->service($module)->update($record, $data, $this->actor()));
                $this->assertSame($before, $record->fresh()->getRawOriginal());
                DB::table('associations')->where('id', $blockedId)->update(['status' => 'active', 'archived_at' => null]);
            }
        }
        $this->assertDatabaseCount('commodities', 0);
        $this->assertDatabaseCount('training_participants', 0);
    }

    #[DataProvider('readOnlyStates')]
    public function test_history_cannot_be_archived_restored_or_unlinked_to_bypass_parent_policy(string $status, ?string $archivedAt): void
    {
        DB::table('associations')->where('id', self::ASSOCIATION)->update(['status' => $status, 'archived_at' => $archivedAt]);
        foreach (['project', 'training', 'activity'] as $module) {
            foreach (['archive', 'unarchive'] as $method) {
                $record = $this->record($module);
                if ($method === 'unarchive') $record->forceFill(['archived_at' => '2025-01-01'])->save();
                $before = $record->getRawOriginal();
                $this->reject(fn () => $this->service($module)->{$method}($record, $this->actor()));
                $this->assertSame($before, $record->fresh()->getRawOriginal());
            }
        }
        $activity = $this->record('activity');
        $activity->forceFill(['archived_at' => null])->save();
        $data = $this->payload('activity');
        $data['association_id'] = null;
        $this->reject(fn () => app(ProgramActivityService::class)->update($activity, $data, $this->actor()));
        $this->assertSame(self::ASSOCIATION, $activity->fresh()->association_id);
    }

    public function test_active_associations_and_admin_program_wide_creation_remain_available(): void
    {
        foreach (['project', 'training', 'activity'] as $module) {
            $created = $this->service($module)->create($this->payload($module), $this->actor());
            $this->assertSame(self::ASSOCIATION, $created->association_id);
        }
        $data = $this->payload('activity');
        $data['association_id'] = null;
        $this->assertNull(app(ProgramActivityService::class)->create($data, $this->actor())->association_id);
    }

    public function test_registration_failure_rolls_back_new_inactive_association(): void
    {
        $data = ['name' => 'New synthetic association', 'area_id' => self::DESTINATION,
            'primary_field_officer_id' => self::OFFICER, 'saad_phase2_join_date' => '2025-01-01',
            'status' => 'inactive', 'registration' => ['agency_id' => 1, 'registration_number' => 'SYNTHETIC-1',
                'registration_date' => '2024-01-01', 'is_primary' => true]];
        $this->reject(fn () => app(AssociationService::class)->create($data, $this->actor()));
        $this->assertDatabaseCount('associations', 2);
        $this->assertDatabaseCount('association_registrations', 0);
        $data['status'] = 'active';
        $created = app(AssociationService::class)->create($data, $this->actor());
        $this->assertSame('active', $created->status);
        $this->assertDatabaseCount('association_registrations', 1);
    }

    public function test_unarchive_preserves_status_and_existing_status_form_reactivates_operations(): void
    {
        $stale = Association::findOrFail(self::ASSOCIATION);
        DB::table('associations')->where('id', self::ASSOCIATION)->update(['status' => 'inactive', 'archived_at' => '2026-01-01']);
        $service = app(AssociationService::class);
        $restored = $service->unarchive($stale, $this->actor());
        $this->assertNull($restored->archived_at);
        $this->assertSame('inactive', $restored->status);
        $this->reject(fn () => DB::transaction(fn () => app(AssociationLifecycleService::class)->lockOperational([self::ASSOCIATION])));
        $data = ['name' => $restored->name, 'area_id' => self::DESTINATION,
            'saad_phase2_join_date' => '2025-01-01', 'primary_field_officer_id' => self::OFFICER, 'status' => 'active'];
        $reactivated = $service->update($restored, $data, $this->actor());
        $this->assertSame('active', $reactivated->status);
        $this->assertSame(self::ASSOCIATION, app(ProjectService::class)->create($this->payload('project'), $this->actor())->association_id);
    }

    public function test_eligibility_lock_requires_a_transaction(): void
    {
        $this->expectException(LogicException::class);
        app(AssociationLifecycleService::class)->lockOperational([self::ASSOCIATION]);
    }

    public function test_inactive_metadata_edits_and_stale_archived_edits_require_reactivation(): void
    {
        $stale = Association::findOrFail(self::ASSOCIATION);
        $data = ['name' => 'Must not change', 'area_id' => self::DESTINATION,
            'saad_phase2_join_date' => '2025-01-01', 'primary_field_officer_id' => self::OFFICER];
        foreach ([['inactive', null], ['active', '2026-01-01']] as [$status, $archivedAt]) {
            DB::table('associations')->where('id', self::ASSOCIATION)->update(['status' => $status, 'archived_at' => $archivedAt]);
            $before = Association::findOrFail(self::ASSOCIATION)->getRawOriginal();
            try {
                app(AssociationService::class)->update($stale, $data + ['status' => $status], $this->actor());
                $this->fail('Historical association edits must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('association', $exception->errors());
            }
            $this->assertSame($before, Association::findOrFail(self::ASSOCIATION)->getRawOriginal());
        }
    }

    public function test_rejected_training_update_preserves_existing_participant_history(): void
    {
        $memberId = '88888888-8888-4888-8888-888888888888';
        DB::table('members')->insert(['id' => $memberId, 'association_id' => self::ASSOCIATION,
            'member_status' => 'inactive', 'archived_at' => '2025-01-01']);
        DB::table('training_participants')->insert(['id' => '99999999-9999-4999-8999-999999999999',
            'training_id' => self::TRAINING, 'member_id' => $memberId, 'attendance_status' => 'present', 'remarks' => 'Retained history']);
        $before = (array) DB::table('training_participants')->sole();
        $training = Training::findOrFail(self::TRAINING);
        DB::table('associations')->where('id', self::ASSOCIATION)->update(['status' => 'inactive']);
        $data = $this->payload('training') + ['expected_updated_at' => $training->updated_at->format('Y-m-d\TH:i:s.uP'),
            'removed_participant_ids' => [$before['id']]];
        $this->reject(fn () => app(TrainingService::class)->update($training, $data, $this->actor()));
        $this->assertSame($before, (array) DB::table('training_participants')->sole());
        $this->assertDatabaseCount('members', 1);
    }

    public function test_association_listing_filters_use_only_existing_lifecycle_columns(): void
    {
        Schema::create('areas', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('barangay_id')->nullable();
            $table->string('address'); $table->boolean('is_active');
        });
        Schema::create('barangays', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('municipality_id'); $table->string('name');
        });
        Schema::create('municipalities', function (Blueprint $table) { $table->uuid('id')->primary(); $table->string('name'); });
        Schema::create('association_positions', function (Blueprint $table) { $table->id(); $table->string('code'); });
        Schema::table('members', function (Blueprint $table) {
            $table->unsignedBigInteger('position_id')->nullable();
            foreach (['first_name', 'middle_name', 'last_name', 'suffix'] as $column) $table->string($column)->nullable();
        });
        DB::table('associations')->where('id', self::DESTINATION)->update(['status' => 'inactive', 'archived_at' => '2026-01-01']);
        $directory = app(\App\Services\AssociationDirectoryQueryService::class);
        $filters = ['status' => '', 'q' => '', 'municipality' => '', 'barangay' => '', 'field_officer' => '', 'sort' => 'az'];
        foreach (['' => self::ASSOCIATION, 'active' => self::ASSOCIATION, 'archived' => self::DESTINATION] as $status => $expected) {
            $records = $directory->records($this->actor(), array_replace($filters, ['status' => $status]));
            $this->assertSame([$expected], $records->pluck('id')->all());
        }
        DB::table('associations')->where('id', self::DESTINATION)->update(['archived_at' => null]);
        $records = $directory->records($this->actor(), array_replace($filters, ['status' => 'inactive']));
        $this->assertSame([self::DESTINATION], $records->pluck('id')->all());
        $this->assertFalse(app('router')->has('admin.associations.terminate'));
        $this->assertFalse(app('router')->has('field-officer.associations.terminate'));
        $this->assertFalse(Schema::hasColumn('associations', 'terminated_at'));
    }
}
