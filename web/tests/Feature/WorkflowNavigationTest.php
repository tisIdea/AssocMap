<?php

namespace Tests\Feature;

use App\View\WorkflowNavigation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkflowNavigationTest extends TestCase
{
    private const PROJECT = '11111111-1111-4111-8111-111111111111';

    private const ASSOCIATION = '22222222-2222-4222-8222-222222222222';

    private const TRAINING = '33333333-3333-4333-8333-333333333333';

    private function requestFor(string $uri): Request
    {
        $request = Request::create($uri);
        $route = Route::getRoutes()->match($request);
        $request->setRouteResolver(fn () => $route);
        $this->app->instance('request', $request);

        return $request;
    }

    public function test_nested_record_navigation_restores_parent_tab_pagination_and_original_register(): void
    {
        $navigation = new WorkflowNavigation;
        $associationList = '/admin/associations?q=Fish&status=active&sort=za&page=3';
        $association = $this->requestFor('/admin/associations/'.self::ASSOCIATION.'?'.http_build_query([
            'q' => 'Fish', 'sort' => 'za', 'page' => 3, 'section' => 'projects', 'related_page' => 2, 'return_to' => $associationList,
        ]));
        $projectUrl = $navigation->to('admin.projects.show', ['project' => self::PROJECT], 'related-association-records');
        $project = $this->requestFor($projectUrl.'&monitoring_page=4&training_page=2');
        $trainingUrl = $navigation->to('admin.trainings.show', ['training' => self::TRAINING], 'project-trainings');
        $training = $this->requestFor($trainingUrl);
        $back = $navigation->back($training, 'admin.trainings.index', [], [
            ['name' => 'admin.projects.show', 'parameters' => ['project' => self::PROJECT], 'label' => 'Recorded project'],
        ]);
        $this->assertTrue($back['contextual']);
        $this->assertSame('project-trainings', parse_url($back['url'], PHP_URL_FRAGMENT));
        parse_str(parse_url($back['url'], PHP_URL_QUERY), $query);
        $this->assertSame('4', $query['monitoring_page']);
        $this->assertSame('2', $query['training_page']);
        $project = $this->requestFor($back['url']);
        $back = $navigation->back($project, 'admin.projects.index', [], [
            ['name' => 'admin.associations.show', 'parameters' => ['association' => self::ASSOCIATION], 'label' => 'Recorded association'],
        ]);
        parse_str(parse_url($back['url'], PHP_URL_QUERY), $query);
        $this->assertSame('projects', $query['section']);
        $this->assertSame('2', $query['related_page']);
        $this->assertSame('3', $query['page']);
        $this->assertSame($associationList, $query['return_to']);
        $this->assertSame([], DB::getConnections(), 'Navigation must never query record relationships.');
    }

    public static function unsafeReturns(): array
    {
        return array_map(fn ($value) => [$value], [
            'https://example.test/admin/projects', '//example.test/admin/projects', '/admin/projects\\evil', "/admin/projects\r\nInjected",
            '/field-officer/projects', '/admin/users', '/admin/projects/'.self::PROJECT.'/edit',
            '/admin/associations/'.self::TRAINING, '/admin/projects?status=scheduled', '/admin/projects?page[]=2',
            '/admin/projects?sort=unknown', '/admin/projects?year=1999', str_repeat('x', 4097), ['url' => '/admin/projects'],
        ]);
    }

    #[DataProvider('unsafeReturns')]
    public function test_external_action_cross_role_unrelated_and_invalid_returns_fall_back(mixed $value): void
    {
        $request = $this->requestFor('/admin/projects/'.self::PROJECT.'?'.http_build_query(['return_to' => $value]));
        $back = (new WorkflowNavigation)->back($request, 'admin.projects.index', [], [
            ['name' => 'admin.associations.show', 'parameters' => ['association' => self::ASSOCIATION], 'label' => 'Parent association'],
        ]);
        $this->assertFalse($back['contextual']);
        $this->assertSame(route('admin.projects.index'), $back['url']);
        $this->assertSame([], DB::getConnections());
    }

    public static function roleRegisters(): array
    {
        $cases = [];
        foreach (['admin', 'field-officer', 'association'] as $prefix) {
            foreach (['projects', 'trainings', 'program-activities'] as $module) {
                $cases[] = [$prefix, $module];
            }
        }

        return $cases;
    }

    #[DataProvider('roleRegisters')]
    public function test_register_returns_keep_supported_search_period_and_page_only(string $prefix, string $module): void
    {
        $navigation = new WorkflowNavigation;
        $search = $prefix === 'association' ? 'search' : 'q';
        $target = '/'.$prefix.'/'.$module.'?'.http_build_query([$search => 'Recorded fish', 'year' => 2026, 'page' => 2, 'editor' => 'create', 'finalize' => 'completed']);
        $request = $this->requestFor('/'.$prefix.'/'.$module.'/'.self::TRAINING.'?'.http_build_query(['return_to' => $target]));
        $back = $navigation->back($request, $prefix.'.'.$module.'.index');
        parse_str(parse_url($back['url'], PHP_URL_QUERY), $query);
        $this->assertTrue($back['contextual']);
        $this->assertSame([$search => 'Recorded fish', 'year' => '2026', 'page' => '2'], $query);
    }

    public function test_current_context_drops_editor_lookup_and_finalization_actions_and_bounds_nested_urls(): void
    {
        $navigation = new WorkflowNavigation;
        $request = $this->requestFor('/admin/projects?q=Fish&page=3&editor=create&finalize=terminated&lookup=associations');
        $this->assertSame('/admin/projects?q=Fish&page=3', $navigation->current($request));
        $request = $this->requestFor('/admin/projects?'.http_build_query(['q' => 'Fish', 'return_to' => '/admin/projects?q='.str_repeat('a', 4070)]));
        $this->assertSame('/admin/projects?q=Fish', $navigation->current($request));
    }

    public function test_breadcrumb_component_escapes_record_labels_and_never_invents_unavailable_parent_routes(): void
    {
        $html = view('components.workflow-navigation', ['back' => ['url' => route('association.projects.index'), 'label' => 'My Projects'],
            'parents' => [['label' => '<script>unsafe</script>']], 'current' => 'Recorded project'])->render();
        $this->assertStringContainsString('&lt;script&gt;unsafe&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>unsafe</script>', $html);
        $this->assertStringNotContainsString('/admin/', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
    }
}
