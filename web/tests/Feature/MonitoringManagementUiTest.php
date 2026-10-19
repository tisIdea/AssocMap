<?php

namespace Tests\Feature;

use App\Services\SupabaseAuthService;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedDatabaseManager;
use Tests\Support\MonitoringMaterialsFixture;
use Tests\TestCase;

class MonitoringManagementUiTest extends TestCase
{
    use MonitoringMaterialsFixture;

    private string $identity = self::ADMIN;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertInstanceOf(IsolatedDatabaseManager::class, DB::getFacadeRoot());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        config(['app.key' => 'base64:'.base64_encode(str_repeat('d', 32))]);
        $this->withoutVite();
        $this->schema();
        $this->fixtures();
        $auth = Mockery::mock(SupabaseAuthService::class);
        $auth->shouldReceive('getUser')->andReturnUsing(fn () => ['id' => $this->identity]);
        $this->app->instance(SupabaseAuthService::class, $auth);
        $this->withSession(['supabase_access_token' => 'synthetic-monitoring-session']);
        DB::table('monitoring_records')->update(['created_at' => '2026-01-15 00:00:00']);
    }

    protected function tearDown(): void
    {
        try { Http::assertNothingSent(); parent::tearDown(); }
        finally { HandleExceptions::flushState($this); }
    }

    public static function roles(): array
    {
        return ['Admin' => [self::ADMIN, 'admin'], 'Field Officer' => [self::OFFICER, 'field-officer'],
            'Association Account' => [self::ACCOUNT, 'association']];
    }

    private function id(int $number): string
    {
        return sprintf('99999999-9999-4999-8999-%012d', $number);
    }

    private function addRecords(int $count): void
    {
        for ($number = 1; $number <= $count; $number++) {
            $id = $this->id($number);
            DB::table('projects')->insert(['id' => $id, 'association_id' => self::ASSOCIATION,
                'title' => 'Extra Project '.$number, 'program_year' => 2026, 'status' => 'ongoing']);
            DB::table('monitoring_records')->insert(['id' => $id, 'project_id' => $id, 'year' => 2026, 'quarter_id' => 11,
                'monitoring_date' => '2026-01-15', 'monitored_by' => self::OFFICER, 'updated_by' => self::ADMIN,
                'site_validated' => false, 'project_status' => 'ongoing', 'created_at' => '2026-01-15 00:00:00']);
        }
    }

    private function addVisit(int $number, array $changes = []): void
    {
        DB::table('monitoring_visit_reports')->insert(array_replace(['id' => $this->id(100 + $number),
            'monitoring_record_id' => self::RECORD, 'monitoring_date' => '2026-01-16', 'created_at' => '2026-01-16 00:00:00',
            'conducted_by_user_id' => self::OFFICER, 'site_validated' => true, 'overall_remarks' => 'Visit '.$number], $changes));
    }

    #[DataProvider('roles')]
    public function test_shared_pages_preserve_each_roles_navigation_and_management_actions(string $identity, string $prefix): void
    {
        $this->identity = $identity;
        $this->get('/'.$prefix.'/monitoring')->assertOk()->assertSee('Monitoring Management')
            ->assertSee('Matching projects')->assertSee('monitoring-filter-panel" hidden', false);
        foreach (['overview', 'production', 'income', 'materials'] as $section) {
            $page = $this->get(route($prefix.'.monitoring.workspace', [self::RECORD, $section]))->assertOk()
                ->assertSee('Synthetic Association')->assertSee('Synthetic Project')->assertSee('Back to Dashboard');
            $dom = new \DOMDocument();
            @$dom->loadHTML($page->getContent());
            $xpath = new \DOMXPath($dom);
            $this->assertSame(1, $xpath->query('//nav[@aria-label="Monitoring navigation"]/a[@aria-current="page"]')->length);
            if ($identity === self::ACCOUNT) $page->assertDontSee('Edit Overview')->assertDontSee('Manage '.ucfirst($section));
            elseif ($section !== 'overview') $page->assertSee('Manage '.ucfirst($section));
        }
        $visits = $this->get(route($prefix.'.monitoring.visits', self::RECORD))->assertOk();
        if ($identity === self::ACCOUNT) {
            $visits->assertDontSee('Add Visit Report');
            $this->get('/association/monitoring/create')->assertNotFound();
        } else {
            $visits->assertSee('Add Visit Report');
            $this->get('/'.$prefix.'/monitoring/create')->assertOk()->assertSee('name="_token"', false);
            foreach (['production', 'income', 'materials'] as $section) {
                $this->get(route($prefix.'.monitoring.section.edit', [self::RECORD, $section]))->assertOk()
                    ->assertSee('name="production_revision"', false)
                    ->assertSee('name="'.($section === 'production' ? 'production' : $section).'_revision"', false);
            }
        }
    }

    #[DataProvider('roles')]
    public function test_register_filters_counts_pagination_and_reset_use_the_scoped_full_result(string $identity, string $prefix): void
    {
        $this->identity = $identity;
        $this->addRecords(29);
        DB::table('quarters')->insert(['id' => 22, 'quarter_number' => 2, 'name' => 'Q2']);
        DB::table('monitoring_records')->where('id', self::RECORD)->update(['year' => 2025, 'quarter_id' => 22]);
        $url = '/'.$prefix.'/monitoring';
        $page = $this->get($url.'?year=2026&search=Extra&validated=0&quarter=1&sort=oldest')->assertOk()
            ->assertSee('4 active filter(s)')->assertSee('href="'.url($url).'">Reset', false);
        $records = $page->viewData('projects');
        $this->assertSame(29, $records->total());
        $this->assertCount(25, $records);
        $this->assertSame($this->id(1), $records->first()->id);
        foreach (['year=2026', 'search=Extra', 'validated=0', 'quarter=1', 'sort=oldest'] as $parameter) {
            $this->assertStringContainsString($parameter, $records->nextPageUrl());
        }
        $this->assertCount(4, $this->get($records->nextPageUrl())->assertOk()->viewData('projects'));
        $this->assertSame(1, $this->get($url.'?year=2025&quarter=2&validated=1&sort=quarter')->assertOk()->viewData('projects')->total());
        $this->assertSame(29, $this->get($url)->assertOk()->viewData('projects')->total());
        $this->get($url.'?search=missing')->assertOk()->assertSee('No matching Monitoring records');
    }

    public function test_filter_validation_rejects_unlisted_sort_fields_and_malformed_values(): void
    {
        foreach (['sort' => 'project_id desc', 'year' => 'invalid', 'quarter' => 5, 'validated' => 2, 'search' => ['bad']] as $key => $value) {
            $this->getJson('/admin/monitoring?'.http_build_query([$key => $value]))->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $this->getJson(route('admin.monitoring.visits', [self::RECORD, 'sort' => 'quarter']))
            ->assertUnprocessable()->assertJsonValidationErrors('sort');
    }

    public function test_quarter_sort_uses_quarter_numbers_instead_of_lookup_ids(): void
    {
        $this->addRecords(3);
        foreach ([7 => 2, 3 => 3, 23 => 4] as $id => $number) {
            DB::table('quarters')->insert(['id' => $id, 'quarter_number' => $number, 'name' => 'Q'.$number]);
            DB::table('monitoring_records')->where('id', $this->id($number - 1))->update(['quarter_id' => $id]);
        }
        $records = $this->get('/admin/monitoring?sort=quarter')->assertOk()->viewData('projects');
        $this->assertSame([1, 2, 3, 4], $records->getCollection()->map(fn ($project) => (int) $project->first_matching_quarter)->all());
    }

    #[DataProvider('roles')]
    public function test_visit_search_precedes_pagination_and_preserves_history_and_literal_wildcards(string $identity, string $prefix): void
    {
        $this->identity = $identity;
        for ($number = 1; $number <= 30; $number++) $this->addVisit($number);
        DB::table('monitoring_visit_reports')->where('id', $this->id(130))->update([
            'overall_remarks' => 'Unique 100%_! <script>draft</script>', 'site_validated' => false,
            'monitoring_date' => '2025-12-31', 'is_legacy_import' => true,
        ]);
        $url = route($prefix.'.monitoring.visits', self::RECORD);
        $page = $this->get($url)->assertOk();
        $this->assertCount(25, $page->viewData('reports'));
        $filtered = $this->get($url.'?search=100%25_!&validated=0&sort=oldest')->assertOk()
            ->assertSee('Unique 100%_! &lt;script&gt;draft&lt;/script&gt;', false)
            ->assertSee('Dec 31, 2025')->assertSee('Retained historical visit report.');
        $this->assertSame(1, $filtered->viewData('reports')->total());
        $this->assertSame(30, $this->get($url.'?search=Synthetic%20Actor')->assertOk()->viewData('reports')->total());
        $this->assertSame(1, $this->get($url.'?search=Dec%2031,%202025')->assertOk()->viewData('reports')->total());
        $matched = $this->get($url.'?search=Visit&sort=oldest')->assertOk()->viewData('reports');
        $this->assertSame(29, $matched->total());
        $this->assertSame($this->id(101), $matched->first()->id);
        $this->assertStringContainsString('search=Visit', $matched->nextPageUrl());
        $this->assertStringContainsString('sort=oldest', $matched->nextPageUrl());
        $this->get($url.'?search=absent')->assertOk()->assertSee('No Visit Reports match these filters.');
        $this->assertDatabaseCount('monitoring_visit_reports', 30);
    }

    public function test_search_cannot_expand_officer_or_account_access_to_another_association(): void
    {
        $this->addRecords(1);
        DB::table('associations')->insert(['id' => $this->id(500), 'name' => 'Restricted Association', 'status' => 'active',
            'primary_field_officer_id' => self::ADMIN]);
        DB::table('projects')->where('id', $this->id(1))->update(['association_id' => $this->id(500), 'title' => 'Restricted Project']);
        foreach ([self::OFFICER => 'field-officer', self::ACCOUNT => 'association'] as $identity => $prefix) {
            $this->identity = $identity;
            $this->assertSame(0, $this->get('/'.$prefix.'/monitoring?search=Restricted')->assertOk()->viewData('projects')->total());
            $this->get(route($prefix.'.monitoring.visits', $this->id(1)).'?search=Restricted')->assertNotFound();
        }
    }

    public function test_saved_values_units_money_and_material_observations_render_without_writes(): void
    {
        DB::table('monitoring_income')->insert(['id' => $this->id(1), 'monitoring_production_id' => self::PRODUCTION,
            'period_type' => 'monthly', 'month' => 1, 'gross_income' => '12345.60', 'remarks' => '<b>Saved income</b>']);
        DB::table('monitoring_materials')->insert(['id' => $this->id(2), 'monitoring_record_id' => self::RECORD,
            'project_material_id' => self::MATERIAL, 'condition' => 'Good', 'remarks' => '<b>Saved material</b>']);
        $before = DB::table('monitoring_records')->get()->toJson();
        $this->get(route('admin.monitoring.workspace', [self::RECORD, 'production']))->assertOk()
            ->assertSee('5.00')->assertSee('kg')->assertSee('Legacy quarterly-only record.');
        $this->get(route('admin.monitoring.workspace', [self::RECORD, 'income']))->assertOk()
            ->assertSee('12,345.60')->assertSee('January 2026')->assertSee('&lt;b&gt;Saved income&lt;/b&gt;', false);
        $this->get(route('admin.monitoring.workspace', [self::RECORD, 'materials']))->assertOk()
            ->assertSee('Synthetic Material 1')->assertSee('Good')->assertSee('piece')->assertSee('&lt;b&gt;Saved material&lt;/b&gt;', false);
        $this->assertSame($before, DB::table('monitoring_records')->get()->toJson());
    }

    private function queryCount(string $url): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->get($url)->assertOk();
            return count(DB::getQueryLog());
        } finally { DB::disableQueryLog(); }
    }

    private function addSectionEntries(int $first, int $last): void
    {
        for ($number = $first; $number <= $last; $number++) {
            $production = $number === 1 ? self::PRODUCTION : $this->id(1000 + $number);
            $material = $number === 1 ? self::MATERIAL : $this->id(2000 + $number);
            if ($number !== 1) {
                $product = $this->id(3000 + $number);
                DB::table('project_products')->insert(['id' => $product, 'project_id' => self::PROJECT,
                    'product_name' => 'Product '.$number, 'product_type' => 'raw', 'default_unit' => 'kg', 'is_active' => true]);
                DB::table('monitoring_production')->insert(['id' => $production, 'monitoring_record_id' => self::RECORD,
                    'project_product_id' => $product, 'target_output' => 100, 'actual_output' => 5, 'unit' => 'kg']);
                DB::table('project_materials')->insert(['id' => $material, 'project_id' => self::PROJECT, 'item_name' => 'Material '.$number]);
            }
            DB::table('monitoring_materials')->insert(['id' => $this->id(4000 + $number), 'monitoring_record_id' => self::RECORD,
                'project_material_id' => $material, 'condition' => 'Good']);
            DB::table('monitoring_income')->insert(['id' => $this->id(5000 + $number), 'monitoring_production_id' => $production,
                'period_type' => 'quarterly', 'gross_income' => 100]);
            foreach ([1 => 1, 2 => 2, 3 => 2] as $month => $quantity) {
                DB::table('monitoring_production_monthly')->insert(['id' => $this->id(6000 + $number * 3 + $month),
                    'monitoring_production_id' => $production, 'month' => $month, 'activity_count' => 1, 'total_quantity' => $quantity]);
            }
        }
    }

    #[DataProvider('roles')]
    public function test_section_query_counts_stay_constant_from_one_to_thirty_entries(string $identity, string $prefix): void
    {
        $this->identity = $identity;
        $this->addSectionEntries(1, 1);
        $urls = [];
        foreach (['production', 'income', 'materials'] as $section) {
            $urls[$section] = route($prefix.'.monitoring.workspace', [self::RECORD, $section]);
            if ($prefix !== 'association') $urls[$section.' editor'] = route($prefix.'.monitoring.section.edit', [self::RECORD, $section]);
        }
        $one = array_map($this->queryCount(...), $urls);
        $this->addSectionEntries(2, 30);
        $thirty = array_map($this->queryCount(...), $urls);
        $this->assertSame($one, $thirty, 'Section queries must not grow per related entry.');
        fwrite(STDOUT, "\nMonitoring {$prefix} sections (1 -> 30 entries): ".json_encode($one)." -> ".json_encode($thirty)." queries\n");
    }

    #[DataProvider('roles')]
    public function test_list_query_counts_stay_constant_from_one_to_thirty_records(string $identity, string $prefix): void
    {
        $this->identity = $identity;
        $this->addVisit(1);
        $dashboard = '/'.$prefix.'/monitoring';
        $visits = route($prefix.'.monitoring.visits', self::RECORD);
        $one = [$this->queryCount($dashboard), $this->queryCount($visits)];
        $this->addRecords(29);
        for ($number = 2; $number <= 30; $number++) $this->addVisit($number);
        $thirty = [$this->queryCount($dashboard), $this->queryCount($visits)];
        $this->assertSame($one, $thirty, 'List queries must not grow per displayed row.');
        fwrite(STDOUT, "\nMonitoring {$prefix}: dashboard {$one[0]} -> {$thirty[0]}, visits {$one[1]} -> {$thirty[1]} queries (1 -> 30 records)\n");
    }
}
