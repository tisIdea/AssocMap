<?php

namespace Tests\Unit;

use App\Services\ProductionMonitoringService;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductionMonitoringCalculationTest extends TestCase
{
    public static function quarters(): array
    {
        return [[1, [1, 2, 3]], [2, [4, 5, 6]], [3, [7, 8, 9]], [4, [10, 11, 12]]];
    }

    #[DataProvider('quarters')]
    public function test_quarter_mapping_and_independent_decimal_totals(int $quarter, array $months): void
    {
        $calculator = app(ProductionMonitoringService::class);
        $this->assertSame($months, $calculator->monthsForQuarter($quarter));
        $rows = array_map(fn ($month) => ['month' => $month, 'activity_count' => 1, 'total_quantity' => '0.10'], $months);
        $this->assertSame('0.30', $calculator->calculateActualOutput(2026, $quarter, $rows));
        $rows[0]['total_quantity'] = '999999999999.79';
        $this->assertSame('999999999999.99', $calculator->calculateActualOutput(2026, $quarter, $rows));
        $this->assertSame('0.30', $calculator->calculateActualOutput(2026, $quarter,
            array_map(fn ($month) => ['month' => $month, 'activity_count' => 1, 'total_quantity' => '0.10'], $months)));
    }

    public static function totals(): array
    {
        return [['0', '0', '0', '0.00'], ['0', '125', '175', '300.00'],
            ['0.10', '0.20', '0', '0.30'], ['10.01', '2.02', '3.03', '15.06']];
    }

    #[DataProvider('totals')]
    public function test_explicit_zero_and_decimal_quantities(string $a, string $b, string $c, string $total): void
    {
        $rows = [];
        foreach ([$a, $b, $c] as $index => $quantity) {
            $rows[] = ['month' => $index + 1, 'activity_count' => $quantity === '0' ? 0 : 1, 'total_quantity' => $quantity];
        }
        $this->assertSame($total, app(ProductionMonitoringService::class)->calculateActualOutput(2026, 1, $rows));
    }

    public static function invalidDetails(): array
    {
        $base = array_map(fn ($month) => ['month' => $month, 'activity_count' => 1, 'total_quantity' => '1.00'], [1, 2, 3]);
        $cases = [];
        foreach (['negative quantity' => ['total_quantity', '-1'], 'negative count' => ['activity_count', -1],
            'fractional count' => ['activity_count', '1.5'], 'missing count' => ['activity_count', null],
            'missing quantity' => ['total_quantity', null], 'blank quantity' => ['total_quantity', ''],
            'excess precision' => ['total_quantity', '1.001'], 'scientific notation' => ['total_quantity', '1e2'],
            'too large' => ['total_quantity', '1000000000000'], 'duplicate month' => ['month', 2],
            'outside quarter' => ['month', 4], 'count overflow' => ['activity_count', '2147483648']] as $name => [$key, $value]) {
            $rows = $base; $rows[0][$key] = $value; $cases[$name] = [$rows];
        }
        $cases['missing month'] = [array_slice($base, 0, 2)];
        $cases['extra month'] = [[...$base, ['month' => 4, 'activity_count' => 0, 'total_quantity' => '0']]];
        $overflow = $base; $overflow[0]['total_quantity'] = '999999999999.99'; $cases['sum overflow'] = [$overflow];
        return $cases;
    }

    #[DataProvider('invalidDetails')]
    public function test_invalid_monthly_sets_are_rejected(array $rows): void
    {
        $this->expectException(ValidationException::class);
        app(ProductionMonitoringService::class)->calculateActualOutput(2026, 1, $rows);
    }

    public function test_invalid_year_and_quarter_are_rejected(): void
    {
        foreach ([[1999, 1], [2026, 0], [2026, 5]] as [$year, $quarter]) {
            try {
                app(ProductionMonitoringService::class)->calculateActualOutput($year, $quarter, []);
                $this->fail('An invalid monitoring period must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }
}
