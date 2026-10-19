<?php

namespace Tests\Feature;

use App\Services\SupabaseAuthService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedDatabaseManager;
use Tests\Support\MonitoringMaterialsFixture;
use Tests\TestCase;

class MonitoringDashboardGroupingTest extends TestCase
{
    use MonitoringMaterialsFixture;

    private string $identity = self::ADMIN;
    private const QUARTERS = [1 => 11, 2 => 7, 3 => 23, 4 => 9];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertInstanceOf(IsolatedDatabaseManager::class, DB::getFacadeRoot());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        config(['app.key' => 'base64:'.base64_encode(str_repeat('q', 32))]);
        $this->withoutVite();
        $this->schema();
        $this->fixtures();
        foreach (self::QUARTERS as $number => $id) {
            if ($number !== 1) DB::table('quarters')->insert(['id' => $id, 'quarter_number' => $number, 'name' => 'Q'.$number]);
        }
        DB::table('monitoring_records')->update(['created_at' => '2026-01-15 00:00:00']);
        $auth = Mockery::mock(SupabaseAuthService::class);
        $auth->shouldReceive('getUser')->andReturnUsing(fn () => ['id' => $this->identity]);
        $this->app->instance(SupabaseAuthService::class, $auth);
        $this->withSession(['supabase_access_token' => 'synthetic-quarterly-session']);
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

    private function project(int $number, array $changes = []): string
    {
        $id = $this->id(1000 + $number);
        DB::table('projects')->insert(array_replace(['id' => $id, 'association_id' => self::ASSOCIATION,
            'title' => 'Grouping Project '.$number, 'program_year' => 2026, 'status' => 'ongoing'], $changes));
        return $id;
    }

    private function record(int $number, string $project, int $year, int $quarter, array $changes = []): string
    {
        $id = $this->id(2000 + $number);
        DB::table('monitoring_records')->insert(array_replace(['id' => $id, 'project_id' => $project, 'year' => $year,
            'quarter_id' => self::QUARTERS[$quarter], 'monitoring_date' => sprintf('%04d-%02d-15', $year, ($quarter - 1) * 3 + 1),
            'monitored_by' => self::OFFICER, 'updated_by' => self::ADMIN, 'site_validated' => true,
            'project_status' => 'ongoing', 'created_at' => '2026-01-15 00:00:00'], $changes));
        return $id;
    }

    private function dom(TestResponse $response): DOMXPath
    {
        $document = new DOMDocument();
        @$document->loadHTML($response->getContent());
        return new DOMXPath($document);
    }

