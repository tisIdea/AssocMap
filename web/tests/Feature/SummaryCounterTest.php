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
                ['key' => 'associations_total', 'label' => 'Registered associations', 'value' => 23456, 'icon' => 'building', 'note' => 'Retained associations'],
                ['key' => 'associations_active', 'label' => 'Assigned active associations', 'value' => 34567, 'icon' => 'building', 'note' => 'Current assignments'],
                ['key' => 'members_total', 'label' => 'Official members', 'value' => 12345, 'icon' => 'users', 'note' => 'Authorized records'],
                ['key' => 'projects_total', 'label' => 'Registered projects', 'value' => null, 'icon' => 'briefcase', 'note' => 'Unavailable source'],
                ['key' => 'applications_pending', 'label' => 'Pending member applications', 'value' => 0, 'icon' => 'receipt', 'note' => 'Awaiting review'],
                ['key' => 'members_active', 'label' => 'Active official members', 'value' => 987654, 'icon' => 'users', 'note' => 'Unselected authorized metric'],
            ],
            'applications' => ['pending' => 0, 'approved' => 10, 'rejected' => 1],
            'officer_attention' => 0, 'charts' => [], 'associations' => [], 'recent_projects' => [], 'activities' => [],
            'training' => ['records' => 10, 'completed' => 9, 'distinct_present_members' => 45, 'recent' => []],
            'monitoring' => ['records' => 100, 'periods' => 4, 'visits' => null, 'recent' => []],
        ];
        $html = view('dashboard.content', compact('role', 'prefix', 'dashboard') + ['state' => 'ready'])->render();
        $this->assertSame($role !== 'association_account', str_contains($html, '>12,345</span>'));
        $this->assertSame($role === 'system_admin' ? 2 : 1, substr_count($html, '>Unavailable</span>'));
        $this->assertSame(match ($role) {
            'system_admin' => 9, 'field_officer' => 12, default => 10,
        }, substr_count($html, 'data-summary-counter'));
        $this->assertSame(1, preg_match('/<section class="dashboard-metrics"[^>]*>(.*?)<\/section>/s', $html, $summary));
        preg_match_all('/<a class="dashboard-summary-link" href="([^"]+)"[^>]*>(.*?)<\/a>/s', $summary[1], $cards, PREG_SET_ORDER);
        $expected = match ($role) {
            'system_admin' => [
                ['associations.index', 'Total Associations', '23,456'],
                ['members.index', 'Total Members', '12,345'],
                ['projects.index', 'Total Projects', 'Unavailable'],
            ],
            'field_officer' => [
                ['association-officers.index', 'Assigned Associations', '34,567'],
                ['members.index', 'Total Members', '12,345'],
                ['member-applications.index', 'Pending Applications', '0'],
            ],
            default => [['member-applications.index', 'Pending Applications', '0']],
        };
        $this->assertSame(array_map(fn ($card) => route($prefix.'.'.$card[0], $card[0] === 'member-applications.index' ? ['status' => 'pending'] : []), $expected), array_column($cards, 1));
        foreach ($expected as $index => [, $label, $value]) {
            $this->assertStringContainsString($label, $cards[$index][2]);
            $this->assertStringContainsString('>'.$value.'</span>', $cards[$index][2]);
        }
        $this->assertStringNotContainsString('987,654', $html);
        $this->assertStringNotContainsString('Unselected authorized metric', $html);
        $this->assertStringContainsString(route($prefix.'.monitoring.index'), $html);
        $this->assertSame($role === 'system_admin', str_contains($html, 'Review accounts & assignments'));

        $denied = view('dashboard.content', compact('role', 'prefix') + ['state' => 'denied'])->render();
        $this->assertStringNotContainsString('data-summary-counter', $denied);
        $this->assertStringNotContainsString('12,345', $denied);
    }
}
