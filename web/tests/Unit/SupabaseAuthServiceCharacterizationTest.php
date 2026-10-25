<?php

namespace Tests\Unit;

use App\Services\Auth\SupabaseAuthService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ProviderRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SupabaseAuthServiceCharacterizationTest extends TestCase
{
    private const PROVIDER = 'https://auth.example.test';
    private const EMAIL = 'recipient@example.test';
    private const ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.supabase' => ['url' => self::PROVIDER, 'publishable_key' => 'sb_publishable_synthetic',
            'service_role_key' => 'synthetic.server.signature', 'invitation_redirect_url' => 'https://app.example.test/invitation']]);
        Http::fake([]);
    }

    protected function tearDown(): void
    {
        try {
            foreach (Http::recorded() as [$request]) $this->assertSame('auth.example.test', parse_url($request->url(), PHP_URL_HOST));
        } finally {
            try { parent::tearDown(); } finally { HandleExceptions::flushState($this); }
        }
    }

    private function response(string $path, string $body, int $status = 200): void
    {
        Http::fake([self::PROVIDER.'/auth/v1/'.$path => Http::response($body, $status, ['Content-Type' => 'application/json'])]);
    }

    private function identity(): array
    {
        return ['id' => self::ID, 'email' => self::EMAIL];
    }

    public function test_browser_sign_in_and_identity_retrieval_preserve_provider_data_and_request_order(): void
    {
        $tokens = ['access_token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh', 'user' => $this->identity()];
        Http::fake([self::PROVIDER.'/auth/v1/token*' => Http::response($tokens),
            self::PROVIDER.'/auth/v1/user' => Http::response($this->identity())]);
        $auth = app(SupabaseAuthService::class);
        $this->assertSame(['success' => true, 'data' => $tokens], $auth->signIn(self::EMAIL, 'synthetic-password'));
        $this->assertSame($this->identity(), $auth->getUser($tokens['access_token']));
        Http::assertSentCount(2);
        [$signIn, $identity] = Http::recorded()->pluck(0)->all();
        $this->assertSame('POST', $signIn->method());
        $this->assertSame(self::PROVIDER.'/auth/v1/token?grant_type=password', $signIn->url());
        $this->assertSame(['email' => self::EMAIL, 'password' => 'synthetic-password'], $signIn->data());
        $this->assertTrue($signIn->hasHeader('apikey', 'sb_publishable_synthetic'));
        $this->assertSame('GET', $identity->method());
        $this->assertTrue($identity->hasHeader('Authorization', 'Bearer synthetic-access'));
    }

    #[DataProvider('browserErrorStatuses')]
    public function test_browser_errors_distinguish_rejection_unavailability_and_unexpected_responses(int $status, string $code, string $message): void
    {
        $this->response('token*', '{"error":"synthetic provider failure"}', $status);
        $this->assertSame(['success' => false, 'code' => $code, 'message' => $message],
            app(SupabaseAuthService::class)->signIn(self::EMAIL, 'synthetic-password'));
        Http::assertSentCount(1);
    }

    public static function browserErrorStatuses(): array
    {
        return ['credentials' => [400, 'authentication_rejected', 'Invalid email or password.'],
            'unauthorized' => [401, 'authentication_rejected', 'Invalid email or password.'],
            'forbidden' => [403, 'authentication_rejected', 'Invalid email or password.'],
            'unexpected client error' => [418, 'invalid_provider_response', 'Unable to verify this account. Please try again.'],
            'rate limited' => [429, 'provider_unavailable', 'Sign-in is temporarily unavailable. Please try again.'],
            'provider outage' => [503, 'provider_unavailable', 'Sign-in is temporarily unavailable. Please try again.']];
    }

    // M3A reproduced success for these unusable bodies; these assertions enforce rejection.
    #[Group('regression-m3a')]
    #[DataProvider('unusableBrowserBodies')]
    public function test_m3a_regression_browser_sign_in_rejects_unusable_provider_bodies(string $body): void
    {
        $this->response('token*', $body);
        $result = app(SupabaseAuthService::class)->signIn(self::EMAIL, 'synthetic-password');
        $this->assertFalse($result['success']);
        $this->assertSame('invalid_provider_response', $result['code']);
        $this->assertArrayNotHasKey('data', $result);
        Http::assertSentCount(1);
    }

    public static function unusableBrowserBodies(): array
    {
        return ['malformed JSON' => ['{'], 'empty success' => [''], 'null' => ['null'],
            'boolean' => ['true'], 'number' => ['42'], 'string' => ['"identity"'],
            'list' => ['["identity"]'], 'empty object' => ['{}'],
            'missing user and access token' => ['{"refresh_token":"synthetic-refresh"}']];
    }

    #[Group('regression-m3a')]
    public function test_m3a_regression_browser_sign_in_rejects_a_redirect_without_location(): void
    {
        $this->response('token*', '{}', 302);
        $result = app(SupabaseAuthService::class)->signIn(self::EMAIL, 'synthetic-password');
        $this->assertFalse($result['success']);
        $this->assertSame('invalid_provider_response', $result['code']);
        Http::assertSentCount(1);
    }

    #[Group('regression-m3a')]
    #[DataProvider('scalarIdentityBodies')]
    public function test_m3a_regression_browser_identity_scalar_json_causes_a_controlled_provider_error(string $body): void
    {
        $this->response('user', $body);
        try {
            app(SupabaseAuthService::class)->getUser('synthetic-access');
            $this->fail('Scalar JSON must not supply an identity.');
        } catch (HttpException $exception) {
            $this->assertSame(502, $exception->getStatusCode());
        } finally {
            Http::assertSentCount(1);
        }
    }

    public static function scalarIdentityBodies(): array
    {
        return [['true'], ['42'], ['"identity"']];
    }

    #[DataProvider('identityRejections')]
    public function test_browser_identity_rejection_and_provider_failure_are_distinct(int $status, string $body, ?int $errorStatus): void
    {
        $this->response('user', $body, $status);
        try {
            $result = app(SupabaseAuthService::class)->getUser('synthetic-access');
            $this->assertNull($errorStatus, 'Provider failures must stop the protected request.');
            $this->assertNull($result);
        } catch (HttpException $exception) {
            $this->assertSame($errorStatus, $exception->getStatusCode());
        } finally {
            Http::assertSentCount(1);
        }
    }

    public static function identityRejections(): array
    {
        return [[401, '{"error_code":"bad_jwt"}', null], [403, '{"code":"user_banned"}', null],
            [401, '{}', 502], [503, '{}', 503], [429, '{}', 503], [302, '{}', 502], [200, '{', 502], [204, '', 502],
            [201, '{}', 502], [200, '{}', 502], [200, '[]', 502], [200, '{"email":"recipient@example.test"}', 502],
            [200, '{"id":"'.self::ID.'"}', 502], [200, '{"id":"invalid","email":"recipient@example.test"}', 502],
            [200, '{"id":"'.self::ID.'","email":"invalid"}', 502],
            [200, '{"id":"'.self::ID.'","email":"recipient@example.test","is_anonymous":"false"}', 502],
            [200, '{"id":"'.self::ID.'","email":"recipient@example.test","error_code":"bad_jwt"}', 502],
            [401, '{"error_code":"unknown_error"}', 502], [401, 'null', 502]];
    }

    #[DataProvider('browserOperations')]
    public function test_browser_connection_failure_is_sanitized_once_without_a_retry(string $operation): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) { $attempts++; throw new ConnectionException('synthetic-private-provider-details'); });
        try {
            $auth = app(SupabaseAuthService::class);
            if ($operation === 'signIn') {
                $this->assertSame(['success' => false, 'code' => 'provider_unavailable',
                    'message' => 'Sign-in is temporarily unavailable. Please try again.'], $auth->signIn(self::EMAIL, 'synthetic-password'));
            } else {
                try {
                    $auth->getUser('synthetic-access');
                    $this->fail('A timeout must stop identity verification.');
                } catch (HttpException $exception) {
                    $this->assertSame(503, $exception->getStatusCode());
                    $this->assertNull($exception->getPrevious());
                    $this->assertStringNotContainsString('synthetic-private-provider-details', $exception->getMessage());
                }
            }
        } finally {
            $this->assertSame(1, $attempts);
        }
    }

    public static function browserOperations(): array
    {
        return [['signIn'], ['getUser']];
    }

    #[DataProvider('browserOperations')]
    public function test_browser_requests_disable_redirects_even_when_a_location_is_present(string $operation): void
    {
        Http::fake(function (ProviderRequest $request, array $options) {
            $this->assertFalse($options['allow_redirects']);

            return Http::response('{}', 302, ['Content-Type' => 'application/json', 'Location' => 'https://untrusted.example.test/redirect']);
        });
        try {
            $auth = app(SupabaseAuthService::class);
            if ($operation === 'signIn') {
                $this->assertSame('invalid_provider_response', $auth->signIn(self::EMAIL, 'synthetic-password')['code']);
            } else {
                try {
                    $auth->getUser('synthetic-access');
                    $this->fail('A redirected identity response must stop the request.');
                } catch (HttpException $exception) {
                    $this->assertSame(502, $exception->getStatusCode());
                }
            }
        } finally {
            Http::assertSentCount(1);
        }
    }

    #[DataProvider('optionalRefreshTokens')]
    public function test_supported_optional_refresh_tokens_opaque_access_and_metadata_are_preserved(array $optional): void
    {
        $identity = $this->identity() + ['is_anonymous' => false, 'user_metadata' => ['display_name' => 'Synthetic Recipient']];
        $tokens = $optional + ['access_token' => 'opaque+/=token', 'user' => $identity, 'token_type' => 'bearer', 'expires_in' => 3600];
        Http::fake([self::PROVIDER.'/auth/v1/token*' => Http::response(json_encode($tokens), 200, ['Content-Type' => 'application/json; charset=utf-8']),
            self::PROVIDER.'/auth/v1/user' => Http::response($identity)]);
        $auth = app(SupabaseAuthService::class);
        $this->assertSame(['success' => true, 'data' => $tokens], $auth->signIn(self::EMAIL, 'synthetic-password'));
        $this->assertSame($identity, $auth->getUser('opaque+/=token'));
        Http::assertSentCount(2);
    }

    public static function optionalRefreshTokens(): array
    {
        return ['omitted refresh token' => [[]], 'null refresh token' => [['refresh_token' => null]]];
    }

    #[DataProvider('unexpectedSignInResponses')]
    public function test_unexpected_status_media_type_or_error_envelope_cannot_supply_a_session(int $status, string $contentType, array $extra): void
    {
        $tokens = $extra + ['access_token' => 'synthetic-access', 'user' => $this->identity()];
        Http::fake([self::PROVIDER.'/auth/v1/token*' => Http::response(json_encode($tokens), $status, ['Content-Type' => $contentType])]);
        $result = app(SupabaseAuthService::class)->signIn(self::EMAIL, 'synthetic-password');
        $this->assertFalse($result['success']);
        $this->assertSame('invalid_provider_response', $result['code']);
        Http::assertSentCount(1);
    }

    public static function unexpectedSignInResponses(): array
    {
        return ['created' => [201, 'application/json', []], 'no content' => [204, 'application/json', []],
            'HTML' => [200, 'text/html', []], 'missing media type' => [200, '', []],
            'error in success' => [200, 'application/json', ['error_code' => 'invalid_credentials']],
            'invalid refresh token' => [200, 'application/json', ['refresh_token' => []]],
            'empty refresh token' => [200, 'application/json', ['refresh_token' => '']]];
    }

    public function test_malformed_authentication_rejection_does_not_masquerade_as_bad_credentials(): void
    {
        $this->response('token*', '{', 400);
        $this->assertSame('invalid_provider_response', app(SupabaseAuthService::class)->signIn(self::EMAIL, 'synthetic-password')['code']);
        Http::assertSentCount(1);
    }

    private function pagination(int $total, int $page): array
    {
        $last = (int) ceil($total / 100);
        $link = fn (int $number, string $relation) => '<https://untrusted.example.test/ignored?'.http_build_query([
            'filter' => self::EMAIL, 'page' => $number, 'per_page' => 100]).'>; rel="'.$relation.'"';

        return ['X-Total-Count' => (string) $total, 'Link' => $link($last, 'last').($page < $last ? ', '.$link($page + 1, 'next') : '')];
    }

    public function test_identity_lookup_searches_exact_email_across_pages_without_following_provider_urls(): void
    {
        $pages = [];
        Http::fake([self::PROVIDER.'/auth/v1/admin/users*' => function (ProviderRequest $request) use (&$pages) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            $pages[] = (int) $query['page'];
            $this->assertSame(self::EMAIL, $query['filter']);
            $this->assertSame('100', $query['per_page']);
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer synthetic.server.signature'));
            $users = $query['page'] === '1' ? array_fill(0, 100, ['email' => 'recipient@example.test.evil'])
                : [['email' => '  RECIPIENT@EXAMPLE.TEST  '], ['email' => self::EMAIL]];

            return Http::response(['users' => $users], 200, $this->pagination(102, (int) $query['page']));
        }]);
        $this->assertSame(['outcome' => 'existing', 'code' => 'existing_identity'], app(SupabaseAuthService::class)->findInvitationIdentity(self::EMAIL));
        $this->assertSame([1, 2], $pages);
        Http::assertSentCount(2);
    }

    public function test_lookup_stops_at_five_pages_and_never_invites_when_absence_cannot_be_established(): void
    {
        $pages = [];
        Http::fake([self::PROVIDER.'/auth/v1/admin/users*' => function (ProviderRequest $request) use (&$pages) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            $pages[] = (int) $query['page'];
            $this->assertSame('GET', $request->method());

            return Http::response(['users' => array_fill(0, 100, ['email' => 'other@example.test'])], 200, $this->pagination(501, (int) $query['page']));
        }]);
        $this->assertSame(['outcome' => 'failed', 'code' => 'identity_lookup_limit'], app(SupabaseAuthService::class)->findInvitationIdentity(self::EMAIL));
        $this->assertSame([1, 2, 3, 4, 5], $pages);
        Http::assertSentCount(5);
    }

    #[DataProvider('invalidLookupMetadata')]
    public function test_lookup_requires_consistent_metadata_before_reporting_absence(array $headers): void
    {
        Http::fake([self::PROVIDER.'/auth/v1/admin/users*' => Http::response(['users' => []], 200, $headers)]);
        $this->assertSame(['outcome' => 'failed', 'code' => 'identity_lookup_inconclusive'], app(SupabaseAuthService::class)->findInvitationIdentity(self::EMAIL));
        Http::assertSentCount(1);
    }

    public static function invalidLookupMetadata(): array
    {
        $last = '</?filter=recipient%40example.test&page=0&per_page=100>; rel="last"';

        return ['missing headers' => [[]], 'missing Link' => [['X-Total-Count' => '0']],
            'invalid total' => [['X-Total-Count' => '-1', 'Link' => $last]],
            'inconsistent count' => [['X-Total-Count' => '1', 'Link' => str_replace('page=0', 'page=1', $last)]],
            'wrong filter' => [['X-Total-Count' => '0', 'Link' => str_replace('recipient%40', 'other%40', $last)]],
            'duplicate last links' => [['X-Total-Count' => '0', 'Link' => $last.', '.$last]],
            'wrong page size' => [['X-Total-Count' => '0', 'Link' => str_replace('per_page=100', 'per_page=50', $last)]]];
    }

    public function test_empty_identity_search_with_complete_zero_total_metadata_reports_absence(): void
    {
        Http::fake([self::PROVIDER.'/auth/v1/admin/users*' => Http::response(['users' => []], 200, $this->pagination(0, 1))]);
        $this->assertSame(['outcome' => 'absent'], app(SupabaseAuthService::class)->findInvitationIdentity(self::EMAIL));
        Http::assertSentCount(1);
    }

    #[DataProvider('invalidLookupBodies')]
    public function test_invalid_identity_search_payloads_fail_closed(string $body): void
    {
        $this->response('admin/users*', $body);
        $this->assertSame(['outcome' => 'failed', 'code' => 'identity_lookup_failed'], app(SupabaseAuthService::class)->findInvitationIdentity(self::EMAIL));
        Http::assertSentCount(1);
    }

    public static function invalidLookupBodies(): array
    {
        return [['{'], ['true'], ['{}'], ['{"users":{"invalid":"shape"}}'], ['{"users":[{}]}'], ['{"users":[{"email":42}]}']];
    }

    #[Group('regression-m3a')]
    public function test_m3a_regression_empty_users_object_cannot_establish_identity_absence(): void
    {
        Http::fake([self::PROVIDER.'/auth/v1/admin/users*' => Http::response('{"users":{}}', 200, $this->pagination(0, 1))]);
        $this->assertSame(['outcome' => 'failed', 'code' => 'identity_lookup_failed'], app(SupabaseAuthService::class)->findInvitationIdentity(self::EMAIL));
        Http::assertSentCount(1);
    }

    #[DataProvider('invitationErrors')]
    public function test_invitation_provider_errors_preserve_failure_vs_uncertain_outcomes(int $status, array $body, string $outcome, string $code): void
    {
        $this->response('invite*', json_encode($body), $status);
        $this->assertSame(['outcome' => $outcome, 'code' => $code], app(SupabaseAuthService::class)->inviteUser(self::EMAIL, 'Synthetic Recipient'));
        Http::assertSentCount(1);
    }

    public static function invitationErrors(): array
    {
        return [[403, ['code' => 'not_admin'], 'failed', 'provider_auth_rejected'],
            [429, ['error_code' => 'over_email_send_rate_limit'], 'failed', 'provider_rate_limited'],
            [422, ['code' => 'email_exists'], 'failed', 'existing_identity'],
            [403, ['code' => 'not_admin', 'error_code' => 'bad_jwt'], 'uncertain', 'provider_request_rejected'],
            [400, ['error' => 'custom hook failed'], 'uncertain', 'provider_request_rejected'],
            [503, [], 'uncertain', 'provider_outcome_unknown'], [302, [], 'uncertain', 'provider_outcome_unknown']];
    }

    public function test_invitation_success_preserves_normalized_identity_and_creation_time(): void
    {
        $this->response('invite*', json_encode(['id' => strtoupper(self::ID), 'email' => ' RECIPIENT@EXAMPLE.TEST ', 'created_at' => '2026-10-01T12:30:00Z']));
        $result = app(SupabaseAuthService::class)->inviteUser(self::EMAIL, 'Synthetic Recipient');
        $this->assertSame('sent', $result['outcome']);
        $this->assertSame(self::ID, $result['auth_user_id']);
        $this->assertInstanceOf(CarbonImmutable::class, $result['auth_created_at']);
        $this->assertSame('2026-10-01T12:30:00+00:00', $result['auth_created_at']->toIso8601String());
        Http::assertSentCount(1);
    }

    public function test_invalid_invitation_creation_metadata_retains_identity_for_reconciliation(): void
    {
        $this->response('invite*', json_encode($this->identity() + ['created_at' => '2026-02-30T12:30:00Z']));
        $this->assertSame(['outcome' => 'uncertain', 'code' => 'provider_response_invalid', 'reconciliation_auth_user_id' => self::ID],
            app(SupabaseAuthService::class)->inviteUser(self::EMAIL, 'Synthetic Recipient'));
        Http::assertSentCount(1);
    }

    #[DataProvider('uncertainWrites')]
    public function test_uncertain_invitation_and_password_writes_are_never_retried(string $operation, array $expected): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) { $attempts++; throw new ConnectionException('Synthetic timeout after possible write'); });
        $auth = app(SupabaseAuthService::class);
        $result = $operation === 'invite' ? $auth->inviteUser(self::EMAIL, 'Synthetic Recipient')
            : $auth->updateInvitationPassword('synthetic-access', 'synthetic-password');
        $this->assertSame($expected, $result);
        $this->assertSame(1, $attempts);
    }

    public static function uncertainWrites(): array
    {
        return [['invite', ['outcome' => 'uncertain', 'code' => 'provider_outcome_unknown']],
            ['password', ['success' => false, 'code' => 'password_update_uncertain']]];
    }

    #[DataProvider('invalidInvitationBodies')]
    public function test_unusable_invitation_write_responses_remain_uncertain_without_retry(string $body): void
    {
        $this->response('invite*', $body);
        $this->assertSame(['outcome' => 'uncertain', 'code' => 'provider_response_invalid'],
            app(SupabaseAuthService::class)->inviteUser(self::EMAIL, 'Synthetic Recipient'));
        Http::assertSentCount(1);
    }

    public static function invalidInvitationBodies(): array
    {
        return [['{'], [''], ['true'], ['[]'], ['{}'], ['{"id":"invalid","email":"recipient@example.test"}'],
            ['{"id":"'.self::ID.'","email":"another@example.test"}']];
    }

    #[DataProvider('invalidVerificationFields')]
    public function test_single_use_verification_rejects_missing_or_invalid_session_fields(string $field, mixed $value): void
    {
        $body = ['user' => $this->identity(), 'access_token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh',
            'token_type' => 'bearer', 'expires_in' => 3600];
        $body[$field] = $value;
        $this->response('verify', json_encode($body));
        $this->assertSame(['success' => false, 'code' => 'invalid_provider_response'],
            app(SupabaseAuthService::class)->verifyInvitationToken(str_repeat('a', 56)));
        Http::assertSentCount(1);
    }

    public static function invalidVerificationFields(): array
    {
        return [['access_token', null], ['access_token', ''], ['access_token', []], ['refresh_token', null],
            ['refresh_token', ''], ['token_type', 'Bearer'], ['expires_in', '3600'], ['expires_in', 59], ['user', []]];
    }

    public function test_identity_search_does_not_trust_a_changed_total_between_pages(): void
    {
        $requests = 0;
        Http::fake([self::PROVIDER.'/auth/v1/admin/users*' => function () use (&$requests) {
            $requests++;

            return Http::response(['users' => $requests === 1 ? array_fill(0, 100, ['email' => 'other@example.test']) : []],
                200, $this->pagination($requests === 1 ? 101 : 100, $requests));
        }]);
        $this->assertSame(['outcome' => 'failed', 'code' => 'identity_lookup_inconclusive'], app(SupabaseAuthService::class)->findInvitationIdentity(self::EMAIL));
        $this->assertSame(2, $requests);
        Http::assertSentCount(2);
    }
}
