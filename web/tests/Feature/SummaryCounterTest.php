<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SummaryCounterTest extends TestCase
{
    public static function values(): array
    {
        return array_map(fn ($value) => [$value], [0, 1, '12,345', '₱1,234.50', '-42.75', '18.50%', '900719925474099312345', 'Unavailable', '—', 'N/A', '', null]);
    }

    #[DataProvider('values')]
    public function test_server_value_is_visible_without_javascript(mixed $value): void
    {
        $html = Blade::render('<x-stat-card label="Official members" :value="$value" icon="users" note="Authorized records" />', compact('value'));
        $this->assertStringContainsString('Official members', $html);
        $this->assertStringContainsString('Authorized records', $html);
        $this->assertStringContainsString('data-summary-counter', $html);
        $this->assertStringContainsString('>'.e($value ?? '—').'</span>', $html);
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringNotContainsString('aria-live', $html);
        $this->assertStringNotContainsString('tabindex', $html);
        $this->assertStringNotContainsString('summary-counter-half', $html);
    }

    public function test_existing_default_call_and_explicit_opt_out_render(): void
    {
        $html = Blade::render('<x-stat-card label="Records" />');
        $this->assertStringContainsString('>—</span>', $html);
        $static = Blade::render('<x-stat-card label="Records" value="0" :animate="false" />');
        $this->assertStringNotContainsString('data-summary-counter', $static);
        $this->assertStringContainsString('>0</span>', $static);
    }

    public function test_values_and_labels_are_escaped(): void
    {
        $value = '<script>alert(1)</script>';
        $html = Blade::render('<x-stat-card :label="$value" :value="$value" :note="$value" />', compact('value'));
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertSame(3, substr_count($html, e($value)));
    }

    public static function roles(): array
    {
        return [['system_admin', 'admin'], ['field_officer', 'field-officer'], ['association_account', 'association']];
    }

    #[DataProvider('roles')]
    public function test_role_dashboard_keeps_supplied_summaries_links_and_missing_states(string $role, string $prefix): void
    {
        $dashboard = [
            'generated_at' => '2026-10-17T00:00:00Z', 'timezone' => 'Asia/Manila', 'scope_count' => 1,
            'association' => ['name' => 'Synthetic association', 'municipality' => 'Synthetic municipality', 'status' => 'active', 'joined_on' => '2026-01-01'],
            'metrics' => [
                ['label' => 'Official members', 'value' => 12345, 'icon' => 'users', 'note' => 'Authorized records'],
                ['label' => 'Missing statistic', 'value' => null, 'icon' => 'chart', 'note' => 'Unavailable source'],
            ],
            'applications' => ['pending' => 0, 'approved' => 10, 'rejected' => 1],
            'officer_attention' => 0, 'charts' => [], 'associations' => [], 'recent_projects' => [], 'activities' => [],
            'training' => ['records' => 10, 'completed' => 9, 'distinct_present_members' => 45, 'recent' => []],
            'monitoring' => ['records' => 100, 'periods' => 4, 'visits' => null, 'recent' => []],
        ];
        $html = view('dashboard.content', compact('role', 'prefix', 'dashboard') + ['state' => 'ready'])->render();
        $this->assertStringContainsString('>12,345</span>', $html);
        $this->assertSame(2, substr_count($html, '>Unavailable</span>'));
        $this->assertSame($role === 'system_admin' ? 8 : 11, substr_count($html, 'data-summary-counter'));
        $this->assertStringContainsString(route($prefix.'.monitoring.index'), $html);
        $this->assertSame($role === 'system_admin', str_contains($html, 'Review accounts & assignments'));

        $denied = view('dashboard.content', compact('role', 'prefix') + ['state' => 'denied'])->render();
        $this->assertStringNotContainsString('data-summary-counter', $denied);
        $this->assertStringNotContainsString('12,345', $denied);
    }
}
