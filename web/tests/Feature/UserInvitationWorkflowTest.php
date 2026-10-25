<?php

namespace Tests\Feature;

use App\Models\UserInvitation;
use App\Models\UserProfile;
use App\Services\Auth\InvitationActivationService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ProviderRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Support\CreatesLifecycleAuditTable;

class UserInvitationWorkflowTest extends TestCase
{
    use CreatesLifecycleAuditTable;

    private const ADMIN = '11111111-1111-4111-8111-111111111111';
    private const RECIPIENT = '22222222-2222-4222-8222-222222222222';
    private const ASSOCIATION = '33333333-3333-4333-8333-333333333333';
    private const OTHER_ASSOCIATION = '44444444-4444-4444-8444-444444444444';
    private const PROVIDER = 'https://supabase.example.test';

    private int $inviteRequests = 0;
    private bool $existingIdentity = false;
    private bool $timeout = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.invitation_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ], 'services.supabase.url' => self::PROVIDER,
            'services.supabase.publishable_key' => 'sb_publishable_synthetic_test_only',
            'services.supabase.service_role_key' => 'synthetic.server.signature',
            'services.supabase.invitation_redirect_url' => 'https://activation.example.test/invitation',
            // Route session locks remain in memory in this isolated fixture.
            'services.supabase.activation_cache_store' => 'array']);
        DB::setDefaultConnection('invitation_test');
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        $this->withoutVite();
        $this->schema();
        $this->createLifecycleAuditTable();
        (require database_path('migrations/2026_10_22_010000_support_association_account_replacement.php'))->up();
        $this->fixture();
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake([self::PROVIDER.'/auth/v1/*' => function (ProviderRequest $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'GET' && $path === '/auth/v1/user') {
                return Http::response(['id' => self::ADMIN, 'email' => 'administrator@example.test']);
            }
            if ($request->method() === 'GET' && $path === '/auth/v1/admin/users') {
                parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
                $users = $this->existingIdentity ? [['id' => self::RECIPIENT, 'email' => $query['filter']]] : [];
                $last = http_build_query(['filter' => $query['filter'], 'page' => count($users), 'per_page' => 100]);
                return Http::response(['users' => $users], 200, [
                    'X-Total-Count' => (string) count($users),
                    'Link' => '</auth/v1/admin/users?'.$last.'>; rel="last"',
                ]);
            }
            if ($request->method() === 'POST' && $path === '/auth/v1/invite') {
                $this->inviteRequests++;
                if ($this->timeout) throw new ConnectionException('Synthetic invitation timeout.');
                return Http::response(['id' => self::RECIPIENT, 'email' => $request['email'],
                    'created_at' => now()->utc()->addSecond()->toIso8601String()]);
            }
            if ($request->method() === 'POST' && $path === '/auth/v1/token') {
                return Http::response(['user' => ['id' => self::RECIPIENT, 'email' => 'recipient@example.test'],
                    'access_token' => 'synthetic_recipient_access', 'refresh_token' => 'synthetic_recipient_refresh']);
            }
            throw new \RuntimeException('Unexpected synthetic Auth request.');
        }]);
    }

    protected function tearDown(): void
    {
        UserProfile::flushEventListeners();
        DB::purge('invitation_test');
        parent::tearDown();
    }

    private function schema(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id(); $table->string('code')->unique(); $table->string('name');
            $table->boolean('is_active'); $table->timestamps();
            $table->text('description')->nullable();
        });
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->unsignedBigInteger('role_id');
            $table->string('display_name'); $table->boolean('is_active');
            $table->timestamp('activated_at')->nullable(); $table->uuid('invited_by')->nullable();
            $table->timestamp('invited_at')->nullable(); $table->timestamps();
        });
        Schema::create('associations', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->string('name'); $table->string('status');
            $table->uuid('primary_field_officer_id')->nullable();
            $table->timestamp('archived_at')->nullable(); $table->timestamps();
        });
        Schema::create('association_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('user_id')->unique();
            $table->uuid('association_id')->unique(); $table->boolean('is_active');
            $table->uuid('linked_by')->nullable(); $table->timestamp('linked_at'); $table->timestamps();
        });
        Schema::create('user_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->string('email'); $table->string('display_name');
            $table->unsignedBigInteger('role_id'); $table->uuid('association_id')->nullable();
            $table->uuid('invited_by')->nullable(); $table->uuid('auth_user_id')->nullable();
            $table->string('status'); $table->integer('attempt_count')->default(0);
            $table->timestamp('last_attempt_at')->nullable(); $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable(); $table->string('last_error_code')->nullable();
            $table->timestamps();
        });
    }

    private function fixture(): void
    {
        foreach ([1 => 'system_admin', 2 => 'field_officer', 3 => 'association_account'] as $id => $code) {
            DB::table('roles')->insert(['id' => $id, 'code' => $code, 'name' => ucwords(str_replace('_', ' ', $code)),
                'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('user_profiles')->insert(['id' => self::ADMIN, 'role_id' => 1,
            'display_name' => 'Synthetic Administrator', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([self::ASSOCIATION => 'Eligible Synthetic Association', self::OTHER_ASSOCIATION => 'Other Synthetic Association'] as $id => $name) {
            DB::table('associations')->insert(['id' => $id, 'name' => $name, 'status' => 'active',
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function data(int $role = 2): array
    {
        return ['email' => 'recipient@example.test', 'display_name' => 'Synthetic Recipient',
            'role_id' => $role, 'association_id' => $role === 3 ? self::ASSOCIATION : null];
    }

    private function administratorSession(): array
    {
        return ['supabase_access_token' => 'synthetic_admin_access'];
    }

    private function existingHolder(): string
    {
        $holder = '55555555-5555-4555-8555-555555555555';
        DB::table('user_profiles')->insert(['id' => $holder, 'role_id' => 3, 'display_name' => 'Former synthetic holder',
            'is_active' => true, 'activated_at' => now()->subDays(2), 'created_at' => now()->subDays(3)]);
        DB::table('association_accounts')->insert(['id' => $holder, 'user_id' => $holder, 'association_id' => self::ASSOCIATION,
            'is_active' => true, 'linked_by' => self::ADMIN, 'linked_at' => now()->subDays(2)]);

        return $holder;
    }

    public function test_replacement_revokes_old_holder_preserves_history_and_activates_a_separate_identity_once(): void
    {
        $old = $this->existingHolder();
        $associationBefore = DB::table('associations')->get()->toJson();
        $this->withSession($this->administratorSession())->patchJson('/admin/users/'.$old.'/revoke', ['confirm_revocation' => '1'])->assertOk();
        $historyBefore = DB::table('association_accounts')->where('id', $old)->first();
        $this->assertFalse(UserProfile::findOrFail($old)->is_active);
        $this->assertFalse((bool) $historyBefore->is_active);
        $this->assertNotNull($historyBefore->ended_at);
        $this->get('/admin/user-invitations/create')->assertOk()->assertSee('value="'.self::ASSOCIATION.'"', false);
        $this->postJson('/admin/user-invitations', $this->data(3))->assertCreated();
        $activation = app(InvitationActivationService::class);
        $context = $activation->identify(['auth_user_id' => self::RECIPIENT, 'email' => 'recipient@example.test']);
        $context['expires_at'] = time() + 600;
        $activation->claimPassword($context, 'synthetic_replacement_claim');
        $context['phase'] = 'password_confirmed';
        $activation->finish($context, 'synthetic_replacement_claim');
        $activation->finish($context, 'synthetic_replacement_claim');
        $this->assertSame($associationBefore, DB::table('associations')->get()->toJson());
        $this->assertEquals($historyBefore, DB::table('association_accounts')->where('id', $old)->first());
        $this->assertDatabaseCount('association_accounts', 2);
        $this->assertSame(self::RECIPIENT, DB::table('association_accounts')->where('is_active', true)->sole()->user_id);
        $this->assertFalse(UserProfile::findOrFail($old)->is_active);
        $this->assertTrue(UserProfile::findOrFail(self::RECIPIENT)->is_active);
        foreach (['END ASSOCIATION ACCOUNT LINK', 'REVOKE USER ACCESS', 'REQUEST USER INVITATION',
            'SEND USER INVITATION', 'ISSUE USER INVITATION', 'ACTIVATE USER ACCOUNT'] as $action) {
            $this->assertSame(1, DB::table('audit_logs')->where('action_type', $action)->count());
        }
        $this->assertSame(1, $this->inviteRequests);
    }

    public function test_uncertain_replacement_invitation_reserves_the_association_without_restoring_the_old_holder(): void
    {
        $old = $this->existingHolder();
        $this->withSession($this->administratorSession())->patchJson('/admin/users/'.$old.'/revoke', ['confirm_revocation' => '1'])->assertOk();
        $this->timeout = true;
        $this->postJson('/admin/user-invitations', $this->data(3))->assertStatus(202);
        $competing = $this->data(3);
        $competing['email'] = 'another-replacement@example.test';
        $this->postJson('/admin/user-invitations', $competing)->assertUnprocessable()->assertJsonValidationErrors('association_id');
        $this->assertSame(1, $this->inviteRequests);
        $this->assertSame(0, DB::table('association_accounts')->where('is_active', true)->count());
        $this->assertFalse(UserProfile::findOrFail($old)->is_active);
        $this->assertDatabaseCount('association_accounts', 1);
    }

    public function test_required_invitation_audit_failure_prevents_provider_requests(): void
    {
        DB::statement("CREATE TRIGGER reject_invitation_audit BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT, 'Synthetic audit rejection'); END");
        $this->withSession($this->administratorSession())->postJson('/admin/user-invitations', $this->data(3))->assertStatus(503);
        $this->assertSame(0, $this->inviteRequests);
        $this->assertDatabaseCount('user_invitations', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_required_activation_audit_failure_preserves_inactive_replacement_and_ended_history(): void
    {
        $old = $this->existingHolder();
        $this->withSession($this->administratorSession())->patchJson('/admin/users/'.$old.'/revoke', ['confirm_revocation' => '1'])->assertOk();
        $this->postJson('/admin/user-invitations', $this->data(3))->assertCreated();
        $activation = app(InvitationActivationService::class);
        $context = $activation->identify(['auth_user_id' => self::RECIPIENT, 'email' => 'recipient@example.test']);
        $context['expires_at'] = time() + 600;
        $activation->claimPassword($context, 'synthetic_replacement_claim');
        $context['phase'] = 'password_confirmed';
        DB::statement("CREATE TRIGGER reject_activation_audit BEFORE INSERT ON audit_logs WHEN NEW.action_type = 'ACTIVATE USER ACCOUNT' BEGIN SELECT RAISE(ABORT, 'Synthetic audit rejection'); END");
        try {
            $activation->finish($context, 'synthetic_replacement_claim');
            $this->fail('Activation must roll back when its required audit fails.');
        } catch (QueryException $exception) {
            $this->assertFalse(UserProfile::findOrFail(self::RECIPIENT)->is_active);
            $this->assertSame(0, DB::table('association_accounts')->where('is_active', true)->count());
            $this->assertDatabaseCount('association_accounts', 1);
            $this->assertSame('sent', UserInvitation::sole()->status);
            $this->assertSame('synthetic_replacement_claim', UserInvitation::sole()->last_error_code);
        }
    }

    public function test_authorized_form_uses_current_roles_and_eligible_associations(): void
    {
        DB::table('associations')->where('id', self::OTHER_ASSOCIATION)->update(['status' => 'inactive']);
        $response = $this->withSession($this->administratorSession())->get('/admin/user-invitations/create');
        $response->assertOk()->assertSee('Send invitation')->assertSee('Eligible Synthetic Association')
            ->assertDontSee('Other Synthetic Association')->assertSee('data-role-code="association_account"', false)
            ->assertDontSee('data-role-code="system_admin"', false)->assertSee('name="_token"', false);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        Http::assertSentCount(1);
        $this->assertSame(0, $this->inviteRequests);
    }

    #[DataProvider('recipientRoles')]
    public function test_administrator_creates_inactive_profile_with_correct_role(int $role): void
    {
        $response = $this->withSession($this->administratorSession())->postJson('/admin/user-invitations', $this->data($role));
        $response->assertCreated()->assertJsonPath('code', 'provider_accepted')->assertJsonPath('status', 'sent');
        $profile = UserProfile::findOrFail(self::RECIPIENT);
        $this->assertSame($role, (int) $profile->role_id);
        $this->assertFalse($profile->is_active);
        $this->assertNull($profile->activated_at);
        $this->assertSame(self::ADMIN, $profile->invited_by);
        $this->assertSame(0, DB::table('association_accounts')->count());
        $this->assertSame(1, $this->inviteRequests);
        Http::assertSent(fn (ProviderRequest $request) => $request->method() === 'POST'
            && parse_url($request->url(), PHP_URL_PATH) === '/auth/v1/invite'
            && $request['email'] === 'recipient@example.test' && ! isset($request['data']['role']));
        $response->assertDontSee('synthetic.server.signature')->assertDontSee('synthetic_admin_access');
    }

    public static function recipientRoles(): array
    {
        return ['Field Officer' => [2], 'Association Account' => [3]];
    }

    public function test_native_form_receives_clear_operation_feedback_and_clears_success_input(): void
    {
        $this->withSession($this->administratorSession())->post('/admin/user-invitations', $this->data())
            ->assertRedirect(route('admin.user-invitations.create'))
            ->assertSessionHas('invitation_result.code', 'provider_accepted')->assertSessionMissing('_old_input.email');
        $this->assertSame(1, $this->inviteRequests);
    }

    public function test_invalid_array_input_is_rejected_and_form_can_render_validation_feedback(): void
    {
        $data = $this->data();
        $data['display_name'] = ['invalid'];
        $this->withSession($this->administratorSession())->from('/admin/user-invitations/create')
            ->post('/admin/user-invitations', $data)->assertRedirect('/admin/user-invitations/create')
            ->assertSessionHasErrors('display_name');
        $this->get('/admin/user-invitations/create')->assertOk();
        $this->assertSame(0, $this->inviteRequests);
    }

    #[DataProvider('recipientRoles')]
    public function test_nonadministrator_cannot_view_or_submit_invitation(int $role): void
    {
        DB::table('user_profiles')->where('id', self::ADMIN)->update(['role_id' => $role]);
        $this->withSession($this->administratorSession())->get('/admin/user-invitations/create')->assertForbidden();
        $this->withSession($this->administratorSession())->postJson('/admin/user-invitations', $this->data())->assertForbidden();
        $this->assertSame(0, UserInvitation::count());
        $this->assertSame(0, $this->inviteRequests);
    }

    public function test_guest_and_inactive_administrator_cannot_invite(): void
    {
        $this->get('/admin/user-invitations/create')->assertRedirect(route('login'));
        $this->postJson('/admin/user-invitations', $this->data())->assertRedirect(route('login'));
        DB::table('user_profiles')->where('id', self::ADMIN)->update(['is_active' => false]);
        $this->withSession($this->administratorSession())->postJson('/admin/user-invitations', $this->data())->assertRedirect(route('login'));
        $this->assertSame(0, $this->inviteRequests);
    }

    public function test_duplicate_and_conflicting_requests_do_not_resend_or_change_role(): void
    {
        $this->withSession($this->administratorSession())->postJson('/admin/user-invitations', $this->data())->assertCreated();
        $this->postJson('/admin/user-invitations', $this->data())->assertStatus(409)->assertJsonPath('code', 'already_sent');
        $this->postJson('/admin/user-invitations', $this->data(3))->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame(1, UserInvitation::count());
        $this->assertSame(2, (int) UserProfile::findOrFail(self::RECIPIENT)->role_id);
        $this->assertSame(1, $this->inviteRequests);
    }

    public function test_association_conflict_names_the_existing_relationship_without_sending(): void
    {
        $this->withSession($this->administratorSession())->postJson('/admin/user-invitations', $this->data(3))->assertCreated();
        $profile = UserProfile::findOrFail(self::RECIPIENT);
        $profile->is_active = true;
        $profile->activated_at = now();
        $profile->save();
        DB::table('user_invitations')->where('auth_user_id', self::RECIPIENT)->update(['status' => 'accepted', 'accepted_at' => now()]);
        DB::table('association_accounts')->insert(['id' => '55555555-5555-4555-8555-555555555555',
            'user_id' => self::RECIPIENT, 'association_id' => self::ASSOCIATION, 'is_active' => true, 'linked_at' => now()]);
        $data = $this->data(3);
        $data['email'] = 'another@example.test';
        $this->postJson('/admin/user-invitations', $data)->assertUnprocessable()->assertJsonPath('errors.association_id.0',
            'This association already has an assigned Association Account: Eligible Synthetic Association. Review the existing account details.');
        $this->assertSame(1, $this->inviteRequests);
        $this->assertSame(1, UserInvitation::count());
    }

    public function test_inactive_association_and_another_unresolved_invitation_have_distinct_feedback(): void
    {
        DB::table('associations')->where('id', self::ASSOCIATION)->update(['status' => 'inactive']);
        $this->withSession($this->administratorSession())->postJson('/admin/user-invitations', $this->data(3))
            ->assertUnprocessable()->assertJsonPath('errors.association_id.0', 'This association is not currently eligible for account invitation.');
        DB::table('associations')->where('id', self::ASSOCIATION)->update(['status' => 'active']);
        $this->postJson('/admin/user-invitations', $this->data(3))->assertCreated();
        $data = $this->data(3);
        $data['email'] = 'another@example.test';
        $this->postJson('/admin/user-invitations', $data)->assertUnprocessable()->assertJsonPath('errors.association_id.0',
            'This association already has another unresolved invitation: Eligible Synthetic Association. Review that operation before inviting another account.');
        $this->assertSame(1, $this->inviteRequests);
    }

    #[DataProvider('recipientRoles')]
    public function test_contradictory_provider_success_never_binds_or_activates_an_account_or_resends(int $role): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake([
            self::PROVIDER.'/auth/v1/user' => Http::response(['id' => self::ADMIN, 'email' => 'administrator@example.test']),
            self::PROVIDER.'/auth/v1/admin/users*' => function (ProviderRequest $request) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
                $last = http_build_query(['filter' => $query['filter'], 'page' => 0, 'per_page' => 100]);

                return Http::response(['users' => []], 200, [
                    'X-Total-Count' => '0', 'Link' => '</auth/v1/admin/users?'.$last.'>; rel="last"',
                ]);
            },
            self::PROVIDER.'/auth/v1/invite*' => function (ProviderRequest $request) {
                $this->inviteRequests++;

                return Http::response(['id' => self::RECIPIENT, 'email' => $request['email'],
                    'created_at' => now()->utc()->addSecond()->toIso8601String(),
                    'error' => 'Synthetic private provider detail']);
            },
        ]);

        $this->withSession($this->administratorSession())->postJson('/admin/user-invitations', $this->data($role))
            ->assertStatus(202)->assertJsonPath('code', 'provider_response_invalid')
            ->assertJsonPath('status', 'failed')->assertDontSee('Synthetic private provider detail');
        $invitation = UserInvitation::sole();
        $this->assertNull($invitation->auth_user_id);
        $this->assertNull($invitation->accepted_at);
        $this->assertSame('provider_response_invalid', $invitation->last_error_code);
        $this->assertNull(UserProfile::find(self::RECIPIENT));
        $this->assertSame(0, DB::table('association_accounts')->count());
        $this->postJson('/admin/user-invitations', $this->data($role))
            ->assertStatus(409)->assertJsonPath('code', 'reconciliation_required');
        $this->assertSame(1, UserInvitation::count());
        $this->assertSame(1, $this->inviteRequests);
    }

    public function test_inactive_invited_role_and_inactive_administrator_role_are_denied(): void
    {
        DB::table('roles')->where('id', 2)->update(['is_active' => false]);
        $this->withSession($this->administratorSession())->postJson('/admin/user-invitations', $this->data())
            ->assertUnprocessable()->assertJsonValidationErrors('role_id');
        DB::table('roles')->where('id', 1)->update(['is_active' => false]);
        $this->postJson('/admin/user-invitations', $this->data(3))->assertRedirect(route('login'));
        $this->assertSame(0, UserInvitation::count());
        $this->assertSame(0, $this->inviteRequests);
    }

    public function test_other_unresolved_invitation_reserves_association(): void
    {
        $this->withSession($this->administratorSession())->postJson('/admin/user-invitations', $this->data(3))->assertCreated();
        $data = $this->data(3);
        $data['email'] = 'other-recipient@example.test';
        $this->postJson('/admin/user-invitations', $data)->assertUnprocessable()->assertJsonValidationErrors('association_id');
        $this->assertSame(1, UserInvitation::count());
        $this->assertSame(1, $this->inviteRequests);
    }

    #[DataProvider('unavailableAssociations')]
    public function test_unavailable_association_is_rejected_before_sending(string $state): void
    {
        DB::table('associations')->where('id', self::ASSOCIATION)->update(
            $state === 'inactive' ? ['status' => 'inactive'] : ['archived_at' => now()]
        );
        $this->withSession($this->administratorSession())->postJson('/admin/user-invitations', $this->data(3))
            ->assertUnprocessable()->assertJsonValidationErrors('association_id');
        $this->assertSame(0, $this->inviteRequests);
    }

    public static function unavailableAssociations(): array
    {
        return [['inactive'], ['archived']];
    }

    public function test_existing_active_account_link_and_elevated_role_are_rejected(): void
    {
        DB::table('association_accounts')->insert(['id' => '55555555-5555-4555-8555-555555555555',
            'user_id' => self::ADMIN, 'association_id' => self::ASSOCIATION, 'is_active' => true, 'linked_at' => now()]);
        $this->withSession($this->administratorSession())->postJson('/admin/user-invitations', $this->data(3))
            ->assertUnprocessable()->assertJsonValidationErrors('association_id');
        $this->postJson('/admin/user-invitations', $this->data(1))->assertUnprocessable()->assertJsonValidationErrors('role_id');
        $this->assertSame(0, $this->inviteRequests);
    }

    public function test_existing_auth_identity_is_not_overwritten(): void
    {
        $this->existingIdentity = true;
        DB::table('user_profiles')->insert(['id' => self::RECIPIENT, 'role_id' => 1, 'display_name' => 'Existing Synthetic Account',
            'is_active' => true, 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        $this->withSession($this->administratorSession())->postJson('/admin/user-invitations', $this->data())
            ->assertStatus(409)->assertJsonPath('code', 'existing_identity');
        $this->assertSame('Existing Synthetic Account', UserProfile::findOrFail(self::RECIPIENT)->display_name);
        $this->assertTrue(UserProfile::findOrFail(self::RECIPIENT)->is_active);
        $this->assertSame(0, $this->inviteRequests);
    }

    public function test_unknown_provider_outcome_cannot_be_blindly_retried(): void
    {
        $this->timeout = true;
        $this->withSession($this->administratorSession())->postJson('/admin/user-invitations', $this->data())
            ->assertStatus(202)->assertJsonPath('code', 'provider_outcome_unknown');
        $this->postJson('/admin/user-invitations', $this->data())->assertStatus(409)->assertJsonPath('code', 'reconciliation_required');
        $this->assertNull(UserProfile::find(self::RECIPIENT));
        $this->assertSame(1, $this->inviteRequests);
    }

    public function test_configuration_failure_precedes_provider_calls_and_preserves_cooldown_and_safe_retry(): void
    {
        config(['services.supabase.service_role_key' => 'sb_secret_synthetic_test_only']);
        $this->withSession($this->administratorSession())->postJson('/admin/user-invitations', $this->data())
            ->assertStatus(503)->assertJsonPath('code', 'configuration_missing');
        $operation = UserInvitation::sole();
        $this->assertSame('failed', $operation->status);
        $this->assertSame('configuration_missing', $operation->last_error_code);
        $this->assertNull($operation->auth_user_id);
        $this->assertSame(0, $this->inviteRequests);
        Http::assertSentCount(1);
        $this->get('/admin/user-invitations/create')->assertOk()->assertSee('SUPABASE_SERVICE_ROLE_KEY')
            ->assertDontSee('sb_secret_synthetic_test_only')->assertSee('Configuration cache: not present');
        $this->assertSame(1, UserInvitation::sole()->attempt_count);
        $this->postJson('/admin/user-invitations', $this->data())->assertStatus(429)->assertJsonPath('code', 'retry_cooldown');
        $this->assertSame(1, UserInvitation::count());
        $this->assertSame(1, UserInvitation::sole()->attempt_count);
        $this->assertSame(0, $this->inviteRequests);

        // Synthetic clock boundary; no real invitation or record is involved.
        UserInvitation::whereKey($operation->id)->update(['last_attempt_at' => now()->subMinutes(2)]);
        config(['services.supabase.service_role_key' => 'synthetic.server.signature']);
        $this->postJson('/admin/user-invitations', $this->data())->assertCreated();
        $this->assertSame($operation->id, UserInvitation::sole()->id);
        $this->assertSame(2, UserInvitation::sole()->attempt_count);
        $this->assertSame(1, $this->inviteRequests);
    }

    public function test_profile_storage_failure_rolls_back_and_requires_reconciliation(): void
    {
        UserProfile::creating(function () {
            throw new QueryException('invitation_test', 'synthetic profile insert', [], new \RuntimeException('Synthetic storage failure.'));
        });
        $this->withSession($this->administratorSession())->postJson('/admin/user-invitations', $this->data())
            ->assertStatus(503)->assertJsonPath('code', 'profile_sync_failed');
        $this->assertNull(UserProfile::find(self::RECIPIENT));
        $invitation = UserInvitation::sole();
        $this->assertSame('failed', $invitation->status);
        $this->assertNotNull($invitation->sent_at);
        $this->assertSame(self::RECIPIENT, $invitation->reconciliationAuthUserId());
        $this->postJson('/admin/user-invitations', $this->data())->assertStatus(409);
        $this->assertSame(1, $this->inviteRequests);
    }

    #[DataProvider('recipientRoles')]
    public function test_database_activation_then_login_reaches_correct_workspace(int $role): void
    {
        $this->withSession($this->administratorSession())->postJson('/admin/user-invitations', $this->data($role))->assertCreated();
        $activation = app(InvitationActivationService::class);
        $context = $activation->identify(['auth_user_id' => self::RECIPIENT, 'email' => 'recipient@example.test']);
        $context['expires_at'] = time() + 600;
        $activation->claimPassword($context, 'synthetic_confirmed_claim');
        $context['phase'] = 'password_confirmed';
        $activation->finish($context, 'synthetic_confirmed_claim');
        $this->post('/logout')->assertRedirect(route('login'));
        $destination = $role === 2 ? 'field-officer.dashboard' : 'association.dashboard';
        $this->post('/login', ['email' => 'recipient@example.test', 'password' => 'Synthetic-Password42!'])
            ->assertRedirect(route($destination))->assertSessionHas('user_profile_id', self::RECIPIENT)
            ->assertSessionHas('user_role', $role === 2 ? 'field_officer' : 'association_account');
        $this->assertSame('accepted', UserInvitation::sole()->status);
        $this->assertSame($role === 3 ? 1 : 0, DB::table('association_accounts')->count());
        if ($role === 3) {
            $this->assertSame(self::ASSOCIATION, DB::table('association_accounts')->sole()->association_id);
        }
    }

    public function test_sent_inactive_account_cannot_login_before_activation(): void
    {
        $this->withSession($this->administratorSession())->postJson('/admin/user-invitations', $this->data())->assertCreated();
        $this->post('/logout');
        $this->from('/login')->post('/login', ['email' => 'recipient@example.test', 'password' => 'Synthetic-Password42!'])
            ->assertRedirect('/login')->assertSessionHasErrors('email')->assertSessionMissing('supabase_access_token');
    }

    public function test_loopback_proxy_generates_https_links_without_hardcoded_tunnel_host(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->withHeaders([
            'X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'activation.example.test', 'X-Forwarded-Port' => '443',
        ])->get('/invitation');
        $response->assertOk()->assertSee('action="https://activation.example.test/invitation/verify"', false)
            ->assertHeader('Referrer-Policy', 'no-referrer');
        Http::assertNothingSent();
    }

    public function test_untrusted_remote_cannot_force_forwarded_scheme_or_host(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])->withHeaders([
            'X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'untrusted.example.test', 'X-Forwarded-Port' => '443',
        ])->get('http://origin.example.test/invitation');
        $response->assertOk()->assertSee('action="http://origin.example.test/invitation/verify"', false)
            ->assertDontSee('untrusted.example.test')->assertHeader('Referrer-Policy', 'no-referrer');
        Http::assertNothingSent();
    }
}
