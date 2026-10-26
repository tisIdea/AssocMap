<?php

namespace Tests\Feature;

use App\Models\UserProfile;
use App\Services\Auth\AccountEmailChangeService;
use App\Services\Auth\AccountReactivationService;
use App\Services\Auth\InvitationActivationContext;
use App\Services\Auth\SupabaseSessionBoundary;
use App\Services\Auth\UserAccessRevocationService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesEmailChangeFixture;
use Tests\TestCase;

class AccountEmailChangeTest extends TestCase
{
    use CreatesEmailChangeFixture;

    private const ADMIN = '11111111-1111-4111-8111-111111111111';

    private const USER = '22222222-2222-4222-8222-222222222222';

    private const OTHER = '33333333-3333-4333-8333-333333333333';

    private const ASSOCIATION = '44444444-4444-4444-8444-444444444444';

    private const SESSION = '55555555-5555-4555-8555-555555555555';

    private const DESTINATION = 'verified-new@example.test';

    private const PASSWORD = 'Synthetic-New-Password42!';

    private string $reference = '';

    private string $timeout = '';

    private bool $wrongIdentity = false;

    private bool $singleConfirmation = false;

    private int $mailboxProofs = 0;

    private int $passwordWrites = 0;

    private int $emailWrites = 0;

    private int $recoveryEmails = 0;

