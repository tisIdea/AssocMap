<?php

namespace Tests\Feature;

use App\Http\Middleware\ApiRequestGuard;
use App\Http\Middleware\EnsureMobileAssociationAccess;
use App\Models\UserProfile;
use App\Services\MobileAssociationAccessService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class MobileAuthenticationTest extends TestCase
{
    private const USER = '11111111-1111-4111-8111-111111111111';
    private const ASSOCIATION = '22222222-2222-4222-8222-222222222222';
    private const LINK = '33333333-3333-4333-8333-333333333333';
    private const OWN_RECORD = '44444444-4444-4444-8444-444444444444';
    private const OTHER_RECORD = '55555555-5555-4555-8555-555555555555';
    private const OTHER_ASSOCIATION = '66666666-6666-4666-8666-666666666666';
    private const TOKEN = 'synthetic.header.signature';
    private const AUTH_URL = 'https://auth.example.test/auth/v1/user';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.mobile_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ], 'services.supabase.url' => 'https://auth.example.test',
            'services.supabase.publishable_key' => 'sb_publishable_synthetic_test_only',
            'services.supabase.service_role_key' => null]);
        DB::setDefaultConnection('mobile_test');
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        $this->fakeProvider(Http::response($this->identity() + [
            'user_metadata' => ['role' => 'system_admin', 'private_note' => 'withheld'],
        ]));
        $this->schema();
        $this->fixture();

        // These routes exist only inside the isolated test application.
        Route::middleware([ApiRequestGuard::class, 'supabase.api.auth', 'mobile.association'])
            ->get('/api/v1/_mobile-test/records/{record}', function (Request $request, string $record) {
                $row = app(MobileAssociationAccessService::class)->scope(
                    DB::table('mobile_test_records'), $request->attributes->get('supabase_identity')
                )->where('id', $record)->first();
                abort_unless($row, 404);

                return response()->json(['data' => ['id' => $row->id]]);
            });
    }

    protected function tearDown(): void
    {
        DB::purge('mobile_test');
        parent::tearDown();
    }

    private function schema(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id(); $table->string('code'); $table->string('name'); $table->boolean('is_active'); $table->timestamps();
        });
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->unsignedBigInteger('role_id');
            $table->string('display_name'); $table->string('contact_number')->nullable();
            $table->boolean('is_active'); $table->timestamp('activated_at')->nullable(); $table->timestamps();
        });
        Schema::create('associations', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->string('name'); $table->string('status');
            $table->timestamp('archived_at')->nullable(); $table->timestamps();
        });
        // Deliberately permit inconsistent fixtures to exercise fail-closed relationship checks.
        Schema::create('association_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('user_id'); $table->uuid('association_id');
            $table->boolean('is_active'); $table->timestamps();
        });
        Schema::create('user_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->string('email'); $table->uuid('auth_user_id')->nullable();
            $table->string('status'); $table->string('last_error_code')->nullable();
            $table->timestamp('accepted_at')->nullable(); $table->timestamp('last_attempt_at')->nullable(); $table->timestamps();
        });
        Schema::create('mobile_test_records', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('association_id');
        });
    }

    private function fixture(): void
    {
        DB::table('roles')->insert(['id' => 1, 'code' => 'association_account', 'name' => 'Association Account',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_profiles')->insert(['id' => self::USER, 'role_id' => 1,
            'display_name' => 'Synthetic Association Account', 'contact_number' => 'private-test-contact',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('associations')->insert(['id' => self::ASSOCIATION, 'name' => 'Synthetic Test Association',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('association_accounts')->insert(['id' => self::LINK, 'user_id' => self::USER,
            'association_id' => self::ASSOCIATION, 'is_active' => true]);
        DB::table('mobile_test_records')->insert([
            ['id' => self::OWN_RECORD, 'association_id' => self::ASSOCIATION],
            ['id' => self::OTHER_RECORD, 'association_id' => self::OTHER_ASSOCIATION],
        ]);
    }

    private function identity(): array
    {
        return ['id' => self::USER, 'email' => 'synthetic@example.test'];
    }

    #[DataProvider('protectedMobileEndpoints')]
    public function test_actual_mobile_endpoints_reject_missing_identity_and_currently_inactive_accounts(string $endpoint): void
    {
        $this->getJson($endpoint)->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
        Http::assertNothingSent();
        DB::table('user_profiles')->update(['is_active' => false]);
        $response = $this->getJson($endpoint, ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertForbidden()->assertJsonPath('error.code', 'account_unavailable');
        $this->assertPrivateResponse($response);
    }

    public static function protectedMobileEndpoints(): array
    {
        return array_map(fn ($path) => ['/api/v1/'.$path],
            ['me', 'association/dashboard', 'association/reports', 'association/reports/options']);
    }

    private function fakeProvider(mixed $response): void
    {
        // Laravel accumulates fakes; replace the factory while retaining network isolation.
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake([self::AUTH_URL => $response]);
    }

    private function me(): TestResponse
    {
        return $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.self::TOKEN]);
    }

    private function assertPrivateResponse(TestResponse $response): void
    {
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Referrer-Policy', 'no-referrer');
        foreach ([self::TOKEN, 'sb_publishable_synthetic_test_only', 'synthetic@example.test',
            'private-test-contact', 'withheld'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $response->getContent());
        }
    }

    public function test_eligible_legacy_account_preserves_existing_identity_fields(): void
    {
        $this->assertNull(UserProfile::find(self::USER)->activated_at);
        $response = $this->me()->assertOk()->assertExactJson(['data' => [
            'id' => self::USER, 'display_name' => 'Synthetic Association Account',
            'role' => ['code' => 'association_account', 'name' => 'Association Account'], 'is_active' => true,
            'mobile' => ['eligible' => true, 'reason' => null,
                'association' => ['id' => self::ASSOCIATION, 'name' => 'Synthetic Test Association']],
        ]]);
        $this->assertPrivateResponse($response);
        Http::assertSentCount(1);
    }

    public function test_state_four_area_free_association_keeps_mobile_eligibility_privacy_and_scope(): void
    {
        config(['associations.geography_schema_ready' => true, 'associations.direct_geography' => true]);
        Schema::create('municipalities', fn (Blueprint $table) => $table->uuid('id')->primary());
        Schema::create('barangays', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignUuid('municipality_id')->constrained('municipalities');
        });
        Schema::create('areas', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->foreignUuid('barangay_id')->constrained('barangays');
            $table->unique(['id', 'barangay_id']);
        });
        DB::table('municipalities')->insert(['id' => self::USER]);
        DB::table('barangays')->insert(['id' => self::LINK, 'municipality_id' => self::USER]);
        Schema::table('associations', function (Blueprint $table) {
            $table->uuid('area_id')->nullable(); $table->uuid('barangay_id')->nullable();
            $table->text('specific_address')->nullable();
        });
        DB::table('associations')->update(['barangay_id' => self::LINK, 'specific_address' => 'Private independent address']);
        Schema::table('associations', function (Blueprint $table) {
            $table->uuid('barangay_id')->nullable(false)->change();
            $table->foreign('barangay_id')->references('id')->on('barangays')->restrictOnDelete();
            $table->foreign(['area_id', 'barangay_id'])->references(['id', 'barangay_id'])->on('areas')->restrictOnDelete()->restrictOnUpdate();
        });
        $before = DB::table('associations')->orderBy('id')->get()->toJson();
        $response = $this->me()->assertOk()->assertJsonPath('data.mobile.eligible', true)
            ->assertJsonPath('data.mobile.association.id', self::ASSOCIATION);
        $this->assertPrivateResponse($response);
        $this->assertStringNotContainsString('Private independent address', $response->getContent());
        $headers = ['Authorization' => 'Bearer '.self::TOKEN];
        $this->getJson('/api/v1/_mobile-test/records/'.self::OWN_RECORD.'?association_id='.self::OTHER_ASSOCIATION, $headers)
            ->assertOk()->assertExactJson(['data' => ['id' => self::OWN_RECORD]]);
        $this->getJson('/api/v1/_mobile-test/records/'.self::OTHER_RECORD, $headers)->assertNotFound();
        $this->assertSame($before, DB::table('associations')->orderBy('id')->get()->toJson());
    }

    #[DataProvider('otherRoles')]
    public function test_other_authorized_roles_receive_ineligible_context(string $code, string $name): void
    {
        DB::table('roles')->update(['code' => $code, 'name' => $name]);
        $response = $this->me()->assertOk()->assertJsonPath('data.role.code', $code)
            ->assertJsonPath('data.mobile', ['eligible' => false, 'reason' => 'role_not_supported', 'association' => null]);
        $this->assertPrivateResponse($response);
        $this->getJson('/api/v1/_mobile-test/records/'.self::OWN_RECORD, ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertForbidden()->assertJsonPath('error.code', 'mobile_access_denied');
    }

    public static function otherRoles(): array
    {
        return [['system_admin', 'System Administrator'], ['field_officer', 'Field Officer']];
    }

    #[DataProvider('accountDenials')]
    public function test_unavailable_accounts_are_forbidden(string $scenario): void
    {
        match ($scenario) {
            'missing_profile' => DB::table('user_profiles')->delete(),
            'inactive_profile' => DB::table('user_profiles')->update(['is_active' => false]),
            'inactive_role' => DB::table('roles')->update(['is_active' => false]),
            'missing_role' => DB::table('roles')->delete(),
        };
        $response = $this->me()->assertForbidden()->assertJsonPath('error.code', 'account_unavailable');
        $this->assertPrivateResponse($response);
        $this->assertArrayNotHasKey('data', $response->json());
    }

    public static function accountDenials(): array
    {
        return [['missing_profile'], ['inactive_profile'], ['inactive_role'], ['missing_role']];
    }

    #[DataProvider('linkDenials')]
    public function test_invalid_relationships_fail_closed_without_association_details(string $scenario, string $reason): void
    {
        match ($scenario) {
            'missing_link' => DB::table('association_accounts')->delete(),
            'inactive_link' => DB::table('association_accounts')->update(['is_active' => false]),
            'missing_association' => DB::table('associations')->delete(),
            'inactive_association' => DB::table('associations')->update(['status' => 'inactive']),
            'archived_association' => DB::table('associations')->update(['archived_at' => now()]),
            'blank_name' => DB::table('associations')->update(['name' => ' ']),
            'invalid_link_id' => DB::table('association_accounts')->update(['id' => 'invalid']),
            'duplicate_user_link' => DB::table('association_accounts')->insert([
                'id' => self::OTHER_RECORD, 'user_id' => self::USER,
                'association_id' => self::OTHER_ASSOCIATION, 'is_active' => true,
            ]),
            'shared_association' => DB::table('association_accounts')->insert([
                'id' => self::OTHER_RECORD, 'user_id' => self::OTHER_RECORD,
                'association_id' => self::ASSOCIATION, 'is_active' => true,
            ]),
        };
        $response = $this->me()->assertOk()->assertJsonPath('data.mobile', [
            'eligible' => false, 'reason' => $reason, 'association' => null,
        ]);
        $this->assertPrivateResponse($response);
        $denied = $this->getJson('/api/v1/_mobile-test/records/'.self::OWN_RECORD,
            ['Authorization' => 'Bearer '.self::TOKEN])->assertForbidden()->assertJsonPath('error.code', 'mobile_access_denied');
        $this->assertPrivateResponse($denied);
    }

    public static function linkDenials(): array
    {
        return [
            ['missing_link', 'association_link_unavailable'], ['inactive_link', 'association_link_unavailable'],
            ['invalid_link_id', 'association_link_unavailable'], ['duplicate_user_link', 'association_link_unavailable'],
            ['shared_association', 'association_link_unavailable'], ['missing_association', 'association_unavailable'],
            ['inactive_association', 'association_unavailable'], ['archived_association', 'association_unavailable'],
            ['blank_name', 'association_unavailable'],
        ];
    }

    #[DataProvider('invitationStates')]
    public function test_existing_invitation_restrictions_are_preserved(string $status): void
    {
        DB::table('user_invitations')->insert(['id' => self::LINK, 'email' => 'synthetic@example.test',
            'auth_user_id' => self::USER, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        $response = $this->me()->assertForbidden()->assertJsonPath('error.code', 'account_unavailable');
        $this->assertPrivateResponse($response);
    }

    public static function invitationStates(): array
    {
        return [['pending'], ['processing'], ['sent'], ['failed'], ['cancelled'], ['expired']];
    }

    public function test_accepted_invitation_allows_access_and_unbound_identity_conflict_denies_it(): void
    {
        DB::table('user_invitations')->insert(['id' => self::LINK, 'email' => 'synthetic@example.test',
            'auth_user_id' => self::USER, 'status' => 'accepted', 'accepted_at' => now(),
            'created_at' => now(), 'updated_at' => now()]);
        $this->me()->assertOk()->assertJsonPath('data.mobile.eligible', true);
        DB::table('user_invitations')->delete();
        DB::table('user_invitations')->insert(['id' => self::LINK, 'email' => 'synthetic@example.test',
            'auth_user_id' => null, 'status' => 'failed', 'last_error_code' => 'provider_outcome_unknown',
            'created_at' => now(), 'updated_at' => now()]);
        $this->me()->assertForbidden()->assertJsonPath('error.code', 'account_unavailable');
    }

    public function test_missing_and_malformed_tokens_are_unauthenticated_without_provider_requests(): void
    {
        $response = $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated')
            ->assertHeader('WWW-Authenticate', 'Bearer');
        $this->assertPrivateResponse($response);
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer invalid'])->assertUnauthorized();
        $this->getJson('/api/v1/me?access_token=synthetic')->assertUnauthorized();
        Http::assertNothingSent();
    }

    #[DataProvider('providerErrors')]
    public function test_provider_failures_have_safe_http_classification(int $providerStatus, array $body, int $status, string $code): void
    {
        $this->fakeProvider(Http::response($body + ['msg' => 'private-provider-detail'], $providerStatus));
        $response = $this->me()->assertStatus($status)->assertJsonPath('error.code', $code);
        $this->assertPrivateResponse($response);
        $this->assertStringNotContainsString('private-provider-detail', $response->getContent());
        Http::assertSentCount(1);
    }

    public static function providerErrors(): array
    {
        return [
            [403, ['error_code' => 'bad_jwt'], 401, 'unauthenticated'],
            [401, ['error_code' => 'session_expired'], 401, 'unauthenticated'],
            [403, ['error_code' => 'session_not_found'], 401, 'unauthenticated'],
            [403, ['error_code' => 'user_banned'], 403, 'account_unavailable'],
            [503, [], 503, 'authentication_unavailable'],
            [401, ['message' => 'Invalid API key'], 503, 'authentication_unavailable'],
        ];
    }

    public function test_timeout_and_malformed_provider_success_are_retryable_unavailability(): void
    {
        $this->fakeProvider(Http::failedConnection('private-timeout-context'));
        $response = $this->me()->assertStatus(503)->assertJsonPath('error.code', 'authentication_unavailable');
        $this->assertPrivateResponse($response);
        Http::assertSentCount(1);
        $this->fakeProvider(Http::response('{', 200));
        $this->me()->assertStatus(503)->assertJsonPath('error.code', 'authentication_unavailable');
    }

    #[DataProvider('databaseFailures')]
    public function test_database_failures_are_unavailable_and_never_grant_context(string $table): void
    {
        Schema::drop($table);
        $response = $this->me()->assertStatus(503)->assertJsonPath('error.code', 'service_unavailable');
        $this->assertPrivateResponse($response);
        $this->assertArrayNotHasKey('data', $response->json());
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    public static function databaseFailures(): array
    {
        return [['user_profiles'], ['roles'], ['user_invitations'], ['association_accounts'], ['associations']];
    }

    public function test_scope_excludes_other_associations_and_ignores_client_association_selection(): void
    {
        $headers = ['Authorization' => 'Bearer '.self::TOKEN];
        $this->getJson('/api/v1/_mobile-test/records/'.self::OWN_RECORD.'?association_id='.self::OTHER_ASSOCIATION, $headers)
            ->assertOk()->assertExactJson(['data' => ['id' => self::OWN_RECORD]]);
        $response = $this->getJson('/api/v1/_mobile-test/records/'.self::OTHER_RECORD, $headers)
            ->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->assertPrivateResponse($response);
        DB::table('association_accounts')->update(['is_active' => false]);
        $this->getJson('/api/v1/_mobile-test/records/'.self::OWN_RECORD, $headers)->assertForbidden();
    }

    public function test_guard_revalidates_current_state_instead_of_cached_eligibility(): void
    {
        $request = Request::create('/api/v1/_mobile-test');
        $context = app(MobileAssociationAccessService::class)->resolve($this->identity());
        $request->attributes->set('supabase_identity', $this->identity());
        $request->attributes->set('current_user', $context['profile']);
        $request->attributes->set('mobile_context', $context['mobile']);
        DB::table('associations')->update(['archived_at' => now()]);
        $reached = false;
        try {
            app(EnsureMobileAssociationAccess::class)->handle($request, function () use (&$reached) {
                $reached = true;
                return response()->json([]);
            });
            $this->fail('An archived association must be denied.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertFalse($reached);
        Http::assertNothingSent();
    }

    public function test_guard_requires_a_server_verified_identity(): void
    {
        $request = Request::create('/api/v1/_mobile-test');
        $request->attributes->set('mobile_context', ['eligible' => true]);
        $response = app(EnsureMobileAssociationAccess::class)->handle($request,
            fn () => throw new \RuntimeException('Unauthorized handler was reached.'));
        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        Http::assertNothingSent();
    }

    public function test_api_rate_limit_is_safe_and_blocks_provider_calls_after_the_limit(): void
    {
        $this->freezeTime();
        for ($attempt = 0; $attempt < 60; $attempt++) $this->me()->assertOk();
        $response = $this->me()->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
        $this->assertPrivateResponse($response);
        $this->assertNotNull($response->headers->get('Retry-After'));
        Http::assertSentCount(60);
    }

    public function test_api_errors_remain_json_without_accept_header_or_debug_details(): void
    {
        $response = $this->get('/api/v1/unknown')->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->assertPrivateResponse($response);
        Http::assertNothingSent();
    }

    public function test_unfaked_http_requests_are_blocked_in_the_feature_fixture(): void
    {
        $this->expectException(\Illuminate\Http\Client\StrayRequestException::class);
        Http::get('https://not-faked.example.test');
    }

    public function test_default_web_invitation_database_failure_behavior_is_preserved(): void
    {
        $profile = UserProfile::with('role')->find(self::USER);
        Schema::drop('user_invitations');
        $this->assertFalse($profile->canAccessApplication($this->identity()));
        Http::assertNothingSent();
    }
}
