<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Municipality;
use App\Models\UserProfile;
use App\Http\Requests\StoreAreaRequest;
use App\Services\Areas\AreaManagementQueryService;
use App\Services\Areas\AreaService;
use App\Services\Areas\BarangayService;
use App\Services\Areas\MunicipalityService;
use App\Services\Auth\SupabaseAuthService;
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
        config(['associations.geography_schema_ready' => true, 'associations.direct_geography' => true]);
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        $pdo = DB::connection()->getPdo();
        $createFunction = $pdo instanceof \Pdo\Sqlite
            ? $pdo->createFunction(...)
            : \Closure::fromCallable([$pdo, 'sqliteCreateFunction']);
        $createFunction('gen_random_uuid', fn () => (string) Str::uuid());
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
            $t->text('description')->nullable();
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
                if ($table === 'areas') {
                    $t->text('address');
                    $t->unique(['id', 'barangay_id']);
                }
                else { $t->string('name'); $t->string('psgc_code')->nullable()->unique(); }
                $t->string('source')->default('manual'); $t->boolean('is_active')->default(true);
                $t->uuid('created_by')->nullable(); $t->timestamps();
            });
        }
        DB::statement('CREATE UNIQUE INDEX uq_area_barangay_address_ci ON areas(barangay_id, LOWER(address))');
        Schema::create('associations', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('name'); $t->uuid('area_id')->nullable();
            $t->uuid('barangay_id'); $t->text('specific_address')->nullable();
            $t->foreign('area_id')->references('id')->on('areas')->restrictOnDelete();
            $t->foreign('barangay_id')->references('id')->on('barangays')->restrictOnDelete();
            $t->foreign(['area_id', 'barangay_id'])->references(['id', 'barangay_id'])->on('areas')->restrictOnDelete()->restrictOnUpdate();
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
                'barangay_id' => DB::table('areas')->where('id', self::id($area))->value('barangay_id'),
                'name' => $name, 'status' => $status, 'archived_at' => $archived, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public static function geographyWrites(): array
    {
        return array_map(fn ($operation) => [$operation], [
            'municipality_create', 'municipality_update', 'municipality_deactivate',
            'barangay_create', 'barangay_update', 'barangay_deactivate', 'area_create',
        ]);
    }

    public static function changedGeographyActors(): iterable
    {
        foreach (self::geographyWrites() as [$operation]) {
            foreach (['revoked', 'session_cutoff', 'advanced_cutoff', 'changed_role', 'inactive_role', 'email_hold'] as $state) {
                yield $operation.' '.$state => [$operation, $state];
            }
        }
    }

    private function geographyFreshnessSchema(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->timestampTz('auth_sessions_valid_after')->nullable();
            $table->uuid('email_change_hold_id')->nullable();
        });
    }

    private function geographyWrite(string $operation, UserProfile $actor): void
    {
        $municipality = Municipality::findOrFail(self::id(1));
        $barangay = Barangay::findOrFail(self::id(11));
        $data = ['name' => 'Synthetic security locality', 'municipality_id' => $municipality->id,
            'barangay_id' => $barangay->id, 'address' => 'Synthetic security address',
            'auth_sessions_valid_after' => '2026-02-01 00:00:00'];
        match ($operation) {
            'municipality_create' => app(MunicipalityService::class)->create($data, $actor),
            'municipality_update' => app(MunicipalityService::class)->update($municipality, $data, $actor),
            'municipality_deactivate' => app(MunicipalityService::class)->deactivate($municipality, $actor),
            'barangay_create' => app(BarangayService::class)->create($data, $actor),
            'barangay_update' => app(BarangayService::class)->update($barangay, $data, $actor),
            'barangay_deactivate' => app(BarangayService::class)->deactivate($barangay, $actor),
            'area_create' => app(AreaService::class)->create($data, $actor),
        };
    }

    #[DataProvider('changedGeographyActors')]
    public function test_geography_writes_reject_changed_authenticated_state_before_persistence(string $operation, string $state): void
    {
        $this->geographyFreshnessSchema();
        if ($state === 'advanced_cutoff') {
            DB::table('user_profiles')->where('id', self::ADMIN)->update(['auth_sessions_valid_after' => '2026-01-01 00:00:00']);
        }
        $actor = UserProfile::with('role')->findOrFail(self::ADMIN);
        $this->assertTrue($actor->canAccessApplication());
        match ($state) {
            'revoked' => DB::table('user_profiles')->where('id', self::ADMIN)->update(['is_active' => false]),
            'session_cutoff', 'advanced_cutoff' => DB::table('user_profiles')->where('id', self::ADMIN)->update(['auth_sessions_valid_after' => '2026-02-01 00:00:00']),
            'changed_role' => DB::table('user_profiles')->where('id', self::ADMIN)->update(['role_id' => 2]),
            'inactive_role' => DB::table('roles')->where('id', 1)->update(['is_active' => false]),
            'email_hold' => DB::table('user_profiles')->where('id', self::ADMIN)->update(['email_change_hold_id' => self::OFFICER]),
        };
        $before = $this->geographySnapshot();
        try {
            $this->geographyWrite($operation, $actor);
            $this->fail('A changed authenticated Administrator must not change geography.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame($before, $this->geographySnapshot());
    }

    #[DataProvider('geographyWrites')]
    public function test_geography_writes_accept_an_unchanged_authenticated_cutoff(string $operation): void
    {
        $this->geographyFreshnessSchema();
        DB::table('user_profiles')->where('id', self::ADMIN)->update(['auth_sessions_valid_after' => '2026-01-01 00:00:00']);
        $actor = UserProfile::with('role')->findOrFail(self::ADMIN);
        $before = $this->geographySnapshot();
        $this->geographyWrite($operation, $actor);
        $this->assertNotSame($before, $this->geographySnapshot());
    }

    public function test_geography_http_write_rechecks_a_cutoff_changed_after_authentication(): void
    {
        $this->geographyFreshnessSchema();
        $changed = false;
        $this->app->resolving(MunicipalityService::class, function () use (&$changed): void {
            $changed = true;
            DB::table('user_profiles')->where('id', self::ADMIN)->update(['auth_sessions_valid_after' => '2026-02-01 00:00:00']);
        });
        $before = $this->geographySnapshot();
        $this->postJson(route('admin.municipalities.store'), ['name' => 'Rejected HTTP locality',
            'auth_sessions_valid_after' => '2026-02-01 00:00:00'])->assertForbidden();
        $this->assertTrue($changed);
        $this->assertSame($before, $this->geographySnapshot());
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
            /** @var \DOMElement $link */
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
            /** @var \DOMElement $link */
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
            DB::table('associations')->insert(['id' => self::id($number + 200), 'area_id' => self::id(21), 'barangay_id' => self::id(11), 'name' => 'Extra '.$number, 'status' => 'active']);
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
            $this->post('/admin/areas', ['municipality_id' => self::id(1), 'barangay_id' => self::id(11), 'address' => 'Unauthorized'])->assertForbidden();
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
        $this->post('/admin/areas', ['municipality_id' => self::id(1), 'barangay_id' => self::id(11), 'address' => 'New Purok', 'area_form' => 'editor'])->assertRedirect(route('admin.areas.index'));
        $this->patch('/admin/municipalities/'.self::id(1), ['name' => 'Renamed Municipality', 'psgc_code' => 'M1'])->assertRedirect(route('admin.areas.index'));
        $before = DB::table('associations')->orderBy('id')->get()->toJson();
        $this->patch('/admin/municipalities/'.self::id(1).'/deactivate')->assertRedirect(route('admin.areas.index'));
        $this->patch('/admin/barangays/'.self::id(11).'/deactivate')->assertRedirect(route('admin.areas.index'));
        $this->assertSame($before, DB::table('associations')->orderBy('id')->get()->toJson());
        $this->get('/admin/barangays/'.self::id(11))->assertOk()->assertSee('Archived Association')->assertSee('Purok One');
        $this->postJson('/admin/areas', ['municipality_id' => self::id(1), 'barangay_id' => self::id(11), 'address' => 'Blocked'])->assertUnprocessable();
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
        $this->post('/admin/areas', ['municipality_id' => self::id(1), 'barangay_id' => self::id(11), 'address' => 'Blocked'])->assertStatus(419);
        $this->patch('/admin/barangays/'.self::id(11), ['name' => 'Blocked'])->assertStatus(419);
        $this->patch('/admin/municipalities/'.self::id(1).'/deactivate')->assertStatus(419);
        $this->assertSame(4, DB::table('municipalities')->count());
        $this->assertSame(5, DB::table('areas')->count());
        $this->assertTrue((bool) DB::table('municipalities')->where('id', self::id(1))->value('is_active'));
    }

    public function test_validation_restores_the_correct_editor_and_prevents_duplicate_records(): void
    {
        $url = '/admin/barangays/'.self::id(11);
        $this->from($url)->post('/admin/areas', ['area_form' => 'editor', 'municipality_id' => self::id(1), 'barangay_id' => self::id(11), 'address' => 'Purok One'])
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

    private function geographySnapshot(): array
    {
        return collect(['municipalities', 'barangays', 'areas', 'associations'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    #[DataProvider('invalidAreaGeography')]
    public function test_specific_area_requires_active_matching_parents_and_a_nonblank_address(array $overrides, string $field): void
    {
        $before = $this->geographySnapshot();
        $data = array_replace(['municipality_id' => self::id(1), 'barangay_id' => self::id(11), 'address' => 'New address'], $overrides);
        $this->postJson('/admin/areas', $data)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $this->geographySnapshot());
    }

    public static function invalidAreaGeography(): array
    {
        return [
            [['municipality_id' => null], 'municipality_id'], [['municipality_id' => ''], 'municipality_id'],
            [['municipality_id' => []], 'municipality_id'], [['municipality_id' => 'bad'], 'municipality_id'],
            [['municipality_id' => self::id(999)], 'municipality_id'], [['municipality_id' => self::id(3)], 'municipality_id'],
            [['municipality_id' => self::id(2)], 'barangay_id'],
            [['barangay_id' => null], 'barangay_id'], [['barangay_id' => []], 'barangay_id'],
            [['barangay_id' => 'bad'], 'barangay_id'], [['barangay_id' => self::id(999)], 'barangay_id'],
            [['barangay_id' => self::id(12)], 'barangay_id'],
            [['address' => null], 'address'], [['address' => " \t\r\n "], 'address'], [['address' => ['bad']], 'address'],
        ];
    }

    public function test_specific_area_trims_address_prevents_duplicates_and_preserves_existing_records(): void
    {
        $before = $this->geographySnapshot();
        foreach (['  pUrOk OnE  ', '  SITIO HISTORICAL  '] as $address) {
            $this->postJson('/admin/areas', ['municipality_id' => self::id(1), 'barangay_id' => self::id(11), 'address' => $address])
                ->assertUnprocessable()->assertJsonValidationErrors('address');
        }
        $this->assertSame($before, $this->geographySnapshot());
        foreach ([[1, 11, '  New Purok  ', 'New Purok'], [2, 13, 'Purok One', 'Purok One']] as [$municipality, $barangay, $address, $stored]) {
            $this->post('/admin/areas', ['municipality_id' => self::id($municipality), 'barangay_id' => self::id($barangay),
                'address' => $address, 'source' => 'imported', 'created_by' => self::OFFICER, 'is_active' => false])
                ->assertSessionHasNoErrors()->assertRedirect(route('admin.areas.index'));
            $this->assertDatabaseHas('areas', ['barangay_id' => self::id($barangay), 'address' => $stored,
                'source' => 'manual', 'created_by' => self::ADMIN, 'is_active' => true]);
        }
        $this->assertDatabaseCount('areas', 7);
        $after = $this->geographySnapshot();
        foreach (['municipalities', 'barangays', 'associations'] as $table) $this->assertSame($before[$table], $after[$table]);
        $this->assertSame($before['areas'], DB::table('areas')->whereIn('id', array_column(json_decode($before['areas'], true), 'id'))->orderBy('id')->get()->toJson());
    }

    public function test_specific_area_service_rechecks_parents_changed_after_validation(): void
    {
        $data = ['municipality_id' => self::id(1), 'barangay_id' => self::id(11), 'address' => 'New address'];
        foreach (['municipalities' => 'municipality_id', 'barangays' => 'barangay_id'] as $table => $field) {
            $request = StoreAreaRequest::create('/admin/areas', 'POST', $data);
            validator($data, $request->rules())->validate();
            DB::table($table)->where('id', $data[$field])->update(['is_active' => false]);
            $before = $this->geographySnapshot();
            try {
                app(AreaService::class)->create($data, UserProfile::findOrFail(self::ADMIN));
                $this->fail('A deactivated parent must not be used.');
            } catch (\Illuminate\Validation\ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame($before, $this->geographySnapshot());
            DB::table($table)->where('id', $data[$field])->update(['is_active' => true]);
        }
    }

    public function test_specific_area_editor_submits_municipality_and_restores_its_type_after_missing_barangay(): void
    {
        $url = '/admin/barangays/'.self::id(11);
        $this->from($url)->post('/admin/areas', ['area_form' => 'editor', 'area_type' => 'Specific Area',
            'municipality_id' => self::id(1), 'address' => 'Unsaved independent Area address'])
            ->assertRedirect($url)->assertSessionHasErrors('barangay_id');
        $response = $this->get($url)->assertOk()->assertSee('Unsaved independent Area address');
        $dom = $this->dom($response->getContent());
        $this->assertSame(1, $dom->query('//select[@name="area_type"]/option[@value="Specific Area" and @selected]')->length);
        $this->assertSame(1, $dom->query('//select[@data-specific-municipality and @name="municipality_id" and @required]/option[@selected and @value="'.self::id(1).'"]')->length);
        DB::table('municipalities')->where('id', self::id(1))->update(['is_active' => false]);
        $catalog = app(AreaManagementQueryService::class)->editorCatalog(Barangay::with('municipality')->findOrFail(self::id(11)));
        $this->assertSame([], $catalog['options']['barangay_records']);
        $this->assertSame([], $catalog['options']['specific_municipality_records']);
    }

    public function test_area_form_request_authorizes_only_an_eligible_administrator(): void
    {
        $request = new StoreAreaRequest;
        $this->assertFalse($request->authorize());
        foreach ([self::ADMIN => true, self::OFFICER => false, self::ACCOUNT => false] as $id => $allowed) {
            $request->attributes->set('current_user', UserProfile::with('role')->findOrFail($id));
            $this->assertSame($allowed, $request->authorize());
        }
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
