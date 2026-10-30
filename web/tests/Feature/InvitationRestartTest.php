<?php

namespace Tests\Feature;

use App\Models\AssociationAccount;
use App\Models\UserInvitation;
use App\Models\UserProfile;
use App\Services\Auth\InvitationActivationContext;
use App\Services\Auth\InvitationActivationService;
use App\Services\Auth\InvitationRestartAudit;
use App\Services\Auth\InvitationRestartService;
use App\Services\Auth\UserAccessRevocationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ProviderRequest;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Tests\Support\CreatesAccountLifecycleSchema;

class InvitationRestartTest extends TestCase
{
    use CreatesAccountLifecycleSchema;

    private const USER = '11111111-1111-4111-8111-111111111111';
    private const OTHER = '22222222-2222-4222-8222-222222222222';
    private const INVITATION = '33333333-3333-4333-8333-333333333333';
    private const ASSOCIATION = '44444444-4444-4444-8444-444444444444';
    private const PASSWORD = 'Synthetic-Password42!';
    private ?string $reference = null;
    private int $passwordWrites = 0;
    private int $verifications = 0;
    private string $providerId = self::USER;
    private string $providerEmail = 'recipient@example.test';
    private bool $confirmed = true;
    private ?string $independentId = null;
    private int $verificationStatus = 200;
    private $afterPassword = null;
    private ?string $timeout = null;
    private $duringVerification = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        config(['app.url' => 'https://app.example.test', 'services.supabase.url' => 'https://auth.example.test',
            'services.supabase.publishable_key' => 'sb_publishable_synthetic',
            'services.supabase.service_role_key' => 'synthetic.server.signature',
            'services.supabase.invitation_redirect_url' => 'https://app.example.test/invitation',
            'services.supabase.activation_cache_store' => 'array', 'services.supabase.invitation_restart_enabled' => true]);
        $storage = Mockery::mock(InvitationActivationContext::class)->makePartial();
        $storage->shouldReceive('storage')->andReturn(Cache::store('array'));
        $this->app->instance(InvitationActivationContext::class, $storage);
        $this->schema();
        foreach ([2 => 'field_officer', 3 => 'association_account'] as $id => $code) {
            DB::table('roles')->insert(['id' => $id, 'code' => $code, 'name' => $code, 'is_active' => true]);
        }
        DB::table('user_profiles')->insert(['id' => self::USER, 'role_id' => 2, 'display_name' => 'Synthetic Recipient',
            'is_active' => false, 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        DB::table('associations')->insert(['id' => self::ASSOCIATION, 'name' => 'Synthetic Association', 'status' => 'active']);
        DB::table('user_invitations')->insert(['id' => self::INVITATION, 'auth_user_id' => self::USER,
            'role_id' => 2, 'email' => 'recipient@example.test', 'display_name' => 'Synthetic Recipient', 'status' => 'sent',
            'attempt_count' => 1, 'created_at' => now()->subDay(), 'sent_at' => now()->subDay()]);
        Http::fake(function (ProviderRequest $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($this->timeout === $request->method().' '.$path) throw new ConnectionException('Synthetic private provider detail');
            if ($path === '/auth/v1/admin/users/'.self::USER) {
                return Http::response($this->identity() + ['invited_at' => '2026-10-01T00:00:00Z']);
            }
            if ($path === '/auth/v1/recover') {
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
                $this->assertMatchesRegularExpression('~\Ahttps://app\.example\.test/invitation/restart/[A-Za-z0-9]{64}\z~D', $query['redirect_to']);
                $this->reference = basename($query['redirect_to']);
                $this->assertSame('recipient@example.test', $request['email']);
                return Http::response('{}', 200, ['Content-Type' => 'application/json']);
            }
            if ($path === '/auth/v1/verify') {
                $this->verifications++;
                if ($this->verificationStatus !== 200) return Http::response(['code' => 'synthetic_private_detail'], $this->verificationStatus);
                $this->assertSame('recovery', $request['type']);
                if ($this->duringVerification) ($this->duringVerification)();
                return Http::response(['access_token' => 'synthetic_recipient_access', 'refresh_token' => 'synthetic_unused_refresh',
                    'token_type' => 'bearer', 'expires_in' => 3600, 'user' => $this->identity()]);
            }
            $this->assertSame('/auth/v1/user', $path);
            if ($request->method() === 'PUT') {
                $this->passwordWrites++;
                if ($this->afterPassword) ($this->afterPassword)();
            }
            $identity = $this->identity();
            if ($request->method() === 'GET' && $this->independentId !== null) $identity['id'] = $this->independentId;
            return Http::response($identity);
        });
    }

    protected function tearDown(): void
    {
        UserInvitation::flushEventListeners();
        AssociationAccount::flushEventListeners();
        parent::tearDown();
    }

    private function schema(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->id(); $t->string('code'); $t->string('name'); $t->boolean('is_active');
            $t->text('description')->nullable(); $t->timestamps();
        });
        Schema::create('user_profiles', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->unsignedBigInteger('role_id'); $t->string('display_name');
            $t->boolean('is_active'); $t->timestamp('activated_at')->nullable(); $t->timestamps();
        });
        Schema::create('associations', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('name'); $t->string('status'); $t->timestamp('archived_at')->nullable();
        });
        Schema::create('association_accounts', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('user_id')->unique(); $t->uuid('association_id')->unique();
            $t->boolean('is_active'); $t->uuid('linked_by')->nullable(); $t->timestamp('linked_at'); $t->timestamps();
        });
        Schema::create('user_invitations', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('auth_user_id')->nullable(); $t->string('email'); $t->string('display_name');
            $t->unsignedBigInteger('role_id'); $t->uuid('association_id')->nullable(); $t->string('status');
            $t->unsignedInteger('attempt_count'); $t->timestamp('accepted_at')->nullable(); $t->timestamp('sent_at')->nullable();
            $t->timestamp('last_attempt_at')->nullable(); $t->string('last_error_code')->nullable(); $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('actor_user_id')->nullable(); $t->string('action_type'); $t->string('module');
            $t->string('entity_type')->nullable(); $t->string('entity_id')->nullable(); $t->text('old_values')->nullable();
            $t->text('new_values')->nullable(); $t->timestamp('performed_at');
        });
    }

    private function identity(): array
    {
        return ['id' => $this->providerId, 'email' => $this->providerEmail,
            'email_confirmed_at' => $this->confirmed ? '2026-10-01T00:00:00Z' : null];
    }

    private function associationRole(): void
    {
        DB::table('user_profiles')->where('id', self::USER)->update(['role_id' => 3]);
        DB::table('user_invitations')->where('id', self::INVITATION)->update(['role_id' => 3, 'association_id' => self::ASSOCIATION]);
    }

    public function test_replacement_can_restart_interrupted_activation_without_restoring_the_former_holder(): void
    {
        $this->associationRole();
        $this->createAssociationReplacementSchema();
        DB::table('user_profiles')->insert(['id' => self::ASSOCIATION, 'role_id' => 3,
            'display_name' => 'Former synthetic holder', 'is_active' => false, 'activated_at' => now()->subDays(2)]);
        DB::table('association_accounts')->insert(['id' => self::ASSOCIATION, 'user_id' => self::ASSOCIATION,
            'association_id' => self::ASSOCIATION, 'is_active' => false, 'linked_at' => now()->subDays(2), 'ended_at' => now()->subDay()]);
        $before = DB::table('association_accounts')->sole();
        $this->requestLink()->assertRedirect(route('invitation.restart'));
        $this->verify()->assertRedirect(route('invitation.password'));
        $this->complete()->assertSessionHas('invitation_activation_succeeded', true);
        $this->assertEquals($before, DB::table('association_accounts')->where('id', self::ASSOCIATION)->first());
        $this->assertSame(self::USER, DB::table('association_accounts')->where('is_active', true)->sole()->user_id);
        $this->assertFalse(UserProfile::findOrFail(self::ASSOCIATION)->is_active);
        $this->assertSame(1, $this->passwordWrites);
        $this->assertDatabaseCount('association_accounts', 2);
    }

    private function requestLink(): TestResponse
    {
        return $this->remember($this->post('/invitation/restart', ['email' => ' Recipient@Example.test ']));
    }

    private function verify(?string $reference = null, array $extra = []): TestResponse
    {
        return $this->remember($this->post('/invitation/restart/'.($reference ?? $this->reference).'/verify',
            ['token_hash' => str_repeat('a', 56)] + $extra));
    }

    private function remember(TestResponse $response): TestResponse
    {
        $cookie = $response->getCookie(config('session.cookie'));
        if ($cookie) $this->withCookie($cookie->getName(), $cookie->getValue());

        return $response;
    }

    private function record(): array
    {
        return json_decode(Crypt::decryptString(Cache::store('array')->get('invitation-restart:'.$this->reference)), true);
    }

    private function complete(): TestResponse
    {
        return $this->remember($this->post('/invitation/password', ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD]));
    }

    #[DataProvider('roles')]
    public function test_clean_restart_preserves_binding_and_completes_once(int $role): void
    {
        if ($role === 3) $this->associationRole();
        $before = UserProfile::findOrFail(self::USER)->getAttributes();
        $this->requestLink()->assertRedirect(route('invitation.restart'))->assertSessionMissing('_old_input');
        $this->assertFalse(UserProfile::findOrFail(self::USER)->is_active);
        $snapshot = $this->record()['scope'];
        $this->assertSame(self::INVITATION, $snapshot['invitation_id']);
        $this->assertSame(self::USER, $snapshot['auth_user_id']);
        $this->assertSame((string) $role, $snapshot['role_id']);
        $this->assertSame($role === 3 ? self::ASSOCIATION : null, $snapshot['association_id']);
        $oldId = session()->getId();
        $oldCsrf = session()->token();
        $this->verify()->assertRedirect(route('invitation.password'))->assertSessionMissing('supabase_access_token')
            ->assertSessionMissing('supabase_refresh_token')->assertSessionMissing('user_profile_id');
        $this->assertNotSame($oldId, session()->getId());
        $this->assertNotSame($oldCsrf, session()->token());
        $this->assertSame(0, $this->passwordWrites);
        $this->assertSame($before, UserProfile::findOrFail(self::USER)->getAttributes());
        $this->remember($this->get('/invitation/password'))->assertOk()->assertDontSee('synthetic_recipient_access');
        $this->complete()->assertRedirect(route('invitation.result'))->assertSessionHas('invitation_activation_succeeded', true);
        $this->assertTrue(UserProfile::findOrFail(self::USER)->is_active);
        $this->assertSame('accepted', UserInvitation::findOrFail(self::INVITATION)->status);
        $this->assertSame(1, UserProfile::count());
        $this->assertSame($role === 3 ? 1 : 0, AssociationAccount::count());
        if ($role === 3) {
            $link = AssociationAccount::sole();
            $this->assertSame(self::USER, $link->user_id);
            $this->assertSame(self::ASSOCIATION, $link->association_id);
        }
        $this->complete();
        $this->verify()->assertRedirect(route('invitation.restart'));
        $this->assertSame(1, $this->passwordWrites);
        $this->assertSame(1, $this->verifications);
        $outcomes = DB::table('audit_logs')->pluck('new_values')->map(fn ($value) => json_decode($value, true)['outcome']);
        foreach (['requested', 'provider_accepted', 'verification_completed', 'activation_completed'] as $outcome) {
            $this->assertSame(1, $outcomes->filter(fn ($value) => $value === $outcome)->count());
        }
    }

    public static function roles(): array
    {
        return [[2], [3]];
    }

    #[DataProvider('ineligible')]
    public function test_ineligible_requests_never_contact_provider(string $state): void
    {
        $this->associationRole();
        match ($state) {
            'revoked' => DB::table('user_invitations')->update(['status' => 'cancelled']),
            'accepted' => DB::table('user_invitations')->update(['status' => 'accepted', 'accepted_at' => now()]),
            'active' => DB::table('user_profiles')->update(['is_active' => true]),
            'previously_activated' => DB::table('user_profiles')->update(['activated_at' => now()]),
            'disabled_role' => DB::table('roles')->where('id', 3)->update(['is_active' => false]),
            'inactive_association' => DB::table('associations')->update(['status' => 'inactive']),
            'archived_association' => DB::table('associations')->update(['archived_at' => now()]),
            'unbound' => DB::table('user_invitations')->update(['auth_user_id' => null]),
            'password_started' => DB::table('user_invitations')->update(['last_error_code' => 'activation_password_started:synthetic']),
            'provider_uncertain' => DB::table('user_invitations')->update(['last_error_code' => 'provider_outcome_unknown']),
            'revocation_history' => DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'action_type' => UserAccessRevocationService::ACTION,
                'module' => 'User Management', 'entity_type' => 'user_profile', 'entity_id' => self::USER, 'performed_at' => now()]),
            'link_conflict' => DB::table('association_accounts')->insert(['id' => (string) Str::uuid(), 'user_id' => self::OTHER,
                'association_id' => self::ASSOCIATION, 'is_active' => true, 'linked_at' => now()]),
        };
        $before = UserInvitation::findOrFail(self::INVITATION)->getAttributes();
        $this->requestLink()->assertRedirect(route('invitation.restart'))->assertSessionHas('invitation_restart_requested', true);
        $this->assertSame($before, UserInvitation::findOrFail(self::INVITATION)->getAttributes());
        Http::assertNothingSent();
    }

    public static function ineligible(): array
    {
        return array_map(fn ($state) => [$state], ['revoked', 'accepted', 'active', 'previously_activated', 'disabled_role',
            'inactive_association', 'archived_association', 'unbound', 'password_started', 'provider_uncertain', 'revocation_history', 'link_conflict']);
    }

    #[DataProvider('identityFailures')]
    public function test_provider_preflight_requires_original_confirmed_identity(string $state): void
    {
        if ($state === 'uuid') $this->providerId = self::OTHER;
        if ($state === 'email') $this->providerEmail = 'other@example.test';
        if ($state === 'unconfirmed') $this->confirmed = false;
        $this->requestLink()->assertSessionHas('invitation_restart_requested', true);
        $this->assertNull($this->reference);
        Http::assertSentCount(1);
    }

    public static function identityFailures(): array
    {
        return [['uuid'], ['email'], ['unconfirmed']];
    }

    #[DataProvider('identityFailures')]
    public function test_verified_identity_mismatch_cannot_create_context(string $state): void
    {
        $this->requestLink();
        if ($state === 'uuid') $this->providerId = self::OTHER;
        if ($state === 'email') $this->providerEmail = 'other@example.test';
        if ($state === 'unconfirmed') $this->confirmed = false;
        $this->verify()->assertRedirect(route('invitation.restart'))->assertSessionMissing(InvitationActivationContext::SESSION_KEY);
        $this->assertSame(0, $this->passwordWrites);
    }

    public function test_independent_provider_identity_must_match_the_verification_response(): void
    {
        $this->requestLink(); $this->independentId = self::OTHER;
        $this->verify()->assertRedirect(route('invitation.restart'))->assertSessionMissing(InvitationActivationContext::SESSION_KEY);
        $this->assertSame(1, $this->verifications);
        $this->assertSame(0, $this->passwordWrites);
    }

    public function test_provider_unavailable_response_never_grants_context(): void
    {
        $this->requestLink(); $this->verificationStatus = 503;
        $this->verify()->assertRedirect(route('invitation.restart'))->assertSessionMissing(InvitationActivationContext::SESSION_KEY);
        $this->assertSame('consumed', $this->record()['phase']);
        $this->assertSame(0, $this->passwordWrites);
        $this->get('/invitation/restart')->assertDontSee('synthetic_private_detail');
    }

    public function test_get_is_passive_and_public_feedback_does_not_disclose_account_state(): void
    {
        $this->requestLink();
        $known = $this->remember($this->get('/invitation/restart'))->assertOk()->getContent();
        $this->remember($this->get('/invitation/restart/'.$this->reference))->assertOk()
            ->assertSee('Verify activation recovery')->assertDontSee(self::USER)->assertDontSee(self::INVITATION)
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertSame('issued', $this->record()['phase']);
        $this->assertSame(0, $this->verifications);
        $this->remember($this->post('/invitation/restart', ['email' => 'unknown@example.test']))->assertSessionHas('invitation_restart_requested', true);
        $unknown = $this->remember($this->get('/invitation/restart'))->assertOk()->getContent();
        $this->assertStringContainsString('If this invitation is eligible', $known);
        $this->assertStringContainsString('If this invitation is eligible', $unknown);
        foreach ([$known, $unknown] as $html) {
            $this->assertStringNotContainsString('recipient@example.test', $html);
            $this->assertStringNotContainsString(self::USER, $html);
        }
    }

    public function test_expired_reference_cannot_consume_provider_token(): void
    {
        $this->requestLink();
        $record = $this->record(); $record['expires_at'] = time() - 1;
        Cache::store('array')->put('invitation-restart:'.$this->reference, Crypt::encryptString(json_encode($record)), 60);
        $this->verify()->assertRedirect(route('invitation.restart'));
        $this->assertSame(0, $this->verifications);
    }

    public function test_reference_is_consumed_before_provider_contact_and_cannot_be_replayed(): void
    {
        $this->requestLink();
        $this->duringVerification = function () { $this->assertSame('consumed', $this->record()['phase']); };
        $this->verify()->assertRedirect(route('invitation.password'));
        $this->verify()->assertRedirect(route('invitation.restart'));
        $this->assertSame(1, $this->verifications);
    }

    public function test_competing_verification_lock_prevents_provider_contact(): void
    {
        $this->requestLink();
        $lock = Cache::store('array')->lock('invitation-restart-verify:'.$this->reference, 120);
        $this->assertTrue($lock->get());
        try { $this->verify()->assertRedirect(route('invitation.restart')); }
        finally { $lock->release(); }
        $this->assertSame(0, $this->verifications);
        $this->assertSame('issued', $this->record()['phase']);
    }

    public function test_multiple_verified_contexts_share_one_password_claim(): void
    {
        $this->requestLink();
        $firstReference = $this->reference;
        app(InvitationRestartService::class)->requestLink('recipient@example.test');
        $secondReference = $this->reference;
        $this->assertNotSame($firstReference, $secondReference);
        $this->verify($firstReference);
        $firstContext = session()->get(InvitationActivationContext::SESSION_KEY);
        $firstScope = json_decode(Crypt::decryptString(Cache::store('array')->get('invitation-activation:'.$firstContext)), true);
        $this->verify($secondReference);
        $secondContext = session()->get(InvitationActivationContext::SESSION_KEY);
        $this->assertNotSame($firstContext, $secondContext);
        $this->complete();
        try {
            app(InvitationActivationService::class)->claimPassword($firstScope, 'synthetic_competing_claim');
            $this->fail('The other verified context must not claim a completed invitation.');
        } catch (\App\Exceptions\InvitationActivationException $exception) {
            $this->assertSame('invitation_not_eligible', $exception->getMessage());
        }
        $this->withSession([InvitationActivationContext::SESSION_KEY => $firstContext]);
        $this->complete();
        $this->assertSame(1, $this->passwordWrites);
    }

    public function test_state_changes_during_verification_are_rechecked(): void
    {
        $this->requestLink();
        $this->duringVerification = fn () => DB::table('user_invitations')->update(['status' => 'cancelled']);
        $this->verify()->assertSessionMissing(InvitationActivationContext::SESSION_KEY);
        $this->assertFalse(UserProfile::findOrFail(self::USER)->is_active);
        $this->assertSame(0, $this->passwordWrites);
    }

    public function test_saved_role_and_association_binding_cannot_be_replaced(): void
    {
        $this->requestLink();
        $this->associationRole();
        $this->verify()->assertRedirect(route('invitation.restart'));
        $this->assertSame(0, $this->verifications);
    }

    public function test_confirmed_local_failure_rolls_back_and_retries_without_another_password_write(): void
    {
        $this->associationRole(); $this->requestLink(); $this->verify();
        $fail = true;
        UserInvitation::saving(function (UserInvitation $row) use (&$fail) {
            if ($row->status === 'accepted' && $fail) { $fail = false; throw new RuntimeException('Synthetic transaction failure'); }
        });
        $this->complete()->assertRedirect(route('invitation.recovery'));
        $this->assertSame(1, $this->passwordWrites);
        $this->assertFalse(UserProfile::findOrFail(self::USER)->is_active);
        $this->assertSame(0, AssociationAccount::count());
        $this->remember($this->post('/invitation/recover'))->assertRedirect(route('invitation.result'));
        $this->assertTrue(UserProfile::findOrFail(self::USER)->is_active);
        $this->assertSame(1, AssociationAccount::count());
        $this->assertSame(1, $this->passwordWrites);
    }

    public function test_activation_audit_failure_rolls_back_the_transition_and_can_recover(): void
    {
        $this->associationRole(); $this->requestLink(); $this->verify();
        DB::statement("CREATE TRIGGER reject_recovery_audit BEFORE INSERT ON audit_logs WHEN NEW.new_values LIKE '%activation_completed%' BEGIN SELECT RAISE(ABORT, 'Synthetic audit failure'); END");
        $this->complete()->assertRedirect(route('invitation.recovery'));
        $this->assertFalse(UserProfile::findOrFail(self::USER)->is_active);
        $this->assertSame('sent', UserInvitation::findOrFail(self::INVITATION)->status);
        $this->assertSame(0, AssociationAccount::count());
        DB::statement('DROP TRIGGER reject_recovery_audit');
        $this->remember($this->post('/invitation/recover'))->assertSessionHas('invitation_activation_succeeded', true);
        $this->assertSame(1, $this->passwordWrites);
        $this->assertSame(1, AssociationAccount::count());
    }

    public function test_mail_request_and_identity_lookup_outages_do_not_change_invitation_state(): void
    {
        $this->timeout = 'GET /auth/v1/admin/users/'.self::USER;
        $this->requestLink()->assertSessionHas('invitation_restart_requested', true);
        $this->assertNull($this->reference);
        $this->timeout = 'POST /auth/v1/recover';
        app(InvitationRestartService::class)->requestLink('recipient@example.test');
        $this->assertSame('sent', UserInvitation::sole()->status);
        $this->assertNull(UserInvitation::sole()->last_error_code);
        $this->assertFalse(UserProfile::sole()->is_active);
        $this->assertTrue(DB::table('audit_logs')->where('new_values', 'like', '%provider_uncertain%')->exists());
    }

    public function test_password_claim_after_mail_issue_blocks_callback_without_consuming_provider_proof(): void
    {
        $this->requestLink();
        DB::table('user_invitations')->update(['last_error_code' => 'activation_password_started:synthetic_other_context']);
        $this->verify()->assertRedirect(route('invitation.restart'));
        $this->assertSame(0, $this->verifications);
        $this->assertSame('activation_password_started:synthetic_other_context', UserInvitation::sole()->last_error_code);
    }

    public function test_revocation_after_external_password_update_cannot_be_undone_by_activation(): void
    {
        $this->requestLink(); $this->verify();
        $this->afterPassword = fn () => DB::table('user_invitations')->update(['status' => 'cancelled']);
        $this->complete();
        $this->assertSame('cancelled', UserInvitation::sole()->status);
        $this->assertFalse(UserProfile::sole()->is_active);
        $this->assertSame(1, $this->passwordWrites);
        $this->assertSame(0, AssociationAccount::count());
    }

    public function test_client_binding_and_redirect_values_are_rejected_without_flashing_inputs(): void
    {
        $this->post('/invitation/restart', ['email' => 'recipient@example.test', 'auth_user_id' => self::OTHER,
            'redirect_to' => 'https://other.example.test'])->assertSessionHasErrors('restart')->assertSessionMissing('_old_input');
        Http::assertNothingSent();
    }

    public function test_uncertain_password_update_remains_held_and_cannot_restart(): void
    {
        $this->requestLink(); $this->verify();
        $this->timeout = 'PUT /auth/v1/user';
        $this->complete();
        $marker = UserInvitation::findOrFail(self::INVITATION)->last_error_code;
        $this->assertStringStartsWith('activation_password_started:', $marker);
        app(InvitationRestartService::class)->requestLink('recipient@example.test');
        $this->assertSame($marker, UserInvitation::findOrFail(self::INVITATION)->last_error_code);
        $this->assertFalse(UserProfile::findOrFail(self::USER)->is_active);
        $this->assertSame(1, $this->verifications);
    }

    public function test_timeout_consumes_verification_reference_without_password_write_or_sensitive_feedback(): void
    {
        $this->requestLink(); $this->timeout = 'POST /auth/v1/verify';
        $this->verify()->assertRedirect(route('invitation.restart'))->assertSessionMissing('_old_input');
        $this->assertSame('consumed', $this->record()['phase']);
        $this->timeout = null;
        $this->verify();
        $this->assertSame(0, $this->verifications);
        $this->assertSame(0, $this->passwordWrites);
        $events = json_encode(DB::table('audit_logs')->get());
        foreach (['token_hash', 'synthetic_recipient_access', 'synthetic_unused_refresh', 'Synthetic private provider detail', 'recipient@example.test'] as $secret) {
            $this->assertStringNotContainsString($secret, $events);
        }
    }

    public function test_cross_browser_callback_can_verify_but_created_context_cannot_move_sessions(): void
    {
        $this->requestLink();
        $this->app['session']->driver()->invalidate();
        $this->defaultCookies = [];
        $this->verify()->assertRedirect(route('invitation.password'));
        $contextId = session()->get(InvitationActivationContext::SESSION_KEY);
        $otherBrowser = new Store('synthetic_other_browser', new ArraySessionHandler(120));
        $otherBrowser->start();
        $otherBrowser->put(InvitationActivationContext::SESSION_KEY, $contextId);
        $request = Request::create('/invitation/password');
        $request->setLaravelSession($otherBrowser);
        $this->assertNull(app(InvitationActivationContext::class)->read($request));
        $this->assertSame(0, $this->passwordWrites);
    }

    public function test_existing_login_requires_consent_and_failure_preserves_it(): void
    {
        $this->requestLink();
        $this->withSession(['supabase_access_token' => 'synthetic_existing_login']);
        $this->verify()->assertSessionHas('supabase_access_token', 'synthetic_existing_login');
        $this->assertSame(0, $this->verifications);
        $this->verify(null, ['sign_out_current_account' => '1'])->assertRedirect(route('invitation.password'))
            ->assertSessionMissing('supabase_access_token');
    }

    public function test_csrf_rejection_and_request_throttling_precede_provider_contact(): void
    {
        $this->app->bind(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, function ($app) {
            return new class($app, $app['encrypter']) extends \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken {
                protected function runningUnitTests() { return false; }
            };
        });
        $this->post('/invitation/restart', ['email' => 'recipient@example.test'])->assertStatus(419);
        Http::assertNothingSent();
    }

    public function test_request_cooldown_blocks_duplicate_mail(): void
    {
        $this->requestLink();
        $this->requestLink()->assertStatus(429);
        Http::assertSentCount(2);
    }

    public function test_switching_to_forgot_password_does_not_bypass_email_cooldown(): void
    {
        $this->requestLink();
        $this->post('/forgot-password', ['email' => 'recipient@example.test'])->assertStatus(429);
        Http::assertSentCount(2);
    }

    public function test_feature_is_off_until_shared_mail_prerequisites_are_reviewed(): void
    {
        config(['services.supabase.invitation_restart_enabled' => false]);
        $this->get('/invitation/restart')->assertOk()->assertSee('not currently available')->assertDontSee('name="email"', false);
        $this->requestLink()->assertSessionHas('invitation_restart_requested', true);
        Http::assertNothingSent();
    }

    public function test_disabling_restart_after_issue_blocks_verification_without_provider_contact(): void
    {
        $this->requestLink()->assertRedirect(route('invitation.restart'));
        $this->assertNotNull($this->reference);
        $this->assertSame('issued', $this->record()['phase']);
        Http::assertSentCount(2);

        config(['services.supabase.invitation_restart_enabled' => false]);
        $this->verify()->assertRedirect(route('invitation.restart'))->assertSessionHasErrors('restart')
            ->assertSessionMissing(InvitationActivationContext::SESSION_KEY)
            ->assertSessionMissing('supabase_access_token')->assertSessionMissing('supabase_refresh_token')
            ->assertSessionMissing('user_profile_id');

        Http::assertSentCount(2);
        $this->assertSame(0, $this->verifications);
        $this->assertSame(0, $this->passwordWrites);
        $this->assertSame('issued', $this->record()['phase']);
        $this->assertSame([], array_filter(array_keys(Cache::store('array')->getStore()->all()),
            fn ($key) => str_starts_with($key, 'invitation-activation:')));
        $this->assertFalse(UserProfile::sole()->is_active);
        $this->assertNull(UserProfile::sole()->activated_at);
        $this->assertSame('sent', UserInvitation::sole()->status);
    }

    public function test_required_request_audit_failure_prevents_mail_and_restart_capabilities(): void
    {
        DB::statement("CREATE TRIGGER reject_recovery_request_audit BEFORE INSERT ON audit_logs WHEN NEW.new_values LIKE '%requested%' BEGIN SELECT RAISE(ABORT, 'Synthetic request audit failure'); END");
        $before = UserInvitation::sole()->getAttributes();

        $this->requestLink()->assertRedirect(route('invitation.restart'))
            ->assertSessionHas('invitation_restart_requested', true)->assertSessionMissing('_old_input')
            ->assertSessionMissing(InvitationActivationContext::SESSION_KEY)
            ->assertSessionMissing('supabase_access_token');

        Http::assertNothingSent();
        $this->assertNull($this->reference);
        $this->assertSame([], array_filter(array_keys(Cache::store('array')->getStore()->all()),
            fn ($key) => str_starts_with($key, 'invitation-restart:') || str_starts_with($key, 'invitation-activation:')));
        $this->assertSame(0, $this->verifications);
        $this->assertSame(0, $this->passwordWrites);
        $this->assertSame($before, UserInvitation::sole()->getAttributes());
        $this->assertFalse(UserProfile::sole()->is_active);
        $this->assertNull(UserProfile::sole()->activated_at);
        $this->assertSame(0, AssociationAccount::count());
        $this->assertSame('denied', json_decode(DB::table('audit_logs')->sole()->new_values, true)['outcome']);
    }

    public function test_required_verification_audit_failure_cannot_grant_context_or_application_access(): void
    {
        $this->associationRole();
        $this->requestLink();
        $this->assertSame('issued', $this->record()['phase']);
        Http::assertSentCount(2);
        DB::statement("CREATE TRIGGER reject_recovery_verification_audit BEFORE INSERT ON audit_logs WHEN NEW.new_values LIKE '%verification_completed%' BEGIN SELECT RAISE(ABORT, 'Synthetic verification audit failure'); END");

        $this->verify()->assertRedirect(route('invitation.restart'))->assertSessionHasErrors('restart')
            ->assertSessionMissing(InvitationActivationContext::SESSION_KEY)
            ->assertSessionMissing('supabase_access_token')->assertSessionMissing('supabase_refresh_token')
            ->assertSessionMissing('user_profile_id');

        $this->assertSame(1, $this->verifications);
        Http::assertSent(fn (ProviderRequest $request) => $request->method() === 'GET'
            && $request->url() === 'https://auth.example.test/auth/v1/user');
        Http::assertSentCount(4);
        $this->assertSame('consumed', $this->record()['phase']);
        $this->assertSame([], array_filter(array_keys(Cache::store('array')->getStore()->all()),
            fn ($key) => str_starts_with($key, 'invitation-activation:')));
        $this->assertFalse(DB::table('audit_logs')->where('new_values', 'like', '%verification_completed%')->exists());
        $this->assertTrue(DB::table('audit_logs')->where('new_values', 'like', '%denied%')->exists());

        DB::statement('DROP TRIGGER reject_recovery_verification_audit');
        $this->verify()->assertRedirect(route('invitation.restart'))->assertSessionMissing(InvitationActivationContext::SESSION_KEY);
        $this->complete()->assertRedirect(route('invitation.result'))->assertSessionMissing('invitation_activation_succeeded');
        $this->get('/association/dashboard')->assertRedirect(route('login'));
        Http::assertSentCount(4);
        $this->assertSame(1, $this->verifications);
        $this->assertSame(0, $this->passwordWrites);
        $profile = UserProfile::sole();
        $this->assertFalse($profile->is_active);
        $this->assertNull($profile->activated_at);
        $this->assertFalse($profile->canAccessApplication(['id' => self::USER, 'email' => 'recipient@example.test']));
        $this->assertSame('sent', UserInvitation::sole()->status);
        $this->assertNull(UserInvitation::sole()->last_error_code);
        $this->assertSame(0, AssociationAccount::count());
    }

    public function test_trusted_audit_only_accepts_fixed_events(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(InvitationRestartAudit::class)->record(UserInvitation::sole(), 'client-supplied-outcome');
    }
}
