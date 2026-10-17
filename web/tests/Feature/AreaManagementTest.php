<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Municipality;
use App\Services\AreaManagementQueryService;
use App\Services\SupabaseAuthService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AreaManagementTest extends TestCase
{
    private const ADMIN = '11111111-1111-4111-8111-111111111111';
    private const OFFICER = '22222222-2222-4222-8222-222222222222';
    private const ACCOUNT = '33333333-3333-4333-8333-333333333333';
    private string $identity = self::ADMIN;

    private static function id(int $number): string
    {
        return sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d', $number);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        DB::connection()->getPdo()->sqliteCreateFunction('gen_random_uuid', fn () => (string) Str::uuid());
        Http::swap((new Factory)->preventStrayRequests());
        $this->withoutVite();
        $this->schema();
        $this->fixture();
        $auth = Mockery::mock(SupabaseAuthService::class);
        $auth->shouldReceive('getUser')->andReturnUsing(fn () => ['id' => $this->identity, 'email' => 'synthetic@example.test']);
        $this->app->instance(SupabaseAuthService::class, $auth);
        $this->withSession(['supabase_access_token' => 'synthetic-test-token']);
    }

    protected function tearDown(): void
    {
        try { Http::assertNothingSent(); parent::tearDown(); }
        finally { HandleExceptions::flushState($this); }
    }

    private function schema(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->id(); $t->string('code'); $t->string('name'); $t->boolean('is_active'); $t->timestamps();
        });
        Schema::create('user_profiles', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->integer('role_id'); $t->string('display_name'); $t->boolean('is_active'); $t->timestamps();
        });
        Schema::create('user_invitations', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('auth_user_id')->nullable(); $t->string('email');
            $t->string('status'); $t->string('last_error_code')->nullable(); $t->timestamp('accepted_at')->nullable();
            $t->timestamp('last_attempt_at')->nullable(); $t->timestamps();
        });
        foreach (['municipalities', 'barangays', 'areas'] as $table) {
            Schema::create($table, function (Blueprint $t) use ($table) {
                $t->uuid('id')->primary()->default(DB::raw('(gen_random_uuid())'));
                if ($table === 'municipalities') {
                    $t->string('province')->default('Cebu'); $t->string('region')->default('Region VII');
                    $t->string('locality_type')->default('municipality');
                } else {
                    $foreign = $table === 'barangays' ? 'municipality_id' : 'barangay_id';
                    $t->uuid($foreign);
                    $t->foreign($foreign)->references('id')->on($table === 'barangays' ? 'municipalities' : 'barangays')->restrictOnDelete();
                }
                if ($table === 'areas') $t->text('address');
                else { $t->string('name'); $t->string('psgc_code')->nullable()->unique(); }
                $t->string('source')->default('manual'); $t->boolean('is_active')->default(true);
                $t->uuid('created_by')->nullable(); $t->timestamps();
            });
        }
        Schema::create('associations', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('name'); $t->uuid('area_id');
            $t->foreign('area_id')->references('id')->on('areas')->restrictOnDelete();
            $t->string('status'); $t->timestamp('archived_at')->nullable(); $t->timestamps();
        });
    }

    private function fixture(): void
    {
        foreach ([1 => ['system_admin', self::ADMIN], 2 => ['field_officer', self::OFFICER], 3 => ['association_account', self::ACCOUNT]] as $role => [$code, $id]) {
            DB::table('roles')->insert(['id' => $role, 'code' => $code, 'name' => $code, 'is_active' => true]);
            DB::table('user_profiles')->insert(['id' => $id, 'role_id' => $role, 'display_name' => 'Synthetic actor',
                'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([1 => ['Alpha Municipality', true, 'municipality'], 2 => ['Beta City', true, 'city'],
            3 => ['Inactive Municipality', false, 'municipality'], 4 => ['Zero Municipality', true, 'municipality']] as $id => [$name, $active, $type]) {
            DB::table('municipalities')->insert(['id' => self::id($id), 'name' => $name, 'is_active' => $active,
                'locality_type' => $type, 'psgc_code' => 'M'.$id, 'created_at' => '2020-01-0'.$id, 'updated_at' => now()]);
        }
        foreach ([11 => [1, 'Alpha Barangay', true], 12 => [1, 'Inactive Barangay', false],
            13 => [2, 'Other Barangay', true], 14 => [1, 'Zero Barangay', true]] as $id => [$parent, $name, $active]) {
            DB::table('barangays')->insert(['id' => self::id($id), 'municipality_id' => self::id($parent),
                'name' => $name, 'is_active' => $active, 'psgc_code' => 'B'.$id,
                'created_at' => '2021-01-01', 'updated_at' => now()]);
        }
        foreach ([21 => [11, 'Purok One', true], 22 => [11, 'Sitio Historical', false],
            23 => [12, 'Inactive barangay address', true], 24 => [13, 'Other municipality address', true],
            25 => [11, 'Unoccupied location', true]] as $id => [$parent, $address, $active]) {
            DB::table('areas')->insert(['id' => self::id($id), 'barangay_id' => self::id($parent),
                'address' => $address, 'is_active' => $active, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([31 => [21, 'Alpha Association', 'active', null], 32 => [21, 'Inactive Association', 'inactive', null],
            33 => [22, 'Archived Association', 'active', '2022-01-01'], 34 => [23, 'Historical Association', 'inactive', null],
            35 => [24, 'Other Association', 'active', null]] as $id => [$area, $name, $status, $archived]) {
            DB::table('associations')->insert(['id' => self::id($id), 'area_id' => self::id($area),
                'name' => $name, 'status' => $status, 'archived_at' => $archived, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function dom(string $html): \DOMXPath
    {
        $doc = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors(); libxml_use_internal_errors($previous);

        return new \DOMXPath($doc);
    }

    private function url(array $query = []): string
    {
        return '/admin/areas?'.http_build_query($query);
    }

    public function test_summary_cards_match_lifecycle_counts_and_filter_destinations(): void
    {
        $response = $this->get('/admin/areas')->assertOk()->assertViewIs('admin.areas.register');
        $this->assertSame(['municipalities' => 4, 'associations' => 4, 'current_barangays' => 3, 'inactive_barangays' => 1], $response->viewData('summary'));
        $dom = $this->dom($response->getContent());
        foreach (['municipalities' => [4, 'municipalities', ''], 'current_barangays' => [3, 'barangays', 'active'],
            'inactive_barangays' => [1, 'barangays', 'inactive']] as $card => [$count, $tab, $status]) {
            $href = $dom->query('//a[@data-area-summary="'.$card.'"]')->item(0)->getAttribute('href');
            $filtered = $this->get($href)->assertOk();
            $this->assertSame($count, $filtered->viewData('records')->total());
            $this->assertSame($tab, $filtered->viewData('filters')['tab']);
            $this->assertSame($status, $filtered->viewData('filters')['status']);
            $selected = $this->dom($filtered->getContent())->query('//a[@data-area-summary and @aria-current="page"]');
            $this->assertSame(1, $selected->length);
            $this->assertSame($card, $selected->item(0)->getAttribute('data-area-summary'));
        }
        $this->assertSame(route('admin.associations.index').'#associations',
            $dom->query('//a[@data-area-summary="associations"]')->item(0)->getAttribute('href'));
        $response->assertSee('Inactive Barangays')->assertDontSee('Archived Barangays')
            ->assertDontSee('data-area-list-type="Specific Area"', false);
    }

    public function test_legacy_shell_opens_the_real_register_without_a_separate_specific_area_tab(): void
    {
        $this->get('/admin/areas')->assertOk();
        $html = view('dashboard.index', ['errors' => new \Illuminate\Support\ViewErrorBag])->render();
        $dom = $this->dom($html);
        $this->assertSame(1, $dom->query('//section[@data-page="areas"]//a[@href="'.route('admin.areas.index').'"]')->length);
        $this->assertSame(0, $dom->query('//*[@data-area-list-type="Specific Area"]')->length);
    }

    public function test_rows_count_through_area_without_duplicates_and_keep_zero_and_historical_records(): void
    {
        $response = $this->get('/admin/areas')->assertOk();
        $records = $response->viewData('records')->keyBy('id');
        $this->assertSame(3, $records[self::id(1)]->barangays_count);
        $this->assertSame(4, $records[self::id(1)]->associations_count);
        $this->assertSame(1, $records[self::id(2)]->associations_count);
        $this->assertSame(0, $records[self::id(4)]->associations_count);
        $this->assertSame(0, $records[self::id(4)]->barangays_count);
        $this->assertFalse($records[self::id(3)]->is_active);
        $this->assertSame(4, $this->dom($response->getContent())->query('//table[contains(@class,"area-record-table")]/tbody/tr')->length);
        $barangays = $this->get('/admin/areas?tab=barangays')->assertOk()->viewData('records')->keyBy('id');
        $this->assertSame(3, $barangays[self::id(11)]->associations_count);
        $this->assertSame(1, $barangays[self::id(12)]->associations_count);
        $this->assertSame(0, $barangays[self::id(14)]->associations_count);
    }

    public function test_filters_and_stable_sorting_are_applied_server_side(): void
    {
        DB::table('municipalities')->where('id', self::id(2))->update(['source' => 'psgc']);
        foreach ([
            [['q' => '  ALPHA  '], [1]],
            [['type' => 'city', 'source' => 'psgc'], [2]],
            [['status' => 'inactive'], [3]],
            [['sort' => 'za'], [4, 3, 2, 1]],
            [['sort' => 'newest'], [4, 3, 2, 1]],
            [['sort' => 'oldest'], [1, 2, 3, 4]],
            [['tab' => 'barangays', 'q' => 'Beta City'], [13]],
            [['tab' => 'barangays', 'municipality_id' => self::id(1), 'status' => 'inactive'], [12]],
            [['q' => "' OR 1=1 --"], []],
        ] as [$filters, $ids]) {
            $response = $this->get($this->url($filters))->assertOk();
            $this->assertSame(array_map(self::id(...), $ids), $response->viewData('records')->pluck('id')->all());
        }
        DB::table('municipalities')->where('id', self::id(1))->update(['name' => '50%_! <script>bad()</script>']);
        foreach (['%', '_', '!'] as $search) {
            $response = $this->get($this->url(['q' => $search]))->assertOk()->assertDontSee('<script>bad()</script>', false);
            $this->assertSame([self::id(1)], $response->viewData('records')->pluck('id')->all());
        }
    }

    public function test_cards_and_tab_switches_reset_conflicting_filters_and_preserve_search_and_sort(): void
    {
        $response = $this->get($this->url(['tab' => 'barangays', 'q' => 'Alpha', 'status' => 'inactive',
            'municipality_id' => self::id(2), 'source' => 'psgc', 'page' => 2, 'sort' => 'za']))->assertOk();
        $dom = $this->dom($response->getContent());
        foreach ($dom->query('//a[@data-area-summary and @data-area-summary!="associations"] | //nav[@aria-label="Geographic record type"]/a') as $link) {
            parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY), $parameters);
            $this->assertSame('Alpha', $parameters['q']);
            $this->assertSame('za', $parameters['sort']);
            $this->assertArrayNotHasKey('municipality_id', $parameters);
            $this->assertArrayNotHasKey('source', $parameters);
            $this->assertArrayNotHasKey('page', $parameters);
            $this->get($link->getAttribute('href'))->assertOk();
        }
    }

    public function test_municipality_details_scope_barangays_associations_and_existing_authorized_links(): void
    {
        $response = $this->get('/admin/municipalities/'.self::id(1))->assertOk()->assertViewIs('admin.areas.municipality')
            ->assertSee('Alpha Municipality')->assertSee('Inactive Barangay')->assertSee('Archived Association')
            ->assertSee('Historical Association')->assertDontSee('Other Association')->assertDontSee('Other Barangay');
        $this->assertSame(3, $response->viewData('barangays')->total());
        $this->assertSame(4, $response->viewData('associations')->total());
        $dom = $this->dom($response->getContent());
        $this->assertGreaterThan(0, $dom->query('//a[@href="'.route('admin.barangays.show', self::id(11)).'"]')->length);
        foreach ($dom->query('//a[contains(@aria-label,"Open Association Management")]') as $link) {
            parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY), $parameters);
            $this->assertSame('Alpha Municipality', $parameters['municipality']);
            $this->assertNotSame('Other Barangay', $parameters['barangay']);
            $this->assertContains($parameters['status'], ['active', 'inactive', 'archived']);
            $this->assertSame('/admin/associations', parse_url($link->getAttribute('href'), PHP_URL_PATH));
        }
    }

    public function test_barangay_details_show_actual_area_ownership_and_zero_address_counts(): void
    {
        $response = $this->get('/admin/barangays/'.self::id(11))->assertOk()->assertViewIs('admin.areas.barangay')
            ->assertSee('Alpha Municipality')->assertSee('Purok One')->assertSee('Sitio Historical')->assertSee('Unoccupied location')
            ->assertSee('Archived Association')->assertDontSee('Other Association')->assertDontSee('Inactive barangay address');
        $this->assertSame(3, $response->viewData('record')->associations_count);
        $this->assertSame(3, $response->viewData('areas')->total());
        $this->assertSame(0, $response->viewData('areas')->keyBy('id')[self::id(25)]->associations_count);
        $dom = $this->dom($response->getContent());
        $button = $dom->query('//button[@data-area-create-type="Specific Area"]')->item(0);
        $this->assertFalse($button->hasAttribute('disabled'));
        $this->assertSame(self::id(11), $button->getAttribute('data-area-barangay'));
        $this->assertSame(1, count($response->viewData('catalog')['options']['barangay_records']));
        $this->get('/admin/barangays/'.self::id(12))->assertOk()->assertSee('Historical Association');
        $this->get('/admin/municipalities/'.self::id(3))->assertOk()->assertSee('Inactive Municipality');
    }

    public function test_empty_states_and_inactive_parent_edits_remain_available(): void
    {
        $this->get('/admin/municipalities/'.self::id(4))->assertOk()->assertSee('No connected barangays')->assertSee('No connected associations');
        $this->get('/admin/barangays/'.self::id(14))->assertOk()->assertSee('No connected associations')->assertSee('No recorded addresses');
        DB::table('municipalities')->where('id', self::id(1))->update(['is_active' => false]);
        $response = $this->get('/admin/barangays/'.self::id(11))->assertOk();
        $this->assertTrue($this->dom($response->getContent())->query('//button[@data-area-create-type="Specific Area"]')->item(0)->hasAttribute('disabled'));
        $this->patch('/admin/barangays/'.self::id(11), ['municipality_id' => self::id(1), 'name' => 'Renamed historical barangay'])
            ->assertRedirect(route('admin.areas.index'));
        $this->assertSame('Renamed historical barangay', DB::table('barangays')->where('id', self::id(11))->value('name'));
    }

    public function test_lists_and_all_detail_relationships_are_paginated_and_keep_return_context(): void
    {
        for ($number = 100; $number < 127; $number++) {
            DB::table('barangays')->insert(['id' => self::id($number), 'municipality_id' => self::id(1), 'name' => 'Extra '.$number]);
            DB::table('areas')->insert(['id' => self::id($number + 100), 'barangay_id' => self::id(11), 'address' => 'Extra '.$number]);
            DB::table('associations')->insert(['id' => self::id($number + 200), 'area_id' => self::id(21), 'name' => 'Extra '.$number, 'status' => 'active']);
        }
        $response = $this->get($this->url(['tab' => 'barangays', 'q' => 'Extra', 'sort' => 'za', 'municipality_id' => self::id(1)]))->assertOk();
        $this->assertCount(25, $response->viewData('records'));
        $next = $this->get($response->viewData('records')->nextPageUrl())->assertOk();
        $this->assertCount(2, $next->viewData('records'));
        $this->assertSame($response->viewData('filters'), $next->viewData('filters'));
        $municipality = $this->get('/admin/municipalities/'.self::id(1).'?page=2&sort=za')->assertOk();
        foreach (['barangays', 'associations'] as $relationship) {
            $paginator = $municipality->viewData($relationship);
            $this->assertCount(25, $paginator);
            $page = $this->get($paginator->nextPageUrl())->assertOk();
            $this->assertSame($municipality->viewData('backUrl'), $page->viewData('backUrl'));
            $this->assertLessThanOrEqual(6, $page->viewData($relationship)->count());
        }
        $barangay = $this->get('/admin/barangays/'.self::id(11))->assertOk();
        foreach (['areas', 'associations'] as $relationship) {
            $this->assertCount(25, $barangay->viewData($relationship));
            $this->get($barangay->viewData($relationship)->nextPageUrl())->assertOk();
        }
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_are_rejected(array $filters): void
    {
        foreach (['/admin/areas', '/admin/municipalities/'.self::id(1), '/admin/barangays/'.self::id(11)] as $url) {
            $this->getJson($url.'?'.http_build_query($filters))->assertUnprocessable();
        }
    }

    public static function invalidFilters(): array
    {
        return [
            [['tab' => 'specific_areas']], [['tab' => ['barangays']]], [['status' => 'archived']],
            [['q' => ['Alpha']]], [['q' => str_repeat('x', 121)]], [['sort' => 'name; DROP TABLE areas']],
            [['source' => ['manual']]], [['municipality_id' => 'bad-uuid']], [['page' => 0]], [['areas_page' => -1]],
        ];
    }

    public function test_unauthorized_roles_cannot_read_or_mutate_geography_by_direct_url(): void
    {
        foreach ([self::OFFICER, self::ACCOUNT] as $actor) {
            $this->identity = $actor;
            foreach (['/admin/areas', '/admin/municipalities/'.self::id(1), '/admin/barangays/'.self::id(11)] as $url) $this->get($url)->assertForbidden();
            $this->post('/admin/municipalities', ['name' => 'Unauthorized'])->assertForbidden();
            $this->patch('/admin/barangays/'.self::id(11), ['name' => 'Unauthorized'])->assertForbidden();
            $this->patch('/admin/municipalities/'.self::id(1).'/deactivate')->assertForbidden();
            $this->post('/admin/areas', ['barangay_id' => self::id(11), 'address' => 'Unauthorized'])->assertForbidden();
        }
        $this->assertSame(4, DB::table('municipalities')->count());
        $this->assertTrue((bool) DB::table('municipalities')->where('id', self::id(1))->value('is_active'));
    }

    public function test_guests_and_revoked_administrators_are_denied_and_missing_records_are_404(): void
    {
        $this->withSession(['supabase_access_token' => null])->get('/admin/areas')->assertRedirect(route('login'));
        $this->get('/admin/barangays/'.self::id(11))->assertRedirect(route('login'));
        $this->withSession(['supabase_access_token' => 'synthetic-test-token']);
        foreach (['/admin/municipalities/not-a-uuid', '/admin/barangays/'.self::id(999)] as $url) $this->get($url)->assertNotFound();
        DB::table('user_profiles')->where('id', self::ADMIN)->update(['is_active' => false]);
        $this->get('/admin/areas')->assertRedirect(route('login'));
        DB::table('user_profiles')->where('id', self::ADMIN)->update(['is_active' => true]);
        DB::table('roles')->where('id', 1)->update(['is_active' => false]);
        $this->withSession(['supabase_access_token' => 'synthetic-test-token'])->get('/admin/municipalities/'.self::id(1))->assertRedirect(route('login'));
    }

    public function test_existing_create_edit_and_deactivate_handlers_preserve_geographic_links(): void
    {
        $this->post('/admin/municipalities', ['name' => 'New Municipality', 'area_form' => 'editor'])->assertRedirect(route('admin.areas.index'));
        $this->post('/admin/barangays', ['municipality_id' => self::id(1), 'name' => 'New Barangay', 'area_form' => 'editor'])->assertRedirect(route('admin.areas.index'));
        $this->post('/admin/areas', ['barangay_id' => self::id(11), 'address' => 'New Purok', 'area_form' => 'editor'])->assertRedirect(route('admin.areas.index'));
        $this->patch('/admin/municipalities/'.self::id(1), ['name' => 'Renamed Municipality', 'psgc_code' => 'M1'])->assertRedirect(route('admin.areas.index'));
        $before = DB::table('associations')->orderBy('id')->get()->toJson();
        $this->patch('/admin/municipalities/'.self::id(1).'/deactivate')->assertRedirect(route('admin.areas.index'));
        $this->patch('/admin/barangays/'.self::id(11).'/deactivate')->assertRedirect(route('admin.areas.index'));
        $this->assertSame($before, DB::table('associations')->orderBy('id')->get()->toJson());
        $this->get('/admin/barangays/'.self::id(11))->assertOk()->assertSee('Archived Association')->assertSee('Purok One');
        $this->postJson('/admin/areas', ['barangay_id' => self::id(11), 'address' => 'Blocked'])->assertUnprocessable();
        $this->postJson('/admin/barangays', ['municipality_id' => self::id(1), 'name' => 'Blocked'])->assertUnprocessable();
    }

    public function test_geographic_writes_still_require_csrf_protection(): void
    {
        $this->app->bind(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, function ($app) {
            return new class($app, $app['encrypter']) extends \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken {
                protected function runningUnitTests() { return false; }
            };
        });
        $this->post('/admin/municipalities', ['name' => 'Blocked'])->assertStatus(419);
        $this->post('/admin/areas', ['barangay_id' => self::id(11), 'address' => 'Blocked'])->assertStatus(419);
        $this->patch('/admin/barangays/'.self::id(11), ['name' => 'Blocked'])->assertStatus(419);
        $this->patch('/admin/municipalities/'.self::id(1).'/deactivate')->assertStatus(419);
        $this->assertSame(4, DB::table('municipalities')->count());
        $this->assertSame(5, DB::table('areas')->count());
        $this->assertTrue((bool) DB::table('municipalities')->where('id', self::id(1))->value('is_active'));
    }

    public function test_validation_restores_the_correct_editor_and_prevents_duplicate_records(): void
    {
        $url = '/admin/barangays/'.self::id(11);
        $this->from($url)->post('/admin/areas', ['area_form' => 'editor', 'barangay_id' => self::id(11), 'address' => 'Purok One'])
            ->assertRedirect($url)->assertSessionHasErrors('address');
        $restored = $this->get($url)->assertOk()->assertSee('Purok One')->assertSee('data-has-errors="true"', false);
        $this->assertSame(1, $this->dom($restored->getContent())->query('//select[@name="area_type"]/option[@value="Specific Area" and @selected]')->length);
        $this->assertSame(5, DB::table('areas')->count());
        $this->from('/admin/areas')->patch('/admin/barangays/'.self::id(11), [
            'area_form' => 'editor', 'area_type' => 'Barangay', 'area_record_id' => self::id(11),
            'municipality_id' => self::id(1), 'name' => '',
        ])->assertRedirect('/admin/areas')->assertSessionHasErrors('name');
        $this->get('/admin/areas')->assertOk()->assertSee('data-mode="edit"', false)
            ->assertSee('action="'.route('admin.barangays.update', self::id(11)).'"', false);
    }

    public function test_query_counts_remain_constant_and_relationship_pages_are_bounded(): void
    {
        $query = app(AreaManagementQueryService::class);
        $filters = ['tab' => 'municipalities', 'q' => '', 'source' => '', 'type' => '', 'status' => '', 'municipality_id' => '', 'sort' => 'az'];
        $measure = function (callable $callback) {
            DB::flushQueryLog(); DB::enableQueryLog(); $result = $callback(); $count = count(DB::getQueryLog()); DB::disableQueryLog();
            return [$result, $count];
        };
        [, $municipalityBefore] = $measure(fn () => $query->records($filters));
        [, $barangayBefore] = $measure(fn () => $query->records(array_replace($filters, ['tab' => 'barangays'])));
        [, $detailBefore] = $measure(fn () => $query->barangayDetails(Barangay::findOrFail(self::id(11)), []));
        for ($number = 100; $number < 127; $number++) {
            DB::table('municipalities')->insert(['id' => self::id($number), 'name' => 'Extra municipality '.$number]);
            DB::table('barangays')->insert(['id' => self::id($number + 100), 'municipality_id' => self::id(1), 'name' => 'Extra barangay '.$number]);
            DB::table('areas')->insert(['id' => self::id($number + 200), 'barangay_id' => self::id(11), 'address' => 'Extra address '.$number]);
        }
        [$records, $municipalityAfter] = $measure(fn () => $query->records($filters));
        [, $barangayAfter] = $measure(fn () => $query->records(array_replace($filters, ['tab' => 'barangays'])));
        [$details, $detailAfter] = $measure(fn () => $query->barangayDetails(Barangay::findOrFail(self::id(11)), []));
        $this->assertCount(25, $records);
        $this->assertCount(25, $details['areas']);
        $this->assertSame(2, $municipalityAfter);
        $this->assertSame(3, $barangayAfter);
        $this->assertSame($municipalityBefore, $municipalityAfter);
        $this->assertSame($barangayBefore, $barangayAfter);
        $this->assertSame($detailBefore, $detailAfter);
        $this->assertLessThanOrEqual(6, $detailAfter);
        [, $summaryQueries] = $measure(fn () => $query->summary());
        $this->assertSame(1, $summaryQueries);
    }
}
