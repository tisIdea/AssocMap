<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureSupabaseAuthenticated;
use App\Services\SupabaseAuthService;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class StaffAuthenticationCharacterizationTest extends TestCase
{
    private const ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const EMAIL = 'synthetic@example.test';
    private const PROVIDER = 'https://auth.example.test/auth/v1/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        config(['app.key' => 'base64:'.base64_encode(str_repeat('s', 32)), 'app.debug' => false,
            'services.supabase.url' => 'https://auth.example.test', 'services.supabase.publishable_key' => 'sb_publishable_synthetic']);
        $this->withoutVite();
        Http::fake([]);
        DB::unprepared(<<<'SQL'
            CREATE TABLE roles (id INTEGER PRIMARY KEY, code TEXT, is_active BOOLEAN);
            CREATE TABLE user_profiles (id TEXT PRIMARY KEY, role_id INTEGER, display_name TEXT, is_active BOOLEAN, created_at TEXT, updated_at TEXT);
            CREATE TABLE user_invitations (id TEXT PRIMARY KEY, auth_user_id TEXT, email TEXT, status TEXT, last_error_code TEXT, accepted_at TEXT, last_attempt_at TEXT, created_at TEXT);
            SQL);
        DB::table('roles')->insert(['id' => 1, 'code' => 'system_admin', 'is_active' => true]);
        DB::table('user_profiles')->insert(['id' => self::ID, 'role_id' => 1, 'display_name' => 'Synthetic Actor', 'is_active' => true]);
        Route::middleware(['web', EnsureSupabaseAuthenticated::class, EnsureRole::class.':system_admin'])
            ->get('/_tests/authentication-probe', fn (Request $request) => response()->json(['id' => $request->attributes->get('current_user')->id]));
    }

    protected function tearDown(): void
    {
        try { parent::tearDown(); } finally { HandleExceptions::flushState($this); }
    }

    private function tokens(): array
    {
        return ['access_token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh',
            'user' => ['id' => self::ID, 'email' => self::EMAIL, 'user_metadata' => ['role' => 'system_admin']]];
    }

    private function credentials(): array
    {
        return ['email' => self::EMAIL, 'password' => 'synthetic-password'];
    }

    private function browserSession(array $values = []): string
    {
        $this->withSession($values);
        $entry = $this->get('/login')->assertOk();
        $cookie = $entry->getCookie(config('session.cookie'));
        $this->assertNotNull($cookie);
        $this->withCookie($cookie->getName(), $cookie->getValue());

        return $cookie->getValue();
    }

    public function test_login_page_links_password_recovery_and_preserves_sign_in(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('Forgot password?')->assertSee(route('password.forgot'))
            ->assertSee('action="'.route('login.store').'"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('name="email"', false)->assertSee('name="password"', false);
        Http::assertNothingSent();
    }

    #[DataProvider('webRoles')]
    public function test_login_resolves_the_database_profile_role_and_rotates_the_existing_session(string $role, string $destination): void
    {
        DB::table('roles')->where('id', 1)->update(['code' => $role]);
        Http::fake([self::PROVIDER.'token*' => Http::response($this->tokens())]);
        $previousId = $this->browserSession(['marker' => 'retained session data']);
        $response = $this->post('/login', $this->credentials() + ['user_profile_id' => 'untrusted', 'user_role' => 'system_admin'])
            ->assertRedirect(route($destination))->assertSessionHas('user_profile_id', self::ID)->assertSessionHas('user_role', $role)
            ->assertSessionHas('supabase_access_token', 'synthetic-access')->assertSessionHas('supabase_refresh_token', 'synthetic-refresh')
            ->assertSessionHas('marker', 'retained session data');
        $this->assertNotSame($previousId, $response->getCookie(config('session.cookie'))->getValue());
        Http::assertSentCount(1);
    }

    public static function webRoles(): array
    {
        return [['system_admin', 'admin.dashboard'], ['field_officer', 'field-officer.dashboard'], ['association_account', 'association.dashboard']];
    }

    #[DataProvider('webRoles')]
    public function test_login_and_authenticated_requests_preserve_provider_and_database_budgets(string $role, string $destination): void
    {
        DB::table('roles')->where('id', 1)->update(['code' => $role]);
        Http::fake([self::PROVIDER.'token*' => Http::response($this->tokens()),
            self::PROVIDER.'user' => Http::response($this->tokens()['user'])]);
        $this->browserSession();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $login = $this->post('/login', $this->credentials())->assertRedirect(route($destination));
            $this->assertCount(3, DB::getQueryLog(), 'Login reads the profile, role and invitation eligibility once.');
            Http::assertSentCount(1);
            DB::flushQueryLog();
            $cookie = $login->getCookie(config('session.cookie'));
            $this->withCookie($cookie->getName(), $cookie->getValue())->get('/_tests/authentication-probe')
                ->assertStatus($role === 'system_admin' ? 200 : 403);
            $this->assertCount(3, DB::getQueryLog(), 'Authenticated requests must retain fresh profile, role and invitation checks.');
            Http::assertSentCount(2);
        } finally {
            DB::disableQueryLog();
        }
    }

    #[DataProvider('accountRestrictions')]
    public function test_inactive_unregistered_unsupported_and_pending_accounts_do_not_get_a_login_session(string $restriction): void
    {
        match ($restriction) {
            'inactive profile' => DB::table('user_profiles')->update(['is_active' => false]),
            'inactive role' => DB::table('roles')->update(['is_active' => false]),
            'missing role' => DB::table('user_profiles')->update(['role_id' => 999]),
            'unregistered' => DB::table('user_profiles')->delete(),
            'unsupported role' => DB::table('roles')->update(['code' => 'unsupported_role']),
            'pending invitation' => DB::table('user_invitations')->insert(['id' => 'pending', 'auth_user_id' => self::ID,
                'email' => self::EMAIL, 'status' => 'sent', 'created_at' => '2026-01-01']),
        };
        Http::fake([self::PROVIDER.'token*' => Http::response($this->tokens())]);
        $this->withSession(['supabase_access_token' => 'stale-access', 'supabase_refresh_token' => 'stale-refresh',
            'user_profile_id' => self::ID, 'user_role' => 'system_admin'])
            ->from('/login')->post('/login', $this->credentials())->assertRedirect('/login')->assertSessionHasErrors('email')
            ->assertSessionMissing('supabase_access_token')->assertSessionMissing('supabase_refresh_token')
            ->assertSessionMissing('user_profile_id')->assertSessionMissing('user_role')->assertSessionMissing('_old_input.password');
        Http::assertSentCount(1);
    }

    public static function accountRestrictions(): array
    {
        return array_map(fn ($restriction) => [$restriction], ['inactive profile', 'inactive role', 'missing role', 'unregistered', 'unsupported role', 'pending invitation']);
    }

    public function test_invalid_credentials_restore_only_the_email_and_do_not_create_authentication_state(): void
    {
        Http::fake([self::PROVIDER.'token*' => Http::response(['error_code' => 'invalid_credentials'], 400)]);
        $this->from('/login')->post('/login', $this->credentials())->assertRedirect('/login')->assertSessionHasErrors('email')
            ->assertSessionHasInput('email', self::EMAIL)->assertSessionMissing('_old_input.password')->assertSessionMissing('supabase_access_token');
        Http::assertSentCount(1);
    }

    public function test_missing_login_fields_are_rejected_before_provider_contact(): void
    {
        $this->post('/login', ['email' => self::EMAIL])->assertSessionHasErrors('password');
        Http::assertNothingSent();
    }

    public function test_logout_invalidates_session_rotates_csrf_and_does_not_contact_the_provider(): void
    {
        $previousId = $this->browserSession(['supabase_access_token' => 'synthetic-access', 'supabase_refresh_token' => 'synthetic-refresh',
            'user_profile_id' => self::ID, 'user_role' => 'system_admin', 'marker' => 'private session data']);
        $previousCsrf = session()->token();
        $response = $this->post('/logout')->assertRedirect(route('login'))->assertSessionMissing('supabase_access_token')
            ->assertSessionMissing('supabase_refresh_token')->assertSessionMissing('user_profile_id')->assertSessionMissing('user_role')->assertSessionMissing('marker');
        $this->assertNotSame($previousId, $response->getCookie(config('session.cookie'))->getValue());
        $this->assertNotSame($previousCsrf, session()->token());
        Http::assertNothingSent();
    }

    public function test_middleware_rechecks_database_role_instead_of_client_or_session_role_claims(): void
    {
        DB::table('roles')->update(['code' => 'field_officer']);
        Http::fake([self::PROVIDER.'user' => Http::response($this->tokens()['user'])]);
        $this->withSession(['supabase_access_token' => 'synthetic-access', 'user_role' => 'system_admin'])
            ->get('/_tests/authentication-probe?user_role=system_admin')->assertForbidden();
        Http::assertSentCount(1);
    }

    public function test_browser_provider_outage_stops_access_without_expiring_a_previously_valid_session(): void
    {
        Http::fake([self::PROVIDER.'user' => Http::response([], 503)]);
        $this->withSession(['supabase_access_token' => 'synthetic-access', 'user_profile_id' => self::ID])
            ->get('/_tests/authentication-probe')->assertStatus(503)->assertDontSee(self::ID)
            ->assertSessionHas('supabase_access_token', 'synthetic-access')->assertSessionHas('user_profile_id', self::ID);
        Http::assertSentCount(1);
    }

    // M3A reproduced a 500 or authenticated redirects for these invalid tokens.
    #[Group('regression-m3a')]
    public function test_m3a_regression_missing_access_token_uses_the_login_error_workflow(): void
    {
        $tokens = $this->tokens();
        unset($tokens['access_token']);
        Http::fake([self::PROVIDER.'token*' => Http::response($tokens)]);
        $this->assertRejectedLogin();
        Http::assertSentCount(1);
    }

    #[Group('regression-m3a')]
    #[DataProvider('invalidAccessTokens')]
    public function test_m3a_regression_invalid_access_token_cannot_create_or_retain_authentication_state(mixed $token): void
    {
        Http::fake([self::PROVIDER.'token*' => Http::response(array_replace($this->tokens(), ['access_token' => $token]))]);
        $this->assertRejectedLogin();
        Http::assertSentCount(1);
    }

    public static function invalidAccessTokens(): array
    {
        return ['null' => [null], 'empty' => [''], 'whitespace' => [" \t\n"], 'array' => [['synthetic-invalid-token']],
            'object' => [(object) ['value' => 'synthetic-invalid-token']], 'boolean' => [true], 'integer' => [42],
            'float' => [1.5], 'embedded whitespace' => ['synthetic invalid'], 'header control' => ["synthetic\r\ninvalid"]];
    }

    private function assertRejectedLogin(): void
    {
        $previousId = $this->browserSession(['supabase_access_token' => 'stale-access', 'supabase_refresh_token' => 'stale-refresh',
            'user_profile_id' => self::ID, 'user_role' => 'system_admin', 'marker' => 'retained']);
        $previousCsrf = session()->token();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $response = $this->from('/login')->post('/login', $this->credentials())->assertRedirect('/login')
            ->assertSessionHasErrors('email')->assertSessionHasInput('email', self::EMAIL)->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('supabase_access_token')->assertSessionMissing('supabase_refresh_token')
            ->assertSessionMissing('user_profile_id')->assertSessionMissing('user_role')->assertSessionHas('marker', 'retained');
        $this->assertSame($previousId, $response->getCookie(config('session.cookie'))->getValue());
        $this->assertSame($previousCsrf, session()->token());
        $this->assertSame([], DB::getQueryLog(), 'Invalid provider data must be rejected before profile lookup.');
        DB::disableQueryLog();
    }

    public function test_controller_revalidates_a_claimed_success_before_session_mutation(): void
    {
        $tokens = $this->tokens();
        unset($tokens['access_token']);
        $this->partialMock(SupabaseAuthService::class, function ($mock) use ($tokens) {
            $mock->shouldReceive('signIn')->once()->with(self::EMAIL, 'synthetic-password')->andReturn(['success' => true, 'data' => $tokens]);
        });
        $this->assertRejectedLogin();
        Http::assertNothingSent();
    }

    #[DataProvider('malformedIdentities')]
    public function test_invalid_identity_cannot_authenticate(mixed $identity): void
    {
        Http::fake([self::PROVIDER.'token*' => Http::response(array_replace($this->tokens(), ['user' => $identity]))]);
        $this->assertRejectedLogin();
        Http::assertSentCount(1);
    }

    public static function malformedIdentities(): array
    {
        return ['null' => [null], 'scalar' => ['synthetic'], 'list' => [[['id' => self::ID, 'email' => self::EMAIL]]],
            'missing id' => [['email' => self::EMAIL]], 'invalid id' => [['id' => 'invalid', 'email' => self::EMAIL]],
            'missing email' => [['id' => self::ID]], 'invalid email' => [['id' => self::ID, 'email' => []]],
            'anonymous' => [['id' => self::ID, 'email' => self::EMAIL, 'is_anonymous' => true]],
            'error object' => [['id' => self::ID, 'email' => self::EMAIL, 'error_code' => 'synthetic-private-detail']]];
    }

    #[DataProvider('loginProviderFailures')]
    public function test_login_provider_failures_have_distinct_safe_messages(int $status, string $body, string $message): void
    {
        Http::fake([self::PROVIDER.'token*' => Http::response($body, $status, ['Content-Type' => 'application/json'])]);
        $this->assertRejectedLogin();
        $this->assertSame($message, session('errors')->first('email'));
        $this->assertStringNotContainsString('synthetic-private-detail', session('errors')->first('email'));
        Http::assertSentCount(1);
    }

    public static function loginProviderFailures(): array
    {
        return [[400, '{"error_code":"invalid_credentials","msg":"synthetic-private-detail"}', 'Invalid email or password.'],
            [503, 'synthetic-private-detail', 'Sign-in is temporarily unavailable. Please try again.'],
            [200, '{', 'Unable to verify this account. Please try again.'],
            [200, 'true', 'Unable to verify this account. Please try again.'],
            [302, '{}', 'Unable to verify this account. Please try again.']];
    }

    public function test_login_timeout_has_a_safe_error_and_clears_old_authentication_state(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new \Illuminate\Http\Client\ConnectionException('synthetic-private-detail');
        });
        $this->assertRejectedLogin();
        $this->assertSame('Sign-in is temporarily unavailable. Please try again.', session('errors')->first('email'));
        $this->assertSame(1, $attempts);
    }

    public function test_explicit_identity_rejection_invalidates_session_and_rotates_csrf(): void
    {
        Http::fake([self::PROVIDER.'user' => Http::response(['code' => 401, 'error_code' => 'bad_jwt'], 401)]);
        $previousId = $this->browserSession(['supabase_access_token' => 'synthetic-access', 'user_profile_id' => self::ID]);
        $previousCsrf = session()->token();
        $response = $this->get('/_tests/authentication-probe')->assertRedirect(route('login'))
            ->assertSessionMissing('supabase_access_token')->assertSessionMissing('user_profile_id');
        $this->assertNotSame($previousId, $response->getCookie(config('session.cookie'))->getValue());
        $this->assertNotSame($previousCsrf, session()->token());
        Http::assertSentCount(1);
    }

    public function test_malformed_identity_response_blocks_protected_content_with_a_controlled_error(): void
    {
        Http::fake([self::PROVIDER.'user' => Http::response('true', 200, ['Content-Type' => 'application/json'])]);
        $this->withSession(['supabase_access_token' => 'synthetic-access', 'user_profile_id' => self::ID])
            ->get('/_tests/authentication-probe')->assertStatus(502)->assertDontSee(self::ID)->assertDontSee('synthetic-access');
        Http::assertSentCount(1);
    }

    public function test_malformed_stored_token_is_rejected_before_provider_contact(): void
    {
        $this->withSession(['supabase_access_token' => ['synthetic-invalid'], 'user_profile_id' => self::ID])
            ->get('/_tests/authentication-probe')->assertRedirect(route('login'))
            ->assertSessionMissing('supabase_access_token')->assertSessionMissing('user_profile_id');
        Http::assertNothingSent();
    }
}
