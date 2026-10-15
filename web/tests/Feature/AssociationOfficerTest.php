<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\Member;
use App\Models\UserProfile;
use App\Services\AssociationOfficerService;
use App\Services\AuditLogService;
use App\Services\SupabaseAuthService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\IsolatedDatabaseManager;
use Tests\TestCase;

class AssociationOfficerTest extends TestCase
{
    private const ADMIN = '11111111-1111-4111-8111-111111111111';
    private const OFFICER = '22222222-2222-4222-8222-222222222222';
    private const ACCOUNT = '33333333-3333-4333-8333-333333333333';
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
        $auth = Mockery::mock(SupabaseAuthService::class);
        $auth->shouldReceive('getUser')->andReturnUsing(fn () => ['id' => $this->identity]);
        $this->app->instance(SupabaseAuthService::class, $auth);
    }

    protected function tearDown(): void
    {
        Member::flushEventListeners();
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
            $table->uuid('primary_field_officer_id'); $table->timestamp('archived_at')->nullable(); $table->timestamps();
        });
        Schema::create('association_positions', function (Blueprint $table) {
            $table->id(); $table->string('code')->unique(); $table->string('name');
            $table->boolean('is_officer'); $table->boolean('is_active'); $table->integer('sort_order'); $table->timestamps();
        });
        Schema::create('member_applications', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('association_id'); $table->string('status'); $table->timestamps();
        });
        Schema::create('members', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('association_id'); $table->uuid('source_application_id')->nullable()->unique();
            $table->string('first_name'); $table->string('last_name'); $table->string('contact_number')->nullable();
            $table->unsignedBigInteger('position_id'); $table->string('position_other_text')->nullable();
            $table->date('date_registered'); $table->string('member_status'); $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->foreign('position_id')->references('id')->on('association_positions');
            $table->foreign('association_id')->references('id')->on('associations');
            $table->foreign('source_application_id')->references('id')->on('member_applications');
        });
        // SQLite fixture protection does not prove PostgreSQL locking or migration 008.
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
        foreach ([1 => 'system_admin', 2 => 'field_officer', 3 => 'association_account'] as $id => $code) {
            DB::table('roles')->insert(['id' => $id, 'code' => $code, 'is_active' => true]);
        }
        foreach ([self::ADMIN => 1, self::OFFICER => 2, self::ACCOUNT => 3] as $id => $role) {
            DB::table('user_profiles')->insert(['id' => $id, 'role_id' => $role,
                'display_name' => 'Synthetic Actor', 'is_active' => true]);
        }
        foreach ([self::ASSOCIATION, self::OTHER_ASSOCIATION] as $id) {
            DB::table('associations')->insert(['id' => $id, 'name' => $id === self::ASSOCIATION ? 'Assigned Association' : 'Other Association',
                'status' => 'active', 'primary_field_officer_id' => $id === self::ASSOCIATION ? self::OFFICER : self::ADMIN]);
        }
        foreach ([1 => 'MEMBER', 2 => 'PRESIDENT', 3 => 'VICE_PRESIDENT', 4 => 'SECRETARY', 5 => 'TREASURER', 6 => 'AUDITOR'] as $id => $code) {
            DB::table('association_positions')->insert(['id' => $id, 'code' => $code, 'name' => $code,
                'is_officer' => $id !== 1, 'is_active' => true, 'sort_order' => $id]);
        }
    }

    private function member(int $position = 1, array $overrides = []): Member
    {
        $id = (string) Str::uuid();
        DB::table('members')->insert(array_replace([
            'id' => $id, 'association_id' => self::ASSOCIATION, 'source_application_id' => null,
            'first_name' => 'Synthetic', 'last_name' => 'Member', 'contact_number' => 'Private test contact',
            'position_id' => $position, 'date_registered' => '2025-01-01', 'member_status' => 'active',
            'created_at' => '2025-01-01 00:00:00', 'updated_at' => '2025-01-01 00:00:00',
        ], $overrides));

        return Member::findOrFail($id);
    }

    private function application(string $status = 'approved', string $association = self::ASSOCIATION): string
    {
        $id = (string) Str::uuid();
        DB::table('member_applications')->insert(['id' => $id, 'association_id' => $association, 'status' => $status]);

        return $id;
    }

    private function data(Member $member, int $position = 2): array
    {
        return ['action' => 'assign', 'member_id' => $member->id, 'position_id' => $position,
            'expected_position_id' => $member->position_id,
            'expected_updated_at' => $member->updated_at->format('Y-m-d\TH:i:s.uP'), 'confirm_change' => '1'];
    }

    private function replacement(Member $member, Member $former): array
    {
        return array_replace($this->data($member), ['action' => 'replace', 'former_member_id' => $former->id,
            'expected_former_position_id' => $former->position_id,
            'expected_former_updated_at' => $former->updated_at->format('Y-m-d\TH:i:s.uP')]);
    }

    private function change(array $data, string $actor = self::ADMIN): void
    {
        $request = Request::create('/synthetic/officers', 'PATCH');
        $request->attributes->set('current_user', UserProfile::with('role')->findOrFail($actor));
        app(AssociationOfficerService::class)->change($request, Association::findOrFail(self::ASSOCIATION), $data);
        Http::assertNothingSent();
    }

    private function rejected(array $data, string $field = 'member_id', int $status = 422): void
    {
        try {
            $this->change($data);
            $this->fail('An invalid officer position change must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
            $this->assertSame($status, $exception->status);
        }
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_admin_and_current_field_officer_can_appoint_admitted_members(): void
    {
        $direct = $this->member();
        $approved = $this->member(1, ['source_application_id' => $this->application()]);
        $this->change($this->data($direct), self::ADMIN);
        $this->change($this->data($approved, 3), self::OFFICER);
        $this->assertSame(2, $direct->fresh()->position_id);
        $this->assertSame(3, $approved->fresh()->position_id);
        $this->assertSame('approved', DB::table('member_applications')->value('status'));
        $audit = DB::table('audit_logs')->where('entity_id', $approved->id)->first();
        $this->assertSame(self::OFFICER, $audit->actor_user_id);
        $this->assertSame('ASSIGN ASSOCIATION OFFICER', $audit->action_type);
        $this->assertNotNull($audit->performed_at);
        $this->assertSame(['association_id' => self::ASSOCIATION, 'position_id' => 3], json_decode($audit->new_values, true));
        $this->assertSame('Private test contact', $approved->fresh()->contact_number);
        $this->assertSame('2025-01-01', $approved->fresh()->date_registered->format('Y-m-d'));
    }

    #[DataProvider('ineligibleCases')]
    public function test_ineligible_members_cannot_be_appointed(string $case): void
    {
        $overrides = match ($case) {
            'inactive' => ['member_status' => 'inactive'],
            'archived' => ['archived_at' => '2025-02-01 00:00:00'],
            'pending', 'rejected' => ['source_application_id' => $this->application($case)],
            'wrong_application_association' => ['source_application_id' => $this->application('approved', self::OTHER_ASSOCIATION)],
            default => ['association_id' => self::OTHER_ASSOCIATION],
        };
        $member = $this->member(1, $overrides);
        $this->rejected($this->data($member));
        $this->assertSame(1, $member->fresh()->position_id);
    }

    public static function ineligibleCases(): array
    {
        return array_map(fn ($case) => [$case], ['inactive', 'archived', 'pending', 'rejected', 'wrong_application_association', 'cross_association']);
    }

    #[DataProvider('executivePositions')]
    public function test_occupied_executive_office_rejects_assignment(int $position): void
    {
        $holder = $this->member($position);
        $candidate = $this->member();
        $this->rejected($this->data($candidate, $position), 'position_id', 409);
        $this->assertSame($position, $holder->fresh()->position_id);
        $this->assertSame(1, $candidate->fresh()->position_id);
    }

    public static function executivePositions(): array
    {
        return [[2], [3]];
    }

    public function test_nonexecutive_positions_allow_multiple_holders_and_duplicate_assignment_is_rejected(): void
    {
        $first = $this->member(4);
        $second = $this->member();
        $this->change($this->data($second, 4));
        $this->assertSame(2, Member::where('position_id', 4)->count());
        DB::table('audit_logs')->delete();
        $this->rejected($this->data($first, 4), 'position_id', 409);
    }

    #[DataProvider('officerPositions')]
    public function test_replacement_returns_former_holder_to_member_and_records_both_changes(int $position): void
    {
        $formerApplication = $this->application();
        $candidateApplication = $this->application();
        $former = $this->member($position, ['source_application_id' => $formerApplication]);
        $candidate = $this->member(1, ['source_application_id' => $candidateApplication]);
        $this->change($this->replacement($candidate, $former));
        $this->assertSame(1, $former->fresh()->position_id);
        $this->assertSame($position, $candidate->fresh()->position_id);
        $this->assertSame(2, Member::count());
        $this->assertSame(2, DB::table('audit_logs')->where('action_type', 'REPLACE ASSOCIATION OFFICER')->count());
        $this->assertSame('active', $former->fresh()->member_status);
        $this->assertSame(self::ASSOCIATION, $former->fresh()->association_id);
        $this->assertSame($formerApplication, $former->fresh()->source_application_id);
        $this->assertSame($candidateApplication, $candidate->fresh()->source_application_id);
        $this->assertSame(2, DB::table('member_applications')->where('status', 'approved')->count());
    }

    public static function officerPositions(): array
    {
        return [[2], [3], [4], [5], [6]];
    }

    public function test_removal_preserves_membership_and_allows_a_vacancy(): void
    {
        $holder = $this->member(2);
        $this->change(array_replace($this->data($holder), ['action' => 'remove']));
        $this->assertSame(1, $holder->fresh()->position_id);
        $this->assertSame('active', $holder->fresh()->member_status);
        $this->assertSame(0, Member::where('position_id', 2)->count());
        $this->assertSame('REMOVE ASSOCIATION OFFICER', DB::table('audit_logs')->value('action_type'));
    }

    public function test_another_associations_president_does_not_block_an_appointment(): void
    {
        $this->member(2, ['association_id' => self::OTHER_ASSOCIATION]);
        $candidate = $this->member();
        $this->change($this->data($candidate));
        $this->assertSame(2, Member::where('position_id', 2)->count());
    }

    public function test_cross_association_replacement_and_unavailable_member_lookup_are_rejected(): void
    {
        $former = $this->member(2, ['association_id' => self::OTHER_ASSOCIATION]);
        $candidate = $this->member();
        $this->rejected($this->replacement($candidate, $former));
        $holder = $this->member(3);
        DB::table('association_positions')->where('id', 1)->update(['is_active' => false]);
        $this->rejected($this->replacement($candidate, $holder), 'position_id');
        $this->assertSame(3, $holder->fresh()->position_id);
        $this->assertSame(1, $candidate->fresh()->position_id);
    }

    public function test_repeated_and_stale_decisions_fail_without_an_additional_audit(): void
    {
        $member = $this->member();
        $stale = $this->data($member);
        $this->change($stale);
        DB::table('audit_logs')->delete();
        $this->rejected($stale, 'member_id', 409);
        $fresh = $this->data($member->fresh(), 3);
        DB::table('members')->where('id', $member->id)->update(['updated_at' => '2026-01-01 00:00:00']);
        $this->rejected($fresh, 'member_id', 409);
    }

    public function test_replacement_rejects_stale_former_holder_and_self_replacement(): void
    {
        $former = $this->member(2);
        $candidate = $this->member();
        $data = $this->replacement($candidate, $former);
        DB::table('members')->where('id', $former->id)->update(['position_id' => 1]);
        $this->rejected($data, 'member_id', 409);
        $this->rejected($this->replacement($candidate, $candidate));
        $this->assertSame(1, $candidate->fresh()->position_id);
    }

    public function test_audit_failure_rolls_back_both_members_and_the_first_audit(): void
    {
        $former = $this->member(2);
        $candidate = $this->member();
        $actual = new AuditLogService;
        $calls = 0;
        $audit = Mockery::mock(AuditLogService::class);
        $audit->shouldReceive('record')->twice()->andReturnUsing(function (...$args) use ($actual, &$calls) {
            if (++$calls === 2) throw new RuntimeException('Synthetic audit failure.');

            return $actual->record(...$args);
        });
        $this->app->instance(AuditLogService::class, $audit);
        try {
            $this->change($this->replacement($candidate, $former));
            $this->fail('The whole replacement must roll back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure.', $exception->getMessage());
        }
        $this->assertSame(2, $former->fresh()->position_id);
        $this->assertSame(1, $candidate->fresh()->position_id);
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_reassignment_is_revalidated_even_with_a_stale_actor_and_association(): void
    {
        $request = Request::create('/synthetic/officers', 'PATCH');
        $request->attributes->set('current_user', UserProfile::with('role')->findOrFail(self::OFFICER));
        $association = Association::findOrFail(self::ASSOCIATION);
        $member = $this->member();
        DB::table('associations')->where('id', self::ASSOCIATION)->update(['primary_field_officer_id' => self::ADMIN]);
        try {
            app(AssociationOfficerService::class)->change($request, $association, $this->data($member));
            $this->fail('A former Field Officer must be denied.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(1, $member->fresh()->position_id);
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_archived_association_inactive_position_and_missing_confirmation_are_rejected(): void
    {
        $member = $this->member();
        $this->rejected(array_replace($this->data($member), ['confirm_change' => '0']), 'confirm_change');
        DB::table('association_positions')->where('id', 2)->update(['is_active' => false]);
        $this->rejected($this->data($member), 'position_id');
        DB::table('associations')->where('id', self::ASSOCIATION)->update(['archived_at' => '2025-01-01']);
        $this->rejected($this->data($member), 'association');
    }

    public function test_disabled_actor_and_inactive_role_cannot_write(): void
    {
        $member = $this->member();
        DB::table('user_profiles')->where('id', self::ADMIN)->update(['is_active' => false]);
        try {
            $this->change($this->data($member));
            $this->fail('A disabled actor must be denied.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        DB::table('user_profiles')->where('id', self::ADMIN)->update(['is_active' => true]);
        DB::table('roles')->where('id', 1)->update(['is_active' => false]);
        try {
            $this->change($this->data($member));
            $this->fail('An inactive role must be denied.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(1, $member->fresh()->position_id);
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_pages_scope_candidates_and_disable_occupied_executive_positions(): void
    {
        $this->member(2);
        $candidate = $this->member();
        $pending = $this->member(1, ['first_name' => 'PendingOnly', 'source_application_id' => $this->application('pending')]);
        $this->member(1, ['first_name' => 'CrossAssociationOnly', 'association_id' => self::OTHER_ASSOCIATION]);
        $this->withSession(['supabase_access_token' => 'synthetic-test-token']);
        $response = $this->get(route('admin.association-officers.show', ['association' => self::ASSOCIATION, 'member' => $candidate->id]));
        $response->assertOk()->assertSee('Save officer position')->assertDontSee('PendingOnly')
            ->assertDontSee('CrossAssociationOnly')->assertDontSee('Private test contact');
        $this->assertMatchesRegularExpression('/<option value="2"\s+disabled>/', $response->getContent());
        $this->identity = self::OFFICER;
        $this->get(route('field-officer.association-officers.index'))->assertOk()
            ->assertSee('Assigned Association')->assertDontSee('Other Association');
        $this->get(route('field-officer.association-officers.show', self::OTHER_ASSOCIATION))->assertForbidden();
        $this->get(route('field-officer.association-officers.show', ['association' => self::ASSOCIATION, 'member' => $pending->id]))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_public_and_association_accounts_cannot_manage_officers(): void
    {
        $this->get(route('admin.association-officers.index'))->assertRedirect(route('login'));
        $this->identity = self::ACCOUNT;
        $this->withSession(['supabase_access_token' => 'synthetic-test-token']);
        $this->get(route('admin.association-officers.index'))->assertForbidden();
        $this->get(route('field-officer.association-officers.index'))->assertForbidden();
        $member = $this->member();
        $this->patch(route('field-officer.association-officers.update', self::ASSOCIATION), $this->data($member))->assertForbidden();
        $this->assertSame(1, $member->fresh()->position_id);
        Http::assertNothingSent();
    }

    public function test_validation_errors_stay_in_the_officer_form_without_opening_the_area_editor(): void
    {
        $member = $this->member();
        $url = route('admin.association-officers.show', ['association' => self::ASSOCIATION, 'member' => $member->id]);
        $this->withSession(['supabase_access_token' => 'synthetic-test-token']);
        $this->from($url)->patch(route('admin.association-officers.update', self::ASSOCIATION),
            array_replace($this->data($member), ['confirm_change' => '0']))
            ->assertRedirect($url)->assertSessionHasErrors(['confirm_change'], null, 'officerPosition');
        $html = $this->get($url)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-area-form[\s\S]*?data-has-errors="false"/', $html);
        $this->assertSame(1, $member->fresh()->position_id);
        $this->assertSame(0, DB::table('audit_logs')->count());
        Http::assertNothingSent();
    }

    public function test_stale_submission_redirects_to_current_records_with_recovery_guidance(): void
    {
        $member = $this->member();
        $data = $this->data($member);
        DB::table('members')->where('id', $member->id)->update(['member_status' => 'inactive']);
        $show = route('admin.association-officers.show', self::ASSOCIATION);
        $this->withSession(['supabase_access_token' => 'synthetic-test-token']);
        $this->from($show.'?member='.$member->id)
            ->patch(route('admin.association-officers.update', self::ASSOCIATION), $data)
            ->assertRedirect($show)->assertSessionHasErrors(['member_id'], null, 'officerPosition');
        $this->get($show)->assertOk()->assertSee('Select an active, nonarchived association member.');
        $this->assertSame(0, DB::table('audit_logs')->count());
        Http::assertNothingSent();
    }
}
