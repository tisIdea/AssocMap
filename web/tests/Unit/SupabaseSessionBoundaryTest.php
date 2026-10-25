<?php

namespace Tests\Unit;

use App\Models\UserProfile;
use App\Services\Auth\SupabaseSessionBoundary;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Support\Facades\DB;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class SupabaseSessionBoundaryTest extends TestCase
{
    private const USER = '11111111-1111-4111-8111-111111111111';

    private const SESSION = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.supabase.url' => 'https://auth.example.test']);
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        DB::statement("ATTACH DATABASE ':memory:' AS auth");
        DB::statement('CREATE TABLE auth.sessions (id TEXT, user_id TEXT, created_at TEXT)');
        DB::table('auth.sessions')->insert(['id' => self::SESSION, 'user_id' => self::USER, 'created_at' => now()->subSeconds(10)]);
    }

    private function profile(): UserProfile
    {
        return (new UserProfile)->forceFill(['id' => self::USER, 'auth_sessions_valid_after' => now()->subMinute()]);
    }

    private function token(array $overrides = []): string
    {
        $claims = array_replace(['sub' => self::USER, 'session_id' => self::SESSION, 'iss' => 'https://auth.example.test/auth/v1',
            'role' => 'authenticated', 'aud' => ['authenticated'], 'exp' => now()->addHour()->timestamp,
            'amr' => [['method' => 'password', 'timestamp' => now()->subSeconds(10)->timestamp]]], $overrides);

        return 'synthetic_header.'.rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=').'.synthetic_signature';
    }

    public static function invalidClaims(): array
    {
        return [
            'wrong subject' => [['sub' => self::SESSION]], 'wrong issuer' => [['iss' => 'https://other.example.test/auth/v1']],
            'wrong audience' => [['aud' => 'anon']], 'wrong role' => [['role' => 'service_role']],
            'missing session' => [['session_id' => null]], 'invalid session' => [['session_id' => 'invalid']],
            'expired' => [['exp' => 1]], 'no authentication proof' => [['amr' => []]],
            'recovery is not password sign-in' => [['amr' => [['method' => 'recovery', 'timestamp' => 2147483647]]]],
            'old password proof with new JWT' => [['amr' => [['method' => 'password', 'timestamp' => 1]]]],
            'untrusted proof timestamp' => [['amr' => [['method' => 'password', 'timestamp' => '2147483647']]]],
        ];
    }

    #[DataProvider('invalidClaims')]
    public function test_invalid_or_old_session_evidence_fails_closed(array $claims): void
    {
        $this->assertFalse(app(SupabaseSessionBoundary::class)->allows($this->profile(), ['id' => self::USER], $this->token($claims)));
    }

    public function test_only_an_existing_new_provider_session_with_fresh_password_proof_is_accepted(): void
    {
        $boundary = app(SupabaseSessionBoundary::class);
        $this->assertTrue($boundary->allows($this->profile(), ['id' => self::USER], $this->token()));
        DB::table('auth.sessions')->update(['created_at' => now()->subDay()]);
        $this->assertFalse($boundary->allows($this->profile(), ['id' => self::USER], $this->token()));
        DB::table('auth.sessions')->delete();
        $this->assertFalse($boundary->allows($this->profile(), ['id' => self::USER], $this->token()));
    }

    public function test_postgresql_session_query_projects_only_id_and_preserves_security_bindings(): void
    {
        $profile = $this->profile();
        $connection = DB::connection();
        $originalGrammar = $connection->getQueryGrammar();
        $queries = [];
        $connection->beforeExecuting(static function (string $query, array $bindings) use (&$queries) {
            $queries[] = ['query' => $query, 'bindings' => $bindings];
        });
        $connection->setQueryGrammar(new PostgresGrammar($connection));
        try {
            $connection->pretend(fn () => app(SupabaseSessionBoundary::class)
                ->allows($profile, ['id' => self::USER], $this->token()));
        } finally {
            $connection->setQueryGrammar($originalGrammar);
        }

        $this->assertCount(1, $queries);
        $this->assertSame('select exists(select "id" from "auth"."sessions" where "id" = ? and "user_id" = ? and "created_at" > ?) as "exists"', $queries[0]['query']);
        $this->assertSame([self::SESSION, self::USER], array_slice($queries[0]['bindings'], 0, 2));
        $this->assertEquals($profile->auth_sessions_valid_after, $queries[0]['bindings'][2]);
    }

    public function test_wrong_session_id_and_session_created_at_the_cutoff_are_denied(): void
    {
        $profile = $this->profile();
        $boundary = app(SupabaseSessionBoundary::class);
        $this->assertFalse($boundary->allows($profile, ['id' => self::USER], $this->token(['session_id' => self::USER])));
        DB::table('auth.sessions')->where('id', self::SESSION)->update(['created_at' => $profile->auth_sessions_valid_after]);
        $this->assertFalse($boundary->allows($profile, ['id' => self::USER], $this->token()));
    }

    public function test_session_permission_failure_denies_access_without_exposing_database_details(): void
    {
        DB::connection()->beforeExecuting(static function () {
            throw new PDOException('permission denied for table sessions', 42501);
        });
        try {
            app(SupabaseSessionBoundary::class)->allows($this->profile(), ['id' => self::USER], $this->token());
            $this->fail('Session permission errors must not grant access.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertSame('Session verification is temporarily unavailable.', $exception->getMessage());
        }
    }

    public function test_session_user_binding_and_unsupported_tokens_are_rejected(): void
    {
        DB::table('auth.sessions')->update(['user_id' => self::SESSION]);
        $boundary = app(SupabaseSessionBoundary::class);
        $this->assertFalse($boundary->allows($this->profile(), ['id' => self::USER], $this->token()));
        $this->assertFalse($boundary->allows($this->profile(), ['id' => self::USER], 'invalid'));
        $this->assertFalse($boundary->allows($this->profile(), ['id' => self::SESSION], $this->token()));
    }

    public function test_session_lookup_failure_stops_access_and_legacy_profiles_keep_existing_behavior(): void
    {
        DB::statement('DROP TABLE auth.sessions');
        $boundary = app(SupabaseSessionBoundary::class);
        $legacy = $this->profile()->forceFill(['auth_sessions_valid_after' => null]);
        $this->assertTrue($boundary->allows($legacy, ['id' => self::USER], 'legacy_token'));
        try {
            $boundary->allows($this->profile(), ['id' => self::USER], $this->token());
            $this->fail('Session storage errors must not grant access.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(503, $exception->getStatusCode());
        }
    }
}
