<?php

namespace Tests\Feature;

use App\Exceptions\InvitationActivationException;
use App\Http\Controllers\Auth\InvitationActivationController;
use App\Http\Requests\SetInvitationPasswordRequest;
use App\Http\Requests\VerifyInvitationRequest;
use App\Services\InvitationActivationContext;
use App\Services\InvitationActivationService;
use App\Services\SupabaseAuthService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ProviderRequest;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Mockery;
use Tests\TestCase;

class SupabaseInvitationAuthTest extends TestCase
{
    private const USER_ID = 'bd92f5ee-5b1b-477c-8d50-808839635cee';
    private const INVITATION_ID = '018f5ee0-8ba2-4f19-aa4d-91b32c312cd1';
    private const PASSWORD = 'Secure-Test-Password42!';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.supabase.url' => 'https://supabase.example.test',
            'services.supabase.publishable_key' => 'sb_publishable_test',
            'services.supabase.service_role_key' => 'test_server_key',
            'services.supabase.activation_cache_store' => 'file',
            'cache.default' => 'array',
            'session.driver' => 'array',
        ]);
        Http::preventStrayRequests();
    }

    public function test_verification_uses_invite_type_and_does_not_retain_refresh_token(): void
    {
        Http::fake(['*/auth/v1/verify' => Http::response($this->verificationResponse())]);
        $result = app(SupabaseAuthService::class)->verifyInvitationToken(str_repeat('a', 64));
        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('refresh_token', $result);
        Http::assertSentCount(1);
        Http::assertSent(fn (ProviderRequest $request) => $request->method() === 'POST'
            && $request['type'] === 'invite' && $request['token_hash'] === str_repeat('a', 64)
            && $request->hasHeader('apikey', 'sb_publishable_test') && ! $request->hasHeader('Authorization'));
    }

    public function test_token_verification_timeout_is_not_retried(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('Simulated provider timeout.');
        });
        $result = app(SupabaseAuthService::class)->verifyInvitationToken(str_repeat('a', 64));
        $this->assertFalse($result['success']);
        $this->assertSame(1, $attempts);
    }

    public function test_password_update_uses_recipient_bearer_and_public_key(): void
    {
        Http::fake(['*/auth/v1/user' => Http::response($this->identity())]);
        $result = app(SupabaseAuthService::class)->updateInvitationPassword('test_recipient_access', self::PASSWORD);
        $this->assertTrue($result['success']);
        Http::assertSentCount(1);
        Http::assertSent(fn (ProviderRequest $request) => $request->method() === 'PUT'
            && $request['password'] === self::PASSWORD
            && $request->hasHeader('Authorization', 'Bearer test_recipient_access')
            && $request->hasHeader('apikey', 'sb_publishable_test'));
    }

    public function test_service_role_key_is_rejected_for_activation(): void
    {
        Http::fake();
        config(['services.supabase.publishable_key' => 'test_server_key']);
        $this->assertFalse(app(SupabaseAuthService::class)->verifyInvitationToken(str_repeat('a', 64))['success']);
        $this->assertFalse(app(SupabaseAuthService::class)->updateInvitationPassword('recipient', self::PASSWORD)['success']);
        Http::assertNothingSent();
    }

    public function test_unconfirmed_independent_identity_is_rejected(): void
    {
        $user = $this->identity();
        $user['email_confirmed_at'] = null;
        Http::fake(['*/auth/v1/user' => Http::response($user)]);
        $this->assertNull(app(SupabaseAuthService::class)->getInvitationIdentity('test_recipient_access'));
    }

    public function test_public_get_does_not_consume_token_and_has_privacy_headers(): void
    {
        Http::fake();
        $response = $this->get('/invitation');
        $response->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString("default-src 'none'", $response->headers->get('Content-Security-Policy'));
        $response->assertDontSee('test_recipient_access')->assertDontSee('test_refresh_token');
        $response->assertSee('history.replaceState', false);
        Http::assertNothingSent();
    }

    public function test_query_token_is_discarded_without_rendering_or_verification(): void
    {
        Http::fake();
        $response = $this->get('/invitation?token_hash='.str_repeat('a', 64));
        $response->assertRedirect('/invitation')->assertDontSee(str_repeat('a', 64));
        Http::assertNothingSent();
    }

    public function test_invalid_verification_input_is_not_flashed_to_session(): void
    {
        Http::fake();
        $this->post('/invitation/verify', ['token_hash' => 'invalid-token-value'])
            ->assertRedirect(route('invitation.verify'))->assertSessionMissing('_old_input');
        Http::assertNothingSent();
    }

    public function test_csrf_failure_is_generic_and_does_not_contact_provider(): void
    {
        Http::fake();
        $this->app->bind(ValidateCsrfToken::class, function ($app) {
            return new class($app, $app['encrypter']) extends ValidateCsrfToken {
                protected function runningUnitTests() { return false; }
            };
        });
        $response = $this->post('/invitation/verify', ['token_hash' => str_repeat('b', 64)]);
        $response->assertStatus(419)->assertHeader('Referrer-Policy', 'no-referrer')->assertDontSee(str_repeat('b', 64));
        Http::assertNothingSent();
    }

    public function test_existing_protected_dashboard_still_requires_normal_login(): void
    {
        Http::fake();
        $this->get('/dashboard')->assertRedirect(route('login'));
        Http::assertNothingSent();
    }

    public function test_weak_password_and_mismatched_confirmation_fail_validation(): void
    {
        $rules = (new SetInvitationPasswordRequest)->rules();
        $this->assertTrue(Validator::make(['password' => 'short', 'password_confirmation' => 'short'], $rules)->fails());
        $this->assertTrue(Validator::make(['password' => self::PASSWORD, 'password_confirmation' => 'different'], $rules)->fails());
        $this->assertFalse(Validator::make(['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD], $rules)->fails());
    }

    public function test_independent_identity_mismatch_prevents_context_creation(): void
    {
        $other = $this->identity();
        $other['email'] = 'other@example.test';
        Http::fake([
            '*/auth/v1/verify' => Http::response($this->verificationResponse()),
            '*/auth/v1/user' => Http::response($other),
        ]);
        [$controller, $contexts, $session] = $this->controller();
        $request = $this->verificationRequest($session);
        $response = $controller->verify($request);
        $this->assertSame(route('invitation.result'), $response->getTargetUrl());
        $this->assertFalse($session->has(InvitationActivationContext::SESSION_KEY));
        Http::assertSentCount(2);
    }

    public function test_context_is_encrypted_separate_from_normal_login_and_expiry_is_fixed(): void
    {
        Http::fake([
            '*/auth/v1/verify' => Http::response($this->verificationResponse()),
            '*/auth/v1/user' => Http::response($this->identity()),
        ]);
        $activation = Mockery::mock(InvitationActivationService::class);
        $activation->shouldReceive('identify')->once()->andReturn($this->scope());
        [$controller, $contexts, $session] = $this->controller($activation);
        $request = $this->verificationRequest($session);
        $oldSessionId = $session->getId();
        $controller->verify($request);
        $id = $contexts->id($request);
        $encrypted = Cache::store('array')->get('invitation-activation:'.$id);
        $this->assertIsString($encrypted);
        $this->assertStringNotContainsString('test_recipient_access', $encrypted);
        $this->assertFalse($session->has('supabase_access_token'));
        $this->assertFalse($session->has('supabase_refresh_token'));
        $this->assertFalse($session->has('user_profile_id'));
        $this->assertNotSame($oldSessionId, $session->getId());
        $context = $contexts->read($request);
        $this->assertLessThanOrEqual(time() + 600, $context['expires_at']);
        $context['phase'] = 'password_confirmed';
        $contexts->save($id, $context);
        $this->assertSame($context['expires_at'], $contexts->read($request)['expires_at']);
        $session->migrate(true);
        $this->assertNull($contexts->read($request));
    }

    public function test_uncertain_password_update_blocks_replay_and_recovery(): void
    {
        $attempts = 0;
        Http::fake(function (ProviderRequest $request) use (&$attempts) {
            if ($request->method() === 'PUT') {
                $attempts++;
                throw new ConnectionException('Simulated interrupted password response.');
            }
            return Http::response($this->identity());
        });
        $activation = Mockery::mock(InvitationActivationService::class);
        $activation->shouldReceive('eligible')->once();
        $activation->shouldReceive('claimPassword')->once();
        $activation->shouldNotReceive('finish');
        [$controller, $contexts, $session] = $this->controller($activation);
        $request = $this->passwordRequest($session);
        $contexts->create($request, $this->scope() + ['access_token' => 'test_recipient_access'], 3600);
        $controller->setPassword($request);
        $controller->setPassword($request);
        $controller->recover($request);
        $this->assertSame(1, $attempts);
        $this->assertFalse($session->has(InvitationActivationContext::SESSION_KEY));
    }

    public function test_database_retry_uses_confirmed_context_without_second_password_update(): void
    {
        $passwordWrites = 0;
        Http::fake(function (ProviderRequest $request) use (&$passwordWrites) {
            if ($request->method() === 'PUT') $passwordWrites++;
            return Http::response($this->identity());
        });
        $activation = Mockery::mock(InvitationActivationService::class);
        $activation->shouldReceive('eligible')->once();
        $activation->shouldReceive('claimPassword')->once();
        $finishes = 0;
        $activation->shouldReceive('finish')->twice()->andReturnUsing(function () use (&$finishes) {
            if (++$finishes === 1) throw new \RuntimeException('Simulated database interruption.');
        });
        [$controller, $contexts, $session] = $this->controller($activation);
        $request = $this->passwordRequest($session);
        $contexts->create($request, $this->scope() + ['access_token' => 'test_recipient_access'], 3600);
        $first = $controller->setPassword($request);
        $this->assertSame(route('invitation.recovery'), $first->getTargetUrl());
        $this->assertSame('password_confirmed', $contexts->read($request)['phase']);
        $second = $controller->recover($request);
        $this->assertSame(route('invitation.result'), $second->getTargetUrl());
        $this->assertTrue($session->get('invitation_activation_succeeded'));
        $this->assertFalse($session->has(InvitationActivationContext::SESSION_KEY));
        $this->assertSame(1, $passwordWrites);
    }

    public function test_password_response_identity_mismatch_prevents_database_activation(): void
    {
        Http::fake(function (ProviderRequest $request) {
            $user = $this->identity();
            if ($request->method() === 'PUT') $user['id'] = 'b1ccef19-28d2-4d62-84db-8c37084f906c';
            return Http::response($user);
        });
        $activation = Mockery::mock(InvitationActivationService::class);
        $activation->shouldReceive('eligible')->once();
        $activation->shouldReceive('claimPassword')->once();
        $activation->shouldNotReceive('finish');
        [$controller, $contexts, $session] = $this->controller($activation);
        $request = $this->passwordRequest($session);
        $contexts->create($request, $this->scope() + ['access_token' => 'test_recipient_access'], 3600);
        $response = $controller->setPassword($request);
        $this->assertSame(route('invitation.result'), $response->getTargetUrl());
        $this->assertFalse($session->has(InvitationActivationContext::SESSION_KEY));
        Http::assertSentCount(2);
    }

    public function test_nonpersistent_activation_storage_is_rejected(): void
    {
        config(['services.supabase.activation_cache_store' => 'array']);
        $this->expectException(InvitationActivationException::class);
        (new InvitationActivationContext)->storage();
    }

    private function controller(?InvitationActivationService $activation = null): array
    {
        $contexts = Mockery::mock(InvitationActivationContext::class)->makePartial();
        $contexts->shouldReceive('storage')->andReturn(Cache::store('array'));
        $activation ??= Mockery::mock(InvitationActivationService::class);
        $session = new Store('activation_test', new ArraySessionHandler(120));
        $session->start();

        return [new InvitationActivationController(app(SupabaseAuthService::class), $contexts, $activation), $contexts, $session];
    }

    private function verificationRequest(Store $session): VerifyInvitationRequest
    {
        $request = VerifyInvitationRequest::create('/invitation/verify', 'POST', ['token_hash' => str_repeat('a', 64)]);
        $request->setLaravelSession($session);
        $request->setValidator(Validator::make($request->all(), $request->rules()));

        return $request;
    }

    private function passwordRequest(Store $session): SetInvitationPasswordRequest
    {
        $request = SetInvitationPasswordRequest::create('/invitation/password', 'POST', [
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ]);
        $request->setLaravelSession($session);
        $request->setValidator(Validator::make($request->all(), $request->rules()));

        return $request;
    }

    private function scope(): array
    {
        return ['invitation_id' => self::INVITATION_ID, 'auth_user_id' => self::USER_ID,
            'email' => 'invited@example.test', 'role_id' => '1', 'association_id' => null];
    }

    private function identity(): array
    {
        return ['id' => self::USER_ID, 'email' => 'Invited@Example.Test', 'email_confirmed_at' => '2026-10-12T00:00:00Z'];
    }

    private function verificationResponse(): array
    {
        return ['user' => $this->identity(), 'access_token' => 'test_recipient_access',
            'refresh_token' => 'test_refresh_token', 'token_type' => 'bearer', 'expires_in' => 3600];
    }
}