    private function countedPage(string $url): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $response = $this->get($url)->assertOk();
            return [$response, count(DB::getQueryLog())];
        } finally { DB::disableQueryLog(); }
    }

    #[DataProvider('roles')]
    public function test_one_project_has_four_correct_quarters_and_years_never_mix(string $identity, string $prefix): void
    {
        $this->identity = $identity;
        $ids = [1 => self::RECORD];
        foreach ([2, 3, 4] as $quarter) $ids[$quarter] = $this->record($quarter, self::PROJECT, 2026, $quarter);
        $old = $this->record(5, self::PROJECT, 2025, 1);
        $before = DB::table('monitoring_records')->orderBy('id')->get()->toJson();
        $page = $this->get('/'.$prefix.'/monitoring?year=2026')->assertOk()->assertSee('Matching projects')->assertSee('4 matching record(s)');
        $this->assertSame(1, $page->viewData('projects')->total());
        $dom = $this->dom($page);
        $this->assertSame(1, $dom->query('//*[@data-monitoring-project]')->length);
        $this->assertSame(4, $dom->query('//*[@data-monitoring-quarter]')->length);
        $this->assertSame('1', trim($dom->query('//*[contains(@class,"monitoring-summary-selected")]/strong')->item(0)->textContent));
        foreach ($ids as $quarter => $id) {
            $this->assertSame(1, $dom->query('//*[@data-monitoring-quarter="'.$quarter.'"]//*[@data-monitoring-record="'.$id.'"]')->length);
            $page->assertSee(route($prefix.'.monitoring.workspace', $id), false)->assertSee(route($prefix.'.monitoring.visits', $id), false);
        }
        $page->assertDontSee('data-monitoring-record="'.$old.'"', false);
        $previous = $this->get('/'.$prefix.'/monitoring?year=2025')->assertOk()->assertSee('Reporting year 2025');
        $this->assertSame(1, $this->dom($previous)->query('//*[@data-monitoring-record]')->length);
        $previous->assertSee('data-monitoring-record="'.$old.'"', false)->assertDontSee('data-monitoring-record="'.self::RECORD.'"', false);
        $this->assertSame($before, DB::table('monitoring_records')->orderBy('id')->get()->toJson());
    }

    #[DataProvider('roles')]
    public function test_quarter_validation_and_search_qualify_rows_without_erasing_other_quarters(string $identity, string $prefix): void
    {
        $this->identity = $identity;
        $second = $this->record(2, self::PROJECT, 2026, 2, ['site_validated' => false, 'created_at' => '2026-04-12 00:00:00']);
        $other = $this->project(2);
        $this->record(3, $other, 2026, 2);
        foreach (['quarter=2&validated=0', 'search=Apr%2012,%202026', 'search=Synthetic%20Project&quarter=2'] as $filters) {
            $page = $this->get('/'.$prefix.'/monitoring?year=2026&'.$filters)->assertOk();
            $this->assertSame(1, $page->viewData('projects')->total());
            $this->assertSame(self::PROJECT, $page->viewData('projects')->first()->id);
            $dom = $this->dom($page);
            $this->assertSame(4, $dom->query('//*[@data-monitoring-quarter]')->length);
            $this->assertSame(2, $dom->query('//*[@data-monitoring-record]')->length);
            $page->assertSee('data-monitoring-record="'.self::RECORD.'"', false)
                ->assertSee('data-monitoring-record="'.$second.'"', false)->assertSee('1 matching record(s)');
        }
        $this->assertSame(0, $this->get('/'.$prefix.'/monitoring?quarter=1&validated=0')->assertOk()->viewData('projects')->total());
    }

    #[DataProvider('roles')]
    public function test_missing_quarters_are_empty_and_do_not_fabricate_records_or_creation_actions(string $identity, string $prefix): void
    {
        $this->identity = $identity;
        $this->project(2, ['title' => 'Project without any Monitoring history']);
        $page = $this->get('/'.$prefix.'/monitoring')->assertOk()->assertDontSee('Project without any Monitoring history');
        $dom = $this->dom($page);
        $this->assertSame(1, $page->viewData('projects')->total());
        $this->assertSame(3, $dom->query('//*[contains(@class,"monitoring-quarter-empty")]/p[text()="No record"]')->length);
        $this->assertSame(0, $dom->query('//*[contains(@class,"monitoring-quarter-empty")]//a | //*[contains(@class,"monitoring-quarter-empty")]//form')->length);
        if ($prefix === 'association') $page->assertDontSee('Add Monitoring Record');
        else $page->assertSee(route($prefix.'.monitoring.create'), false);
        $this->assertDatabaseCount('monitoring_records', 1);
        $empty = $this->get('/'.$prefix.'/monitoring?search=nonexistent')->assertOk()->assertSee('No matching Monitoring records');
        $this->assertSame(0, $this->dom($empty)->query('//*[@data-monitoring-project]')->length);
    }

    #[DataProvider('roles')]
    public function test_twenty_five_project_pagination_keeps_all_four_quarters_on_each_page(string $identity, string $prefix): void
    {
        $this->identity = $identity;
        foreach ([2, 3, 4] as $quarter) $this->record($quarter, self::PROJECT, 2026, $quarter);
        $url = '/'.$prefix.'/monitoring?year=2026&quarter=2&validated=1&search=2026&sort=oldest';
        [, $smallQueries] = $this->countedPage($url);
        for ($number = 1; $number <= 29; $number++) {
            $project = $this->project($number);
            foreach ([1, 2, 3, 4] as $quarter) $this->record(10 + $number * 4 + $quarter, $project, 2026, $quarter);
        }
        [$page, $largeQueries] = $this->countedPage($url);
        $this->assertSame($smallQueries, $largeQueries, 'Query count must not grow per project or quarter.');
        fwrite(STDOUT, "\nGrouped Monitoring {$prefix}: {$smallQueries} -> {$largeQueries} queries (1 -> 30 projects; 4 -> 120 records)\n");
        $projects = $page->viewData('projects');
        $this->assertSame(30, $projects->total());
        $this->assertCount(25, $projects);
        $this->assertSame(100, $this->dom($page)->query('//*[@data-monitoring-quarter]')->length);
        foreach ($projects as $project) $this->assertCount(4, $project->monitoringRecords);
        $next = $projects->nextPageUrl();
        foreach (['year=2026', 'quarter=2', 'validated=1', 'search=2026', 'sort=oldest'] as $parameter) $this->assertStringContainsString($parameter, $next);
        $second = $this->get($next)->assertOk();
        $this->assertCount(5, $second->viewData('projects'));
        $this->assertSame(20, $this->dom($second)->query('//*[@data-monitoring-quarter]')->length);
        foreach ($second->viewData('projects') as $project) {
            $this->assertCount(4, $project->monitoringRecords);
            $this->assertFalse($projects->contains('id', $project->id));
        }
        $beyond = $this->get('/'.$prefix.'/monitoring?year=2026&page=999')->assertOk()->viewData('projects');
        $this->assertSame(30, $beyond->total());
        $this->assertCount(0, $beyond);
        $this->assertDatabaseCount('monitoring_records', 120);
    }

    public function test_unexpected_duplicate_quarter_records_are_all_visible(): void
    {
        // This fixture deliberately lacks the production schema's period uniqueness constraint.
        $duplicate = $this->record(2, self::PROJECT, 2026, 1, ['site_validated' => false]);
        $page = $this->get('/admin/monitoring?validated=1')->assertOk()->assertSee('2 records found. Each saved record is shown.');
        $this->assertSame(1, $page->viewData('projects')->total());
        $dom = $this->dom($page);
        $this->assertSame(2, $dom->query('//*[@data-monitoring-quarter="1"]//*[@data-monitoring-record]')->length);
        $page->assertSee('data-monitoring-record="'.$duplicate.'"', false)->assertSee('data-monitoring-record="'.self::RECORD.'"', false);
        $this->assertDatabaseCount('monitoring_records', 2);
    }

    public function test_group_sorts_use_matching_record_dates_and_a_stable_project_tie_breaker(): void
    {
        $second = $this->project(2);
        $third = $this->project(3);
        $this->record(2, self::PROJECT, 2026, 2, ['created_at' => '2026-03-01 00:00:00']);
        foreach ([2 => $second, 3 => $third] as $number => $project) {
            $this->record($number + 1, $project, 2026, 3, ['created_at' => '2026-04-01 00:00:00']);
        }
        foreach (['newest' => [$second, $third, self::PROJECT], 'oldest' => [self::PROJECT, $second, $third],
            'quarter' => [self::PROJECT, $second, $third]] as $sort => $expected) {
            $projects = $this->get('/admin/monitoring?sort='.$sort)->assertOk()->viewData('projects');
            $this->assertSame($expected, $projects->pluck('id')->all());
        }
    }

    public function test_grouping_preserves_role_scopes_and_read_only_history(): void
    {
        foreach ([9000 => ['Restricted Association', 'active', null, self::ADMIN],
            9001 => ['Archived Association', 'inactive', '2026-01-01', self::OFFICER],
            9002 => ['Inactive Association', 'inactive', null, self::OFFICER]] as $number => [$name, $status, $archived, $officer]) {
            DB::table('associations')->insert(['id' => $this->id($number), 'name' => $name, 'status' => $status,
                'archived_at' => $archived, 'primary_field_officer_id' => $officer]);
            $project = $this->project($number, ['association_id' => $this->id($number)]);
            $this->record($number, $project, 2026, 1);
        }
        foreach ([1 => ['status' => 'completed'], 2 => ['status' => 'terminated'],
            3 => ['archived_at' => '2026-01-01'], 4 => ['closed_at' => '2026-01-01']] as $number => $changes) {
            $project = $this->project($number, $changes);
            $this->record($number, $project, 2026, 1);
        }
        $before = DB::table('monitoring_records')->orderBy('id')->get()->toJson();
        $admin = $this->get('/admin/monitoring')->assertOk()->assertSee('Association Archived — Read Only')->assertSee('Project Archived — Read Only')->assertSee('Project finalized — Read Only');
        $this->assertSame(8, $admin->viewData('projects')->total());
        foreach ([self::OFFICER => ['field-officer', 4], self::ACCOUNT => ['association', 5]] as $identity => [$prefix, $total]) {
            $this->identity = $identity;
            $page = $this->get('/'.$prefix.'/monitoring')->assertOk()->assertDontSee('Restricted Association')->assertDontSee('Archived Association')->assertDontSee('Inactive Association');
            $this->assertSame($total, $page->viewData('projects')->total());
            $this->assertSame(0, $this->get('/'.$prefix.'/monitoring?search=Restricted')->assertOk()->viewData('projects')->total());
            $this->get(route($prefix.'.monitoring.workspace', $this->id(11000)))->assertNotFound();
        }
        $this->assertSame($before, DB::table('monitoring_records')->orderBy('id')->get()->toJson());
    }

    public function test_long_project_and_association_names_are_rendered_in_distinct_rows(): void
    {
        $title = str_repeat('Long project ', 20).'<Project>';
        $association = str_repeat('Long association ', 20).'<Association>';
        DB::table('projects')->where('id', self::PROJECT)->update(['title' => $title]);
        DB::table('associations')->where('id', self::ASSOCIATION)->update(['name' => $association]);
        $page = $this->get('/admin/monitoring')->assertOk()->assertSee(e($title), false)->assertSee(e($association), false);
        $this->assertSame(1, $this->dom($page)->query('//*[@data-monitoring-project]')->length);
        $this->assertSame(4, $this->dom($page)->query('//*[@data-monitoring-quarter]')->length);
    }
}
