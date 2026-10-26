<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\Role;
use App\Models\UserProfile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ContextualReportTest extends TestCase
{
    private const ACTOR = '11111111-1111-4111-8111-111111111111';

    private const ASSOCIATION = '22222222-2222-4222-8222-222222222222';

    private function renderReport(string $role, string $prefix, array $overrides = []): string
    {
        $actor = (new UserProfile)->forceFill(['id' => self::ACTOR, 'is_active' => true]);
        $actor->setRelation('role', new Role(['code' => $role]));
        request()->attributes->set('current_user', $actor);

        return Blade::render('<x-contextual-report :prefix="$prefix" :type="$type" :filters="$filters" :scope="$scope" :related="$related" :association="$association" />', array_replace([
            'prefix' => $prefix, 'type' => 'associations', 'filters' => ['association_id' => self::ASSOCIATION],
            'scope' => 'Selected <script>association</script>; current snapshot.', 'related' => false,
            'association' => new Association(['id' => self::ASSOCIATION, 'status' => 'active', 'primary_field_officer_id' => self::ACTOR]),
        ], $overrides));
    }

    public static function roles(): array
    {
        return [['system_admin', 'admin'], ['field_officer', 'field-officer']];
    }

    #[DataProvider('roles')]
    public function test_detail_controls_preserve_post_csrf_formats_scope_and_zero_queries(string $role, string $prefix): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $html = $this->renderReport($role, $prefix);
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertStringContainsString('method="POST"', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringContainsString('Export Association Distribution Report', $html);
        foreach (['pdf', 'xlsx'] as $format) {
            $this->assertStringContainsString('formaction="'.route($prefix.'.reports.export', ['format' => $format]).'"', $html);
        }
        $this->assertStringContainsString('name="association_id" value="'.self::ASSOCIATION.'"', $html);
        $this->assertStringContainsString('name="report_type" value="associations"', $html);
        $this->assertStringContainsString('Selected &lt;script&gt;association&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('role="status" aria-live="polite"', $html);
    }

    public function test_related_controls_have_only_supported_prefilled_preview_and_no_download(): void
    {
        $html = $this->renderReport('system_admin', 'admin', ['type' => 'projects', 'filters' => ['program_year' => 2026], 'related' => true]);
        $this->assertStringContainsString('Open related report', $html);
        $this->assertStringContainsString('report_type=projects&amp;program_year=2026', $html);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('/export/', $html);
    }

    public function test_guest_account_wrong_role_and_ineligible_assignment_never_render_exports(): void
    {
        foreach ([['association_account', 'association'], ['association_account', 'admin'], ['field_officer', 'admin'], ['public_guest', 'admin']] as [$role, $prefix]) {
            $this->assertStringNotContainsString('data-contextual-report', $this->renderReport($role, $prefix));
        }
        foreach ([['status' => 'inactive'], ['archived_at' => '2026-01-01'], ['primary_field_officer_id' => self::ASSOCIATION]] as $changes) {
            $association = (new Association)->forceFill(array_replace(['id' => self::ASSOCIATION, 'status' => 'active', 'primary_field_officer_id' => self::ACTOR], $changes));
            $this->assertStringNotContainsString('data-contextual-report', $this->renderReport('field_officer', 'field-officer', compact('association')));
        }
        request()->attributes->remove('current_user');
        $this->assertStringNotContainsString('data-contextual-report', Blade::render('<x-contextual-report prefix="admin" type="associations" scope="Snapshot" />'));
    }

    public function test_unsupported_types_and_filters_do_not_silently_broaden_the_component_scope(): void
    {
        foreach ([['type' => 'members'], ['filters' => ['status' => 'active']], ['filters' => ['report_type' => 'comprehensive']]] as $overrides) {
            $this->assertStringNotContainsString('data-contextual-report', $this->renderReport('system_admin', 'admin', $overrides));
        }
    }
}
