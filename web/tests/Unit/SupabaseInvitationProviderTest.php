<?php

namespace Tests\Unit;

use App\Services\SupabaseInvitationProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class SupabaseInvitationProviderTest extends TestCase
{
    private const URL = 'https://auth.example.test/auth/v1/admin/users';
    private const EMAIL = 'recipient@example.test';
    private const ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const INVITE_URL = 'https://auth.example.test/auth/v1/invite';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.supabase.url' => 'https://auth.example.test',
            'services.supabase.service_role_key' => 'synthetic.server.signature',
            'services.supabase.invitation_redirect_url' => 'https://app.example.test/invitation']);
        Http::fake([]);
    }

    #[DataProvider('rowOrders')]
    public function test_lookup_preserves_row_order_and_checks_exact_matches_before_metadata(array $users, array $expected): void
    {
        Http::fake([self::URL.'*' => Http::response(['users' => $users])]);
        $this->assertSame($expected, app(SupabaseInvitationProvider::class)->findInvitationIdentity(self::EMAIL));
        Http::assertSentCount(1);
    }

    public static function rowOrders(): array
    {
        $matching = ['email' => '  RECIPIENT@EXAMPLE.TEST  '];

        return ['match before malformed row' => [[['email' => null], $matching, ['email' => 42]],
                ['outcome' => 'existing', 'code' => 'existing_identity']],
            'malformed row before match' => [[['email' => 42], $matching],
                ['outcome' => 'failed', 'code' => 'identity_lookup_failed']]];
    }

    #[DataProvider('completePageTotals')]
    public function test_complete_results_can_establish_absence_through_the_last_allowed_page(int $total): void
    {
        $lastPage = (int) ($total / 100);
        $pages = [];
        Http::fake(function (Request $request, array $options) use ($total, $lastPage, &$pages) {
            $this->assertSame(self::URL, strtok($request->url(), '?'));
            $this->assertSame('GET', $request->method());
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer synthetic.server.signature'));
            $this->assertFalse($options['allow_redirects']);
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            $this->assertSame(self::EMAIL, $query['filter']);
            $this->assertSame('100', $query['per_page']);
            $page = (int) $query['page'];
            $pages[] = $page;
            $link = fn (int $number, string $relation) => '</?'.http_build_query([
                'filter' => self::EMAIL, 'page' => $number, 'per_page' => 100]).'>; rel="'.$relation.'"';
            $links = $link($lastPage, 'last').($page < $lastPage ? ', '.$link($page + 1, 'next') : '');

            return Http::response(['users' => array_fill(0, 100, ['email' => 'another@example.test'])],
                200, ['X-Total-Count' => (string) $total, 'Link' => $links]);
        });
        $this->assertSame(['outcome' => 'absent'], app(SupabaseInvitationProvider::class)->findInvitationIdentity(self::EMAIL));
        $this->assertSame(range(1, $lastPage), $pages);
        Http::assertSentCount($lastPage);
    }

    public static function completePageTotals(): array
    {
        return [[100], [200], [500]];
    }

    public function test_lookup_timeout_remains_a_failure_without_retry_or_provisioning(): void
    {
        $attempts = 0;
        Http::fake(function (Request $request) use (&$attempts) {
            $attempts++;
            $this->assertSame('GET', $request->method());
            throw new ConnectionException('Synthetic lookup timeout');
        });
        $this->assertSame(['outcome' => 'failed', 'code' => 'identity_lookup_failed'],
            app(SupabaseInvitationProvider::class)->findInvitationIdentity(self::EMAIL));
        $this->assertSame(1, $attempts);
    }

    private function invitationBody(mixed $createdAt = '2026-10-01T12:30:00Z'): array
    {
        return ['id' => strtoupper(self::ID), 'email' => ' RECIPIENT@EXAMPLE.TEST ', 'created_at' => $createdAt];
    }

    #[Group('m3c-contract')]
    #[DataProvider('validCreationTimes')]
    public function test_valid_creation_times_preserve_the_provisioned_identity_and_instant(string $createdAt, string $expected): void
    {
        Http::fake([self::INVITE_URL.'*' => Http::response($this->invitationBody($createdAt))]);
        $result = app(SupabaseInvitationProvider::class)->inviteUser(self::EMAIL, 'Synthetic Recipient');
        $this->assertSame('sent', $result['outcome']);
        $this->assertSame(self::ID, $result['auth_user_id']);
        $this->assertInstanceOf(CarbonImmutable::class, $result['auth_created_at']);
        $this->assertSame($expected, $result['auth_created_at']->format('Y-m-d\TH:i:s.uP'));
        Http::assertSentCount(1);
    }

    public static function validCreationTimes(): array
    {
        return ['UTC' => ['2026-10-01T12:30:00Z', '2026-10-01T12:30:00.000000+00:00'],
            'microseconds' => ['2026-10-01T12:30:00.123456Z', '2026-10-01T12:30:00.123456+00:00'],
            'nine fractional digits' => ['2026-10-01T12:30:00.123456789Z', '2026-10-01T12:30:00.123456+00:00'],
            'leap day' => ['2024-02-29T23:59:59Z', '2024-02-29T23:59:59.000000+00:00'],
            'positive offset' => ['2026-10-01T12:30:00+08:00', '2026-10-01T12:30:00.000000+08:00'],
            'negative offset' => ['2026-10-01T12:30:00-05:30', '2026-10-01T12:30:00.000000-05:30']];
    }

    #[Group('m3c-contract')]
    #[DataProvider('invalidCreationTimes')]
    public function test_invalid_creation_times_preserve_only_reconciliation_identity(mixed $createdAt): void
    {
        Http::fake([self::INVITE_URL.'*' => Http::response($this->invitationBody($createdAt))]);
        $this->assertSame(['outcome' => 'uncertain', 'code' => 'provider_response_invalid', 'reconciliation_auth_user_id' => self::ID],
            app(SupabaseInvitationProvider::class)->inviteUser(self::EMAIL, 'Synthetic Recipient'));
        Http::assertSentCount(1);
    }

    public static function invalidCreationTimes(): array
    {
        return ['null' => [null], 'empty' => [''], 'number' => [42], 'array' => [[]], 'boolean' => [true],
            'informal date' => ['October 1, 2026'], 'missing zone' => ['2026-10-01T12:30:00'],
            'invalid day' => ['2026-02-30T12:30:00Z'], 'non-leap day' => ['2026-02-29T12:30:00Z'],
            'invalid month' => ['2026-13-01T12:30:00Z'], 'zero year' => ['0000-10-01T12:30:00Z'],
            'rollover hour' => ['2026-10-01T24:00:00Z'], 'rollover minute' => ['2026-10-01T12:60:00Z'],
            'rollover second' => ['2026-10-01T12:30:60Z'], 'invalid offset hour' => ['2026-10-01T12:30:00+24:00'],
            'invalid offset minute' => ['2026-10-01T12:30:00+08:60'],
            'excess precision' => ['2026-10-01T12:30:00.1234567890Z'], 'trailing newline' => ["2026-10-01T12:30:00Z\n"]];
    }

    #[Group('m3c-contract')]
    public function test_missing_timestamp_and_conflicting_identity_preserve_different_reconciliation_results(): void
    {
        $body = $this->invitationBody();
        unset($body['created_at']);
        Http::fake([self::INVITE_URL.'*' => Http::sequence()->push($body)->push(array_replace($body, ['email' => 'another@example.test']))]);
        $provider = app(SupabaseInvitationProvider::class);
        $this->assertSame(['outcome' => 'uncertain', 'code' => 'provider_response_invalid', 'reconciliation_auth_user_id' => self::ID],
            $provider->inviteUser(self::EMAIL, 'Synthetic Recipient'));
        $this->assertSame(['outcome' => 'uncertain', 'code' => 'provider_response_invalid'],
            $provider->inviteUser(self::EMAIL, 'Synthetic Recipient'));
        Http::assertSentCount(2);
    }

    #[Group('m3c-contract')]
    #[DataProvider('errorEnvelopes')]
    public function test_error_envelope_ambiguity_and_status_preserve_failure_classifications(int $status, array $body, array $headers, array $expected): void
    {
        Http::fake([self::INVITE_URL.'*' => Http::response($body, $status, $headers)]);
        $this->assertSame($expected, app(SupabaseInvitationProvider::class)->inviteUser(self::EMAIL, 'Synthetic Recipient'));
        Http::assertSentCount(1);
    }

    public static function errorEnvelopes(): array
    {
        $authRejected = ['outcome' => 'failed', 'code' => 'provider_auth_rejected'];
        $requestRejected = ['outcome' => 'uncertain', 'code' => 'provider_request_rejected'];

        return ['matching duplicate codes' => [403, ['code' => 'not_admin', 'error_code' => 'not_admin'], ['x-sb-error-code' => 'not_admin'], $authRejected],
            'numeric status with Auth code' => [403, ['code' => 403, 'error_code' => 'not_admin'], [], $authRejected],
            'header only' => [403, ['msg' => 'Synthetic denial'], ['x-sb-error-code' => 'not_admin'], $authRejected],
            'header conflict' => [403, ['code' => 'not_admin'], ['x-sb-error-code' => 'bad_jwt'], $requestRejected],
            'unknown code' => [403, ['code' => 'unknown_code'], [], $requestRejected],
            'known code at wrong status' => [429, ['code' => 'not_admin'], [], $requestRejected],
            'null code overrides valid header' => [403, ['code' => null], ['x-sb-error-code' => 'not_admin'], $requestRejected],
            'empty code overrides valid header' => [403, ['error_code' => ''], ['x-sb-error-code' => 'not_admin'], $requestRejected],
            'numeric status alone' => [403, ['code' => 403], [], $requestRejected],
            'alternate duplicate identity' => [422, ['code' => 'user_already_exists'], [], ['outcome' => 'failed', 'code' => 'existing_identity']],
            'alternate rate limit' => [429, ['code' => 'over_request_rate_limit'], [], ['outcome' => 'failed', 'code' => 'provider_rate_limited']],
            'server failure with Auth code' => [503, ['code' => 'not_admin'], [], ['outcome' => 'uncertain', 'code' => 'provider_outcome_unknown']]];
    }

    #[Group('m3c-contract')]
    #[DataProvider('paginationBoundaries')]
    public function test_next_link_and_terminal_page_counts_must_match_metadata(int $total, int $userCount, ?int $next, array $expected): void
    {
        $last = (int) ceil($total / 100);
        $link = fn (int $page, string $relation) => '</?'.http_build_query([
            'filter' => self::EMAIL, 'page' => $page, 'per_page' => 100]).'>; rel="'.$relation.'"';
        $links = $link($last, 'last').($next !== null ? ', '.$link($next, 'next') : '');
        Http::fake([self::URL.'*' => Http::response(['users' => array_fill(0, $userCount, ['email' => 'another@example.test'])],
            200, ['X-Total-Count' => (string) $total, 'Link' => $links])]);
        $this->assertSame($expected, app(SupabaseInvitationProvider::class)->findInvitationIdentity(self::EMAIL));
        Http::assertSentCount(1);
    }

    public static function paginationBoundaries(): array
    {
        $inconclusive = ['outcome' => 'failed', 'code' => 'identity_lookup_inconclusive'];

        return ['empty terminal page with next' => [0, 0, 1, $inconclusive],
            'complete partial page' => [1, 1, null, ['outcome' => 'absent']],
            'terminal page with next' => [1, 1, 2, $inconclusive],
            'missing required next' => [101, 100, null, $inconclusive],
            'next points backwards' => [101, 100, 1, $inconclusive],
            'next skips a page' => [101, 100, 3, $inconclusive],
            'incomplete terminal result' => [1, 0, null, $inconclusive]];
    }

    #[Group('m3c-contract')]
    public function test_provisioning_preserves_the_single_request_envelope_without_database_queries(): void
    {
        Http::fake(function (Request $request, array $options) {
            $this->assertSame('POST', $request->method());
            $this->assertSame(self::INVITE_URL.'?'.http_build_query(['redirect_to' => 'https://app.example.test/invitation']), $request->url());
            $this->assertTrue($request->hasHeader('apikey', 'synthetic.server.signature'));
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer synthetic.server.signature'));
            $this->assertTrue($request->hasHeader('Accept', 'application/json'));
            $this->assertTrue($request->hasHeader('Content-Type', 'application/json'));
            $this->assertSame(['email' => self::EMAIL, 'data' => ['display_name' => 'Synthetic Recipient']], $request->data());
            $this->assertFalse($options['allow_redirects']);
            $this->assertEquals(5, $options['connect_timeout']);
            $this->assertEquals(15, $options['timeout']);

            return Http::response($this->invitationBody());
        });
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->assertSame('sent', app(SupabaseInvitationProvider::class)->inviteUser(self::EMAIL, 'Synthetic Recipient')['outcome']);
            $this->assertSame([], DB::getQueryLog());
            Http::assertSentCount(1);
        } finally {
            DB::disableQueryLog();
        }
    }
}