    private bool $revokeDuringPassword = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('e', 32)), 'app.debug' => false,
            'app.url' => 'https://app.example.test', 'services.supabase.url' => 'https://auth.example.test',
            'services.supabase.publishable_key' => 'sb_publishable_synthetic',
            'services.supabase.account_email_change_enabled' => true, 'services.supabase.activation_cache_store' => 'array']);
        $this->assertSame('sqlite', DB::getDriverName());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        $storage = Mockery::mock(InvitationActivationContext::class);
        $storage->shouldReceive('storage')->andReturn(Cache::store('array'));
        $storage->shouldReceive('checkStorage')->andReturnNull();
        $this->app->instance(InvitationActivationContext::class, $storage);
        $this->createEmailChangeSchema();
        foreach ([1 => 'system_admin', 2 => 'field_officer', 3 => 'association_account'] as $id => $code) {
            DB::table('roles')->insert(['id' => $id, 'code' => $code, 'name' => $code, 'is_active' => true]);
        }
        foreach ([self::ADMIN => 1, self::USER => 2, self::OTHER => 2] as $id => $role) {
            DB::table('user_profiles')->insert(['id' => $id, 'role_id' => $role, 'display_name' => 'Synthetic holder',
                'is_active' => true, 'activated_at' => now()->subDays(2), 'created_at' => now()->subDays(3)]);
            DB::table('auth.users')->insert(['id' => $id, 'email' => $id.'@example.test']);
        }
        DB::table('user_invitations')->insert(['id' => self::USER, 'auth_user_id' => self::USER, 'role_id' => 2,
            'email' => self::USER.'@example.test', 'status' => 'accepted', 'accepted_at' => now()->subDays(2), 'created_at' => now()->subDays(3)]);
        DB::table('associations')->insert(['id' => self::ASSOCIATION, 'name' => 'Synthetic association', 'status' => 'active', 'primary_field_officer_id' => self::USER]);
        DB::table('auth.sessions')->insert(['id' => self::SESSION, 'user_id' => self::USER, 'created_at' => now()->subDays(2)]);
        DB::unprepared("CREATE TRIGGER email_change_audit_failure BEFORE INSERT ON audit_logs WHEN NEW.action_type = 'FAIL SYNTHETIC' BEGIN SELECT RAISE(ABORT, 'Synthetic audit unavailable'); END");
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(fn (ClientRequest $request) => $this->provider($request));
        Route::middleware(['web', 'supabase.auth'])->get('/_tests/email-change/access', fn () => response('Authorized'));
        Route::middleware(['api', 'supabase.api.auth', 'mobile.association'])->get('/api/_tests/email-change/access', fn () => response()->json(['authorized' => true]));
        $this->withSession(['supabase_access_token' => $this->token(self::ADMIN)]);
    }

    private function token(string $user, bool $fresh = false): string
    {
        $encode = fn ($value) => rtrim(strtr(base64_encode(json_encode($value)), '+/', '-_'), '=');

        return $encode(['alg' => 'HS256']).'.'.$encode(['sub' => $user, 'iss' => 'https://auth.example.test/auth/v1',
            'role' => 'authenticated', 'aud' => 'authenticated', 'exp' => now()->addHour()->timestamp,
            'session_id' => self::SESSION, 'amr' => [['method' => 'password', 'timestamp' => $fresh ? now()->timestamp : now()->subDays(2)->timestamp]]]).'.synthetic_signature';
    }

    private function provider(ClientRequest $request)
    {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/auth/v1/recover') {
            $this->recoveryEmails++;
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            $this->assertMatchesRegularExpression('~\Ahttps://app\.example\.test/account-email-change/([A-Za-z0-9]{64})/old\z~', $query['redirect_to']);
            $this->reference = explode('/', $query['redirect_to'])[4];
            $this->assertSame(self::USER.'@example.test', $request['email']);
            if ($this->timeout === 'request') {
                throw new ConnectionException('Synthetic private timeout');
            }

            return Http::response('{}', 200, ['Content-Type' => 'application/json']);
        }
        if ($path === '/auth/v1/verify') {
            if ($this->timeout === 'verify') {
                throw new ConnectionException('Synthetic private timeout');
            }
            if ($request['type'] === 'email_change') {
                $this->mailboxProofs++;
                if ($this->mailboxProofs === 1 && ! $this->singleConfirmation) {
                    return Http::response(['msg' => 'Confirmation link accepted. Please proceed to confirm link sent to the other email']);
                }
                DB::table('auth.users')->where('id', self::USER)->update(['email' => self::DESTINATION, 'email_change' => null]);
            } else {
                $this->assertSame('recovery', $request['type']);
            }
            $id = $this->wrongIdentity ? self::OTHER : self::USER;

            return Http::response(['access_token' => $this->token($id), 'refresh_token' => 'synthetic_unused',
                'token_type' => 'bearer', 'expires_in' => 3600, 'user' => $this->identity($id)]);
        }
        if ($path === '/auth/v1/user' && $request->method() === 'PUT' && isset($request['email'])) {
            $this->emailWrites++;
            $this->assertSame(self::DESTINATION, $request['email']);
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            $this->assertSame('https://app.example.test/account-email-change/'.$this->reference.'/confirm', $query['redirect_to']);
            DB::table('auth.users')->where('id', self::USER)->update(['email_change' => $request['email']]);
            if ($this->timeout === 'email') {
                throw new ConnectionException('Synthetic private timeout');
            }

            return Http::response($this->identity(self::USER));
        }
        if ($path === '/auth/v1/user' && $request->method() === 'PUT') {
            $this->passwordWrites++;
            $this->assertSame(self::PASSWORD, $request['password']);
            if ($this->timeout === 'password') {
                throw new ConnectionException('Synthetic private timeout');
            }
            if ($this->revokeDuringPassword) {
                $actor = new Request;
                $actor->attributes->set('current_user', UserProfile::with('role')->findOrFail(self::ADMIN));
                app(UserAccessRevocationService::class)->revoke($actor, UserProfile::findOrFail(self::USER));
            }

            return Http::response($this->identity(self::USER));
        }
        if ($path === '/auth/v1/logout') {
            $this->assertStringEndsWith('?scope=global', $request->url());
            if ($this->timeout === 'logout') {
                throw new ConnectionException('Synthetic private timeout');
            }
            DB::table('auth.sessions')->where('user_id', self::USER)->delete();

            return Http::response('', 204);
        }
        if ($path === '/auth/v1/user' && $request->method() === 'GET') {
            $parts = explode('.', substr($request->header('Authorization')[0], 7));
            $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

            return Http::response($this->identity($claims['sub']));
        }
        $this->fail('Unexpected external request in isolated email-change test.');
    }

    private function identity(string $id): array
    {
        return ['id' => $id, 'email' => DB::table('auth.users')->where('id', $id)->value('email'),
            'email_confirmed_at' => now()->subDay()->toIso8601String(), 'is_anonymous' => false];
    }

    private function postForm(string $url, array $data = []): TestResponse
    {
        $response = $this->post($url, $data);
        if ($cookie = $response->getCookie(config('session.cookie'))) {
            $this->withCookie($cookie->getName(), $cookie->getValue());
        }

        return $response;
    }

    private function adminUrl(): string
    {
        return '/admin/users/'.self::USER.'/email-change';
    }

    private function url(string $action): string
    {
        return '/account-email-change/'.$this->reference.'/'.$action;
    }

    private function row(): object
    {
        return DB::table('email_change_requests')->sole();
    }

    private function issue(): void
    {
        $review = $this->postForm($this->adminUrl().'/review', ['new_email' => ' VERIFIED-NEW@example.test ', 'same_holder' => '1'])
            ->assertOk()->viewData('review');
        $this->postForm($this->adminUrl(), ['new_email' => self::DESTINATION, 'same_holder' => '1', 'confirm_change' => '1', 'review_hash' => $review['review_hash']])
            ->assertRedirect();
        $this->assertSame('requested', $this->row()->phase);
        $this->assertSame(1, $this->recoveryEmails);
        $this->withSession(['supabase_access_token' => null, 'supabase_refresh_token' => null]);
        $this->app['session']->save();
    }

    private function verifyOld(): void
    {
        $this->postForm($this->url('old'), ['token_hash' => str_repeat('a', 56)])->assertRedirect($this->url('progress'));
        $this->assertSame('old_verified', $this->row()->phase);
    }

    private function verified(): void
    {
        $this->issue();
        $this->verifyOld();
        $this->postForm($this->url('begin'), ['confirm_change' => '1'])->assertRedirect($this->url('progress'));
        $this->assertSame('awaiting_confirmations', $this->row()->phase);
        $this->postForm($this->url('confirm'), ['token_hash' => str_repeat('b', 56)])->assertRedirect($this->url('progress'));
        $this->assertSame('awaiting_confirmations', $this->row()->phase);
        $this->assertNotNull($this->row()->first_confirmation_at);
        $this->postForm($this->url('confirm'), ['token_hash' => str_repeat('c', 56)])->assertRedirect($this->url('progress'));
        $this->assertSame('verified', $this->row()->phase);
    }

    private function password(): array
    {
        return ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD];
    }

    public function test_complete_change_preserves_identity_history_role_and_assignments_and_requires_new_login(): void
    {
        $before = DB::table('user_invitations')->get()->toJson();
        $assignments = DB::table('associations')->get()->toJson();
        $this->verified();
        $this->assertFalse(UserProfile::with('role')->findOrFail(self::USER)->canAccessApplication($this->identity(self::USER)));
        $this->postForm($this->url('password'), $this->password())->assertRedirect('/account-email-change/result')->assertSessionHas('email_change_completed', true);
        $profile = UserProfile::with('role')->findOrFail(self::USER);
        $this->assertSame(self::USER, $profile->id);
        $this->assertSame(2, $profile->role_id);
        $this->assertTrue($profile->is_active);
        $this->assertSame(self::DESTINATION, $profile->approved_email);
        $this->assertNull($profile->email_change_hold_id);
        $this->assertNotNull($profile->auth_sessions_valid_after);
        $this->assertSame('confirmed', $this->row()->status);
        $this->assertSame('completed', $this->row()->phase);
        $this->assertNotNull($this->row()->password_confirmed_at);
        $this->assertNotNull($this->row()->signout_confirmed_at);
        $this->assertSame($before, DB::table('user_invitations')->get()->toJson());
        $this->assertSame($assignments, DB::table('associations')->get()->toJson());
        $this->assertSame(1, $this->passwordWrites);
        $this->assertSame(1, $this->emailWrites);
        $this->assertTrue($profile->canAccessApplication($this->identity(self::USER)));
        $this->assertFalse($profile->canAccessApplication(['id' => self::USER, 'email' => 'unapproved@example.test']));
        config(['services.supabase.account_email_change_enabled' => false]);
        $this->assertFalse(app(SupabaseSessionBoundary::class)->allows($profile, $this->identity(self::USER), $this->token(self::USER)));
        $audit = DB::table('audit_logs')->get()->toJson();
        foreach ([self::PASSWORD, self::DESTINATION, $this->reference, $this->token(self::USER)] as $secret) {
            $this->assertStringNotContainsString($secret, $audit);
        }
        $this->assertSame(1, DB::table('audit_logs')->where('action_type', 'COMPLETE VERIFIED EMAIL CHANGE')->count());
        $this->assertFalse($this->app['session']->has('supabase_access_token'));
        $this->assertFalse($this->app['session']->has('email_change_context'));
        // No mock can prove the provider rejects a real old password or refresh token.
    }

    public function test_association_account_keeps_its_existing_link_without_revocation_or_unlink(): void
    {
        $this->associationAccount();
        $before = DB::table('association_accounts')->get()->toJson();
        $this->verified();
        $this->postForm($this->url('password'), $this->password())->assertSessionHas('email_change_completed', true);
        $this->assertSame($before, DB::table('association_accounts')->get()->toJson());
        $this->assertSame(3, UserProfile::findOrFail(self::USER)->role_id);
        $this->withHeader('Authorization', 'Bearer '.$this->token(self::USER))->getJson('/api/_tests/email-change/access')->assertForbidden();
        $this->travel(2)->seconds();
        DB::table('auth.sessions')->insert(['id' => self::SESSION, 'user_id' => self::USER, 'created_at' => now()]);
        $fresh = $this->token(self::USER, true);
        $this->withHeader('Authorization', 'Bearer '.$fresh)->getJson('/api/_tests/email-change/access')->assertOk();
        $this->withSession(['supabase_access_token' => $fresh]);
        $this->app['session']->save();
        $this->get('/_tests/email-change/access')->assertOk();
    }

    private function associationAccount(): void
    {
        DB::table('user_profiles')->where('id', self::USER)->update(['role_id' => 3]);
        DB::table('user_invitations')->update(['role_id' => 3]);
        DB::table('associations')->update(['primary_field_officer_id' => self::OTHER]);
        DB::table('association_accounts')->insert(['id' => self::USER, 'user_id' => self::USER, 'association_id' => self::ASSOCIATION, 'is_active' => true]);
    }

    #[DataProvider('ineligible')]
    public function test_invalid_targets_or_destinations_are_rejected_without_provider_writes(string $case): void
    {
        $email = self::DESTINATION;
        if (str_starts_with($case, 'association_')) {
            $this->associationAccount();
        }
        match ($case) {
            'inactive' => DB::table('user_profiles')->where('id', self::USER)->update(['is_active' => false]),
            'unactivated' => DB::table('user_profiles')->where('id', self::USER)->update(['activated_at' => null]),
            'role_disabled' => DB::table('roles')->where('id', 2)->update(['is_active' => false]),
            'invitation_pending' => DB::table('user_invitations')->update(['status' => 'sent', 'accepted_at' => null]),
            'provider_duplicate' => $email = self::OTHER.'@example.test',
            'pending_provider_duplicate' => DB::table('auth.users')->where('id', self::OTHER)->update(['email_change' => $email]),
            'invalid' => $email = 'bad-email',
            'same' => $email = self::USER.'@example.test',
            'historical_role_conflict' => DB::table('association_accounts')->insert(['id' => self::USER, 'user_id' => self::USER, 'association_id' => self::ASSOCIATION, 'is_active' => false]),
            'association_inactive' => DB::table('associations')->update(['status' => 'inactive']),
            'association_archived' => DB::table('associations')->update(['archived_at' => now()]),
        };
        $this->postJson($this->adminUrl().'/review', ['new_email' => $email, 'same_holder' => '1'])->assertUnprocessable();
        $this->assertSame(0, DB::table('email_change_requests')->count());
        Http::assertNotSent(fn (ClientRequest $request) => $request->method() !== 'GET');
    }

    public static function ineligible(): array
    {
        return array_map(fn ($case) => [$case], ['inactive', 'unactivated', 'role_disabled', 'invitation_pending', 'provider_duplicate',
            'pending_provider_duplicate', 'invalid', 'same', 'historical_role_conflict', 'association_inactive', 'association_archived']);
    }

    public function test_nonadministrator_and_self_service_are_denied(): void
    {
        $this->withSession(['supabase_access_token' => $this->token(self::USER)]);
        $this->postJson($this->adminUrl().'/review', ['new_email' => self::DESTINATION, 'same_holder' => '1'])->assertForbidden();
        $this->withSession(['supabase_access_token' => $this->token(self::ADMIN)]);
        $this->postJson('/admin/users/'.self::ADMIN.'/email-change/review', ['new_email' => self::DESTINATION, 'same_holder' => '1'])->assertForbidden();
        $this->assertSame(0, DB::table('email_change_requests')->count());
    }

    public function test_pending_hold_blocks_web_and_mobile_even_when_feature_disabled(): void
    {
        $this->associationAccount();
        $this->withHeader('Authorization', 'Bearer '.$this->token(self::USER))->getJson('/api/_tests/email-change/access')->assertOk();
        $this->issue();
        config(['services.supabase.account_email_change_enabled' => false]);
        $this->withSession(['supabase_access_token' => $this->token(self::USER)]);
        $this->app['session']->save();
        $this->get('/_tests/email-change/access')->assertRedirect();
        $this->withHeader('Authorization', 'Bearer '.$this->token(self::USER))->getJson('/api/_tests/email-change/access')->assertForbidden();
        $this->assertTrue(UserProfile::findOrFail(self::USER)->is_active);
        $this->assertSame(0, DB::table('audit_logs')->where('action_type', 'REVOKE USER ACCESS')->count());
    }

    public function test_disabled_feature_rejects_issued_proof_without_contacting_provider(): void
    {
        $this->issue();
        config(['services.supabase.account_email_change_enabled' => false]);
        $this->postForm($this->url('old'), ['token_hash' => str_repeat('a', 56)])->assertNotFound();
        $this->assertSame('requested', $this->row()->phase);
        Http::assertSentCount(3); // Admin identity checks for review and request, then recovery email.
    }

    public function test_get_prefetch_does_not_consume_proof_and_query_secrets_are_scrubbed(): void
    {
        $this->issue();
        $this->get($this->url('old'))->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Frame-Options', 'DENY')->assertSee('history.replaceState', false)->assertSee('name="_token"', false);
        $this->get($this->url('old').'?token_hash=private')->assertRedirect($this->url('old'));
        $this->assertSame('requested', $this->row()->phase);
        $this->assertSame(0, DB::table('email_change_proofs')->count());
    }

    public function test_expired_request_fails_closed_and_retains_hold(): void
    {
        $this->issue();
        DB::table('email_change_requests')->update(['expires_at' => now()->subSecond()]);
        $this->postForm($this->url('old'), ['token_hash' => str_repeat('a', 56)])->assertRedirect('/account-email-change/result');
        $this->assertSame(0, DB::table('email_change_proofs')->count());
        $this->assertNotNull(UserProfile::findOrFail(self::USER)->email_change_hold_id);
    }

    public function test_wrong_uuid_recovery_proof_does_not_create_usable_context(): void
    {
        $this->issue();
        $this->wrongIdentity = true;
        $this->postForm($this->url('old'), ['token_hash' => str_repeat('a', 56)])->assertRedirect('/account-email-change/result');
        $this->assertSame('old_verifying', $this->row()->phase);
        $this->assertFalse($this->app['session']->has('email_change_context'));
        $this->assertSame(0, $this->emailWrites);
    }

    public function test_missing_browser_context_cannot_consume_mailbox_confirmation(): void
    {
        $this->issue();
        $this->verifyOld();
        $this->postForm($this->url('begin'), ['confirm_change' => '1']);
        $this->app['session']->forget('email_change_context');
        $this->app['session']->save();
        $this->postForm($this->url('confirm'), ['token_hash' => str_repeat('b', 56)])->assertRedirect('/account-email-change/result');
        $this->assertSame(0, $this->mailboxProofs);
        $this->assertSame('awaiting_confirmations', $this->row()->phase);
    }

    public function test_single_mailbox_configuration_cannot_skip_required_second_confirmation(): void
    {
        $this->issue();
        $this->verifyOld();
        $this->postForm($this->url('begin'), ['confirm_change' => '1']);
        $this->singleConfirmation = true;
        $this->postForm($this->url('confirm'), ['token_hash' => str_repeat('b', 56)])->assertRedirect('/account-email-change/result');
        $this->assertSame('confirming', $this->row()->phase);
        $this->assertSame(0, $this->passwordWrites);
        $this->assertNotNull(UserProfile::findOrFail(self::USER)->email_change_hold_id);
    }

    public function test_replayed_mailbox_proof_does_not_contact_provider_again(): void
    {
        $this->issue();
        $this->verifyOld();
        $this->postForm($this->url('begin'), ['confirm_change' => '1']);
        $this->postForm($this->url('confirm'), ['token_hash' => str_repeat('b', 56)]);
        $this->postForm($this->url('confirm'), ['token_hash' => str_repeat('b', 56)])->assertRedirect('/account-email-change/result');
        $this->assertSame(1, $this->mailboxProofs);
        $this->assertSame('awaiting_confirmations', $this->row()->phase);
    }

    #[DataProvider('timeouts')]
    public function test_uncertain_operations_are_not_retried(string $stage, string $expected): void
    {
        if ($stage === 'password' || $stage === 'logout') {
            $this->verified();
        } else {
            $this->issue();
            if ($stage === 'email') {
                $this->verifyOld();
            }
        }
        $this->timeout = $stage;
        $action = match ($stage) {
            'email' => 'begin', 'verify' => 'old', default => 'password'
        };
        $data = match ($action) {
            'begin' => ['confirm_change' => '1'], 'old' => ['token_hash' => str_repeat('a', 56)], default => $this->password()
        };
        $this->postForm($this->url($action), $data)->assertRedirect('/account-email-change/result');
        $this->assertSame($expected, $this->row()->phase);
        $count = count(Http::recorded());
        $this->postForm($this->url($action), $data)->assertRedirect('/account-email-change/result');
        $this->assertSame($count, count(Http::recorded()));
        $this->assertNotNull(UserProfile::findOrFail(self::USER)->email_change_hold_id);
        $this->assertSame('pending', $this->row()->status);
    }

    public static function timeouts(): array
    {
        return [['verify', 'old_verifying'], ['email', 'email_started'], ['password', 'password_started'], ['logout', 'signout_started']];
    }

    public function test_new_password_strength_is_enforced_before_provider_write(): void
    {
        $this->verified();
        $this->postForm($this->url('password'), ['password' => 'weak', 'password_confirmation' => 'weak'])->assertRedirect('/account-email-change/result');
        $this->assertSame('verified', $this->row()->phase);
        $this->assertSame(0, $this->passwordWrites);
    }

    public function test_revocation_during_password_write_prevents_finalization_and_retains_hold(): void
    {
        $this->verified();
        $this->revokeDuringPassword = true;
        $this->postForm($this->url('password'), $this->password())->assertRedirect('/account-email-change/result');
        $this->assertSame(1, $this->passwordWrites);
        $this->assertFalse(UserProfile::findOrFail(self::USER)->is_active);
        $this->assertNotNull(UserProfile::findOrFail(self::USER)->email_change_hold_id);
        $this->assertSame('password_started', $this->row()->phase);
    }

    public function test_completed_current_email_allows_reactivation_without_rewriting_original_invitation(): void
    {
        $this->verified();
        $this->postForm($this->url('password'), $this->password())->assertSessionHas('email_change_completed', true);
        $request = new Request;
        $request->attributes->set('current_user', UserProfile::with('role')->findOrFail(self::ADMIN));
        app(UserAccessRevocationService::class)->revoke($request, UserProfile::findOrFail(self::USER));
        config(['services.supabase.account_reactivation_enabled' => true]);
        $review = app(AccountReactivationService::class)->review(UserProfile::findOrFail(self::ADMIN), UserProfile::findOrFail(self::USER));
        $this->assertSame(self::DESTINATION, $review['email']);
        $this->assertSame(self::USER.'@example.test', DB::table('user_invitations')->value('email'));
    }

    public function test_csrf_is_required_before_verification(): void
    {
        $this->issue();
        $this->app->instance(ValidateCsrfToken::class, new class($this->app, $this->app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->postForm($this->url('old'), ['token_hash' => str_repeat('a', 56)])->assertStatus(419);
        $this->assertSame('requested', $this->row()->phase);
    }

    private function rejectAudit(string $phase): void
    {
        $this->assertMatchesRegularExpression('/\A[a-z_]+\z/', $phase);
        DB::unprepared("CREATE TRIGGER reject_email_audit BEFORE INSERT ON audit_logs WHEN json_extract(NEW.new_values, '$.phase') = '".$phase."' BEGIN SELECT RAISE(ABORT, 'Synthetic audit unavailable'); END");
    }

    public function test_required_request_audit_failure_rolls_back_hold_and_prevents_email(): void
    {
        $review = $this->postForm($this->adminUrl().'/review', ['new_email' => self::DESTINATION, 'same_holder' => '1'])->assertOk()->viewData('review');
        $this->rejectAudit('requested');
        $this->postForm($this->adminUrl(), ['new_email' => self::DESTINATION, 'same_holder' => '1', 'confirm_change' => '1', 'review_hash' => $review['review_hash']])->assertRedirect();
        $this->assertSame(0, DB::table('email_change_requests')->count());
        $this->assertNull(UserProfile::findOrFail(self::USER)->email_change_hold_id);
        $this->assertSame(0, $this->recoveryEmails);
    }

    #[DataProvider('auditPhases')]
    public function test_required_audit_failures_preserve_safe_phase_and_block_replay(string $phase, string $expected): void
    {
        if (in_array($phase, ['password_started', 'password_confirmed', 'completed'], true)) {
            $this->verified();
        } else {
            $this->issue();
        }
        $this->rejectAudit($phase);
        $action = str_starts_with($phase, 'old_') ? 'old' : 'password';
        $data = $action === 'old' ? ['token_hash' => str_repeat('a', 56)] : $this->password();
        $this->postForm($this->url($action), $data)->assertRedirect('/account-email-change/result');
        $this->assertSame($expected, $this->row()->phase);
        $this->assertNotNull(UserProfile::findOrFail(self::USER)->email_change_hold_id);
        $this->assertNull(UserProfile::findOrFail(self::USER)->approved_email);
        $this->assertNull(UserProfile::findOrFail(self::USER)->auth_sessions_valid_after);
        $this->assertFalse($this->app['session']->has('email_change_context'));
        $writes = $this->passwordWrites;
        $this->postForm($this->url($action), $data)->assertRedirect('/account-email-change/result');
        $this->assertSame($writes, $this->passwordWrites);
        $this->assertSame(in_array($phase, ['password_confirmed', 'completed'], true) ? 1 : 0, $writes);
    }

    public static function auditPhases(): array
    {
        return [['old_verifying', 'requested'], ['old_verified', 'old_verifying'], ['password_started', 'verified'],
            ['password_confirmed', 'password_started'], ['completed', 'signout_started']];
    }

    public function test_safe_cancellation_before_verification_rejects_the_old_reference(): void
    {
        $this->issue();
        $this->withSession(['supabase_access_token' => $this->token(self::ADMIN)]);
        $this->app['session']->save();
        $this->postForm($this->adminUrl().'/cancel', ['confirm_change' => '1'])->assertRedirect();
        $this->assertSame('cancelled', $this->row()->status);
        $this->assertNull(UserProfile::findOrFail(self::USER)->email_change_hold_id);
        $this->withSession(['supabase_access_token' => null]);
        $this->app['session']->save();
        $this->postForm($this->url('old'), ['token_hash' => str_repeat('a', 56)])->assertRedirect('/account-email-change/result');
        $this->assertSame(0, $this->emailWrites);
    }

    public function test_changed_scope_or_revoked_administrator_blocks_approval_after_review(): void
    {
        $review = $this->postForm($this->adminUrl().'/review', ['new_email' => self::DESTINATION, 'same_holder' => '1'])->assertOk()->viewData('review');
        DB::table('associations')->update(['status' => 'inactive']);
        $this->withCredentials()->postJson($this->adminUrl(), ['new_email' => self::DESTINATION, 'same_holder' => '1', 'confirm_change' => '1', 'review_hash' => $review['review_hash']])->assertUnprocessable();
        DB::table('user_profiles')->where('id', self::ADMIN)->update(['is_active' => false]);
        $this->postForm($this->adminUrl(), ['new_email' => self::DESTINATION, 'same_holder' => '1', 'confirm_change' => '1', 'review_hash' => $review['review_hash']])->assertRedirect();
        $this->assertSame(0, $this->recoveryEmails);
    }

    public function test_client_identity_and_destination_substitution_are_prohibited(): void
    {
        $this->issue();
        $this->postForm($this->url('old'), ['token_hash' => str_repeat('a', 56), 'auth_user_id' => self::OTHER])->assertRedirect('/account-email-change/result');
        $this->assertSame('requested', $this->row()->phase);
        $this->verifyOld();
        $this->postForm($this->url('begin'), ['confirm_change' => '1', 'new_email' => 'unapproved@example.test'])->assertRedirect('/account-email-change/result');
        $this->assertSame(0, $this->emailWrites);
    }

    public function test_request_delivery_uncertainty_does_not_send_a_second_email(): void
    {
        $this->timeout = 'request';
        $this->issue();
        $this->assertSame(1, $this->recoveryEmails);
        $request = new Request;
        $request->attributes->set('current_user', UserProfile::with('role')->findOrFail(self::ADMIN));
        try {
            app(AccountEmailChangeService::class)->review(UserProfile::findOrFail(self::ADMIN), UserProfile::findOrFail(self::USER), self::DESTINATION);
            $this->fail('A pending hold must block a new request.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('email_change', $exception->errors());
        }
        $this->assertNotNull(UserProfile::findOrFail(self::USER)->email_change_hold_id);
        $this->assertSame(1, $this->recoveryEmails);
    }

    public function test_public_progress_pages_render_the_actual_pending_and_password_states(): void
    {
        $this->issue();
        $this->verifyOld();
        $this->get($this->url('progress'))->assertOk()->assertSee(self::DESTINATION);
        $this->postForm($this->url('begin'), ['confirm_change' => '1']);
        $this->get($this->url('progress'))->assertOk()->assertSee('Check both mailboxes');
        $this->postForm($this->url('confirm'), ['token_hash' => str_repeat('b', 56)]);
        $this->get($this->url('progress'))->assertOk()->assertSee('One mailbox confirmed');
        $this->postForm($this->url('confirm'), ['token_hash' => str_repeat('c', 56)]);
        $this->get($this->url('progress'))->assertOk()->assertSee('Set Password and Complete Change');
    }

    public function test_mailbox_confirmation_for_a_different_uuid_cannot_authorize_password_setup(): void
    {
        $this->issue();
        $this->verifyOld();
        $this->postForm($this->url('begin'), ['confirm_change' => '1']);
        $this->postForm($this->url('confirm'), ['token_hash' => str_repeat('b', 56)]);
        $this->wrongIdentity = true;
        $this->postForm($this->url('confirm'), ['token_hash' => str_repeat('c', 56)])->assertRedirect('/account-email-change/result');
        $this->assertSame('confirming', $this->row()->phase);
        $this->assertSame(0, $this->passwordWrites);
        $this->assertNotNull(UserProfile::findOrFail(self::USER)->email_change_hold_id);
    }

    public function test_a_stolen_context_handle_cannot_be_used_in_another_browser_session(): void
    {
        $this->issue();
        $this->verifyOld();
        $session = new Store('other_browser', new ArraySessionHandler(120));
        $session->start();
        $session->put('email_change_context', $this->app['session']->get('email_change_context'));
        $request = new Request;
        $request->setLaravelSession($session);
        try {
            app(AccountEmailChangeService::class)->begin($request, $this->reference);
            $this->fail('A context must be bound to its original browser session.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('email_change_context_unavailable', $exception->getMessage());
        }
        $this->assertSame('old_verified', $this->row()->phase);
        $this->assertSame(0, $this->emailWrites);
    }

    public function test_callback_rate_limit_does_not_consume_provider_proof(): void
    {
        $this->issue();
        for ($i = 0; $i < 20; $i++) {
            $this->get($this->url('old'))->assertOk();
        }
        $this->get($this->url('old'))->assertStatus(429);
        $this->assertSame('requested', $this->row()->phase);
        $this->assertSame(0, DB::table('email_change_proofs')->count());
    }
}
