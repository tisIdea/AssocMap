<?php

namespace Tests\Feature;

use App\Services\Auth\SupabaseAuthService;
use App\Services\GIS\GisAddressResolver;
use App\Services\GIS\GisFeatureSerializer;
use App\Services\GIS\GisLocationService;
use App\Services\GIS\GisQueryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GisManagementUiTest extends TestCase
{
    private string $identity = 'admin';
    private const LOCATION = '11111111-1111-4111-8111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('g', 32))]);
        $this->withoutVite();
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        Schema::create('gis_site_types', function (Blueprint $t) { $t->id(); $t->string('code')->unique(); $t->string('name'); });
        Schema::create('roles', function (Blueprint $t) { $t->id(); $t->string('code'); $t->boolean('is_active'); $t->string('name')->nullable(); $t->text('description')->nullable(); $t->timestamps(); });
        Schema::create('user_profiles', function (Blueprint $t) {
            $t->string('id')->primary(); $t->integer('role_id'); $t->string('display_name'); $t->boolean('is_active'); $t->timestamps();
        });
        Schema::create('user_invitations', function (Blueprint $t) {
            $t->string('auth_user_id')->nullable(); $t->string('status'); $t->string('last_error_code')->nullable();
            $t->timestamp('accepted_at')->nullable(); $t->timestamp('last_attempt_at')->nullable(); $t->timestamps();
        });
        foreach (['admin' => 'system_admin', 'officer' => 'field_officer', 'account' => 'association_account'] as $id => $code) {
            $role = DB::table('roles')->insertGetId(['code' => $code, 'is_active' => true]);
            DB::table('user_profiles')->insert(['id' => $id, 'role_id' => $role, 'display_name' => 'Synthetic '.$id, 'is_active' => true]);
        }
        $auth = Mockery::mock(SupabaseAuthService::class);
        $auth->shouldReceive('getUser')->andReturnUsing(fn () => ['id' => $this->identity]);
        $this->app->instance(SupabaseAuthService::class, $auth);
        $this->withSession(['supabase_access_token' => 'synthetic-gis-session']);
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    public static function roles(): array
    {
        return [['admin', 'admin', true], ['officer', 'field-officer', true], ['account', 'association', false]];
    }

    #[DataProvider('roles')]
    public function test_workspace_renders_scoped_controls_without_loading_gis_rows(string $id, string $prefix, bool $manager): void
    {
        $this->identity = $id;
        DB::enableQueryLog(); DB::flushQueryLog();
        $page = $this->get('/'.$prefix.'/gis')->assertOk()->assertSee('data-gis-workspace', false)
            ->assertSee('Geographic filters')->assertSee('Clear selected location')->assertSee('Interactive map')
            ->assertSee('data-workspace-module="gis"', false)->assertDontSee('association_point');
        $this->assertCount(3, DB::getQueryLog());
        DB::disableQueryLog();
        $dom = new \DOMDocument();
        @$dom->loadHTML($page->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame(0, $xpath->query('//details[contains(@class,"gis-filters")][@open]')->length);
        $this->assertSame(1, $xpath->query('//input[@name="q" and @type="search"]')->length);
        $this->assertSame(2, $xpath->query('//button[@data-gis-view and @aria-controls and @aria-pressed]')->length);
        $this->assertSame($manager ? 1 : 0, $xpath->query('//select[@name="lifecycle"]')->length);
        $this->assertSame($manager ? 1 : 0, $xpath->query('//dialog[@data-gis-confirm]')->length);
        $panel = '//aside[contains(@class,"gis-location-panel")]';
        $content = $panel.'/div[contains(@class,"gis-location-content")]';
        $this->assertSame(1, $xpath->query($content.'[@role="region" and @tabindex="0" and @aria-labelledby="gis-details-title"]')->length);
        $this->assertSame(1, $xpath->query($panel.'/div[contains(@class,"gis-panel-heading")]/h2[@id="gis-details-title"]')->length);
        $this->assertSame(1, $xpath->query($panel.'/div[contains(@class,"gis-panel-heading")]/button[@data-gis-dismiss]')->length);
        $this->assertSame(0, $xpath->query($content.'//button[@data-gis-dismiss] | '.$content.'//*[@data-gis-map]')->length);
        $this->assertSame(1, $xpath->query($content.'//h3[@data-gis-selection-title and @tabindex="-1"]')->length);
        $this->assertSame(1, $xpath->query($content.'/dl[@data-gis-details]')->length);
        $this->assertSame(1, $xpath->query($content.'/div[@data-gis-record-actions]')->length);
        $this->assertSame($manager ? 1 : 0, $xpath->query($content.'/details[@data-gis-history]/dl[@data-gis-history-details]')->length);
        if ($manager) $page->assertSee('/'.$prefix.'/gis/create', false)->assertSee('Record history and public details');
        else $page->assertSee('Read-only viewer')->assertDontSee('Add GIS location')->assertDontSee('data-gis-csrf', false);
        $page->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }

    public function test_public_workspace_retains_its_approved_filter_projection(): void
    {
        $this->get('/map')->assertOk()->assertSee('Read-only viewer')->assertSee('association_location_id', false)
            ->assertSee('class="gis-location-content" role="region" aria-labelledby="gis-details-title" tabindex="0"', false)
            ->assertDontSee('name="municipality_id"', false)->assertDontSee('name="publication"', false)
            ->assertDontSee('Add GIS location')->assertDontSee('data-gis-history', false);
    }

    public function test_role_and_guest_routes_remain_protected(): void
    {
        $this->identity = 'account';
        foreach (['/admin/gis', '/admin/gis/data?mode=list', '/admin/gis/parents?kind=association',
            '/field-officer/gis/create', '/admin/gis/'.self::LOCATION.'/coordinates'] as $url) $this->getJson($url)->assertForbidden();
        $this->postJson('/admin/gis', [])->assertForbidden();
        $this->get('/association/gis/create')->assertNotFound();
        $this->flushSession();
        $this->get('/admin/gis')->assertRedirect(route('login'));
    }

    public function test_existing_invalid_filter_and_bounding_box_handling_is_preserved(): void
    {
        foreach (['mode=unsupported', 'mode=map', 'mode=list&location_type=association_point', 'mode=list&q='.str_repeat('x', 101),
            'mode=list&association_id=invalid', 'mode=list&publication=secret', 'mode=map&bbox[]=124&bbox[]=11&bbox[]=123&bbox[]=10'] as $query) {
            $this->getJson('/admin/gis/data?'.$query)->assertUnprocessable();
        }
        $this->identity = 'account';
        $this->getJson('/association/gis/data?mode=list&lifecycle=archived')->assertUnprocessable();
        $this->getJson('/map/data?mode=list&publication=published')->assertUnprocessable();
    }

    public function test_editor_preserves_form_contract_and_encodes_seed_safely(): void
    {
        $feature = $this->feature(['title' => '</script><img src=x onerror=alert(1)>', 'version' => 'original-version']);
        $this->bindFeature($feature);
        $page = $this->get('/admin/gis/'.self::LOCATION.'/edit')->assertOk()->assertSee('data-gis-draft-state', false)
            ->assertSee('name="version" value="original-version"', false)->assertSee('data-gis-save disabled', false)
            ->assertSee('Fit current geometry')->assertSee('Clear draft geometry')->assertSee('name="_token"', false)
            ->assertSee('name="_method" value="PATCH"', false)->assertDontSee('association_point');
        $this->assertStringNotContainsString('</script><img src=x', $page->getContent());
        $dom = new \DOMDocument(); @$dom->loadHTML($page->getContent());
        $xpath = new \DOMXPath($dom);
        $seed = json_decode($xpath->query('//script[@data-gis-seed]')->item(0)->textContent, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($feature, $seed);
        $this->get('/admin/gis/create')->assertOk()->assertSee('name="idempotency_key"', false)->assertSee('Create unpublished location');
    }

    public function test_historical_editor_requires_the_existing_correction_flag_and_keeps_ownership_disabled(): void
    {
        $this->bindFeature($this->feature(['association_archived_at' => '2026-10-19', 'can_correct_geometry' => true]));
        $this->get('/admin/gis/'.self::LOCATION.'/edit')->assertConflict();
        $this->get('/admin/gis/'.self::LOCATION.'/coordinates')->assertOk()->assertSee('<fieldset disabled>', false)
            ->assertSee('data-gis-correction="true"', false)->assertSee('Save geometry correction')
            ->assertSee('/admin/gis/'.self::LOCATION.'/coordinates', false);
    }

    public function test_correction_route_remains_unavailable_for_an_operational_location(): void
    {
        $this->bindFeature($this->feature());
        $this->get('/admin/gis/'.self::LOCATION.'/coordinates')->assertConflict();
    }

    private function bindFeature(array $feature): void
    {
        $queries = Mockery::mock(GisQueryService::class, [new GisFeatureSerializer, new GisAddressResolver])->makePartial();
        $queries->shouldReceive('detail')->withAnyArgs()->andReturn($feature);
        $this->app->instance(GisQueryService::class, $queries);
    }

    public function test_empty_catalog_is_optional_and_synthetic_classifications_render_in_all_views(): void
    {
        $emptyEditor = $this->get('/admin/gis/create')->assertOk()->assertSee('No site classifications have been approved yet.')
            ->assertSee('name="site_type_id"', false)->assertSee('Unclassified');
        $emptyPublic = $this->get('/map')->assertOk()->assertSee('Unclassified location')->assertSee('All site classifications')
            ->assertSee('Boundary markers are not surveyed site coordinates.')->assertDontSee('gis-key boundary', false);
        $types = ['fish_cage' => 'Fish cage', 'fish_pen' => 'Fish pen', 'seaweed_cultivation_site' => 'Seaweed cultivation site',
            'fishing_gear_site' => 'Fishing gear site', 'post_harvest_facility' => 'Post-harvest facility'];
        foreach ($types as $code => $name) DB::table('gis_site_types')->insert(['code' => $code, 'name' => $name]);
        $feature = $this->feature(['site_type_id' => 1]);
        $this->bindFeature($feature);
        $pages = ['staff' => $this->get('/admin/gis')->assertOk(), 'public' => $this->get('/map')->assertOk(),
            'editor' => $this->get('/admin/gis/'.self::LOCATION.'/edit')->assertOk()];
        $ring = [[123.7, 10.2], [123.75, 10.2], [123.75, 10.25], [123.7, 10.2]];
        foreach (['boundary-editor' => ['type' => 'Polygon', 'coordinates' => [$ring]],
            'multipolygon-editor' => ['type' => 'MultiPolygon', 'coordinates' => [[$ring], [[[123.8, 10.3], [123.85, 10.3], [123.85, 10.35], [123.8, 10.3]]]]]] as $name => $geometry) {
            $this->bindFeature($this->feature(['site_type_id' => 1, 'location_type' => 'project_boundary'], $geometry));
            $this->app['router']->getRoutes()->getByName('admin.gis.edit')->flushController();
            $pages[$name] = $this->get('/admin/gis/'.self::LOCATION.'/edit')->assertOk();
        }
        foreach ($pages as $page) foreach ($types as $name) $page->assertSee($name);
        $pages['editor']->assertSee('value="1" selected', false);
        if ($directory = getenv('ASSOCMAP_GISCLASS_UI_DIRECTORY')) {
            $this->assertMatchesRegularExpression('/\Aassocmap-gisclass-ui-[a-f0-9]{32}\z/', basename($directory));
            $this->assertSame(realpath(sys_get_temp_dir()), realpath(dirname($directory)));
            foreach ($pages as $name => $page) file_put_contents($directory.'/'.$name.'.html', $page->getContent());
            file_put_contents($directory.'/empty-editor.html', $emptyEditor->getContent());
            file_put_contents($directory.'/empty-public.html', $emptyPublic->getContent());
            foreach ([true => 'staff-gallery', false => 'public-gallery'] as $manager => $name) {
                file_put_contents($directory.'/'.$name.'.html', view('components.gis-photos', ['gisManager' => (bool) $manager])->render());
            }
            $image = UploadedFile::fake()->image('site.png', 64, 32);
            file_put_contents($directory.'/photo.png', file_get_contents($image->getPathname()));
        }
    }

    public function test_http_rejects_invalid_site_assignments_before_the_write_service(): void
    {
        $payload = ['parent_association_id' => self::LOCATION, 'project_id' => self::LOCATION,
            'idempotency_key' => self::LOCATION, 'location_type' => 'project_point', 'location_name' => 'Synthetic',
            'geometry' => ['type' => 'Point', 'coordinates' => [123.8, 10.3]]];
        foreach ([-1, 0, 'unknown', 999, ['id' => 1]] as $invalid) {
            $this->postJson('/admin/gis', $payload + ['site_type_id' => $invalid])->assertUnprocessable()->assertJsonValidationErrors('site_type_id');
        }
        foreach (['mode=list&site_type_id=-1', 'mode=list&site_type_id[]=1', 'mode=list&site_type_id=not-a-type'] as $query) {
            $this->getJson('/map/data?'.$query)->assertUnprocessable()->assertJsonValidationErrors('site_type_id');
        }
    }

    public function test_http_create_and_update_pass_valid_null_and_omitted_classifications_to_the_service(): void
    {
        DB::table('gis_site_types')->insert(['id' => 1, 'code' => 'synthetic', 'name' => 'Synthetic']);
        $service = Mockery::mock(GisLocationService::class);
        $payload = ['parent_association_id' => self::LOCATION, 'project_id' => self::LOCATION,
            'location_type' => 'project_point', 'location_name' => 'Synthetic',
            'geometry' => ['type' => 'Point', 'coordinates' => [123.8, 10.3]]];
        $received = [];
        $service->shouldReceive('store')->times(3)->andReturnUsing(function ($data) use (&$received) {
            $received[] = $data;

            return ['created' => true, 'feature' => $this->feature()];
        });
        $service->shouldReceive('update')->times(3)->andReturnUsing(function ($id, $data) use (&$received) {
            $this->assertSame(self::LOCATION, $id);
            $received[] = $data;

            return $this->feature();
        });
        $this->app->instance(GisLocationService::class, $service);
        foreach ([['site_type_id' => 1], ['site_type_id' => null], []] as $change) {
            $this->postJson('/admin/gis', $payload + $change + ['idempotency_key' => self::LOCATION])->assertCreated();
            $this->patchJson('/admin/gis/'.self::LOCATION, $payload + $change + ['version' => 'expected-version'])->assertOk();
            foreach (array_slice($received, -2) as $data) {
                $this->assertSame(array_key_exists('site_type_id', $change), array_key_exists('site_type_id', $data));
                $this->assertSame($change['site_type_id'] ?? null, $data['site_type_id'] ?? null);
            }
        }
    }

    private function feature(array $properties = [], ?array $geometry = null): array
    {
        return ['type' => 'Feature', 'id' => self::LOCATION, 'geometry' => $geometry ?? ['type' => 'Point', 'coordinates' => [123.8, 10.3]],
            'properties' => array_replace(['location_type' => 'project_point', 'title' => 'Synthetic location', 'archived_at' => null,
                'association_archived_at' => null, 'project_archived_at' => null, 'can_correct_geometry' => false,
                'version' => 'saved-version', 'project_id' => self::LOCATION, 'parent_association_id' => self::LOCATION], $properties)];
    }
}
