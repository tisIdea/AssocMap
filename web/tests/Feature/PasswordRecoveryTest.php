<?php

namespace Tests\Feature;

use App\Services\InvitationActivationContext;
use App\Http\Requests\PasswordRecoveryRequest;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    private const ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const OTHER = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const PASSWORD = 'Synthetic-New-Password42!';
    private bool $used = false;
    private bool $uncertain = false;
    private bool $wrongIdentity = false;
    private int $writes = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('s', 32)), 'app.debug' => false,
            'app.url' => 'https://app.example.test', 'services.supabase.url' => 'https://auth.example.test',
            'services.supabase.publishable_key' => 'sb_publishable_synthetic',
            'services.supabase.activation_cache_store' => 'array']);
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        // Exercise real recovery encryption and locks with an in-memory storage adapter.
        $storage = Mockery::mock(InvitationActivationContext::class);
        $storage->shouldReceive('storage')->andReturn(Cache::store('array'));
        $storage->shouldReceive('checkStorage')->andReturnNull();
        $this->app->instance(InvitationActivationContext::class, $storage);
        Schema::create('roles', function (Blueprint $table) {
            $table->id(); $table->string('code'); $table->boolean('is_active');
            $table->string('name')->nullable(); $table->text('description')->nullable(); $table->timestamps();
        });
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->unsignedBigInteger('role_id'); $table->string('display_name');
            $table->boolean('is_active'); $table->timestamp('activated_at')->nullable(); $table->timestamps();
        });
        Schema::create('user_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('auth_user_id'); $table->string('email'); $table->string('status');
            $table->string('last_error_code')->nullable(); $table->timestamp('accepted_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable(); $table->timestamp('created_at')->nullable();
        });
        DB::table('roles')->insert(['id' => 1, 'code' => 'system_admin', 'is_active' => true]);
        DB::table('user_profiles')->insert(['id' => self::ID, 'role_id' => 1,
            'display_name' => 'Synthetic User', 'is_active' => true, 'activated_at' => now()]);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/auth/v1/recover?')) return Http::response('{}', 200, ['Content-Type' => 'application/json']);
            if (str_ends_with($request->url(), '/auth/v1/verify')) {
                $this->assertSame('recovery', $request['type']);
                if ($this->used) return Http::response(['code' => 'otp_expired'], 403);
                $this->used = true;
                return Http::response(['access_token' => 'synthetic_recovery_access', 'refresh_token' => 'synthetic_unused_refresh',
                    'token_type' => 'bearer', 'expires_in' => 3600, 'user' => $this->identity()]);
            }
            $this->assertStringEndsWith('/auth/v1/user', $request->url());
            if ($request->method() === 'PUT') {
                $this->writes++;
                if ($this->uncertain) throw new ConnectionException('Synthetic private provider failure');
            }
            return Http::response($this->identity($this->wrongIdentity ? self::OTHER : self::ID));
        });
    }

    private function identity(string $id = self::ID): array
    {
        return ['id' => $id, 'email' => 'recipient@example.test', 'email_confirmed_at' => '2026-10-01T00:00:00Z'];
    }

    private function remember(TestResponse $response): TestResponse
    {
        $cookie = $response->getCookie(config('session.cookie'));
        if ($cookie) $this->withCookie($cookie->getName(), $cookie->getValue());

        return $response;
    }

    private function verifyLink(): TestResponse
    {
        $this->remember($this->get('/password-recovery'))->assertOk();

        return $this->remember($this->post('/password-recovery/verify', ['token_hash' => str_repeat('a', 56)]));
    }

    public function test_request_feedback_is_generic_and_never_flashes_the_email(): void
    {
        $this->post('/forgot-password', ['email' => 'unknown@example.test'])->assertRedirect(route('password.forgot'))
            ->assertSessionHas('recovery_requested', true)->assertSessionMissing('_old_input');
        Http::assertSentCount(1);
    }

    public function test_valid_recovery_changes_only_the_provider_password_and_does_not_log_in(): void
    {
        $before = (array) DB::table('user_profiles')->first();
        $this->verifyLink()->assertRedirect(route('password.reset'))
            ->assertSessionMissing('supabase_access_token')->assertSessionMissing('supabase_refresh_token');
        $this->remember($this->get('/password-recovery/password'))->assertOk()->assertSee('Save new password')
            ->assertDontSee('synthetic_recovery_access')->assertDontSee('synthetic_unused_refresh');
        $this->remember($this->post('/password-recovery/password', ['password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD, 'auth_user_id' => self::OTHER]))
            ->assertRedirect(route('password.forgot'))->assertSessionHas('recovery_completed', true)
            ->assertSessionMissing('password_recovery_context')->assertSessionMissing('supabase_access_token');
        $this->assertSame(1, $this->writes);
        $this->assertSame($before, (array) DB::table('user_profiles')->first());
        $this->remember($this->get('/forgot-password'))->assertOk()->assertViewHas('step', 'success')
            ->assertSee('Password updated successfully. Redirecting you to sign in...')
            ->assertDontSee('name="email"', false)->assertDontSee('Request recovery link')
            ->assertSee('Go to Sign In')->assertSessionMissing('recovery_completed');
        $this->get('/forgot-password')->assertViewHas('step', 'forgot')->assertDontSee('id="recovery-success"', false);
        $this->post('/password-recovery/password', ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])
            ->assertRedirect(route('password.forgot'));
        $this->assertSame(1, $this->writes);
    }

    public function test_revoked_profile_cannot_obtain_a_password_context(): void
    {
        DB::table('user_profiles')->where('id', self::ID)->update(['is_active' => false]);
        $this->verifyLink()->assertRedirect(route('password.forgot'))->assertSessionMissing('password_recovery_context');
        $this->assertSame(0, $this->writes);
        $this->assertSame(0, DB::table('user_profiles')->where('is_active', true)->count());
    }

    public function test_revocation_after_verification_prevents_the_password_write(): void
    {
        $this->verifyLink()->assertRedirect(route('password.reset'));
        DB::table('user_profiles')->where('id', self::ID)->update(['is_active' => false]);
        $this->post('/password-recovery/password', ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])
            ->assertRedirect(route('password.forgot'))->assertSessionMissing('password_recovery_context');
        $this->assertSame(0, $this->writes);
    }

    public function test_provider_identity_mismatch_never_authorizes_a_password_write(): void
    {
        $this->wrongIdentity = true;
        $this->verifyLink()->assertRedirect(route('password.forgot'))->assertSessionMissing('password_recovery_context');
        $this->assertSame(0, $this->writes);
    }

    public function test_an_uncertain_password_write_cannot_be_replayed(): void
    {
        $this->verifyLink()->assertRedirect(route('password.reset'));
        $this->uncertain = true;
        $this->remember($this->post('/password-recovery/password', ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD]))
            ->assertRedirect(route('password.forgot'))->assertSessionHasErrors('recovery')
            ->assertSessionMissing('_old_input')->assertSessionMissing('password_recovery_context');
        $this->post('/password-recovery/password', ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD]);
        $this->assertSame(1, $this->writes);
    }

    public function test_reused_provider_hash_is_rejected(): void
    {
        $this->verifyLink()->assertRedirect(route('password.reset'));
        $this->verifyLink()->assertRedirect(route('password.forgot'))->assertSessionMissing('password_recovery_context');
        $this->assertSame(0, $this->writes);
    }

    #[DataProvider('invalidContexts')]
    public function test_expired_or_different_session_contexts_cannot_write(string $field, mixed $value): void
    {
        $this->verifyLink()->assertRedirect(route('password.reset'));
        $id = $this->app['session.store']->get('password_recovery_context');
        $key = 'password-recovery:'.$id;
        $context = json_decode(Crypt::decryptString(Cache::store('array')->get($key)), true);
        $context[$field] = $value;
        Cache::store('array')->put($key, Crypt::encryptString(json_encode($context)), 300);
        $this->post('/password-recovery/password', ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])
            ->assertRedirect(route('password.forgot'))->assertSessionMissing('password_recovery_context');
        $this->assertSame(0, $this->writes);
    }

    public static function invalidContexts(): array
    {
        return [['expires_at', 1], ['session_fingerprint', str_repeat('0', 64)], ['phase', 'password_update_started']];
    }

    public function test_provider_request_failure_uses_the_same_public_feedback(): void
    {
        Http::fake(['*/auth/v1/recover*' => Http::response(['error' => 'Synthetic private error'], 503)]);
        $this->post('/forgot-password', ['email' => 'recipient@example.test'])->assertRedirect(route('password.forgot'))
            ->assertSessionHas('recovery_requested', true)->assertSessionMissing('_old_input');
        $this->get('/forgot-password')->assertOk()->assertDontSee('Synthetic private error');
    }

    public function test_uncertain_password_updates_never_show_or_redirect_from_a_stale_success(): void
    {
        $this->verifyLink()->assertRedirect(route('password.reset'));
        $this->uncertain = true;
        $this->remember($this->withSession(['recovery_completed' => true])->post('/password-recovery/password', [
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ]))->assertRedirect(route('password.forgot'))->assertSessionMissing('recovery_completed');
        $this->get('/forgot-password')->assertOk()->assertViewHas('step', 'invalid')
            ->assertDontSee('id="recovery-success"', false)->assertDontSee('Password updated successfully.');
    }

    public function test_weak_password_validation_preserves_the_verified_context_without_flashing_passwords(): void
    {
        $this->verifyLink()->assertRedirect(route('password.reset'));
        $this->post('/password-recovery/password', ['password' => 'weak', 'password_confirmation' => 'weak'])
            ->assertRedirect(route('password.reset'))->assertSessionHasErrors('password')
            ->assertSessionHas('password_recovery_context')->assertSessionMissing('_old_input');
        $this->assertSame(0, $this->writes);
    }

    public function test_invalid_hash_and_query_parameters_are_not_forwarded_or_flashed(): void
    {
        $this->get('/password-recovery?token_hash=synthetic-private')->assertRedirect('/password-recovery');
        $this->post('/password-recovery/verify', ['token_hash' => 'invalid'])
            ->assertRedirect(route('password.recovery'))->assertSessionMissing('_old_input');
        Http::assertNothingSent();
    }

    public function test_recovery_page_is_private_and_contains_no_third_party_assets(): void
    {
        $response = $this->get('/password-recovery')->assertOk()->assertSee('Verify recovery link');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertStringContainsString("connect-src 'none'", $response->headers->get('Content-Security-Policy'));
        $response->assertDontSee('src="https://', false)->assertDontSee('href="https://fonts', false);
        Http::assertNothingSent();
    }

    public function test_opening_recovery_with_an_existing_session_offers_consent_without_signing_out(): void
    {
        $this->withSession(['supabase_access_token' => 'synthetic_existing_access', 'user_profile_id' => self::OTHER])
            ->get('/password-recovery')->assertOk()->assertViewHas('step', 'verify')->assertViewHas('signedIn', true)
            ->assertSee('id="recovery-verification"', false)->assertSee('name="sign_out_current_account"', false)
            ->assertSee('Sign out and continue recovery')->assertSessionHas('supabase_access_token', 'synthetic_existing_access')
            ->assertSessionHas('user_profile_id', self::OTHER);
        Http::assertNothingSent();
    }

    public function test_an_existing_session_is_preserved_when_sign_out_consent_is_missing(): void
    {
        $this->withSession(['supabase_access_token' => 'synthetic_existing_access', 'user_profile_id' => self::OTHER])
            ->post('/password-recovery/verify', ['token_hash' => str_repeat('a', 56)])
            ->assertRedirect(route('password.recovery'))->assertSessionHasErrors('recovery')
            ->assertSessionHas('supabase_access_token', 'synthetic_existing_access')->assertSessionHas('user_profile_id', self::OTHER)
            ->assertSessionMissing('password_recovery_context')->assertSessionMissing('_old_input');
        Http::assertNothingSent();
    }

    public function test_consent_ends_only_the_local_session_and_still_requires_verified_recovery_identity(): void
    {
        $before = (array) DB::table('user_profiles')->first();
        $this->remember($this->withSession(['supabase_access_token' => 'synthetic_existing_access',
            'supabase_refresh_token' => 'synthetic_existing_refresh', 'user_profile_id' => self::OTHER,
            'user_role' => 'synthetic_role'])->get('/password-recovery'));
        $this->remember($this->post('/password-recovery/verify', ['token_hash' => str_repeat('a', 56), 'sign_out_current_account' => '1']))
            ->assertRedirect(route('password.reset'))->assertSessionHas('password_recovery_context')
            ->assertSessionMissing('supabase_access_token')->assertSessionMissing('supabase_refresh_token')
            ->assertSessionMissing('user_profile_id')->assertSessionMissing('user_role');
        $this->get('/password-recovery/password')->assertOk()->assertSee('Save new password');
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/auth/v1/verify') && $request['type'] === 'recovery');
        Http::assertNotSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer synthetic_existing_access'));
        $this->assertSame(0, $this->writes);
        $this->assertSame($before, (array) DB::table('user_profiles')->first());
    }

    public function test_invalid_hash_cannot_sign_out_an_existing_account_even_with_consent(): void
    {
        $this->withSession(['supabase_access_token' => 'synthetic_existing_access'])
            ->post('/password-recovery/verify', ['token_hash' => 'invalid', 'sign_out_current_account' => '1'])
            ->assertRedirect(route('password.recovery'))->assertSessionHas('supabase_access_token', 'synthetic_existing_access');
        Http::assertNothingSent();
    }

    public function test_a_stale_error_cannot_hide_verification_for_a_fresh_email_link(): void
    {
        $errors = (new ViewErrorBag)->put('default', new MessageBag(['recovery' => 'Synthetic earlier failure']));
        $this->withSession(['errors' => $errors])->get('/password-recovery')->assertOk()->assertViewHas('step', 'verify')
            ->assertSee('id="recovery-verification"', false)->assertSee('name="token_hash"', false);
        Http::assertNothingSent();
    }

    public function test_verification_failure_logs_only_safe_classification_and_reference(): void
    {
        Log::spy();
        Http::fake(['*/auth/v1/verify' => Http::response(['code' => 'otp_expired',
            'msg' => 'Synthetic private provider message'], 403)]);
        $this->verifyLink()->assertRedirect(route('password.forgot'))->assertSessionHasErrors('recovery');
        $this->get('/forgot-password')->assertSee('expired or has already been used')
            ->assertSee('No password change was attempted by this request.')->assertSee('Reference: R-')
            ->assertDontSee('Synthetic private provider message')->assertDontSee('recipient@example.test')
            ->assertDontSee(str_repeat('a', 56));
        Log::shouldHaveReceived('notice')->once()->withArgs(function ($message, $context) {
            return $message === 'Password recovery verification stopped.'
                && array_keys($context) === ['reference', 'stage', 'reason', 'status']
                && preg_match('/\AR-[A-F0-9]{12}\z/D', $context['reference']) === 1
                && $context['stage'] === 'verify.token_verification'
                && $context['reason'] === 'recovery_link_expired' && $context['status'] === 403;
        });
        $this->assertSame(0, $this->writes);
    }

    public function test_missing_context_is_diagnosed_before_password_entry_without_a_provider_request(): void
    {
        Log::spy();
        $this->get('/password-recovery/password')->assertRedirect(route('password.forgot'))->assertSessionHasErrors('recovery');
        Log::shouldHaveReceived('notice')->once()->withArgs(fn ($message, $context) =>
            $context['stage'] === 'password_form.context_read' && $context['reason'] === 'context_missing');
        Http::assertNothingSent();
        $this->assertSame(0, $this->writes);
    }

    public function test_storage_failure_preserves_the_existing_session_and_does_not_consume_the_link(): void
    {
        Log::spy();
        $storage = Mockery::mock(InvitationActivationContext::class);
        $storage->shouldReceive('checkStorage')->once()->andThrow(new \RuntimeException('Synthetic private storage detail'));
        $this->app->instance(InvitationActivationContext::class, $storage);
        $this->remember($this->withSession(['supabase_access_token' => 'synthetic_existing_access'])->get('/password-recovery'));
        $this->remember($this->post('/password-recovery/verify', ['token_hash' => str_repeat('a', 56), 'sign_out_current_account' => '1']))
            ->assertRedirect(route('password.forgot'))->assertSessionHas('supabase_access_token', 'synthetic_existing_access');
        $this->get('/forgot-password')->assertSee('Reference: R-')->assertDontSee('Synthetic private storage detail');
        Log::shouldHaveReceived('notice')->once()->withArgs(fn ($message, $context) =>
            $context['stage'] === 'verify.storage_check' && $context['reason'] === 'unexpected_failure');
        Http::assertNothingSent();
    }

    public function test_repeated_email_requests_are_throttled(): void
    {
        $this->post('/forgot-password', ['email' => 'recipient@example.test'])->assertRedirect();
        $this->post('/forgot-password', ['email' => 'recipient@example.test'])->assertStatus(429)
            ->assertSee('Too many recovery requests.')->assertDontSee('recipient@example.test');
        Http::assertSentCount(1);
    }

    public function test_confirmation_hides_the_email_form_and_retains_a_server_side_resend_target(): void
    {
        $this->remember($this->post('/forgot-password', ['email' => 'unknown@example.test']))->assertRedirect();
        $this->get('/forgot-password')->assertOk()->assertViewHas('step', 'confirmation')
            ->assertSee('Check your email')->assertSee('If an eligible account exists for this email')
            ->assertDontSee('name="email"', false)->assertDontSee('unknown@example.test')
            ->assertSee('Request another link')->assertViewHas('waitSeconds', PasswordRecoveryRequest::COOLDOWN_SECONDS);
        Http::assertSentCount(1);
    }

    public function test_early_resends_are_blocked_and_an_eligible_resend_uses_the_original_email(): void
    {
        $this->remember($this->post('/forgot-password', ['email' => 'recipient@example.test']));
        $this->remember($this->post('/forgot-password/resend'))->assertStatus(429)->assertViewHas('step', 'confirmation');
        Http::assertSentCount(1);
        $this->travel(PasswordRecoveryRequest::COOLDOWN_SECONDS + 1)->seconds();
        $this->post('/forgot-password/resend', ['email' => 'untrusted@example.test'])->assertRedirect(route('password.forgot'));
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request) => $request['email'] === 'untrusted@example.test');
    }

    public function test_correcting_the_email_does_not_reset_the_email_rate_limit(): void
    {
        $this->remember($this->post('/forgot-password', ['email' => 'recipient@example.test']));
        $this->remember($this->get('/forgot-password/email'))->assertRedirect(route('password.forgot'))
            ->assertSessionMissing('recovery_request');
        $this->get('/forgot-password')->assertViewHas('step', 'forgot')->assertSee('name="email"', false);
        $this->post('/forgot-password', ['email' => 'recipient@example.test'])->assertStatus(429);
        Http::assertSentCount(1);
    }

    public function test_hourly_limits_extend_the_visible_wait_instead_of_claiming_resend_is_available(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->remember($this->post('/forgot-password', ['email' => 'recipient@example.test']))->assertRedirect();
            $this->travel(PasswordRecoveryRequest::COOLDOWN_SECONDS + 1)->seconds();
        }
        $response = $this->post('/forgot-password/resend')->assertStatus(429)->assertViewHas('step', 'confirmation');
        $this->assertGreaterThan(PasswordRecoveryRequest::COOLDOWN_SECONDS, $response->viewData('waitSeconds'));
        Http::assertSentCount(5);
    }

    public function test_expired_resend_state_cannot_send_to_an_arbitrary_submitted_email(): void
    {
        $this->remember($this->get('/forgot-password'));
        $this->post('/forgot-password/resend', ['email' => 'untrusted@example.test'])->assertRedirect(route('password.forgot'))
            ->assertSessionHasErrors('recovery');
        Http::assertNothingSent();
    }
}
