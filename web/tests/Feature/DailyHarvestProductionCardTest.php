<?php

namespace Tests\Feature;

use App\Models\MonitoringProduction;
use App\Models\MonitoringProductionMonthly;
use App\Models\MonitoringRecord;
use App\Models\ProjectProduct;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedDatabaseManager;
use Tests\TestCase;

class DailyHarvestProductionCardTest extends TestCase
{
    private const RECORD = '11111111-1111-4111-8111-111111111111';
    private const PRODUCTION = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertInstanceOf(IsolatedDatabaseManager::class, DB::getFacadeRoot());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        DB::enableQueryLog();
    }

    protected function tearDown(): void
    {
        try { Http::assertNothingSent(); parent::tearDown(); }
        finally { HandleExceptions::flushState($this); }
    }

    private function production(?string $target = '500.00', string $actual = '200.00', int $days = 8): MonitoringProduction
    {
        $row = (new MonitoringProduction)->forceFill(['id' => self::PRODUCTION,
            'quantity_source' => 'daily_harvest', 'target_output' => $target, 'actual_output' => $actual, 'unit' => 'pcs']);
        $row->setRelation('projectProduct', (new ProjectProduct)->forceFill(['product_name' => 'Canned Bangus', 'product_type' => 'processed']));
        $row->setRelation('monthlyDetails', new Collection(array_map(fn ($month) =>
            new MonitoringProductionMonthly(['month' => $month, 'activity_count' => $month === 1 ? $days : 0,
                'total_quantity' => $month === 1 ? $actual : '0.00']), [1, 2, 3])));
        return $row;
    }

    private function renderCards(array $rows, string $prefix = 'admin.monitoring'): string
    {
        $record = (new MonitoringRecord)->forceFill(['id' => self::RECORD, 'year' => 2026]);
        $record->setRelation('production', new Collection($rows));
        DB::flushQueryLog();
        $html = view('monitoring.partials.production', ['record' => $record, 'monitoringPrefix' => $prefix,
            'canManage' => false, 'harvestProducts' => collect(), 'productionRevision' => null,
            'monthlyReconciled' => [], 'errors' => new ViewErrorBag])->render();
        $this->assertSame([], DB::getQueryLog(), 'Card rendering must use loaded relationships without querying the database.');
        return $html;
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        return new \DOMXPath($dom);
    }

    public static function states(): array
    {
        return [
            'partial' => ['500.00', '200.00', 8, '40.00', '40', null],
            'reached' => ['500.00', '500.00', 8, '100.00', '100', null],
            'exceeded' => ['500.00', '600.00', 8, '120.00', '100', null],
            'missing target' => [null, '200.00', 8, null, null, 'A quarterly target is needed to track attainment.'],
            'zero target' => ['0.00', '200.00', 8, null, null, 'No positive target.'],
            'no records' => ['500.00', '0.00', 0, null, null, 'Goal attainment is unavailable until harvest records are submitted.'],
            'no records or target' => [null, '0.00', 0, null, null, 'Goal attainment is unavailable until harvest records are submitted.'],
            'decimal ratio' => ['3.00', '1.00', 1, '33.33', '33.33', null],
            'maximum quantity' => ['0.01', '999999999999.99', 1, '9999999999999900.00', '100', null],
            'small recorded ratio' => ['500.00', '0.01', 1, '0.00', '0', null],
        ];
    }

    #[DataProvider('states')]
    public function test_card_states_keep_real_attainment_and_bound_only_the_visual_bar(?string $target, string $actual,
        int $days, ?string $attainment, ?string $bar, ?string $neutral): void
    {
        $html = $this->renderCards([$this->production($target, $actual, $days)]);
        $xpath = $this->xpath($html);
        $this->assertStringContainsString('Canned Bangus', $html);
        $this->assertStringContainsString('Measured in pcs', $html);
        $this->assertStringContainsString('Quarterly Goal Progress', $html);
        $progress = $xpath->query('//progress');
        if ($attainment !== null) {
            $this->assertSame(1, $progress->length);
            $node = $progress->item(0);
            $this->assertSame($bar, $node->getAttribute('value'));
            $this->assertSame('100', $node->getAttribute('max'));
            $this->assertSame($attainment.'% of target achieved', $node->getAttribute('aria-valuetext'));
            foreach (explode(' ', $node->getAttribute('aria-labelledby')) as $id) {
                $this->assertSame(1, $xpath->query('//*[@id="'.$id.'"]')->length);
            }
            $this->assertStringContainsString($attainment.'% of target achieved', $html);
        } else {
            $this->assertSame(0, $progress->length, 'Missing observations or nonpositive targets must not expose a determinate or loading progress bar.');
            $this->assertStringContainsString($neutral, $html);
            $this->assertStringNotContainsString('% of target achieved', $html);
        }
        if ($days === 0) {
            $this->assertStringContainsString('No harvest records submitted', $html);
            $this->assertStringContainsString('None recorded', $html);
        } else {
            $this->assertStringContainsString($days.' '.($days === 1 ? 'day' : 'days'), $html);
            $this->assertStringContainsString(number_format((float) $actual, 2), $html);
        }
        $this->assertStringContainsString($target === null ? 'Not set' : number_format((float) $target, 2), $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_long_product_names_and_units_are_escaped_and_card_ids_remain_unique(): void
    {
        $first = $this->production();
        $name = str_repeat('Long Canned Bangus Product ', 8).'<script>alert(1)</script>';
        $first->projectProduct->product_name = $name;
        $first->unit = '<img src=x onerror=alert(1)>';
        $second = $this->production('100.00', '125.00', 2);
        $second->id = '33333333-3333-4333-8333-333333333333';
        $html = $this->renderCards([$first, $second]);
        $this->assertStringContainsString(e($name), $html);
        $this->assertStringContainsString(e($first->unit), $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img ', $html);
        $xpath = $this->xpath($html);
        $ids = [];
        foreach ($xpath->query('//*[@id]') as $node) $ids[] = $node->getAttribute('id');
        $this->assertCount(count(array_unique($ids)), $ids);
        $this->assertSame(2, $xpath->query('//progress')->length);
    }

    public static function roles(): array
    {
        return [['admin.monitoring'], ['field-officer.monitoring'], ['association.monitoring']];
    }

    #[DataProvider('roles')]
    public function test_existing_detail_destination_is_preserved_in_each_role_namespace(string $prefix): void
    {
        $html = $this->renderCards([$this->production()], $prefix);
        $href = $this->xpath($html)->query('//a[normalize-space(.)="View Daily Harvest"]')->item(0)->getAttribute('href');
        $this->assertSame(route($prefix.'.harvest.index', [self::RECORD, self::PRODUCTION]), $href);
        $this->assertStringNotContainsString('<form', $html);
    }

    public function test_legacy_aggregate_presentation_does_not_gain_daily_progress_or_day_counts(): void
    {
        $row = $this->production(); $row->quantity_source = 'aggregate';
        $html = $this->renderCards([$row]);
        $this->assertStringContainsString('Target Output', $html);
        $this->assertStringContainsString('Actual Output', $html);
        $this->assertStringContainsString('Legacy or unreconciled monthly details.', $html);
        $this->assertStringContainsString('500.00', $html); $this->assertStringContainsString('200.00', $html);
        $this->assertStringNotContainsString('Harvesting Days', $html);
        $this->assertStringNotContainsString('monitoring-harvest-card', $html);
        $this->assertSame(0, $this->xpath($html)->query('//progress')->length);
    }
}
