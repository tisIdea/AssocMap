<?php

namespace Tests\Feature;

use App\Http\Middleware\ApiRequestGuard;
use App\Models\UserProfile;
use App\Services\Auth\AccountReactivationService;
use App\Services\Auth\InvitationActivationContext;
use App\Services\Auth\UserAccessRevocationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class AccountReactivationTest extends TestCase
{
    private const ADMIN = '11111111-1111-4111-8111-111111111111';

    private const USER = '22222222-2222-4222-8222-222222222222';

    private const OTHER = '33333333-3333-4333-8333-333333333333';

    private const ASSOCIATION = '44444444-4444-4444-8444-444444444444';

    private const OLD_SESSION = '55555555-5555-4555-8555-555555555555';

    private const NEW_SESSION = '66666666-6666-4666-8666-666666666666';

    private const PASSWORD = 'Synthetic-New-Password42!';

    private string $reference = '';

    private string $recoveryToken;

    private bool $uncertain = false;

    private bool $wrongIdentity = false;

    private bool $logoutFails = false;

    private bool $expiredProof = false;

    private bool $cancelDuringWrite = false;

    private bool $disableDuringWrite = false;

    private int $emails = 0;

    private int $verifications = 0;

    private int $passwordWrites = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('r', 32)), 'app.debug' => false,
            'app.url' => 'https://app.example.test', 'services.supabase.url' => 'https://auth.example.test',
            'services.supabase.publishable_key' => 'sb_publishable_synthetic',
            'services.supabase.account_reactivation_enabled' => true, 'services.supabase.activation_cache_store' => 'array']);
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        $storage = Mockery::mock(InvitationActivationContext::class);
        $storage->shouldReceive('storage')->andReturn(Cache::store('array'));
        $storage->shouldReceive('checkStorage')->andReturnNull();
        $this->app->instance(InvitationActivationContext::class, $storage);
        $this->schema();
        $this->fixtures();
        $this->recoveryToken = $this->token(self::USER, self::OLD_SESSION, 'recovery');
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(fn (ClientRequest $request) => $this->provider($request));
        Route::middleware(['web', 'supabase.auth'])->get('/_tests/reactivation-access', fn () => response('Authorized account'));
        Route::middleware(['api', ApiRequestGuard::class, 'supabase.api.auth'])->get('/api/v1/_tests/reactivation-access', fn () => response()->json(['authorized' => true]));
        Route::middleware(['api', ApiRequestGuard::class, 'supabase.api.auth', 'mobile.association'])
            ->get('/api/v1/_tests/reactivation-association', fn () => response()->json(['authorized' => true]));
        $this->withSession(['supabase_access_token' => $this->token(self::ADMIN)]);
    }

    private function schema(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active');
            $table->timestamps();
        });
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('role_id');
            $table->string('display_name');
            $table->boolean('is_active');
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
        });
        Schema::create('user_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('association_id')->nullable();
            $table->uuid('auth_user_id')->nullable();
            $table->unsignedBigInteger('role_id');
            $table->string('email');
            $table->string('status');
            $table->string('last_error_code')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamps();
        });
        Schema::create('associations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('primary_field_officer_id');
            $table->string('name');
            $table->string('status');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::create('association_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('association_id');
            $table->boolean('is_active');
            $table->timestamp('ended_at')->nullable();
            $table->uuid('ended_by')->nullable();
            $table->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('actor_user_id')->nullable();
            $table->string('action_type');
            $table->string('module');
            $table->string('entity_type')->nullable();
            $table->string('entity_id')->nullable();
            $table->text('old_values')->nullable();
            $table->text('new_values')->nullable();
            $table->timestamp('performed_at');
        });
        (require database_path('migrations/2026_10_22_000000_add_account_reactivation.php'))->up();
        DB::statement("ATTACH DATABASE ':memory:' AS auth");
        DB::statement('CREATE TABLE auth.users (id TEXT PRIMARY KEY, email TEXT)');
        DB::statement('CREATE TABLE auth.sessions (id TEXT PRIMARY KEY, user_id TEXT, created_at TEXT)');
    }

    private function fixtures(): void
    {
        foreach ([1 => 'system_admin', 2 => 'field_officer', 3 => 'association_account'] as $id => $code) {
            DB::table('roles')->insert(['id' => $id, 'code' => $code, 'name' => ucfirst(str_replace('_', ' ', $code)), 'is_active' => true]);
        }
        foreach ([self::ADMIN => 1, self::USER => 2, self::OTHER => 2] as $id => $role) {
            DB::table('user_profiles')->insert(['id' => $id, 'role_id' => $role, 'display_name' => $id === self::USER ? 'Revoked holder' : 'Synthetic staff',
                'is_active' => $id !== self::USER, 'activated_at' => now()->subDays(2), 'created_at' => now()->subDays(3), 'updated_at' => now()]);
            DB::table('auth.users')->insert(['id' => $id, 'email' => $this->email($id)]);
        }
        DB::table('user_invitations')->insert(['id' => self::USER, 'auth_user_id' => self::USER, 'role_id' => 2,
            'email' => $this->email(self::USER), 'status' => 'accepted', 'accepted_at' => now()->subDays(2), 'created_at' => now()->subDays(3)]);
        DB::table('audit_logs')->insert(['id' => self::USER, 'actor_user_id' => self::ADMIN, 'action_type' => 'REVOKE USER ACCESS',
            'module' => 'User Management', 'entity_type' => 'user_profile', 'entity_id' => self::USER, 'performed_at' => now()->subDay()]);
        DB::table('associations')->insert(['id' => self::ASSOCIATION, 'primary_field_officer_id' => self::USER, 'name' => 'Retained association', 'status' => 'active']);
        DB::table('auth.sessions')->insert(['id' => self::OLD_SESSION, 'user_id' => self::USER, 'created_at' => now()->subDays(2)]);
    }

    private function email(string $id): string
    {
        return $id === self::USER ? 'holder@example.test' : ($id === self::ADMIN ? 'administrator@example.test' : 'other@example.test');
    }

    private function token(string $id, string $session = self::NEW_SESSION, string $method = 'password', ?int $proofAt = null): string
    {
        $encode = fn ($value) => rtrim(strtr(base64_encode(json_encode($value)), '+/', '-_'), '=');

        return $encode(['alg' => 'HS256']).'.'.$encode(['sub' => $id, 'iss' => 'https://auth.example.test/auth/v1',
            'role' => 'authenticated', 'aud' => 'authenticated', 'iat' => now()->timestamp, 'exp' => now()->addHour()->timestamp,
            'session_id' => $session, 'amr' => [['method' => $method, 'timestamp' => $proofAt ?? now()->timestamp]]]).'.synthetic_signature';
    }

    private function provider(ClientRequest $request)
    {
        if (str_contains($request->url(), '/auth/v1/recover?')) {
            $this->emails++;
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            $this->assertStringStartsWith('https://app.example.test/account-reactivation/', $query['redirect_to']);
            $this->reference = basename(parse_url($query['redirect_to'], PHP_URL_PATH));
            $this->assertSame($this->email(self::USER), $request['email']);

            return Http::response('{}', 200, ['Content-Type' => 'application/json']);
        }
        if (str_ends_with($request->url(), '/auth/v1/verify')) {
            $this->verifications++;
            $this->assertSame('recovery', $request['type']);
            if ($this->expiredProof) {
                return Http::response(['code' => 'otp_expired'], 403);
            }

            return Http::response(['access_token' => $this->recoveryToken, 'refresh_token' => 'synthetic_unused_refresh',
                'expires_in' => 3600, 'token_type' => 'bearer', 'user' => $this->identity(self::USER)]);
        }
        if (str_contains($request->url(), '/auth/v1/logout?scope=global')) {
            if ($this->logoutFails) {
                return Http::response('{}', 503);
            }
            DB::table('auth.sessions')->where('user_id', self::USER)->delete();

            return Http::response('', 204);
        }
        if (str_contains($request->url(), '/auth/v1/token?grant_type=password')) {
            $this->assertSame(self::PASSWORD, $request['password']);
            DB::table('auth.sessions')->insertOrIgnore(['id' => self::NEW_SESSION, 'user_id' => self::USER, 'created_at' => now()]);

            return Http::response(['access_token' => $this->token(self::USER), 'refresh_token' => 'synthetic_fresh_refresh', 'user' => $this->identity(self::USER)]);
        }
        if (str_contains($request->url(), '/auth/v1/token?grant_type=refresh_token')) {
            $this->assertSame('synthetic_retained_refresh', $request['refresh_token']);

            return Http::response(['code' => 'refresh_token_not_found'], 400);
        }
        $this->assertStringEndsWith('/auth/v1/user', $request->url());
        if ($request->method() === 'PUT') {
            $this->passwordWrites++;
            if ($this->cancelDuringWrite) {
                $actorRequest = new Request;
                $actorRequest->attributes->set('current_user', UserProfile::with('role')->findOrFail(self::ADMIN));
                app(UserAccessRevocationService::class)->revoke($actorRequest, UserProfile::findOrFail(self::USER));
            }
            if ($this->disableDuringWrite) {
                config(['services.supabase.account_reactivation_enabled' => false]);
            }
            if ($this->uncertain) {
                throw new ConnectionException('Synthetic private provider failure');
            }

            return Http::response($this->identity($this->wrongIdentity ? self::OTHER : self::USER));
        }
        $parts = explode('.', substr($request->header('Authorization')[0], 7));
        $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        return Http::response($this->identity($this->wrongIdentity ? self::OTHER : $claims['sub']));
    }

    private function identity(string $id): array
    {
        return ['id' => $id, 'email' => $this->email($id), 'email_confirmed_at' => '2026-10-01T00:00:00Z'];
    }

    private function url(): string
    {
        return '/admin/users/'.self::USER.'/reactivate';
    }

    private function accountRecords(): array
    {
        return collect(['user_profiles', 'user_invitations', 'associations', 'association_accounts',
            'account_reactivations', 'audit_logs', 'auth.users', 'auth.sessions'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    private function remember(TestResponse $response): TestResponse
    {
        if ($cookie = $response->getCookie(config('session.cookie'))) {
            $this->withCookie($cookie->getName(), $cookie->getValue());
        }

        return $response;
    }

    private function approve(): TestResponse
    {
        $review = $this->get($this->url())->assertOk()->viewData('review');

        return $this->remember($this->post($this->url(), ['review_hash' => $review['review_hash'], 'confirm_reactivation' => '1', 'confirm_relationships' => '1']));
    }

    private function verify(): TestResponse
    {
        $this->approve()->assertRedirect();
        $this->withSession(['supabase_access_token' => null, 'supabase_refresh_token' => null]);
        $this->remember($this->get('/account-reactivation/'.$this->reference))->assertOk();

        return $this->remember($this->post('/account-reactivation/'.$this->reference.'/verify', ['token_hash' => str_repeat('a', 56), 'sign_out_current_account' => '1']));
    }

    private function finish(): TestResponse
    {
        $this->verify()->assertRedirect(route('account-reactivation.password'));

        return $this->remember($this->post('/account-reactivation/password', ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD]));
    }

    public function test_holder_completes_admin_approved_reactivation_without_changing_identity_role_or_assignments(): void
    {
        $before = DB::table('associations')->get()->toJson();
        $activation = UserProfile::findOrFail(self::USER)->activated_at;
        $this->finish()->assertRedirect(route('account-reactivation.result'))->assertSessionHas('reactivation_completed', true)
            ->assertSessionMissing('supabase_access_token')->assertSessionMissing('supabase_refresh_token')->assertSessionMissing('password_recovery_context');
        $profile = UserProfile::findOrFail(self::USER);
        $this->assertTrue($profile->is_active);
        $this->assertEquals($activation, $profile->activated_at);
        $this->assertSame(2, $profile->role_id);
        $this->assertNotNull($profile->auth_sessions_valid_after);
        $this->assertSame($before, DB::table('associations')->get()->toJson());
        $this->assertSame('completed', DB::table('account_reactivations')->value('state'));
        $this->assertSame(1, $this->passwordWrites);
        $this->assertSame(1, DB::table('audit_logs')->where('action_type', 'REACTIVATE USER ACCESS')->count());
    }

    public function test_review_displays_all_retained_assignments_and_requires_current_review_and_both_confirmations(): void
    {
        $page = $this->get($this->url())->assertOk()->assertSee('Retained association')->assertSee('Active, unarchived associations become accessible again');
        $data = ['review_hash' => $page->viewData('review')['review_hash'], 'confirm_reactivation' => '1', 'confirm_relationships' => '1'];
        foreach (['confirm_relationships', 'confirm_reactivation'] as $field) {
            $missing = $data;
            unset($missing[$field]);
            $this->postJson($this->url(), $missing)->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        DB::table('associations')->update(['status' => 'inactive']);
        $this->postJson($this->url(), $data)->assertUnprocessable()->assertJsonValidationErrors('reactivation');
        $this->assertSame(0, $this->emails);
    }

    public static function ineligibleAccounts(): array
    {
        return array_map(fn ($state) => [$state], ['active', 'never_activated', 'no_original_invitation', 'not_revoked', 'disabled_role', 'pending', 'conflict', 'wrong_relationship', 'historical_wrong_relationship']);
    }

    #[DataProvider('ineligibleAccounts')]
    public function test_ineligible_accounts_cannot_receive_reactivation_email(string $state): void
    {
        match ($state) {
            'active' => DB::table('user_profiles')->where('id', self::USER)->update(['is_active' => true]),
            'never_activated' => DB::table('user_profiles')->where('id', self::USER)->update(['activated_at' => null]),
            'no_original_invitation' => DB::table('user_invitations')->delete(),
            'not_revoked' => DB::table('audit_logs')->delete(),
            'disabled_role' => DB::table('roles')->where('id', 2)->update(['is_active' => false]),
            'pending', 'conflict' => DB::table('user_invitations')->insert(['id' => self::OTHER, 'auth_user_id' => $state === 'pending' ? self::USER : null,
                'role_id' => 2, 'email' => 'pending@example.test', 'status' => 'sent', 'last_error_code' => $state === 'conflict' ? 'profile_identity_conflict:'.self::USER : null]),
            'wrong_relationship', 'historical_wrong_relationship' => DB::table('association_accounts')->insert(['id' => self::OTHER, 'user_id' => self::USER,
                'association_id' => self::ASSOCIATION, 'is_active' => $state === 'wrong_relationship']),
        };
        $this->get($this->url())->assertRedirect();
        $this->postJson($this->url(), ['review_hash' => str_repeat('a', 64), 'confirm_reactivation' => '1', 'confirm_relationships' => '1'])->assertUnprocessable();
        $this->assertSame(0, $this->emails);
        $this->assertDatabaseCount('account_reactivations', 0);
    }

    public function test_guests_other_roles_and_self_reactivation_are_denied(): void
    {
        $this->withSession(['supabase_access_token' => null])->get($this->url())->assertRedirect(route('login'));
        $this->withSession(['supabase_access_token' => $this->token(self::OTHER)])->get($this->url())->assertForbidden();
        $this->post($this->url(), ['confirm_reactivation' => '1'])->assertForbidden();
        try {
            app(AccountReactivationService::class)->review(UserProfile::findOrFail(self::ADMIN), UserProfile::findOrFail(self::ADMIN));
            $this->fail('Self-reactivation must be denied.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(0, $this->emails);
    }

    public function test_reference_without_independent_email_proof_cannot_restore_access(): void
    {
        $this->approve();
        $this->withSession(['supabase_access_token' => null]);
        $this->get('/account-reactivation/'.$this->reference)->assertOk()->assertDontSee($this->recoveryToken);
        $this->post('/account-reactivation/'.$this->reference.'/verify', [])->assertRedirect();
        $this->wrongIdentity = true;
        $this->post('/account-reactivation/'.$this->reference.'/verify', ['token_hash' => str_repeat('a', 56), 'sign_out_current_account' => '1'])->assertRedirect(route('account-reactivation.result'));
        $this->assertFalse(UserProfile::findOrFail(self::USER)->is_active);
        $this->assertSame(0, $this->passwordWrites);
    }

    private function associationAccount(): void
    {
        DB::table('user_profiles')->where('id', self::USER)->update(['role_id' => 3]);
        DB::table('user_invitations')->update(['role_id' => 3]);
        DB::table('associations')->update(['primary_field_officer_id' => self::OTHER]);
        DB::table('association_accounts')->insert(['id' => self::USER, 'user_id' => self::USER, 'association_id' => self::ASSOCIATION, 'is_active' => true]);
    }

    public static function reactivationRoles(): array
    {
        return [['field_officer'], ['association_account']];
    }

    #[DataProvider('reactivationRoles')]
    public function test_user_management_offers_existing_review_for_eligible_revoked_accounts_without_writes(string $role): void
    {
        if ($role === 'association_account') {
            $this->associationAccount();
        }
        $before = $this->accountRecords();
        $action = 'href="'.url($this->url()).'"';
        $this->get('/admin/users')->assertOk()->assertSee($action, false)->assertSee('Reactivate Account');
        $this->get('/admin/users/'.self::USER)->assertOk()->assertSee($action, false)
            ->assertSee('Eligibility is checked again during review and submission.');
        $review = $this->get($this->url())->assertOk()->viewData('review');
        $this->assertSame(self::USER, $review['target']->id);
        $this->assertSame($before, $this->accountRecords());
        $this->assertSame(0, $this->emails);
        $this->assertSame(0, $this->verifications);
        $this->assertSame(0, $this->passwordWrites);
        Http::assertNotSent(fn (ClientRequest $request) => $request->method() !== 'GET');
    }

    #[DataProvider('reactivationRoles')]
    public function test_disabled_reactivation_has_no_user_management_action_and_rejects_direct_requests(string $role): void
    {
        if ($role === 'association_account') {
            $this->associationAccount();
        }
        config(['services.supabase.account_reactivation_enabled' => false]);
        $before = $this->accountRecords();
        $action = 'href="'.url($this->url()).'"';
        $this->get('/admin/users')->assertOk()->assertDontSee($action, false)->assertDontSee('Reactivate Account');
        $this->get('/admin/users/'.self::USER)->assertOk()->assertDontSee($action, false)->assertDontSee('Reactivate Account');
        $this->get($this->url())->assertNotFound();
        $this->postJson($this->url(), ['review_hash' => str_repeat('a', 64),
            'confirm_reactivation' => '1', 'confirm_relationships' => '1'])->assertNotFound();
        $this->assertSame($before, $this->accountRecords());
        $this->assertSame(0, $this->emails);
        $this->assertSame(0, $this->verifications);
        $this->assertSame(0, $this->passwordWrites);
        Http::assertNotSent(fn (ClientRequest $request) => $request->method() !== 'GET');
    }

    public function test_relationship_is_rechecked_if_the_link_ends_after_reactivation_review(): void
    {
        $this->associationAccount();
        $review = $this->get($this->url())->assertOk()->viewData('review');
        DB::table('association_accounts')->update(['is_active' => false]);
        $before = $this->accountRecords();
        $this->postJson($this->url(), ['review_hash' => $review['review_hash'],
            'confirm_reactivation' => '1', 'confirm_relationships' => '1'])->assertUnprocessable()
            ->assertJsonPath('errors.reactivation.0', AccountReactivationService::ASSOCIATION_APPOINTMENT_REQUIRED);
        $this->assertSame($before, $this->accountRecords());
        $this->assertSame(0, $this->emails);
        $this->assertSame(0, $this->passwordWrites);
        Http::assertNotSent(fn (ClientRequest $request) => $request->method() !== 'GET');
    }

    public function test_association_account_preserves_the_same_unique_active_link(): void
    {
        $this->associationAccount();
        $before = DB::table('association_accounts')->get()->toJson();
        $this->finish()->assertSessionHas('reactivation_completed', true);
        $this->assertSame($before, DB::table('association_accounts')->get()->toJson());
        $this->assertSame(3, UserProfile::findOrFail(self::USER)->role_id);
    }

    public function test_reactivation_retains_only_the_existing_active_appointment_and_ignores_ended_history(): void
    {
        $this->associationAccount();
        DB::table('association_accounts')->insert(['id' => self::OLD_SESSION, 'user_id' => self::USER,
            'association_id' => self::ASSOCIATION, 'is_active' => false, 'ended_at' => now()->subDay(), 'ended_by' => self::ADMIN]);
        $before = DB::table('association_accounts')->orderBy('id')->get()->toJson();
        $this->get('/admin/users/'.self::USER)->assertOk()->assertSee('Reactivate Account')->assertSee('Ended association appointments');
        $this->finish()->assertSessionHas('reactivation_completed', true);
        $this->assertSame($before, DB::table('association_accounts')->orderBy('id')->get()->toJson());
        $this->assertSame(self::USER, DB::table('association_accounts')->where('is_active', true)->sole()->id);
        $this->assertFalse((bool) DB::table('association_accounts')->where('id', self::OLD_SESSION)->value('is_active'));
    }

    public function test_reactivated_association_account_needs_fresh_password_authentication_at_the_mobile_association_boundary(): void
    {
        $this->associationAccount();
        $old = $this->token(self::USER, self::OLD_SESSION, 'password', now()->subDays(2)->timestamp);
        $this->finish()->assertSessionHas('reactivation_completed', true);
        $this->withHeader('Authorization', 'Bearer '.$old)->getJson('/api/v1/_tests/reactivation-association')->assertForbidden();
        $this->travel(2)->seconds();
        $signedIn = Http::post('https://auth.example.test/auth/v1/token?grant_type=password',
            ['email' => $this->email(self::USER), 'password' => self::PASSWORD]);
        $this->withHeader('Authorization', 'Bearer '.$signedIn->json('access_token'))
            ->getJson('/api/v1/_tests/reactivation-association')->assertOk()->assertJsonPath('authorized', true);
        $this->assertSame(self::ASSOCIATION, DB::table('association_accounts')->value('association_id'));
        $this->travelBack();
    }

    public static function invalidLinks(): array
    {
        return [['missing'], ['inactive'], ['duplicate_user'], ['duplicate_association'], ['inactive_association'], ['archived_association'], ['field_officer_assignment']];
    }

    #[DataProvider('invalidLinks')]
    public function test_association_account_relationship_integrity_cannot_be_bypassed(string $state): void
    {
        $this->associationAccount();
        match ($state) {
            'missing' => DB::table('association_accounts')->delete(),
            'inactive' => DB::table('association_accounts')->update(['is_active' => false]),
            'duplicate_user' => DB::table('association_accounts')->insert(['id' => self::OTHER, 'user_id' => self::USER, 'association_id' => self::OTHER, 'is_active' => true]),
            'duplicate_association' => DB::table('association_accounts')->insert(['id' => self::OTHER, 'user_id' => self::OTHER, 'association_id' => self::ASSOCIATION, 'is_active' => true]),
            'inactive_association' => DB::table('associations')->update(['status' => 'inactive']),
            'archived_association' => DB::table('associations')->update(['archived_at' => now()]),
            'field_officer_assignment' => DB::table('associations')->update(['primary_field_officer_id' => self::USER]),
        };
        $before = $this->accountRecords();
        $action = 'href="'.url($this->url()).'"';
        $reason = match ($state) {
            'missing', 'inactive' => AccountReactivationService::ASSOCIATION_APPOINTMENT_REQUIRED,
            'inactive_association', 'archived_association' => 'The linked association must be active and unarchived before reactivation.',
            'field_officer_assignment' => 'Conflicting Field Officer assignments need review before reactivation.',
            default => 'Account relationships need review before reactivation.',
        };
        $this->get('/admin/users')->assertOk()->assertDontSee($action, false)->assertSee($reason);
        $details = $this->get('/admin/users/'.self::USER)->assertOk()->assertDontSee($action, false)->assertSee($reason);
        $details->assertSee($details->viewData('account')['link_active'] === true ? 'End Retained Appointment' : 'Cancel Pending Reactivation');
        $this->get($this->url())->assertRedirect('/admin/users/'.self::USER)->assertSessionHasErrors('reactivation');
        $denied = $this->postJson($this->url(), ['review_hash' => str_repeat('a', 64),
            'confirm_reactivation' => '1', 'confirm_relationships' => '1'])
            ->assertUnprocessable()->assertJsonValidationErrors('reactivation')->assertSessionMissing('password_recovery_context');
        if (in_array($state, ['missing', 'inactive'], true)) {
            $denied->assertJsonPath('errors.reactivation.0', AccountReactivationService::ASSOCIATION_APPOINTMENT_REQUIRED);
        }
        $this->assertSame($before, $this->accountRecords());
        $this->assertSame(0, $this->emails);
        $this->assertSame(0, $this->verifications);
        $this->assertSame(0, $this->passwordWrites);
        Http::assertNotSent(fn (ClientRequest $request) => $request->method() !== 'GET');
    }

    public function test_old_sessions_bearers_and_refreshed_old_sessions_stay_denied_while_fresh_password_signin_succeeds(): void
    {
        $old = $this->token(self::USER, self::OLD_SESSION, 'password', now()->subDays(2)->timestamp);
        $this->finish()->assertSessionHas('reactivation_completed', true);
        $refresh = Http::post('https://auth.example.test/auth/v1/token?grant_type=refresh_token', ['refresh_token' => 'synthetic_retained_refresh']);
        $this->assertSame(400, $refresh->status());
        $this->withSession(['supabase_access_token' => $old])->get('/_tests/reactivation-access')->assertRedirect(route('login'));
        $this->withHeader('Authorization', 'Bearer '.$old)->getJson('/api/v1/_tests/reactivation-access')->assertForbidden();
        $this->travel(2)->seconds();
        // Even a newly issued token cannot promote the originating old session.
        DB::table('auth.sessions')->insert(['id' => self::OLD_SESSION, 'user_id' => self::USER, 'created_at' => now()->subDays(2)]);
        $refreshed = $this->token(self::USER, self::OLD_SESSION);
        $this->withHeader('Authorization', 'Bearer '.$refreshed)->getJson('/api/v1/_tests/reactivation-access')->assertForbidden();
        $this->withSession(['supabase_access_token' => $refreshed])->get('/_tests/reactivation-access')->assertRedirect(route('login'));
        $this->withHeader('Authorization', '')->post('/login', ['email' => $this->email(self::USER), 'password' => self::PASSWORD])
            ->assertRedirect(route('field-officer.dashboard'))->assertSessionHas('supabase_access_token');
        $this->get('/_tests/reactivation-access')->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$this->token(self::USER))->getJson('/api/v1/_tests/reactivation-access')->assertOk();
        $this->travelBack();
    }

    public static function changedApprovals(): array
    {
        return [['expired'], ['assignments'], ['revocation'], ['administrator'], ['email'], ['role']];
    }

    #[DataProvider('changedApprovals')]
    public function test_changed_or_expired_approvals_fail_closed_before_password_write(string $state): void
    {
        $this->verify()->assertRedirect(route('account-reactivation.password'));
        match ($state) {
            'expired' => DB::table('account_reactivations')->update(['expires_at' => now()->subSecond()]),
            'assignments' => DB::table('associations')->update(['status' => 'inactive']),
            'revocation' => DB::table('audit_logs')->insert(['id' => self::OTHER, 'actor_user_id' => self::ADMIN, 'action_type' => 'REVOKE USER ACCESS', 'module' => 'User Management', 'entity_type' => 'user_profile', 'entity_id' => self::USER, 'performed_at' => now()]),
            'administrator' => DB::table('user_profiles')->where('id', self::ADMIN)->update(['is_active' => false]),
            'email' => DB::table('auth.users')->where('id', self::USER)->update(['email' => 'changed@example.test']),
            'role' => DB::table('roles')->where('id', 2)->update(['is_active' => false]),
        };
        $this->post('/account-reactivation/password', ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertRedirect(route('account-reactivation.result'));
        $this->assertFalse(UserProfile::findOrFail(self::USER)->is_active);
        $this->assertSame(0, $this->passwordWrites);
    }

    public static function auditFailures(): array
    {
        return [['request'], ['verify'], ['complete']];
    }

    #[DataProvider('auditFailures')]
    public function test_required_audit_failure_cannot_issue_authority_or_restore_access(string $stage): void
    {
        $action = match ($stage) {
            'request' => 'REQUEST ACCOUNT REACTIVATION', 'verify' => 'VERIFY ACCOUNT REACTIVATION', default => 'REACTIVATE USER ACCESS'
        };
        if ($stage === 'complete') {
            $this->verify()->assertRedirect(route('account-reactivation.password'));
        }
        if ($stage === 'verify') {
            $this->approve();
            $this->withSession(['supabase_access_token' => null]);
        }
        DB::statement("CREATE TRIGGER reject_reactivation_audit BEFORE INSERT ON audit_logs WHEN NEW.action_type = '$action' BEGIN SELECT RAISE(ABORT, 'Synthetic audit failure'); END");
        if ($stage === 'request') {
            $this->approve();
            $this->assertSame(0, $this->emails);
            $this->assertDatabaseCount('account_reactivations', 0);
        } elseif ($stage === 'verify') {
            $this->post('/account-reactivation/'.$this->reference.'/verify', ['token_hash' => str_repeat('a', 56), 'sign_out_current_account' => '1'])->assertSessionMissing('password_recovery_context');
            $this->assertSame(0, $this->verifications);
        } else {
            $this->post('/account-reactivation/password', ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD]);
            $this->assertSame('password_started', DB::table('account_reactivations')->value('state'));
        }
        $this->assertFalse(UserProfile::findOrFail(self::USER)->is_active);
        $this->assertNull(UserProfile::findOrFail(self::USER)->auth_sessions_valid_after);
    }

    public function test_uncertain_password_or_signout_is_not_retried_or_reactivated(): void
    {
        $this->verify()->assertRedirect(route('account-reactivation.password'));
        $this->uncertain = true;
        $data = ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD];
        $this->post('/account-reactivation/password', $data)->assertRedirect(route('account-reactivation.result'));
        $this->post('/account-reactivation/password', $data)->assertRedirect(route('account-reactivation.result'));
        $this->assertSame(1, $this->passwordWrites);
        $this->assertFalse(UserProfile::findOrFail(self::USER)->is_active);
        $this->assertSame('password_started', DB::table('account_reactivations')->value('state'));
        $this->withSession(['supabase_access_token' => $this->token(self::ADMIN)]);
        $this->approve()->assertSessionHasErrors('reactivation');
        $this->assertSame(1, $this->emails);
    }

    public function test_signout_failure_keeps_the_profile_inactive(): void
    {
        $this->logoutFails = true;
        $this->finish()->assertRedirect(route('account-reactivation.result'))->assertSessionMissing('password_recovery_context');
        $this->assertFalse(UserProfile::findOrFail(self::USER)->is_active);
        $this->assertSame('password_started', DB::table('account_reactivations')->value('state'));
    }

    public function test_repeated_requests_and_verification_are_single_use_and_feature_disable_blocks_existing_requests(): void
    {
        $this->approve();
        $this->approve()->assertSessionHasErrors('reactivation');
        $this->assertSame(1, $this->emails);
        $this->withSession(['supabase_access_token' => null]);
        config(['services.supabase.account_reactivation_enabled' => false]);
        $this->post('/account-reactivation/'.$this->reference.'/verify', ['token_hash' => str_repeat('a', 56)])->assertNotFound();
        $this->assertSame(0, $this->verifications);
        config(['services.supabase.account_reactivation_enabled' => true]);
        $this->remember($this->post('/account-reactivation/'.$this->reference.'/verify', ['token_hash' => str_repeat('a', 56), 'sign_out_current_account' => '1']))->assertRedirect(route('account-reactivation.password'));
        $this->post('/account-reactivation/'.$this->reference.'/verify', ['token_hash' => str_repeat('a', 56)])->assertRedirect(route('account-reactivation.result'));
        $this->assertSame(1, $this->verifications);
        $this->assertFalse(UserProfile::findOrFail(self::USER)->is_active);
    }

    public function test_ordinary_forgot_password_cannot_reactivate_a_revoked_profile(): void
    {
        $this->withSession(['supabase_access_token' => null]);
        $this->post('/password-recovery/verify', ['token_hash' => str_repeat('a', 56)])->assertRedirect(route('password.forgot'))->assertSessionMissing('password_recovery_context');
        $this->assertFalse(UserProfile::findOrFail(self::USER)->is_active);
        $this->assertSame(0, $this->passwordWrites);
    }

    public function test_revocation_cancels_an_inactive_accounts_pending_approval(): void
    {
        $this->verify()->assertRedirect(route('account-reactivation.password'));
        $request = new Request;
        $request->attributes->set('current_user', UserProfile::with('role')->findOrFail(self::ADMIN));
        $this->assertTrue(app(UserAccessRevocationService::class)->revoke($request, UserProfile::findOrFail(self::USER)));
        $this->assertNotNull(DB::table('account_reactivations')->value('cancelled_at'));
        $this->post('/account-reactivation/password', ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertRedirect(route('account-reactivation.result'));
        $this->assertFalse(UserProfile::findOrFail(self::USER)->is_active);
        $this->assertSame(0, $this->passwordWrites);
        $this->assertFalse(app(UserAccessRevocationService::class)->revoke($request, UserProfile::findOrFail(self::USER)));
    }

    public function test_audit_failure_after_provider_verification_does_not_issue_a_password_context(): void
    {
        $this->approve();
        DB::statement("CREATE TRIGGER reject_verified_audit BEFORE INSERT ON audit_logs WHEN NEW.action_type = 'VERIFY ACCOUNT REACTIVATION' AND NEW.new_values LIKE '%\"outcome\":\"verified\"%' BEGIN SELECT RAISE(ABORT, 'Synthetic verified audit failure'); END");
        $this->post('/account-reactivation/'.$this->reference.'/verify', ['token_hash' => str_repeat('a', 56), 'sign_out_current_account' => '1'])
            ->assertRedirect(route('account-reactivation.result'))->assertSessionMissing('password_recovery_context');
        $this->assertSame(1, $this->verifications);
        $this->assertSame('verifying', DB::table('account_reactivations')->value('state'));
        $this->assertFalse(UserProfile::findOrFail(self::USER)->is_active);
    }

    public function test_expired_provider_proof_cannot_create_a_context(): void
    {
        $this->expiredProof = true;
        $this->verify()->assertRedirect(route('account-reactivation.result'))->assertSessionMissing('password_recovery_context');
        $this->assertSame(0, $this->passwordWrites);
        $this->assertFalse(UserProfile::findOrFail(self::USER)->is_active);
    }

    public function test_completed_password_update_cannot_be_replayed(): void
    {
        $this->finish()->assertSessionHas('reactivation_completed', true);
        $this->post('/account-reactivation/password', ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertRedirect(route('account-reactivation.result'));
        $this->assertSame(1, $this->passwordWrites);
        $this->assertSame(1, DB::table('audit_logs')->where('action_type', 'REACTIVATE USER ACCESS')->count());
    }

    public function test_request_and_verification_require_native_csrf_protection(): void
    {
        $this->approve();
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->post($this->url(), ['confirm_reactivation' => '1', 'confirm_relationships' => '1', 'review_hash' => str_repeat('a', 64)])->assertStatus(419);
        $this->post('/account-reactivation/'.$this->reference.'/verify', ['token_hash' => str_repeat('a', 56), 'sign_out_current_account' => '1'])->assertStatus(419);
        $this->assertSame(1, $this->emails);
        $this->assertSame(0, $this->verifications);
        $this->assertFalse(UserProfile::findOrFail(self::USER)->is_active);
    }

    public function test_request_is_not_issued_when_the_server_cannot_enforce_provider_session_checks(): void
    {
        DB::statement('DROP TABLE auth.sessions');
        $this->approve()->assertSessionHas('error');
        $this->assertSame(0, $this->emails);
        $this->assertDatabaseCount('account_reactivations', 0);
    }

    public function test_reactivation_throttling_retains_its_own_error_context_without_provider_requests(): void
    {
        $this->withSession(['supabase_access_token' => null]);
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->get('/account-reactivation/'.str_repeat('a', 64))->assertOk();
        }
        $this->get('/account-reactivation/'.str_repeat('a', 64))->assertStatus(429)
            ->assertSee('Too many reactivation requests')->assertDontSee('name="email"', false);
        Http::assertNothingSent();
    }

    public static function interruptedWrites(): array
    {
        return [['revocation'], ['feature_disable']];
    }

    #[DataProvider('interruptedWrites')]
    public function test_an_interleaved_revocation_or_feature_disable_cannot_restore_access_after_the_provider_write(string $interruption): void
    {
        $this->verify()->assertRedirect(route('account-reactivation.password'));
        $this->cancelDuringWrite = $interruption === 'revocation';
        $this->disableDuringWrite = $interruption === 'feature_disable';
        $this->post('/account-reactivation/password', ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertRedirect(route('account-reactivation.result'));
        $this->assertSame(1, $this->passwordWrites);
        $this->assertFalse(UserProfile::findOrFail(self::USER)->is_active);
        $this->assertSame('password_started', DB::table('account_reactivations')->value('state'));
        $this->assertSame(0, DB::table('audit_logs')->where('action_type', 'REACTIVATE USER ACCESS')->count());
    }
}
