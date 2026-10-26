<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\UserProfile;
use App\Services\Audit\AuditLogService;
use App\Services\Auth\SupabaseAuthService;
use App\Services\Members\MemberApplicationPhotoService;
use App\Services\Members\MemberApplicationService;
use App\Services\Members\MemberService;
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
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\IsolatedDatabaseManager;
use Tests\TestCase;

class MemberOfficerLifecycleTest extends TestCase
{
    private const ADMIN = '11111111-1111-4111-8111-111111111111';
    private const OFFICER = '22222222-2222-4222-8222-222222222222';
    private const OTHER_OFFICER = '33333333-3333-4333-8333-333333333333';
    private const ASSOCIATION = '44444444-4444-4444-8444-444444444444';
    private const OTHER_ASSOCIATION = '55555555-5555-4555-8555-555555555555';

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
        $this->app->instance(MemberApplicationPhotoService::class, Mockery::mock(MemberApplicationPhotoService::class));
        $auth = Mockery::mock(SupabaseAuthService::class);
        $auth->shouldReceive('getUser')->andReturnUsing(fn () => ['id' => $this->identity]);
        $this->app->instance(SupabaseAuthService::class, $auth);
    }

    protected function tearDown(): void
    {
        Member::flushEventListeners();
        MemberApplication::flushEventListeners();
        try {
            parent::tearDown();
        } finally {
            HandleExceptions::flushState($this);
        }
    }

    private function schema(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id(); $table->string('code'); $table->boolean('is_active'); $table->timestamps();
            $table->string('name')->nullable(); $table->text('description')->nullable();
        });
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->unsignedBigInteger('role_id');
            $table->string('display_name'); $table->boolean('is_active'); $table->timestamps();
        });
        Schema::create('user_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('auth_user_id')->nullable();
            $table->string('email'); $table->string('status'); $table->string('last_error_code')->nullable();
            $table->timestamp('accepted_at')->nullable(); $table->timestamp('last_attempt_at')->nullable(); $table->timestamps();
        });
        Schema::create('associations', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->string('name'); $table->string('status');
            $table->uuid('area_id')->nullable();
            $table->uuid('barangay_id')->nullable();
            $table->uuid('primary_field_officer_id'); $table->timestamp('archived_at')->nullable(); $table->timestamps();
        });
        Schema::create('association_positions', function (Blueprint $table) {
            $table->id(); $table->string('code')->unique(); $table->string('name');
            $table->boolean('is_officer'); $table->boolean('is_active'); $table->integer('sort_order'); $table->timestamps();
        });
        Schema::create('sex_categories', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->boolean('is_active'); $table->timestamps();
        });
        Schema::create('member_classifications', function (Blueprint $table) {
            $table->id(); $table->string('code'); $table->string('name'); $table->boolean('is_active'); $table->timestamps();
        });
        Schema::create('member_applications', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('association_id');
            $table->uuid('submitted_by_user_id'); $table->string('first_name'); $table->string('middle_name')->nullable();
            $table->string('last_name'); $table->string('suffix')->nullable(); $table->date('birth_date'); $table->integer('sex_id');
            $table->string('contact_number')->nullable(); $table->string('fishr_registration_number')->nullable();
            $table->unsignedBigInteger('position_id'); $table->string('position_other_text')->nullable();
            $table->string('status'); $table->uuid('reviewed_by')->nullable(); $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable(); $table->timestamps();
        });
        Schema::create('member_application_classifications', function (Blueprint $table) {
            $table->uuid('member_application_id'); $table->unsignedBigInteger('classification_id');
            $table->primary(['member_application_id', 'classification_id']);
        });
        Schema::create('members', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('association_id'); $table->uuid('source_application_id')->nullable()->unique();
            $table->string('first_name'); $table->string('middle_name')->nullable(); $table->string('last_name'); $table->string('suffix')->nullable();
            $table->date('birth_date'); $table->integer('sex_id'); $table->string('contact_number')->nullable();
            $table->string('fishr_registration_number')->nullable()->unique(); $table->unsignedBigInteger('position_id');
            $table->string('position_other_text')->nullable(); $table->date('date_registered'); $table->string('member_status');
            $table->timestamp('archived_at')->nullable(); $table->uuid('archived_by')->nullable(); $table->timestamps();
            $table->foreign('association_id')->references('id')->on('associations');
            $table->foreign('position_id')->references('id')->on('association_positions');
            $table->foreign('source_application_id')->references('id')->on('member_applications');
        });
        Schema::create('member_classification_assignments', function (Blueprint $table) {
            $table->uuid('member_id'); $table->unsignedBigInteger('classification_id');
            $table->primary(['member_id', 'classification_id']);
            $table->foreign('member_id')->references('id')->on('members');
        });
        // This fixture is not a substitute for migration 008 or PostgreSQL concurrency testing.
        DB::statement("CREATE UNIQUE INDEX uq_members_active_executive_position ON members (association_id, position_id)
            WHERE member_status = 'active' AND archived_at IS NULL AND position_id IN (2, 3)");
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('actor_user_id')->nullable();
            $table->string('action_type'); $table->string('module'); $table->string('entity_type')->nullable();
            $table->text('entity_id')->nullable(); $table->json('old_values')->nullable(); $table->json('new_values')->nullable();
            $table->timestamp('performed_at');
        });
    }

    private function fixtures(): void
    {
        foreach ([1 => 'system_admin', 2 => 'field_officer'] as $id => $code) {
            DB::table('roles')->insert(['id' => $id, 'code' => $code, 'is_active' => true]);
        }
        foreach ([self::ADMIN => 1, self::OFFICER => 2, self::OTHER_OFFICER => 2] as $id => $role) {
            DB::table('user_profiles')->insert(['id' => $id, 'role_id' => $role,
                'display_name' => 'Synthetic Actor', 'is_active' => true]);
        }
        foreach ([self::ASSOCIATION, self::OTHER_ASSOCIATION] as $id) {
            DB::table('associations')->insert(['id' => $id, 'name' => 'Synthetic Association',
                'status' => 'active', 'primary_field_officer_id' => self::OFFICER]);
        }
        foreach ([1 => 'MEMBER', 2 => 'PRESIDENT', 3 => 'VICE_PRESIDENT', 4 => 'SECRETARY', 7 => 'OTHER'] as $id => $code) {
            DB::table('association_positions')->insert(['id' => $id, 'code' => $code, 'name' => $code,
                'is_officer' => in_array($id, [2, 3, 4]), 'is_active' => true, 'sort_order' => $id]);
        }
        DB::table('member_classifications')->insert(['id' => 1, 'code' => 'FISHERFOLK', 'name' => 'Fisherfolk', 'is_active' => true]);
        DB::table('sex_categories')->insert(['id' => 1, 'name' => 'Synthetic category', 'is_active' => true]);
    }

    private function actor(string $id = self::OFFICER): UserProfile
    {
        return UserProfile::with('role')->findOrFail($id);
    }

    private function application(int $position = 2, array $overrides = []): MemberApplication
    {
        $application = new MemberApplication;
        $application->forceFill(array_replace([
            'association_id' => self::ASSOCIATION, 'submitted_by_user_id' => self::ADMIN,
            'first_name' => 'Synthetic', 'last_name' => 'Applicant', 'birth_date' => '2000-01-01',
            'sex_id' => 1, 'position_id' => $position, 'status' => 'pending',
        ], $overrides))->save();
        $application->classifications()->attach(1);

        return $application->refresh();
    }

    private function member(int $position = 2, array $overrides = []): Member
    {
        $member = new Member;
        $member->forceFill(array_replace([
            'association_id' => self::ASSOCIATION, 'source_application_id' => null,
            'first_name' => 'Synthetic', 'last_name' => 'Member', 'birth_date' => '2000-01-01',
            'sex_id' => 1, 'position_id' => $position, 'date_registered' => '2025-01-01', 'member_status' => 'active',
            'archived_at' => '2025-02-01 00:00:00', 'archived_by' => self::ADMIN,
        ], $overrides))->save();
        $member->classifications()->attach(1);

        return $member->refresh();
    }

    private function memberData(Member $member, string $action = 'reactivate'): array
    {
        return [
            'member_record_id' => $member->id,
            'member_action' => $action,
            'expected_updated_at' => $member->updated_at->format('Y-m-d\TH:i:s.uP'),
            ...($action === 'profile' ? ['first_name' => 'Updated'] : ['confirm_change' => '1']),
        ];
    }

    private function membershipState(): array
    {
        $state = [];
        foreach ([
            'members' => ['id'],
            'member_applications' => ['id'],
            'member_classification_assignments' => ['member_id', 'classification_id'],
            'member_application_classifications' => ['member_application_id', 'classification_id'],
            'audit_logs' => ['id'],
        ] as $table => $columns) {
            $query = DB::table($table);
            foreach ($columns as $column) {
                $query->orderBy($column);
            }
            $state[$table] = $query->get()->map(fn ($row) => (array) $row)->all();
        }

        return $state;
    }

    private function assertRejected(callable $operation, string $field, int $status = 422): void
    {
        try {
            $operation();
            $this->fail('The operation must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
            $this->assertSame($status, $exception->status);
        }
        Http::assertNothingSent();
    }

    #[DataProvider('approvalPositions')]
    public function test_approval_preserves_requested_position_and_records_only_officer_appointments(int $position): void
    {
        $application = $this->application($position);
        $member = app(MemberApplicationService::class)->approve($application, $this->actor());
        $this->assertSame($position, $member->position_id);
        $this->assertSame($application->id, $member->source_application_id);
        $this->assertSame('approved', $application->fresh()->status);
        $this->assertSame([1], $member->classifications()->pluck('member_classifications.id')->all());
        $this->assertSame($position === 1 ? 0 : 1, DB::table('audit_logs')->count());
        if ($position !== 1) {
            $event = DB::table('audit_logs')->first();
            $this->assertSame('APPOINT ASSOCIATION OFFICER', $event->action_type);
            $this->assertSame(self::OFFICER, $event->actor_user_id);
            $this->assertSame($member->id, $event->entity_id);
            $this->assertNull($event->old_values);
            $this->assertNotNull($event->performed_at);
            $this->assertSame(['association_id', 'position_id', 'member_status', 'archived_at'], array_keys(json_decode($event->new_values, true)));
        }
        Http::assertNothingSent();
    }

    public static function approvalPositions(): array
    {
        return [[1], [2], [3], [4]];
    }

    public static function readOnlyDecisions(): array
    {
        $cases = [];
        foreach (['inactive' => ['inactive', null], 'archived' => ['active', '2026-01-01'],
            'both' => ['inactive', '2026-01-01']] as $state => [$status, $archivedAt]) {
            foreach (['approve', 'reject'] as $decision) $cases[$state.' '.$decision] = [$status, $archivedAt, $decision];
        }
        return $cases;
    }

    #[DataProvider('readOnlyDecisions')]
    public function test_read_only_association_keeps_application_decisions_and_classifications_unchanged(
        string $status, ?string $archivedAt, string $decision): void
    {
        $application = $this->application(1);
        $before = $application->getRawOriginal();
        DB::table('associations')->where('id', self::ASSOCIATION)
            ->update(['status' => $status, 'archived_at' => $archivedAt]);
        $service = app(MemberApplicationService::class);
        $this->assertRejected(fn () => $decision === 'approve'
            ? $service->approve($application, $this->actor())
            : $service->reject($application, $this->actor(), 'Synthetic reason'), 'association_id');
        $this->assertSame($before, $application->fresh()->getRawOriginal());
        $this->assertSame([1], $application->classifications()->pluck('member_classifications.id')->all());
        $this->assertDatabaseCount('members', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function readOnlyMemberChanges(): array
    {
        $cases = [];
        foreach (['inactive' => ['inactive', null], 'archived' => ['active', '2026-01-01'],
            'both' => ['inactive', '2026-01-01']] as $state => [$status, $archivedAt]) {
            foreach (['update', 'archive', 'unarchive'] as $operation) $cases[$state.' '.$operation] = [$status, $archivedAt, $operation];
        }
        return $cases;
    }

    #[DataProvider('readOnlyMemberChanges')]
    public function test_read_only_association_blocks_member_mutations_without_audit_or_history_changes(
        string $status, ?string $archivedAt, string $operation): void
    {
        $member = $this->member(1, ['archived_at' => $operation === 'unarchive' ? '2025-02-01' : null]);
        $before = (array) DB::table('members')->where('id', $member->id)->first();
        $data = ['member_record_id' => $member->id,
            'expected_updated_at' => $member->updated_at->format('Y-m-d\TH:i:s.uP')];
        $data += $operation === 'update' ? ['first_name' => 'Changed'] : ['confirm_change' => '1'];
        DB::table('associations')->where('id', self::ASSOCIATION)
            ->update(['status' => $status, 'archived_at' => $archivedAt]);
        $this->assertRejected(fn () => app(MemberService::class)->{$operation}($member, $data, $this->actor()), 'member');
        $this->assertSame($before, (array) DB::table('members')->where('id', $member->id)->first());
        $this->assertSame([1], $member->classifications()->pluck('member_classifications.id')->all());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_profile_update_cannot_transfer_a_member_into_an_inactive_association(): void
    {
        $member = $this->member(1, ['archived_at' => null]);
        $before = $member->getRawOriginal();
        DB::table('associations')->where('id', self::OTHER_ASSOCIATION)->update(['status' => 'inactive']);
        $data = ['member_record_id' => $member->id, 'expected_updated_at' => $member->updated_at->format('Y-m-d\TH:i:s.uP'),
            'association_id' => self::OTHER_ASSOCIATION, 'first_name' => 'Must not change'];
        $this->assertRejected(fn () => app(MemberService::class)->update($member, $data, $this->actor()), 'member');
        $this->assertSame($before, $member->fresh()->getRawOriginal());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_position_deactivated_after_submission_is_rejected_without_substitution(): void
    {
        $application = $this->application();
        DB::table('association_positions')->where('id', 2)->update(['is_active' => false]);
        $this->assertRejected(fn () => app(MemberApplicationService::class)->approve($application, $this->actor()), 'position_id');
        $this->assertSame('pending', $application->fresh()->status);
        $this->assertSame(2, $application->fresh()->position_id);
        $this->assertSame(0, Member::count());
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_missing_lookup_and_inconsistent_other_position_are_controlled_errors(): void
    {
        $missing = $this->application(999);
        $this->assertRejected(fn () => app(MemberApplicationService::class)->approve($missing, $this->actor()), 'position_id');
        $other = $this->application(7, ['position_other_text' => ' ']);
        $this->assertRejected(fn () => app(MemberApplicationService::class)->approve($other, $this->actor()), 'position_other_text');
        $this->assertSame(0, Member::count());
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_inconsistent_executive_lookup_is_rejected_before_approval(): void
    {
        $application = $this->application();
        DB::table('association_positions')->where('id', 2)->update(['is_officer' => false]);
        $this->assertRejected(fn () => app(MemberApplicationService::class)->approve($application, $this->actor()), 'position_id');
        $this->assertSame('pending', $application->fresh()->status);
        $this->assertSame(0, Member::count());
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    #[DataProvider('actorRevocations')]
    public function test_approval_revalidates_actor_and_role_after_loading_models(string $case): void
    {
        $application = $this->application();
        $actor = $this->actor();
        if ($case === 'disabled') DB::table('user_profiles')->where('id', self::OFFICER)->update(['is_active' => false]);
        else DB::table('roles')->where('id', 2)->update(['is_active' => false]);
        try {
            app(MemberApplicationService::class)->approve($application, $actor);
            $this->fail('A revoked actor must be denied.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame('pending', $application->fresh()->status);
        $this->assertSame(0, Member::count());
        $this->assertSame(0, DB::table('audit_logs')->count());
        Http::assertNothingSent();
    }

    public static function actorRevocations(): array
    {
        return [['disabled'], ['inactive_role']];
    }

    #[DataProvider('decisions')]
    public function test_reassigned_officer_cannot_decide_using_stale_models(string $decision): void
    {
        $application = $this->application();
        $actor = $this->actor();
        DB::table('associations')->where('id', self::ASSOCIATION)->update(['primary_field_officer_id' => self::OTHER_OFFICER]);
        $service = app(MemberApplicationService::class);
        $this->assertRejected(fn () => $decision === 'approve'
            ? $service->approve($application, $actor) : $service->reject($application, $actor, 'Synthetic reason'), 'reviewer');
        $this->assertSame('pending', $application->fresh()->status);
        $this->assertSame(0, Member::count());
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public static function decisions(): array
    {
        return [['approve'], ['reject']];
    }

    public function test_application_association_change_requires_reload(): void
    {
        $application = $this->application();
        DB::table('member_applications')->where('id', $application->id)->update(['association_id' => self::OTHER_ASSOCIATION]);
        $this->assertRejected(fn () => app(MemberApplicationService::class)->approve($application, $this->actor()), 'application', 409);
        $this->assertSame(0, Member::count());
    }

    public function test_rejection_remains_exclusive_with_approval_and_creates_no_officer(): void
    {
        $application = $this->application();
        $service = app(MemberApplicationService::class);
        $service->reject($application, $this->actor(), 'Synthetic reason');
        $this->assertRejected(fn () => $service->approve($application, $this->actor()), 'application', 409);
        $this->assertSame('rejected', $application->fresh()->status);
        $this->assertSame(0, Member::count());
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    #[DataProvider('decisions')]
    public function test_successful_approval_cannot_be_approved_or_rejected_again(string $decision): void
    {
        $application = $this->application();
        $service = app(MemberApplicationService::class);
        $service->approve($application, $this->actor());
        $this->assertRejected(fn () => $decision === 'approve'
            ? $service->approve($application, $this->actor())
            : $service->reject($application, $this->actor(), 'Synthetic reason'), 'application', 409);
        $this->assertSame('approved', $application->fresh()->status);
        $this->assertSame(1, Member::count());
        $this->assertSame(1, DB::table('audit_logs')->count());
    }

    #[DataProvider('executivePositions')]
    public function test_competing_applications_cannot_fill_an_occupied_executive_office(int $position): void
    {
        $first = $this->application($position);
        $second = $this->application($position);
        $service = app(MemberApplicationService::class);
        $service->approve($first, $this->actor());
        $this->assertRejected(fn () => $service->approve($second, $this->actor()), 'position_id', 409);
        $this->assertSame(1, Member::count());
        $this->assertSame('pending', $second->fresh()->status);
        $this->assertSame(1, DB::table('audit_logs')->count());
    }

    public static function executivePositions(): array
    {
        return [[2], [3]];
    }

    #[DataProvider('invalidAdmissions')]
    public function test_active_restoration_requires_approved_source_in_the_same_association(string $case): void
    {
        $application = $this->application(2, [
            'status' => $case === 'wrong_association' ? 'approved' : $case,
            'association_id' => $case === 'wrong_association' ? self::OTHER_ASSOCIATION : self::ASSOCIATION,
        ]);
        $member = $this->member(2, ['source_application_id' => $application->id]);
        $before = $member->getRawOriginal();
        $this->assertRejected(fn () => app(MemberService::class)->unarchive($member, $this->memberData($member), $this->actor()), 'member');
        $this->assertSame($before, $member->fresh()->getRawOriginal());
        $this->assertNotNull($member->fresh()->archived_at);
        $this->assertSame(self::ADMIN, $member->fresh()->archived_by);
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public static function invalidAdmissions(): array
    {
        return [['pending'], ['rejected'], ['wrong_association']];
    }

    public function test_direct_and_approved_member_restoration_preserves_membership_and_history(): void
    {
        $direct = $this->member(2);
        $application = $this->application(3, ['status' => 'approved']);
        $approved = $this->member(3, ['source_application_id' => $application->id]);
        $service = app(MemberService::class);
        foreach ([$direct, $approved] as $member) {
            $before = $member->getRawOriginal();
            $restored = $service->unarchive($member, $this->memberData($member), $this->actor());
            $this->assertNull($restored->archived_at);
            $this->assertNull($restored->archived_by);
            $this->assertSame('active', $restored->member_status);
            $this->assertSame($member->position_id, $restored->position_id);
            $this->assertSame([1], $restored->classifications()->pluck('member_classifications.id')->all());
            $after = $restored->fresh()->getRawOriginal();
            $this->assertSame(array_replace($before, [
                'archived_at' => null, 'archived_by' => null, 'updated_at' => $after['updated_at'],
            ]), $after);
            foreach (['REACTIVATE MEMBER', 'RESTORE ASSOCIATION OFFICER'] as $action) {
                $event = DB::table('audit_logs')->where('entity_id', $member->id)->where('action_type', $action)->sole();
                $this->assertSame(self::OFFICER, $event->actor_user_id);
                $this->assertNotNull(json_decode($event->old_values, true)['archived_at']);
                $this->assertNull(json_decode($event->new_values, true)['archived_at']);
            }
        }
        $this->assertSame($application->id, $approved->fresh()->source_application_id);
        $this->assertSame('approved', $application->fresh()->status);
        $this->assertNull($direct->fresh()->source_application_id);
        $this->assertSame(2, Member::count());
        $this->assertDatabaseCount('audit_logs', 4);
        $this->assertSame(2, DB::table('audit_logs')->where('action_type', 'REACTIVATE MEMBER')->count());
        $this->assertSame(2, DB::table('audit_logs')->where('action_type', 'RESTORE ASSOCIATION OFFICER')->count());
    }

    public function test_reactivation_requires_current_assigned_actor_version_and_confirmation_before_restoring_active_membership(): void
    {
        $member = $this->member(2, ['member_status' => 'inactive']);
        $service = app(MemberService::class);
        $data = $this->memberData($member);
        $before = $member->getRawOriginal();
        foreach ([self::ADMIN, self::OTHER_OFFICER] as $actor) {
            try {
                $service->unarchive($member, $data, $this->actor($actor));
                $this->fail('Only the assigned Field Officer may reactivate members.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
            $this->assertSame($before, $member->fresh()->getRawOriginal());
        }
        foreach (['member_record_id' => self::OTHER_ASSOCIATION, 'expected_updated_at' => '2020-01-01T00:00:00.000000+00:00'] as $field => $value) {
            $this->assertRejected(fn () => $service->unarchive($member, array_replace($data, [$field => $value]), $this->actor()), 'member', 409);
            $this->assertSame($before, $member->fresh()->getRawOriginal());
        }
        $this->assertRejected(fn () => $service->unarchive($member, array_replace($data, ['confirm_change' => '0']), $this->actor()), 'confirm_change');
        $this->assertSame($before, $member->fresh()->getRawOriginal());
        $this->assertDatabaseCount('audit_logs', 0);
        $restored = $service->unarchive($member, $data, $this->actor());
        $this->assertSame('active', $restored->member_status);
        $this->assertNull($restored->archived_at);
        $this->assertNull($restored->archived_by);
        $this->assertSame(2, $restored->position_id);
        $this->assertSame([1], $restored->classifications()->pluck('member_classifications.id')->all());
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertDatabaseHas('audit_logs', ['action_type' => 'REACTIVATE MEMBER', 'entity_id' => $member->id, 'actor_user_id' => self::OFFICER]);
        $this->assertDatabaseHas('audit_logs', ['action_type' => 'RESTORE ASSOCIATION OFFICER', 'entity_id' => $member->id, 'actor_user_id' => self::OFFICER]);
        $this->assertRejected(fn () => $service->unarchive($restored, $this->memberData($restored), $this->actor()), 'member');
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_active_restoration_rejects_an_inactive_position_without_demotion(): void
    {
        $member = $this->member();
        DB::table('association_positions')->where('id', 2)->update(['is_active' => false]);
        $this->assertRejected(fn () => app(MemberService::class)->unarchive($member, $this->memberData($member), $this->actor()), 'position_id');
        $this->assertSame(2, $member->fresh()->position_id);
        $this->assertNotNull($member->fresh()->archived_at);
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_restoration_validation_is_visible_without_opening_an_unrelated_modal(): void
    {
        $application = $this->application();
        $member = $this->member(2, ['source_application_id' => $application->id]);
        $unrelated = $this->member(1, ['first_name' => 'Unrelated']);
        $before = $this->membershipState();
        $data = $this->memberData($member);
        $url = route('field-officer.members.index', ['status' => 'archived']);
        $action = route('field-officer.members.reactivate', $member);
        $this->patch($action, $data)->assertRedirect(route('login'));
        $this->assertSame($before, $this->membershipState());
        foreach ([self::ADMIN, self::OTHER_OFFICER] as $actor) {
            $this->identity = $actor;
            $this->withSession(['supabase_access_token' => 'synthetic-test-token'])
                ->patch($action, $data)->assertForbidden();
            $this->assertSame($before, $this->membershipState());
        }
        $this->identity = self::OFFICER;
        $this->from($url)->patch($action, array_replace($data, ['confirm_change' => '0']))
            ->assertRedirect($url)->assertSessionHasErrors(['confirm_change']);
        $this->assertSame($before, $this->membershipState());
        $this->withSession(['supabase_access_token' => 'synthetic-test-token'])
            ->from($url)->patch($action, $data)
            ->assertRedirect($url)->assertSessionHasErrors(['member'])
            ->assertSessionHasInput('member_record_id', $member->id)
            ->assertSessionHasInput('member_action', 'reactivate')
            ->assertSessionHasInput('expected_updated_at', $data['expected_updated_at'])
            ->assertSessionHasInput('confirm_change', '1');
        $response = $this->get($url)->assertOk()->assertSee('approved source application');
        $html = $response->getContent();
        $this->assertSame(1, preg_match('/<dialog id="member-reactivate-'.$member->id.'".*?<\/dialog>/s', $html, $dialog));
        $this->assertStringContainsString('class="notice notice-error" role="alert"', $dialog[0]);
        $this->assertStringContainsString('data-feedback-error-bag="default"', $dialog[0]);
        $this->assertStringContainsString('action="'.$action.'"', $dialog[0]);
        $this->assertStringContainsString('name="_token"', $dialog[0]);
        $this->assertStringContainsString('name="member_action" value="reactivate"', $dialog[0]);
        $this->assertStringContainsString('name="expected_updated_at" value="'.$data['expected_updated_at'].'"', $dialog[0]);
        $this->assertSame(1, substr_count($html, 'data-feedback-error-bag="default"'));
        $this->assertSame(1, preg_match('/<dialog id="member-reactivate-'.$unrelated->id.'".*?<\/dialog>/s', $html, $otherDialog));
        $this->assertStringNotContainsString('data-feedback-error-bag', $otherDialog[0]);
        $this->assertStringNotContainsString('approved source application', $otherDialog[0]);
        $this->assertSame($before, $this->membershipState());
        $this->assertNotNull($member->fresh()->archived_at);
        Http::assertNothingSent();
    }

    #[DataProvider('executivePositions')]
    public function test_restoration_cannot_displace_an_active_executive(int $position): void
    {
        $archived = $this->member($position);
        $holder = $this->member($position, ['archived_at' => null, 'archived_by' => null]);
        $before = $holder->getRawOriginal();
        $this->assertRejected(fn () => app(MemberService::class)->unarchive($archived, $this->memberData($archived), $this->actor()), 'position_id', 409);
        $this->assertNotNull($archived->fresh()->archived_at);
        $this->assertSame($before, $holder->fresh()->getRawOriginal());
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_archival_records_office_inactivation_without_demoting_or_deleting_membership(): void
    {
        $member = $this->member(2, ['archived_at' => null, 'archived_by' => null]);
        $archived = app(MemberService::class)->archive($member, [
            'member_record_id' => $member->id,
            'expected_updated_at' => $member->updated_at->format('Y-m-d\TH:i:s.uP'),
            'confirm_change' => '1',
        ], $this->actor());
        $this->assertNotNull($archived->archived_at);
        $this->assertSame('inactive', $archived->member_status);
        $this->assertSame(self::OFFICER, $archived->archived_by);
        $this->assertSame(2, $archived->position_id);
        $this->assertSame(1, Member::count());
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertDatabaseHas('audit_logs', ['action_type' => 'ARCHIVE MEMBER', 'entity_id' => $member->id]);
        $event = DB::table('audit_logs')->where('action_type', 'ARCHIVE ASSOCIATION OFFICER')->first();
        $this->assertNotNull($event);
        $this->assertSame('ARCHIVE ASSOCIATION OFFICER', $event->action_type);
        $this->assertSame(self::OFFICER, $event->actor_user_id);
        $this->assertSame($member->id, $event->entity_id);
        $this->assertNull(json_decode($event->old_values, true)['archived_at']);
        $this->assertNotNull(json_decode($event->new_values, true)['archived_at']);
        $this->assertSame([1], $archived->classifications()->pluck('member_classifications.id')->all());
    }

    public function test_profile_updates_require_the_assigned_field_officer_and_reject_protected_membership_fields(): void
    {
        $holder = $this->member(2, ['archived_at' => null, 'archived_by' => null]);
        $candidate = $this->member(1, ['archived_at' => null, 'archived_by' => null]);
        $service = app(MemberService::class);
        $data = $this->memberData($candidate, 'profile');
        $before = $this->membershipState();
        foreach ([self::ADMIN, self::OTHER_OFFICER] as $actor) {
            try {
                $service->update($candidate, $data, $this->actor($actor));
                $this->fail('Only the assigned Field Officer may edit member profiles.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
            $this->assertSame($before, $this->membershipState());
        }
        foreach ([
            'association_id' => self::OTHER_ASSOCIATION,
            'position_id' => 2,
            'position_other_text' => 'Unauthorized office',
            'member_status' => 'inactive',
            'date_registered' => '2020-01-01',
            'classification_ids' => [],
            'source_application_id' => null,
        ] as $field => $value) {
            $this->assertRejected(fn () => $service->update($candidate, array_replace($data, [$field => $value]), $this->actor()), 'member');
            $this->assertSame($before, $this->membershipState());
        }
        $this->assertSame(1, $candidate->fresh()->position_id);
        $this->assertSame(2, $holder->fresh()->position_id);
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_approved_application_admission_and_profile_updates_have_focused_audits_and_preserve_history(): void
    {
        $application = $this->application(4);
        $member = app(MemberApplicationService::class)->approve($application, $this->actor());
        $member->refresh();
        $application->refresh();
        $this->assertSame($application->id, $member->source_application_id);
        $this->assertSame('approved', $application->status);
        $this->assertSame(self::OFFICER, $application->reviewed_by);
        $this->assertNotNull($application->reviewed_at);
        $this->assertSame('active', $member->member_status);
        $this->assertSame(4, $member->position_id);
        $this->assertSame(self::ASSOCIATION, $member->association_id);
        $this->assertSame(now()->toDateString(), $member->date_registered->toDateString());
        $this->assertSame([1], $member->classifications()->pluck('member_classifications.id')->all());
        $this->assertDatabaseCount('members', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $memberBefore = $member->getRawOriginal();
        $applicationBefore = $application->getRawOriginal();
        $appointmentBefore = (array) DB::table('audit_logs')->sole();
        $this->travel(1)->seconds();
        app(MemberService::class)->update($member, $this->memberData($member, 'profile'), $this->actor());
        $after = $member->fresh()->getRawOriginal();
        $this->assertSame(array_replace($memberBefore, ['first_name' => 'Updated', 'updated_at' => $after['updated_at']]), $after);
        $this->assertSame($applicationBefore, $application->fresh()->getRawOriginal());
        $this->assertSame([1], $application->classifications()->pluck('member_classifications.id')->all());
        $this->assertSame([1], $member->classifications()->pluck('member_classifications.id')->all());
        $this->assertSame($appointmentBefore, (array) DB::table('audit_logs')->where('action_type', 'APPOINT ASSOCIATION OFFICER')->sole());
        $this->assertSame(['APPOINT ASSOCIATION OFFICER', 'UPDATE MEMBER PROFILE'],
            DB::table('audit_logs')->orderBy('performed_at')->pluck('action_type')->all());
        $event = DB::table('audit_logs')->where('action_type', 'UPDATE MEMBER PROFILE')->sole();
        $this->assertSame(self::OFFICER, $event->actor_user_id);
        $this->assertSame($member->id, $event->entity_id);
        $this->assertSame(['first_name'], json_decode($event->new_values, true)['changed_fields']);
        $this->assertSame(self::OFFICER, $appointmentBefore['actor_user_id']);
        $this->assertSame($member->id, $appointmentBefore['entity_id']);
        Http::assertNothingSent();
    }

    #[DataProvider('auditFailures')]
    public function test_audit_failure_rolls_back_related_database_writes(string $operation): void
    {
        $audit = Mockery::mock(AuditLogService::class);
        if (in_array($operation, ['archive', 'unarchive'])) {
            $actual = new AuditLogService;
            $calls = 0;
            $audit->shouldReceive('record')->twice()->andReturnUsing(function (...$args) use ($actual, &$calls) {
                if (++$calls === 2) {
                    $this->assertDatabaseCount('audit_logs', 1);

                    throw new RuntimeException('Synthetic audit failure.');
                }

                return $actual->record(...$args);
            });
        } else {
            $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Synthetic audit failure.'));
        }
        $this->app->instance(AuditLogService::class, $audit);
        $application = $this->application();
        $member = $operation === 'approve' ? null : $this->member(2, [
            'archived_at' => in_array($operation, ['archive', 'update']) ? null : '2025-02-01 00:00:00',
            'archived_by' => in_array($operation, ['archive', 'update']) ? null : self::ADMIN,
        ]);
        $before = $this->membershipState();
        try {
            match ($operation) {
                'approve' => app(MemberApplicationService::class)->approve($application, $this->actor()),
                'archive' => app(MemberService::class)->archive($member, $this->memberData($member, 'archive'), $this->actor()),
                'update' => app(MemberService::class)->update($member, $this->memberData($member, 'profile'), $this->actor()),
                'unarchive' => app(MemberService::class)->unarchive($member, $this->memberData($member), $this->actor()),
            };
            $this->fail('Audit failure must roll back the operation.');
        } catch (RuntimeException $exception) {
            $this->assertSame(RuntimeException::class, $exception::class);
            $this->assertSame('Synthetic audit failure.', $exception->getMessage());
        }
        $this->assertSame($before, $this->membershipState());
        if ($operation === 'approve') {
            $this->assertSame('pending', $application->fresh()->status);
            $this->assertNull($application->fresh()->reviewed_by);
            $this->assertSame(0, Member::count());
            $this->assertSame(0, DB::table('member_classification_assignments')->count());
        } elseif ($operation === 'archive') {
            $this->assertNull($member->fresh()->archived_at);
        } elseif ($operation === 'update') {
            $this->assertSame(2, $member->fresh()->position_id);
        } else {
            $this->assertNotNull($member->fresh()->archived_at);
            $this->assertSame(self::ADMIN, $member->fresh()->archived_by);
        }
        $this->assertSame(0, DB::table('audit_logs')->count());
        Http::assertNothingSent();
    }

    public static function auditFailures(): array
    {
        return [['approve'], ['archive'], ['unarchive'], ['update']];
    }
}
