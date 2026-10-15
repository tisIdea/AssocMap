<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Services\ProfileAuthIdentityService;
use App\Services\MemberApplicationPhotoService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    private const ADMIN = '11111111-1111-4111-8111-111111111111';
    private const OFFICER_A = '22222222-2222-4222-8222-222222222222';
    private const OFFICER_B = '33333333-3333-4333-8333-333333333333';
    private const ACCOUNT = '44444444-4444-4444-8444-444444444444';
    private const ASSOCIATION = '55555555-5555-4555-8555-555555555555';
    private const SECOND = '66666666-6666-4666-8666-666666666666';
    private const OTHER = '77777777-7777-4777-8777-777777777777';
    private const ARCHIVED = '88888888-8888-4888-8888-888888888888';
    private const APPLICATION = '99999999-9999-4999-8999-999999999999';
    private const LINK = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const INVITATION = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private string $actor = self::ADMIN;
    private ?Collection $authIdentities;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.user_management_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ], 'services.supabase.url' => 'https://auth.example.test',
            'services.supabase.publishable_key' => 'sb_publishable_synthetic_test_only']);
        DB::setDefaultConnection('user_management_test');
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        $this->withoutVite();
        $this->schema();
        $this->fixture();
        $this->authIdentities = collect([
            self::ADMIN => (object) ['id' => self::ADMIN, 'email' => 'administrator@example.test'],
            self::OFFICER_A => (object) ['id' => self::OFFICER_A, 'email' => 'officer-a@example.test'],
            self::OFFICER_B => (object) ['id' => self::OFFICER_B, 'email' => 'officer-b@example.test'],
            self::ACCOUNT => (object) ['id' => self::ACCOUNT, 'email' => 'association@example.test'],
        ]);
        $reader = Mockery::mock(ProfileAuthIdentityService::class);
        $reader->shouldReceive('read')->andReturnUsing(function (array $ids) {
            $this->assertLessThanOrEqual(25, count($ids));
            return $this->authIdentities?->only($ids);
        });
        $this->app->instance(ProfileAuthIdentityService::class, $reader);
        $this->app->instance(MemberApplicationPhotoService::class, Mockery::mock(MemberApplicationPhotoService::class));
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(['https://auth.example.test/auth/v1/user' => function () {
            return Http::response(['id' => $this->actor, 'email' => 'actor@example.test']);
        }]);
    }

    protected function tearDown(): void
    {
        Association::flushEventListeners();
        DB::purge('user_management_test');
        parent::tearDown();
    }

    private function schema(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id(); $table->string('code'); $table->string('name'); $table->boolean('is_active'); $table->timestamps();
        });
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->unsignedBigInteger('role_id'); $table->string('display_name');
            $table->boolean('is_active'); $table->timestamp('activated_at')->nullable(); $table->timestamps();
        });
        Schema::create('associations', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->string('name'); $table->string('status');
            $table->uuid('primary_field_officer_id'); $table->uuid('updated_by')->nullable();
            $table->timestamp('archived_at')->nullable(); $table->timestamps();
            $table->foreign('primary_field_officer_id')->references('id')->on('user_profiles');
        });
        Schema::create('association_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('association_id')->unique(); $table->uuid('user_id')->unique();
            $table->boolean('is_active'); $table->timestamps();
        });
        Schema::create('user_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->string('email'); $table->uuid('auth_user_id')->nullable();
            $table->string('status'); $table->string('last_error_code')->nullable();
            $table->timestamp('accepted_at')->nullable(); $table->timestamp('last_attempt_at')->nullable(); $table->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('actor_user_id')->nullable(); $table->string('action_type');
            $table->string('module'); $table->string('entity_type')->nullable(); $table->string('entity_id')->nullable();
            $table->text('old_values')->nullable(); $table->text('new_values')->nullable(); $table->timestamp('performed_at');
        });
        Schema::create('member_applications', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('association_id'); $table->string('status');
            $table->uuid('reviewed_by')->nullable(); $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason')->nullable(); $table->timestamps();
        });
    }

    private function fixture(): void
    {
        foreach ([1 => 'system_admin', 2 => 'field_officer', 3 => 'association_account'] as $id => $code) {
            DB::table('roles')->insert(['id' => $id, 'code' => $code, 'name' => ucwords(str_replace('_', ' ', $code)),
                'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([self::ADMIN => [1, 'Synthetic Administrator'], self::OFFICER_A => [2, 'Synthetic Officer A'],
            self::OFFICER_B => [2, 'Synthetic Officer B'], self::ACCOUNT => [3, 'Synthetic Association Account']] as $id => $values) {
            DB::table('user_profiles')->insert(['id' => $id, 'role_id' => $values[0], 'display_name' => $values[1],
                'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([self::ASSOCIATION => [self::OFFICER_A, 'First Synthetic Association', 'active', null],
            self::SECOND => [self::OFFICER_A, 'Second Synthetic Association', 'inactive', null],
            self::OTHER => [self::OFFICER_B, 'Other Synthetic Association', 'active', null],
            self::ARCHIVED => [self::OFFICER_A, 'Archived Synthetic Association', 'active', now()]] as $id => $values) {
            DB::table('associations')->insert(['id' => $id, 'primary_field_officer_id' => $values[0],
                'name' => $values[1], 'status' => $values[2], 'archived_at' => $values[3],
                'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('association_accounts')->insert(['id' => self::LINK, 'user_id' => self::ACCOUNT,
            'association_id' => self::ASSOCIATION, 'is_active' => true]);
        DB::table('member_applications')->insert(['id' => self::APPLICATION, 'association_id' => self::ASSOCIATION,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function actorSessionData(): array
    {
        return ['supabase_access_token' => 'synthetic_actor_access'];
    }

    private function assignmentUrl(string $officer = self::OFFICER_B): string
    {
        return '/admin/users/'.$officer.'/associations';
    }

    private function assignmentData(array $ids = [self::ASSOCIATION, self::SECOND]): array
    {
        return ['association_ids' => $ids, 'expected_officers' => array_fill_keys($ids, self::OFFICER_A),
            'confirm_reassignment' => '1'];
    }

    public function test_administrator_register_lists_profiles_current_emails_and_relationships(): void
    {
        DB::table('user_profiles')->where('id', self::OFFICER_B)->update(['is_active' => false]);
        DB::table('user_invitations')->insert(['id' => self::INVITATION, 'auth_user_id' => self::ACCOUNT,
            'email' => 'association@example.test', 'status' => 'accepted', 'accepted_at' => now(),
            'created_at' => now(), 'updated_at' => now()]);
        $response = $this->withSession($this->actorSessionData())->get('/admin/users');
        $response->assertOk()->assertSee('Synthetic Officer A')->assertSee('officer-a@example.test')
            ->assertSee('Inactive')->assertSee('Accepted')->assertSee('First Synthetic Association')
            ->assertSee('3 association(s); 1 archived.')->assertDontSee('synthetic_actor_access');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        Http::assertSentCount(1);
    }

    #[DataProvider('otherActors')]
    public function test_other_roles_cannot_list_users_or_manage_assignments(string $actor): void
    {
        $this->actor = $actor;
        $this->withSession($this->actorSessionData())->get('/admin/users')->assertForbidden();
        $this->get($this->assignmentUrl())->assertForbidden();
        $this->patchJson($this->assignmentUrl(), $this->assignmentData())->assertForbidden();
        $this->assertSame(self::OFFICER_A, Association::findOrFail(self::ASSOCIATION)->primary_field_officer_id);
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public static function otherActors(): array
    {
        return [[self::OFFICER_A], [self::ACCOUNT]];
    }

    public function test_guest_and_disabled_administrator_are_denied(): void
    {
        $this->get('/admin/users')->assertRedirect(route('login'));
        $this->patchJson($this->assignmentUrl(), $this->assignmentData())->assertRedirect(route('login'));
        DB::table('user_profiles')->where('id', self::ADMIN)->update(['is_active' => false]);
        $this->withSession($this->actorSessionData())->get('/admin/users')->assertRedirect(route('login'));
    }

    public function test_assignment_without_csrf_token_cannot_change_records(): void
    {
        $this->app->bind(ValidateCsrfToken::class, function ($app) {
            return new class($app, $app['encrypter']) extends ValidateCsrfToken {
                protected function runningUnitTests() { return false; }
            };
        });
        $this->withSession($this->actorSessionData())->patchJson($this->assignmentUrl(), $this->assignmentData())->assertStatus(419);
        $this->assertSame(self::OFFICER_A, Association::findOrFail(self::ASSOCIATION)->primary_field_officer_id);
        $this->assertSame(0, DB::table('audit_logs')->count());
        Http::assertNothingSent();
    }

    public function test_missing_auth_identity_is_visible_without_provisioning_or_activation(): void
    {
        $this->authIdentities->forget(self::OFFICER_B);
        $this->withSession($this->actorSessionData())->get('/admin/users')->assertOk()
            ->assertSee('Synthetic Officer B')->assertSee('Matching Auth identity not found in server lookup');
        $this->assertSame(4, DB::table('user_profiles')->count());
        $this->assertSame(0, DB::table('user_invitations')->count());
        $this->assertSame(0, DB::table('audit_logs')->count());
        Http::assertSentCount(1);
    }

    public function test_unavailable_auth_read_does_not_hide_profiles_or_claim_missing_linkage(): void
    {
        $this->authIdentities = null;
        $this->withSession($this->actorSessionData())->get('/admin/users')->assertOk()->assertSee('Synthetic Officer A')
            ->assertSee('Auth metadata lookup unavailable')->assertDontSee('Matching Auth identity not found');
    }

    public function test_auth_only_identity_is_not_an_application_profile(): void
    {
        $this->authIdentities->put('cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            (object) ['id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'email' => 'auth-only@example.test']);
        $this->withSession($this->actorSessionData())->get('/admin/users')->assertOk()->assertDontSee('auth-only@example.test');
        $this->assertSame(4, DB::table('user_profiles')->count());
    }

    public function test_invitation_email_mismatch_is_not_silently_synchronized(): void
    {
        DB::table('user_invitations')->insert(['id' => self::INVITATION, 'auth_user_id' => self::ACCOUNT,
            'email' => 'historical@example.test', 'status' => 'accepted', 'accepted_at' => now(),
            'created_at' => now(), 'updated_at' => now()]);
        $this->withSession($this->actorSessionData())->get('/admin/users')->assertOk()->assertSee('association@example.test')
            ->assertSee('Current Auth email differs from invitation history');
        $this->assertSame('historical@example.test', DB::table('user_invitations')->value('email'));
    }

    public function test_assignment_form_displays_existing_and_selectable_associations(): void
    {
        $this->withSession($this->actorSessionData())->get($this->assignmentUrl(self::OFFICER_A))->assertOk()
            ->assertSee('Current assignments (3)')->assertSee('Archived Synthetic Association')
            ->assertSee('Other Synthetic Association')->assertSee('name="_token"', false);
    }

    public function test_multiple_transfers_are_atomic_and_repeated_submission_creates_no_duplicate_events(): void
    {
        $this->withSession($this->actorSessionData())->patchJson($this->assignmentUrl(), $this->assignmentData())
            ->assertOk()->assertJsonPath('changed', 2);
        $this->assertSame(3, Association::where('primary_field_officer_id', self::OFFICER_B)->count());
        $this->assertSame(self::OFFICER_A, Association::findOrFail(self::ARCHIVED)->primary_field_officer_id);
        $this->assertSame(self::ADMIN, Association::findOrFail(self::ASSOCIATION)->updated_by);
        $this->assertSame(2, DB::table('audit_logs')->count());
        $this->assertSame(self::ADMIN, DB::table('audit_logs')->value('actor_user_id'));
        $event = DB::table('audit_logs')->where('entity_id', self::ASSOCIATION)->sole();
        $this->assertSame('REASSIGN FIELD OFFICER', $event->action_type);
        $this->assertSame(['primary_field_officer_id' => self::OFFICER_A], json_decode($event->old_values, true));
        $this->assertSame(['primary_field_officer_id' => self::OFFICER_B], json_decode($event->new_values, true));
        $this->assertNotNull($event->performed_at);
        $this->assertSame(self::ASSOCIATION, DB::table('association_accounts')->value('association_id'));
        $this->patchJson($this->assignmentUrl(), $this->assignmentData())->assertOk()->assertJsonPath('changed', 0);
        $this->assertSame(2, DB::table('audit_logs')->count());
        $this->assertSame(4, Association::count());
    }

    public function test_duplicates_missing_snapshots_and_unconfirmed_changes_are_rejected(): void
    {
        $this->withSession($this->actorSessionData())->patchJson($this->assignmentUrl(), $this->assignmentData([self::ASSOCIATION, self::ASSOCIATION]))
            ->assertUnprocessable();
        $data = $this->assignmentData();
        $data['expected_officers'] = [];
        $this->patchJson($this->assignmentUrl(), $data)->assertUnprocessable();
        $data = $this->assignmentData();
        $data['confirm_reassignment'] = '0';
        $this->patchJson($this->assignmentUrl(), $data)->assertUnprocessable();
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_archived_and_missing_associations_roll_back_the_whole_batch(): void
    {
        $this->withSession($this->actorSessionData())->patchJson($this->assignmentUrl(), $this->assignmentData([self::ASSOCIATION, self::ARCHIVED]))
            ->assertUnprocessable();
        $this->assertSame(self::OFFICER_A, Association::findOrFail(self::ASSOCIATION)->primary_field_officer_id);
        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->patchJson($this->assignmentUrl(), $this->assignmentData(['dddddddd-dddd-4ddd-8ddd-dddddddddddd']))
            ->assertUnprocessable();
    }

    public function test_stale_transfer_cannot_overwrite_a_different_current_officer(): void
    {
        $data = $this->assignmentData([self::ASSOCIATION]);
        $data['expected_officers'][self::ASSOCIATION] = self::OFFICER_B;
        $this->withSession($this->actorSessionData())->patchJson($this->assignmentUrl(), $data)->assertStatus(409);
        $this->assertSame(self::OFFICER_A, Association::findOrFail(self::ASSOCIATION)->primary_field_officer_id);
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_ineligible_target_and_revoked_administrator_are_denied_without_writes(): void
    {
        $this->withSession($this->actorSessionData())->patchJson($this->assignmentUrl(self::ACCOUNT), $this->assignmentData())->assertUnprocessable();
        DB::table('roles')->where('id', 2)->update(['is_active' => false]);
        $this->patchJson($this->assignmentUrl(), $this->assignmentData())->assertUnprocessable();
        DB::table('roles')->where('id', 1)->update(['is_active' => false]);
        $this->patchJson($this->assignmentUrl(), $this->assignmentData())->assertRedirect(route('login'));
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_inactive_or_pending_invitation_officer_cannot_receive_assignments(): void
    {
        DB::table('user_profiles')->where('id', self::OFFICER_B)->update(['is_active' => false]);
        $this->withSession($this->actorSessionData())->patchJson($this->assignmentUrl(), $this->assignmentData())->assertUnprocessable();
        DB::table('user_profiles')->where('id', self::OFFICER_B)->update(['is_active' => true]);
        DB::table('user_invitations')->insert(['id' => self::INVITATION, 'auth_user_id' => self::OFFICER_B,
            'email' => 'officer-b@example.test', 'status' => 'sent', 'created_at' => now(), 'updated_at' => now()]);
        $this->patchJson($this->assignmentUrl(), $this->assignmentData())->assertUnprocessable();
        $this->assertSame(self::OFFICER_A, Association::findOrFail(self::ASSOCIATION)->primary_field_officer_id);
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_assignment_payload_cannot_change_actor_or_role(): void
    {
        $data = $this->assignmentData();
        $data['updated_by'] = self::OFFICER_A;
        $data['role_id'] = 1;
        $this->withSession($this->actorSessionData())->patchJson($this->assignmentUrl(), $data)->assertUnprocessable()
            ->assertJsonValidationErrors(['updated_by', 'role_id']);
        $this->assertSame(2, (int) DB::table('user_profiles')->where('id', self::OFFICER_B)->value('role_id'));
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_audit_failure_rolls_back_assignment_changes(): void
    {
        Schema::drop('audit_logs');
        $this->withSession($this->actorSessionData())->patchJson($this->assignmentUrl(), $this->assignmentData())->assertStatus(503);
        $this->assertSame(self::OFFICER_A, Association::findOrFail(self::ASSOCIATION)->primary_field_officer_id);
        $this->assertSame(self::OFFICER_A, Association::findOrFail(self::SECOND)->primary_field_officer_id);
    }

    public function test_old_officer_loses_review_scope_and_new_officer_receives_it(): void
    {
        $this->withSession($this->actorSessionData())->patchJson($this->assignmentUrl(), $this->assignmentData([self::ASSOCIATION]))->assertOk();
        $this->actor = self::OFFICER_A;
        $url = '/field-officer/member-applications/'.self::APPLICATION.'/reject';
        $this->patchJson($url, ['rejection_reason' => 'Synthetic decision'])->assertUnprocessable()->assertJsonValidationErrors('reviewer');
        $this->assertSame('pending', DB::table('member_applications')->value('status'));
        $this->actor = self::OFFICER_B;
        $this->patchJson($url, ['rejection_reason' => 'Synthetic decision'])->assertRedirect();
        $this->assertSame('rejected', DB::table('member_applications')->value('status'));
        $this->assertSame(self::OFFICER_B, DB::table('member_applications')->value('reviewed_by'));
        $this->assertSame(self::ACCOUNT, DB::table('association_accounts')->value('user_id'));
        $this->assertSame(self::ASSOCIATION, DB::table('association_accounts')->value('association_id'));
    }
}
