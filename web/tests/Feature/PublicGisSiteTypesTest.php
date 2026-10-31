<?php

namespace Tests\Feature;

use App\Http\Middleware\GisRequestGuard;
use App\Services\GIS\GisQueryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class PublicGisSiteTypesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        Schema::create('gis_site_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('synthetic_private_field')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    public function test_guest_gets_complete_ordered_catalog_without_location_or_private_fields(): void
    {
        DB::table('gis_site_types')->insert([
            ['id' => 81, 'code' => 'future_site', 'name' => 'Z synthetic site', 'synthetic_private_field' => 'private'],
            ['id' => 24, 'code' => 'fish_cage', 'name' => 'A synthetic cage', 'synthetic_private_field' => 'private'],
            ['id' => 42, 'code' => 'custom_site', 'name' => 'A synthetic cage', 'synthetic_private_field' => null],
        ]);
        DB::enableQueryLog();
        $this->getJson('/map/site-types')->assertOk()->assertExactJson(['data' => [
            ['id' => 24, 'code' => 'fish_cage', 'name' => 'A synthetic cage'],
            ['id' => 42, 'code' => 'custom_site', 'name' => 'A synthetic cage'],
            ['id' => 81, 'code' => 'future_site', 'name' => 'Z synthetic site'],
        ]])->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertCount(1, DB::getQueryLog());
        $this->assertStringStartsWith('select ', strtolower(DB::getQueryLog()[0]['query']));
        DB::disableQueryLog();
    }

    public function test_empty_catalog_is_valid_and_does_not_load_defaults(): void
    {
        $this->getJson('/map/site-types')->assertOk()->assertExactJson(['data' => []]);
        $this->assertSame(0, DB::table('gis_site_types')->count());
    }

    public function test_catalog_reuses_public_read_guard_and_throttle_and_has_no_write_route(): void
    {
        $middleware = Route::getRoutes()->getByName('public.gis.site-types')->gatherMiddleware();
        $this->assertContains(GisRequestGuard::class, $middleware);
        $this->assertContains('throttle:gis-read', $middleware);
        $this->postJson('/map/site-types', [])->assertStatus(405);
    }

    public function test_public_site_filters_reach_existing_service_without_exposing_internal_filters(): void
    {
        $query = Mockery::mock(GisQueryService::class);
        foreach (['unclassified', '81'] as $id) {
            $query->shouldReceive('features')->once()->with(null, ['mode' => 'overview', 'site_type_id' => $id])
                ->andReturn(['type' => 'FeatureCollection', 'features' => [], 'meta' => ['bounds' => null, 'empty_reason' => 'filtered']]);
        }
        $this->app->instance(GisQueryService::class, $query);
        foreach (['unclassified', '81'] as $id) {
            $this->getJson('/map/data?mode=overview&site_type_id='.$id)->assertOk();
        }
        $this->getJson('/map/data?mode=overview&site_type_id=fish_cage')->assertUnprocessable();
        $this->getJson('/map/data?mode=overview&site_type_id=81&publication=unpublished')->assertUnprocessable();
    }
}
