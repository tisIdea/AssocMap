<?php

namespace Tests\Unit;

use App\Services\Auth\ProfileAuthIdentityService;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Support\IsolatedDatabaseManager;
use Tests\TestCase;

class ProfileAuthIdentityServiceTest extends TestCase
{
    private const ID = '11111111-1111-4111-8111-111111111111';

    private IsolatedDatabaseManager $isolatedDatabaseManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolatedDatabaseManager = DB::getFacadeRoot();

        DB::swap(Mockery::mock());
    }

    protected function tearDown(): void
    {
        if (isset($this->isolatedDatabaseManager)) {
            DB::swap($this->isolatedDatabaseManager);
        }

        parent::tearDown();
    }

    public function test_empty_profile_page_does_not_query_auth(): void
    {
        DB::shouldReceive('table')->never();
        $this->assertTrue((new ProfileAuthIdentityService)->read([])->isEmpty());
        Http::assertNothingSent();
    }

    public function test_reader_selects_only_ids_and_emails_for_the_supplied_profiles(): void
    {
        $query = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->once()->with('auth.users')->andReturn($query);
        $query->shouldReceive('whereIn')->once()->with('id', [self::ID])->andReturnSelf();
        $query->shouldReceive('get')->once()->with(['id', 'email'])
            ->andReturn(collect([(object) ['id' => self::ID, 'email' => 'synthetic@example.test']]));
        $result = (new ProfileAuthIdentityService)->read([self::ID]);
        $this->assertSame([self::ID], $result->keys()->all());
        $this->assertSame('synthetic@example.test', $result->get(self::ID)->email);
        Http::assertNothingSent();
    }

    public function test_permission_or_schema_failure_is_unavailable_without_raw_error_logging(): void
    {
        $query = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->once()->with('auth.users')->andReturn($query);
        $query->shouldReceive('whereIn')->with('id', [self::ID])->andReturnSelf();
        $query->shouldReceive('get')->with(['id', 'email'])->andThrow(
            new QueryException('synthetic', 'synthetic query', [], new \RuntimeException('Synthetic sensitive exception detail.'))
        );
        Log::shouldReceive('notice')->once()->with('Profile Auth metadata could not be read.', ['code' => 'auth_profile_lookup_unavailable']);
        $this->assertNull((new ProfileAuthIdentityService)->read([self::ID]));
        Http::assertNothingSent();
    }
}
