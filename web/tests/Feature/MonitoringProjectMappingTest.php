<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\MonitoringController as AdminMonitoringController;
use App\Http\Controllers\FieldOfficer\MonitoringController as FieldOfficerMonitoringController;
use App\Models\Project;
use App\Services\SupabaseAuthService;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Js;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Support\IsolatedDatabaseManager;
use Tests\Support\MonitoringMaterialsFixture;
use Tests\TestCase;

class MonitoringProjectMappingTest extends TestCase
{
    use MonitoringMaterialsFixture;

    private string $identity = self::ADMIN;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertInstanceOf(IsolatedDatabaseManager::class, DB::getFacadeRoot());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        $this->withoutVite();
        $this->schema();
        $this->fixtures();
        DB::table('project_products')->insert([
            'id' => self::id(1), 'project_id' => self::PROJECT, 'product_name' => 'Another fish',
            'product_type' => 'processed', 'default_unit' => 'basket', 'is_active' => false,
        ]);
        $auth = Mockery::mock(SupabaseAuthService::class);
        $auth->shouldReceive('getUser')->andReturnUsing(fn () => ['id' => $this->identity]);
        $this->app->instance(SupabaseAuthService::class, $auth);
        $this->withSession(['supabase_access_token' => 'synthetic-token']);
    }

    protected function tearDown(): void
    {
        try {
            Http::assertNothingSent();
            parent::tearDown();
        } finally {
            HandleExceptions::flushState($this);
        }
    }

    private static function id(int $number): string
    {
        return sprintf('99999999-0000-4000-8000-%012d', $number);
    }

    public static function controllers(): array
    {
        return ['administrator' => [AdminMonitoringController::class], 'officer' => [FieldOfficerMonitoringController::class]];
    }

    public static function mappingStates(): array
    {
        $cases = [];
        foreach (self::controllers() as $role => [$controller]) {
            foreach (['active', 'missing association', 'missing officer', 'inactive officer', 'missing role',
                'inactive role', 'wrong role', 'inactive association', 'archived project'] as $state) {
                $cases[$role.' '.$state] = [$controller, $state];
            }
        }

        return $cases;
    }

    public static function roles(): array
    {
        return ['administrator' => ['admin', self::ADMIN], 'assigned officer' => ['field-officer', self::OFFICER]];
    }

    private function expectedRow(): array
    {
        return [
            'id' => self::PROJECT, 'title' => 'Synthetic Project', 'association_id' => self::ASSOCIATION,
            'association_name' => 'Synthetic Association',
            'assigned_officer' => ['id' => self::OFFICER, 'name' => 'Synthetic Actor'],
            'program_year' => 2026, 'status' => 'ongoing',
            'products' => [
                ['id' => self::PRODUCT, 'product_name' => 'Synthetic fish', 'product_type' => 'raw', 'default_unit' => 'kg', 'is_active' => true],
                ['id' => self::id(1), 'product_name' => 'Another fish', 'product_type' => 'processed', 'default_unit' => 'basket', 'is_active' => false],
            ],
            'materials' => [
                ['id' => self::MATERIAL, 'item_name' => 'Synthetic Material 1', 'unit' => 'piece', 'delivery_status' => 'planned'],
                ['id' => self::SECOND_MATERIAL, 'item_name' => 'Synthetic Material 2', 'unit' => 'piece', 'delivery_status' => 'planned'],
                ['id' => self::THIRD_MATERIAL, 'item_name' => 'Synthetic Material 3', 'unit' => 'piece', 'delivery_status' => 'planned'],
            ],
        ];
    }

    private function map(string $controller, Project $project): array
    {
        $mapped = (new ReflectionMethod($controller, 'mapProject'))->invoke(app($controller), $project);
        $this->assertInstanceOf(Collection::class, $mapped['products']);
        $this->assertInstanceOf(Collection::class, $mapped['materials']);
        $mapped['products'] = $mapped['products']->all();
        $mapped['materials'] = $mapped['materials']->all();

        return $mapped;
    }

    #[DataProvider('mappingStates')]
    public function test_mapping_preserves_exact_values_types_key_order_and_loaded_relationship_order(string $controller, string $state): void
    {
        $project = Project::with(['association.primaryFieldOfficer.role', 'products', 'materials'])->findOrFail(self::PROJECT);
        $project->setRelation('products', $project->products->reverse()->keyBy('id'));
        $project->setRelation('materials', $project->materials->reverse()->keyBy('id'));
        match ($state) {
            'missing association' => $project->setRelation('association', null),
            'missing officer' => $project->association->setRelation('primaryFieldOfficer', null),
            'inactive officer' => $project->association->primaryFieldOfficer->is_active = false,
            'missing role' => $project->association->primaryFieldOfficer->setRelation('role', null),
            'inactive role' => $project->association->primaryFieldOfficer->role->is_active = false,
            'wrong role' => $project->association->primaryFieldOfficer->role->code = 'system_admin',
            'inactive association' => $project->association->status = 'inactive',
            'archived project' => $project->archived_at = '2026-01-01',
            default => null,
        };
        $expected = $this->expectedRow();
        $expected['products'] = array_reverse($expected['products']);
        $expected['materials'] = array_reverse($expected['materials']);
        if ($state === 'missing association') {
            $expected['association_name'] = null;
        }
        if (! in_array($state, ['active', 'inactive association', 'archived project'], true)) {
            $expected['assigned_officer'] = null;
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->assertSame($expected, $this->map($controller, $project));
            $this->assertSame([], DB::getQueryLog(), 'Mapping eager-loaded relationships must not query.');
        } finally {
            DB::disableQueryLog();
        }
    }

    #[DataProvider('controllers')]
    public function test_nullable_fields_and_empty_relationships_keep_their_shapes(string $controller): void
    {
        $project = (new Project)->forceFill(['id' => self::PROJECT, 'association_id' => null,
            'title' => 'Synthetic Project', 'program_year' => null, 'status' => 'ongoing']);
        $project->setRelation('association', null)->setRelation('products', collect())->setRelation('materials', collect());
        $expected = array_replace($this->expectedRow(), ['association_id' => null, 'association_name' => null,
            'assigned_officer' => null, 'program_year' => null, 'products' => [], 'materials' => []]);
        $this->assertSame($expected, $this->map($controller, $project));
    }

    #[DataProvider('controllers')]
    public function test_unloaded_relationships_keep_existing_lazy_loading_behavior(string $controller): void
    {
        $project = Project::findOrFail(self::PROJECT);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->assertSame($this->expectedRow(), $this->map($controller, $project));
            $this->assertCount(5, DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    private function addProject(int $number, array $changes = []): string
    {
        $id = self::id($number);
        $original = (array) DB::table('projects')->where('id', self::PROJECT)->first();
        DB::table('projects')->insert(array_replace($original, ['id' => $id, 'title' => 'Project '.$number], $changes));

        return $id;
    }

    #[DataProvider('roles')]
    public function test_editor_keeps_exact_project_data_rendered_json_order_and_role_scope(string $area, string $actor): void
    {
        $this->identity = $actor;
        $alpha = $this->addProject(2, ['title' => 'Alpha project']);
        DB::table('associations')->insert(['id' => self::id(3), 'name' => 'Other association',
            'status' => 'active', 'primary_field_officer_id' => null]);
        $foreign = $this->addProject(4, ['title' => 'Zulu project', 'association_id' => self::id(3)]);
        foreach (['archived_at' => '2026-01-01', 'closed_at' => '2026-01-01', 'status' => 'completed'] as $key => $value) {
            $this->addProject(10 + strlen($key), [$key => $value]);
        }
        DB::table('associations')->insert(['id' => self::id(5), 'name' => 'Inactive association',
            'status' => 'inactive', 'primary_field_officer_id' => self::OFFICER]);
        $this->addProject(6, ['association_id' => self::id(5)]);
        $expected = [array_replace($this->expectedRow(), ['id' => $alpha, 'title' => 'Alpha project',
            'products' => [], 'materials' => []]), $this->expectedRow()];
        if ($area === 'admin') {
            $expected[] = array_replace($this->expectedRow(), ['id' => $foreign, 'title' => 'Zulu project',
                'association_id' => self::id(3), 'association_name' => 'Other association',
                'assigned_officer' => null, 'products' => [], 'materials' => []]);
        }
        $page = $this->get(route($area.'.monitoring.create'))->assertOk()->assertViewIs('monitoring.editor');
        $this->assertSame($expected, json_decode($page->viewData('projectRows')->toJson(), true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame(array_column($expected, 'id'), $page->viewData('projects')->modelKeys());
        $page->assertSee('const projects = '.Js::from($expected)->toHtml().';', false);
    }

    #[DataProvider('roles')]
    public function test_editor_query_count_does_not_grow_from_one_to_thirty_projects(string $area, string $actor): void
    {
        $this->identity = $actor;
        $counts = [];
        foreach ([1, 30] as $size) {
            if ($size === 30) {
                for ($number = 2; $number <= 30; $number++) {
                    $this->addProject(100 + $number);
                }
            }
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $page = $this->get(route($area.'.monitoring.create'))->assertOk();
                $this->assertCount($size, $page->viewData('projectRows'));
                $queries = DB::getQueryLog();
                foreach ($queries as $query) {
                    $this->assertStringStartsWith('select ', $query['query']);
                }
                $counts[] = count($queries);
            } finally {
                DB::disableQueryLog();
            }
        }
        $this->assertSame($counts[0], $counts[1]);
        fwrite(STDOUT, "\nMonitoring {$area} create editor (1 -> 30 projects): ".implode(' -> ', $counts)." queries\n");
    }

    public function test_reassignment_removes_editor_choices_and_association_accounts_cannot_open_it(): void
    {
        $this->identity = self::OFFICER;
        DB::table('associations')->where('id', self::ASSOCIATION)->update(['primary_field_officer_id' => null]);
        $this->get(route('field-officer.monitoring.create'))->assertOk()->assertViewHas('projectRows', fn ($rows) => $rows->isEmpty());
        $this->identity = self::ACCOUNT;
        $this->get(route('admin.monitoring.create'))->assertForbidden();
        $this->get(route('field-officer.monitoring.create'))->assertForbidden();
    }
}
