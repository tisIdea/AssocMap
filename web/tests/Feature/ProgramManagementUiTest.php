<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProgramActivityRecord;
use App\Models\UserProfile;
use App\Services\Auth\SupabaseAuthService;
use App\Services\Reporting\ReportService;
use App\Models\Training;
use App\Services\GIS\GisQueryService;
use App\Services\Programs\ProgramActivityService;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProgramManagementUiTest extends TestCase
{
    use \Tests\Support\AssertsWorkflowNavigation;
    private string $identity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        config(['app.key' => 'base64:'.base64_encode(str_repeat('p', 32))]);
        config(['associations.geography_schema_ready' => false, 'associations.direct_geography' => false]);
        $this->withoutVite();
        $this->identity = $this->id(1);
        $auth = Mockery::mock(SupabaseAuthService::class);
        $auth->shouldReceive('getUser')->andReturnUsing(fn () => ['id' => $this->identity]);
        $this->app->instance(SupabaseAuthService::class, $auth);
        $gis = Mockery::mock(GisQueryService::class);
        $gis->shouldReceive('projectLocations')->andReturn([]);
        $this->app->instance(GisQueryService::class, $gis);
        $this->withSession(['supabase_access_token' => 'synthetic-program-session']);
        $this->schema();
        $this->fixtures();
    }

    protected function tearDown(): void
    {
        try { Http::assertNothingSent(); } finally {
            try { parent::tearDown(); } finally { HandleExceptions::flushState($this); }
        }
    }

    private function id(int $value): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $value);
    }

    private function schema(): void
    {
        // Test tables exist only in the guarded SQLite in-memory connection.
        DB::unprepared(<<<'SQL'
            CREATE TABLE roles (id INTEGER PRIMARY KEY, code TEXT, is_active BOOLEAN, name TEXT, description TEXT, created_at TEXT, updated_at TEXT);
            CREATE TABLE user_profiles (id TEXT PRIMARY KEY, role_id INTEGER, display_name TEXT, is_active BOOLEAN, created_at TEXT, updated_at TEXT);
            CREATE TABLE user_invitations (id TEXT PRIMARY KEY, auth_user_id TEXT, status TEXT, last_error_code TEXT, accepted_at TEXT, last_attempt_at TEXT, created_at TEXT);
            CREATE TABLE municipalities (id TEXT PRIMARY KEY, name TEXT);
            CREATE TABLE barangays (id TEXT PRIMARY KEY, municipality_id TEXT, name TEXT);
            CREATE TABLE areas (id TEXT PRIMARY KEY, barangay_id TEXT, address TEXT);
            CREATE TABLE associations (id TEXT PRIMARY KEY, name TEXT, area_id TEXT, status TEXT, archived_at TEXT, primary_field_officer_id TEXT);
            CREATE TABLE saad_activities (id INTEGER PRIMARY KEY, code TEXT, name TEXT, is_active BOOLEAN);
            CREATE TABLE fisheries_project_categories (id INTEGER PRIMARY KEY, name TEXT, is_active BOOLEAN);
            CREATE TABLE commodities (id INTEGER PRIMARY KEY, name TEXT, category_id INTEGER, source TEXT, is_active BOOLEAN);
            CREATE TABLE projects (id TEXT PRIMARY KEY, association_id TEXT, saad_activity_id INTEGER, fisheries_category_id INTEGER, commodity_id INTEGER, title TEXT, description TEXT, program_year INTEGER, implementation_start_date TEXT, implementation_end_date TEXT, status TEXT, remarks TEXT, archived_at TEXT, archived_by TEXT, closed_at TEXT, closed_by TEXT, closure_remarks TEXT, created_by TEXT, updated_by TEXT, created_at TEXT, updated_at TEXT);
            CREATE TABLE project_products (id TEXT PRIMARY KEY, project_id TEXT, product_name TEXT, product_type TEXT, default_unit TEXT, description TEXT, is_active BOOLEAN, created_at TEXT, updated_at TEXT);
            CREATE TABLE project_materials (id TEXT PRIMARY KEY, project_id TEXT, item_name TEXT, quantity_planned NUMERIC, quantity_delivered NUMERIC, unit TEXT, unit_cost NUMERIC, delivery_status TEXT, delivery_date TEXT, remarks TEXT, created_at TEXT, updated_at TEXT);
            CREATE TABLE trainings (id TEXT PRIMARY KEY, association_id TEXT, related_project_id TEXT, saad_activity_id INTEGER, title TEXT, description TEXT, program_year INTEGER, start_date TEXT, end_date TEXT, venue TEXT, conducted_by TEXT, training_cost NUMERIC, status TEXT, remarks TEXT, archived_at TEXT, archived_by TEXT, created_by TEXT, updated_by TEXT, created_at TEXT, updated_at TEXT);
            CREATE TABLE members (id TEXT PRIMARY KEY, association_id TEXT, first_name TEXT, middle_name TEXT, last_name TEXT, suffix TEXT, member_status TEXT, archived_at TEXT);
            CREATE TABLE training_participants (id TEXT PRIMARY KEY, training_id TEXT, member_id TEXT, attendance_status TEXT, remarks TEXT, created_at TEXT, updated_at TEXT);
            CREATE TABLE monitoring_records (id TEXT PRIMARY KEY, project_id TEXT, year INTEGER, quarter_id INTEGER, monitoring_date TEXT, project_status TEXT);
            CREATE TABLE quarters (id INTEGER PRIMARY KEY, quarter_number INTEGER);
            CREATE TABLE program_activity_records (id TEXT PRIMARY KEY, related_project_id TEXT, association_id TEXT, saad_activity_id INTEGER, title TEXT, description TEXT, program_year INTEGER, activity_date TEXT, venue TEXT, status TEXT, conducted_by TEXT, conducted_by_user_id TEXT, remarks TEXT, archived_at TEXT, archived_by TEXT, created_by TEXT, updated_by TEXT, created_at TEXT, updated_at TEXT);
            CREATE TABLE audit_logs (id TEXT PRIMARY KEY, entity_type TEXT, entity_id TEXT, action_type TEXT, performed_at TEXT);
            SQL);
        foreach (['gis_locations', 'monitoring_visit_reports', 'monitoring_materials'] as $table) {
            DB::statement('CREATE TABLE '.$table.' (id TEXT PRIMARY KEY)');
        }
        (require database_path('migrations/2026_10_27_000000_add_media_photos.php'))->up();
    }

    private function fixtures(): void
    {
        foreach ([1 => 'system_admin', 2 => 'field_officer', 3 => 'association_account'] as $id => $code) {
            DB::table('roles')->insert(['id' => $id, 'code' => $code, 'is_active' => true]);
        }
        foreach ([1 => 1, 2 => 2, 3 => 2, 4 => 3] as $id => $role) {
            DB::table('user_profiles')->insert(['id' => $this->id($id), 'role_id' => $role, 'display_name' => 'Test Actor', 'is_active' => true]);
        }
        DB::table('municipalities')->insert(['id' => $this->id(33), 'name' => 'Test Municipality']);
        DB::table('barangays')->insert(['id' => $this->id(32), 'municipality_id' => $this->id(33), 'name' => 'Test Barangay']);
        DB::table('areas')->insert(['id' => $this->id(31), 'barangay_id' => $this->id(32), 'address' => 'Registered Area']);
        foreach ([11 => 'active', 12 => 'active', 13 => 'archived', 14 => 'inactive'] as $id => $status) {
            DB::table('associations')->insert(['id' => $this->id($id), 'name' => 'Association '.$id, 'area_id' => $this->id(31),
                'status' => $status === 'archived' ? 'inactive' : $status, 'archived_at' => $status === 'archived' ? '2026-01-01' : null,
                'primary_field_officer_id' => $this->id($id === 12 ? 3 : 2)]);
        }
        DB::table('saad_activities')->insert([['id' => 1, 'code' => 'FPL_LPD', 'name' => 'Livelihood Project Delivery', 'is_active' => true],
            ['id' => 2, 'code' => 'SP_LT', 'name' => 'Livelihood Training', 'is_active' => true]]);
        DB::table('fisheries_project_categories')->insert(['id' => 1, 'name' => 'Aquaculture', 'is_active' => true]);
        DB::table('commodities')->insert(['id' => 1, 'name' => 'Fish', 'category_id' => 1, 'source' => 'manual', 'is_active' => true]);
    }

    public function test_cutover_program_modules_keep_area_less_parents_visible_and_assignment_denials(): void
    {
        DB::statement('ALTER TABLE associations ADD COLUMN barangay_id TEXT');
        DB::statement('UPDATE associations SET barangay_id=(SELECT barangay_id FROM areas WHERE areas.id=associations.area_id), area_id=NULL');
        config(['associations.geography_schema_ready' => true, 'associations.direct_geography' => true]);
        $this->project(101);
        $this->project(102, ['association_id' => $this->id(12), 'title' => 'Outside Project']);
        $this->training(201);
        $this->activity(301);
        foreach (['admin' => 1, 'field-officer' => 2] as $prefix => $identity) {
            $this->identity = $this->id($identity);
            foreach (['projects', 'trainings', 'program-activities'] as $module) {
                $page = $this->get('/'.$prefix.'/'.$module)->assertOk()->assertSee('Test Municipality');
                if ($prefix === 'field-officer') $page->assertDontSee('Outside Project');
            }
            $this->get('/'.$prefix.'/projects/'.$this->id(101))->assertOk()->assertSee('Test Municipality');
            $this->get('/'.$prefix.'/trainings/'.$this->id(201))->assertOk()->assertSee('Test Municipality');
        }
        $this->get('/field-officer/projects/'.$this->id(102))->assertForbidden();
        $this->assertDatabaseHas('projects', ['id' => $this->id(101), 'association_id' => $this->id(11), 'status' => 'ongoing']);
    }

    private function project(int $id, array $overrides = []): void
    {
        DB::table('projects')->insert(array_replace(['id' => $this->id($id), 'association_id' => $this->id(11),
            'saad_activity_id' => 1, 'fisheries_category_id' => 1, 'commodity_id' => 1, 'title' => 'Test Project',
            'program_year' => 2026, 'implementation_start_date' => '2026-01-01', 'status' => 'ongoing', 'updated_at' => '2026-01-01 00:00:00'], $overrides));
    }

    public function test_connected_staff_record_links_restore_registers_and_actual_parent_history(): void
    {
        $this->project(101);
        $this->training(201, ['related_project_id' => $this->id(101)]);
        $this->activity(301, ['related_project_id' => $this->id(101)]);
        foreach (['admin' => 1, 'field-officer' => 2] as $prefix => $actor) {
            $this->identity = $this->id($actor);
            foreach (['projects' => 101, 'trainings' => 201, 'program-activities' => 301] as $module => $id) {
                $register = '/'.$prefix.'/'.$module.'?q=Test&year=2026&sort=za&page=1';
                if ($module === 'program-activities') $register = str_replace('q=Test', 'q=Activity', $register);
                $page = $this->get($register)->assertOk();
                $dom = new \DOMDocument;
                @$dom->loadHTML($page->getContent());
                $xpath = new \DOMXPath($dom);
                $link = $xpath->query('//a[contains(@href, "/'.$module.'/'.$this->id($id).'") and contains(@href, "return_to=")]')->item(0);
                $this->assertNotNull($link, 'Record link must retain its filtered register.');
                $detail = $this->get($link->getAttribute('href'))->assertOk();
                $this->assertWorkflowBack($detail, $register);
            }
            $parent = '/'.$prefix.'/projects/'.$this->id(101).'?training_page=1&activity_page=1';
            foreach (['trainings' => [201, 'project-trainings'], 'program-activities' => [301, 'project-activities']] as $module => [$id, $anchor]) {
                $this->get('/'.$prefix.'/'.$module.'/'.$this->id($id).'?'.http_build_query(['return_to' => $parent.'#'.$anchor]))
                    ->assertOk()->assertSee('href="'.e(url($parent).'#'.$anchor).'" data-workflow-back', false);
            }
            $this->get('/'.$prefix.'/trainings/'.$this->id(201).'?'.http_build_query(['return_to' => '/'.$prefix.'/projects/'.$this->id(999)]))
                ->assertOk()->assertDontSee('/projects/'.$this->id(999));
        }
        $this->identity = $this->id(3);
        $this->get('/field-officer/projects/'.$this->id(101).'?return_to=/field-officer/projects')->assertForbidden();
        $this->get('/field-officer/trainings/'.$this->id(201))->assertForbidden();
        $this->get('/field-officer/program-activities/'.$this->id(301))->assertForbidden();
        DB::table('projects')->where('id', $this->id(101))->delete();
        foreach (['admin' => 1, 'field-officer' => 2] as $prefix => $actor) {
            $this->identity = $this->id($actor);
            foreach (['trainings' => 201, 'program-activities' => 301] as $module => $id) {
                $page = $this->get('/'.$prefix.'/'.$module.'/'.$this->id($id).'?'.http_build_query(['return_to' => '/'.$prefix.'/projects/'.$this->id(101)]))->assertOk();
                $page->assertDontSee('href="'.route($prefix.'.projects.show', $this->id(101)), false);
                $this->assertWorkflowBack($page, '/'.$prefix.'/'.$module);
            }
        }
    }

    public function test_project_contextual_actions_preserve_cohort_and_single_project_without_running_reporting_on_get(): void
    {
        $this->project(101);
        $reporting = Mockery::mock(ReportService::class);
        $reporting->shouldNotReceive('generate');
        $reporting->shouldNotReceive('options');
        $this->app->instance(ReportService::class, $reporting);
        foreach (['admin' => 1, 'field-officer' => 2] as $prefix => $identity) {
            $this->identity = $this->id($identity);
            DB::enableQueryLog();
            DB::flushQueryLog();
            $page = $this->get('/'.$prefix.'/projects')->assertOk();
            $this->assertCount(8, DB::getQueryLog(), 'Normal project GET retains its pre-change query count.');
            DB::disableQueryLog();
            $page->assertSee('Open related report')->assertDontSee('data-contextual-report-export', false);
            $filtered = $this->get('/'.$prefix.'/projects?q=Test&association=Association&status=ongoing&year=2026&sort=az')->assertOk();
            $filtered->assertSee('report_type=projects&amp;program_year=2026', false)
                ->assertSee('Search, association-name, status, sorting and pagination selections from this register are not applied.');
            $detail = $this->get('/'.$prefix.'/projects/'.$this->id(101))->assertOk();
            $detail->assertSee('Export Project Portfolio Report')->assertSee('Program year 2026.')
                ->assertSee('name="project_id" value="'.$this->id(101).'"', false)
                ->assertSee('name="association_id" value="'.$this->id(11).'"', false)
                ->assertSee('name="program_year" value="2026"', false);
            foreach (['pdf', 'xlsx'] as $format) {
                $detail->assertSee(route($prefix.'.reports.export', ['format' => $format]));
            }
        }
        $this->identity = $this->id(2);
        $this->project(102, ['association_id' => $this->id(12)]);
        $this->get('/field-officer/projects/'.$this->id(102))->assertForbidden();
        DB::table('associations')->where('id', $this->id(11))->update(['status' => 'inactive']);
        $this->get('/field-officer/projects/'.$this->id(101))->assertOk()->assertDontSee('data-contextual-report', false);
    }

    private function training(int $id, array $overrides = []): void
    {
        DB::table('trainings')->insert(array_replace(['id' => $this->id($id), 'association_id' => $this->id(11),
            'saad_activity_id' => 2, 'title' => 'Test Training', 'program_year' => 2026, 'start_date' => '2026-01-01',
            'status' => 'scheduled', 'updated_at' => '2026-01-01 00:00:00'], $overrides));
    }

    private function member(int $id, array $overrides = []): void
    {
        DB::table('members')->insert(array_replace(['id' => $this->id($id), 'association_id' => $this->id(11),
            'first_name' => 'Member', 'last_name' => (string) $id, 'member_status' => 'active'], $overrides));
    }

    private function participant(int $member, int $training = 201): void
    {
        DB::table('training_participants')->insert(['id' => $this->id($member + 1000), 'training_id' => $this->id($training),
            'member_id' => $this->id($member), 'attendance_status' => 'present', 'remarks' => 'Recorded attendance']);
    }

    private function activity(int $id, array $overrides = []): void
    {
        DB::table('saad_activities')->insertOrIgnore(['id' => 3, 'code' => 'SP_ORG', 'name' => 'Organizational Development', 'is_active' => true]);
        DB::table('program_activity_records')->insert(array_replace(['id' => $this->id($id), 'association_id' => $this->id(11),
            'saad_activity_id' => 3, 'title' => 'Activity '.$id, 'program_year' => 2026, 'activity_date' => '2026-01-01',
            'status' => 'planned', 'updated_at' => '2026-01-01 00:00:00'], $overrides));
    }

    private function activityPayload(): array
    {
        return ['association_id' => $this->id(11), 'saad_activity_id' => 3, 'title' => 'Saved Activity', 'program_year' => 2026,
            'activity_date' => '2026-01-01', 'status' => 'planned'];
    }

    private function trainingPayload(int $id = 201): array
    {
        return ['association_id' => $this->id(11), 'saad_activity_id' => 2, 'title' => 'Updated Training',
            'program_year' => 2026, 'start_date' => '2026-01-01', 'status' => 'scheduled',
            'expected_updated_at' => Training::findOrFail($this->id($id))->updated_at->format('Y-m-d\TH:i:s.uP')];
    }

    public static function managerRoles(): array
    {
        return ['Admin' => ['admin', 1], 'Field Officer' => ['field-officer', 2]];
    }

    #[DataProvider('managerRoles')]
    public function test_editor_queries_and_choice_contracts_for_each_program(string $prefix, int $actor): void
    {
        $this->identity = $this->id($actor);
        $this->project(101);
        $this->training(201, ['related_project_id' => $this->id(101)]);
        $this->activity(301, ['related_project_id' => $this->id(101)]);
        foreach (['projects' => 101, 'trainings' => 201, 'program-activities' => 301] as $module => $id) {
            DB::enableQueryLog(); DB::flushQueryLog();
            $response = $this->get('/'.$prefix.'/'.$module.'/'.$this->id($id).'/edit')->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();
            fwrite(STDOUT, "\n/{$prefix}/{$module} editor: {$queries} queries\n");
            if ($module === 'projects') {
                foreach (['association_records', 'fisheries_category_records', 'commodity_records'] as $key) {
                    $choices = $response->viewData('catalog')['options'][$key];
                    $this->assertIsArray($choices);
                    $this->assertNotEmpty($choices);
                    $this->assertArrayHasKey('value', $choices[0]);
                    $this->assertArrayHasKey('label', $choices[0]);
                }
                continue;
            }
            $this->assertInstanceOf(\Illuminate\Support\Collection::class, $response->viewData('associations'));
            $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $response->viewData('activities'));
            $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $response->viewData('projects'));
            $this->assertSame($this->id(101), $response->viewData('projects')->first()->id);
        }
        app(SupabaseAuthService::class)->shouldHaveReceived('getUser')->times(3);
    }

    #[DataProvider('managerRoles')]
    public function test_project_create_validation_restores_scoped_choices_and_unsaved_values(string $prefix, int $actor): void
    {
        $this->identity = $this->id($actor);
        $url = '/'.$prefix.'/projects';
        $payload = ['project_form' => 'editor', 'project_record_id' => '', 'association_id' => $this->id(11),
            'title' => 'Unsaved <Project>', 'program_year' => 2026, 'status' => 'ongoing',
            'materials' => [['item_name' => 'Unsaved material', 'quantity_planned' => 3]],
            'products' => [['product_name' => 'Unsaved product', 'product_type' => 'raw']]];
        $this->from($url)->post($url, $payload)->assertRedirect($url)->assertSessionHasErrors('fisheries_category_id')
            ->assertSessionHasInput('materials', $payload['materials'])->assertSessionHasInput('products', $payload['products']);
        $response = $this->get($url)->assertOk()->assertSee('data-project-errors', false)->assertSee('Unsaved &lt;Project&gt;', false)
            ->assertSee('Unsaved material')->assertSee('Unsaved product');
        $this->assertSame($this->id(11), $response->viewData('catalog')['options']['association_records'][0]['value']);
        $this->assertDatabaseCount('projects', 0);
    }

    private function trainingFormState(TestResponse $response, array $actions = []): array
    {
        $process = new Process(['node', base_path('tests/Frontend/support/training-form-fixture.mjs')], base_path());
        $process->setInput(json_encode(['html' => $response->getContent(), 'actions' => $actions], JSON_THROW_ON_ERROR));
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    #[DataProvider('managerRoles')]
    public function test_training_retained_project_initialization_and_save_follow_existing_rules(string $prefix, int $actor): void
    {
        $this->identity = $this->id($actor);
        foreach ([false, true] as $archived) {
            $id = $archived ? 102 : 101;
            $trainingId = $id + 100;
            $this->project($id, ['title' => 'Recorded <Project> '.$id, 'archived_at' => $archived ? '2026-02-01' : null]);
            $this->training($trainingId, ['related_project_id' => $this->id($id)]);
            DB::enableQueryLog(); DB::flushQueryLog();
            $editor = $this->get('/'.$prefix.'/trainings/'.$this->id($trainingId).'/edit')->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();
            fwrite(STDOUT, "\n/{$prefix}/trainings editor (".($archived ? 'archived' : 'eligible')." Project): {$queries} queries\n");
            $this->assertSame($archived ? 16 : 15, $queries, 'Editor query counts must match the verified legacy-geography baseline.');
            $this->assertSame($this->id($id), $editor->viewData('row')['related_project_id']);
            $this->assertSame('Recorded <Project> '.$id, $editor->viewData('row')['related_project']);
            $state = $this->trainingFormState($editor);
            $this->assertSame($this->id($id), $state['fields']['related_project_id'], 'Initializing the editor must not submit an unintended Project removal.');
            $this->assertSame('PATCH', $state['fields']['_method']);
            $this->assertSame($editor->viewData('row')['expected_updated_at'], $state['fields']['expected_updated_at']);
            $payload = array_replace($this->trainingPayload($trainingId), ['related_project_id' => $state['fields']['related_project_id']]);
            if ($archived) {
                $this->assertStringContainsString('Recorded <Project> '.$id, $state['projectLabel']);
                $this->assertStringContainsString('retained', $state['projectLabel']);
                $this->assertFalse($state['notice']['hidden']);
                $this->patchJson('/'.$prefix.'/trainings/'.$this->id($trainingId), $payload)->assertUnprocessable()->assertJsonValidationErrors('related_project_id');
            } else {
                $this->patch('/'.$prefix.'/trainings/'.$this->id($trainingId), $payload)->assertRedirect(route($prefix.'.trainings.index'));
            }
            $this->assertDatabaseHas('trainings', ['id' => $this->id($trainingId), 'related_project_id' => $this->id($id)]);
        }
        app(SupabaseAuthService::class)->shouldHaveReceived('getUser')->times(4);
    }

    #[DataProvider('managerRoles')]
    public function test_training_project_validation_recovery_preserves_selection_and_deliberate_removal(string $prefix, int $actor): void
    {
        $this->identity = $this->id($actor);
        $this->project(101, ['title' => 'Archived retained Project', 'archived_at' => '2026-02-01']);
        $this->training(201, ['related_project_id' => $this->id(101)]);
        $url = '/'.$prefix.'/trainings/'.$this->id(201);
        $editorUrl = $url.'/edit';
        $editor = $this->get($editorUrl)->assertOk();
        $state = $this->trainingFormState($editor);
        $payload = array_replace($this->trainingPayload(), ['related_project_id' => $state['fields']['related_project_id'],
            'training_form' => 'editor', 'training_record_id' => $this->id(201)]);
        $this->from($editorUrl)->patch($url, $payload)->assertRedirect($editorUrl)->assertSessionHasErrors('related_project_id');
        $recovered = $this->get($editorUrl)->assertOk()->assertSee('data-training-errors', false);
        $this->assertSame($this->id(101), $this->trainingFormState($recovered)['fields']['related_project_id']);
        $this->assertDatabaseHas('trainings', ['id' => $this->id(201), 'related_project_id' => $this->id(101), 'title' => 'Test Training']);

        $removed = $this->trainingFormState($recovered, [['type' => 'select-project', 'value' => '']]);
        $payload = array_replace($payload, ['related_project_id' => $removed['fields']['related_project_id'], 'title' => '']);
        $this->from($editorUrl)->patch($url, $payload)->assertRedirect($editorUrl)->assertSessionHasErrors('title');
        $restoredRemoval = $this->trainingFormState($this->get($editorUrl)->assertOk());
        $this->assertSame('', $restoredRemoval['fields']['related_project_id']);
        $this->assertSame('No related project', $restoredRemoval['projectLabel']);
        $this->assertDatabaseHas('trainings', ['id' => $this->id(201), 'related_project_id' => $this->id(101)]);
        $payload = array_replace($payload, ['title' => 'Deliberately unlinked Training', 'related_project_id' => $restoredRemoval['fields']['related_project_id']]);
        $this->patch($url, $payload)->assertRedirect(route($prefix.'.trainings.index'));
        $this->assertDatabaseHas('trainings', ['id' => $this->id(201), 'related_project_id' => null]);
        $this->assertDatabaseHas('projects', ['id' => $this->id(101), 'archived_at' => '2026-02-01', 'status' => 'ongoing']);
    }

    #[DataProvider('managerRoles')]
    public function test_training_invalid_replacements_and_stale_saves_preserve_the_recorded_project(string $prefix, int $actor): void
    {
        $this->identity = $this->id($actor);
        $this->project(101);
        $this->project(102, ['association_id' => $this->id(12)]);
        $this->project(103, ['archived_at' => '2026-02-01']);
        $this->project(104, ['status' => 'completed']);
        $this->project(105);
        $this->training(201, ['related_project_id' => $this->id(101)]);
        $url = '/'.$prefix.'/trainings/'.$this->id(201);
        $payload = $this->trainingPayload();
        foreach ([102, 103, 104] as $id) {
            $this->patchJson($url, $payload + ['related_project_id' => $this->id($id)])->assertUnprocessable()->assertJsonValidationErrors('related_project_id');
            $this->assertDatabaseHas('trainings', ['id' => $this->id(201), 'related_project_id' => $this->id(101), 'title' => 'Test Training']);
        }
        $state = $this->trainingFormState($this->get($url.'/edit')->assertOk());
        DB::table('trainings')->where('id', $this->id(201))->update(['updated_at' => '2026-03-01 00:00:00']);
        $stale = array_replace($payload, ['related_project_id' => $state['fields']['related_project_id'], 'expected_updated_at' => $state['fields']['expected_updated_at']]);
        $this->patchJson($url, $stale)->assertConflict()->assertJsonValidationErrors('training');
        $this->assertDatabaseHas('trainings', ['id' => $this->id(201), 'related_project_id' => $this->id(101), 'title' => 'Test Training']);
        $this->patch($url, array_replace($this->trainingPayload(), ['related_project_id' => $this->id(105)]))->assertRedirect(route($prefix.'.trainings.index'));
        $this->assertDatabaseHas('trainings', ['id' => $this->id(201), 'related_project_id' => $this->id(105)]);
    }

    #[DataProvider('managerRoles')]
    public function test_training_retained_project_does_not_bypass_owner_or_lifecycle_restrictions(string $prefix, int $actor): void
    {
        $this->identity = $this->id($actor);
        $this->project(101, ['association_id' => $this->id(12), 'title' => 'Reassigned retained Project']);
        $this->training(201, ['related_project_id' => $this->id(101)]);
        $url = '/'.$prefix.'/trainings/'.$this->id(201);
        $state = $this->trainingFormState($this->get($url.'/edit')->assertOk());
        $this->assertSame($this->id(101), $state['fields']['related_project_id']);
        $this->assertStringContainsString('Reassigned retained Project', $state['projectLabel']);
        $this->patchJson($url, $this->trainingPayload() + ['related_project_id' => $state['fields']['related_project_id']])->assertUnprocessable()->assertJsonValidationErrors('related_project_id');

        foreach ([['association_id' => $this->id(13), 'status' => 'ongoing'], ['association_id' => $this->id(11), 'status' => 'completed'],
            ['association_id' => $this->id(11), 'status' => 'terminated']] as $blocked) {
            DB::table('projects')->where('id', $this->id(101))->update($blocked);
            $this->get($url.'/edit')->assertForbidden();
            $this->patchJson($url, $this->trainingPayload() + ['related_project_id' => ''])->assertUnprocessable()->assertJsonValidationErrors('related_project_id');
            $this->assertDatabaseHas('trainings', ['id' => $this->id(201), 'related_project_id' => $this->id(101), 'title' => 'Test Training']);
        }
        DB::table('projects')->where('id', $this->id(101))->update(['status' => 'ongoing']);
        DB::table('trainings')->where('id', $this->id(201))->update(['archived_at' => '2026-02-01']);
        $this->get($url.'/edit')->assertForbidden();
        $this->patchJson($url, $this->trainingPayload() + ['related_project_id' => ''])->assertUnprocessable()->assertJsonValidationErrors('training');
        $this->assertDatabaseHas('trainings', ['id' => $this->id(201), 'related_project_id' => $this->id(101)]);
    }

    public function test_project_covers_render_private_thumbnails_and_independent_staff_controls(): void
    {
        $this->project(101);
        $photo = $this->id(900);
        DB::table('media_photos')->insert(['id' => $photo, 'project_id' => $this->id(101), 'uploaded_by' => $this->id(1),
            'request_id' => $this->id(901), 'object_prefix' => 'media/'.$this->id(902), 'input_sha256' => str_repeat('a', 64),
            'state' => 'ready', 'image_format' => 'png', 'display_width' => 64, 'display_height' => 32,
            'display_bytes' => 100, 'display_sha256' => str_repeat('b', 64), 'thumbnail_width' => 64,
            'thumbnail_height' => 32, 'thumbnail_bytes' => 100, 'thumbnail_sha256' => str_repeat('c', 64)]);
        foreach (['admin' => 1, 'field-officer' => 2] as $prefix => $actor) {
            $this->identity = $this->id($actor);
            $this->get('/'.$prefix.'/projects')->assertOk()->assertSee('data-project-cover-thumbnail', false)
                ->assertSee(route($prefix.'.projects.cover.image', [$this->id(101), $photo, 'thumbnail']))
                ->assertDontSee('/display')->assertDontSee('media/'.$this->id(902));
            $this->get('/'.$prefix.'/projects/'.$this->id(101))->assertOk()->assertSee('data-cover-upload', false);
            $this->get('/'.$prefix.'/projects/'.$this->id(101).'/edit')->assertOk()->assertSee('data-cover-upload', false)
                ->assertSee('id="project-form"', false);
        }
        DB::table('projects')->update(['status' => 'completed']);
        $this->get('/field-officer/projects/'.$this->id(101))->assertOk()->assertSee('data-project-cover', false)
            ->assertDontSee('data-cover-upload', false);
    }

    public function test_project_register_filters_paginates_and_preserves_distinct_statuses(): void
    {
        foreach (range(101, 130) as $id) $this->project($id);
        $this->project(131, ['status' => 'completed']);
        $this->project(132, ['status' => 'terminated']);
        $this->project(133, ['status' => 'suspended', 'archived_at' => '2026-01-02']);
        $this->project(134, ['status' => 'planned']);
        $this->project(135, ['status' => 'cancelled']);
        $response = $this->get('/admin/projects')->assertOk()->assertSee('Project Management')->assertSee('Aquaculture');
        $records = $response->viewData('projects');
        $this->assertCount(25, $records);
        $this->assertSame(34, $records->total());
        $this->assertSame(30, $response->viewData('summary')['ongoing']);
        $this->assertCount(9, $this->get('/admin/projects?page=2')->assertOk()->viewData('projects'));
        foreach (['planned', 'ongoing', 'suspended', 'cancelled', 'completed', 'terminated', 'archived'] as $status) {
            $rows = $this->get('/admin/projects?status='.$status)->assertOk()->viewData('projects');
            foreach ($rows as $record) $this->assertTrue($status === 'archived' ? $record->archived_at !== null : $record->status === $status && $record->archived_at === null);
        }
        $this->get('/admin/projects?status=archived')->assertOk()->assertSee('Suspended')->assertSee('Archived');
        $this->get('/admin/projects?status=completed')->assertOk()->assertDontSee('project-action-'.$this->id(131));
        $this->get('/admin/projects?status=terminated')->assertOk()->assertDontSee('project-action-'.$this->id(132));
    }

    public function test_project_search_sort_and_role_scope_are_enforced_in_queries(): void
    {
        $this->project(101, ['title' => 'Alpha Fish']);
        $this->project(102, ['title' => 'Zed %_ Project', 'implementation_start_date' => '2026-02-01']);
        $this->project(103, ['association_id' => $this->id(12), 'title' => 'Forbidden Project']);
        $this->project(104, ['association_id' => $this->id(13), 'title' => 'Archived Association Project']);
        $this->assertSame($this->id(102), $this->get('/admin/projects?sort=za')->assertOk()->viewData('projects')->first()->id);
        $this->assertSame($this->id(102), $this->get('/admin/projects?sort=newest')->assertOk()->viewData('projects')->first()->id);
        $this->assertSame(1, $this->get('/admin/projects?q=%25_')->assertOk()->viewData('projects')->total());
        $this->get('/admin/projects?year=2025')->assertOk()->assertSee('No matching projects');
        $this->identity = $this->id(2);
        $response = $this->get('/field-officer/projects')->assertOk()->assertDontSee('Forbidden Project')->assertDontSee('Archived Association Project');
        $this->assertSame(2, $response->viewData('summary')['current']);
        $this->assertSame(0, $this->get('/field-officer/projects?q=Forbidden')->assertOk()->viewData('projects')->total());
        $this->get('/field-officer/projects/'.$this->id(103))->assertForbidden();
        $this->get('/field-officer/projects/'.$this->id(104))->assertForbidden();
        $this->identity = $this->id(4);
        $this->get('/admin/projects')->assertForbidden();
        $this->get('/field-officer/projects')->assertForbidden();
    }

    public function test_project_details_and_editors_preserve_resources_and_finalization_controls(): void
    {
        $this->project(101);
        DB::table('project_products')->insert(['id' => $this->id(301), 'project_id' => $this->id(101), 'product_name' => 'Recorded Product', 'product_type' => 'raw', 'is_active' => true]);
        DB::table('project_materials')->insert(['id' => $this->id(302), 'project_id' => $this->id(101), 'item_name' => 'Recorded Material', 'quantity_planned' => 5, 'quantity_delivered' => 2, 'unit' => 'units']);
        $this->get('/admin/projects/'.$this->id(101))->assertOk()->assertSee('Recorded Product')->assertSee('Recorded Material')
            ->assertSee('Registered Area')->assertSee('data-project-finalization-form', false)->assertSee('expected_updated_at');
        $this->get('/admin/projects/'.$this->id(101).'/edit')->assertOk()->assertSee('Recorded Product')->assertSee('Recorded Material')
            ->assertSee('preserveRowId')->assertSee('data-return-monitoring', false);
        DB::table('projects')->where('id', $this->id(101))->update(['status' => 'terminated', 'closed_at' => '2026-02-01', 'closure_remarks' => '<script>Final remarks</script>']);
        $this->get('/admin/projects/'.$this->id(101))->assertOk()->assertSee('TERMINATED')->assertSee('permanently read-only')
            ->assertSee('&lt;script&gt;Final remarks&lt;/script&gt;', false)->assertDontSee('data-project-finalization-form', false);
        $this->get('/admin/projects/'.$this->id(101).'/edit')->assertForbidden();
    }

    #[DataProvider('managerRoles')]
    public function test_project_register_actions_follow_authorized_lifecycle_data(string $prefix, int $actor): void
    {
        $this->identity = $this->id($actor);
        $cases = [
            101 => ['title' => 'ARCHIVED <Fish Project>', 'actions' => 'ongoing'],
            102 => ['status' => 'planned', 'actions' => 'editable'],
            103 => ['status' => 'suspended', 'actions' => 'editable'],
            104 => ['status' => 'cancelled', 'actions' => 'editable'],
            105 => ['status' => 'completed', 'actions' => 'read-only'],
            106 => ['status' => 'terminated', 'closed_at' => '2026-02-01', 'actions' => 'read-only'],
            107 => ['closed_at' => '2026-02-01', 'actions' => 'read-only'],
            108 => ['association_id' => $this->id(14), 'actions' => 'read-only'],
            109 => ['association_id' => $this->id(13), 'actions' => 'read-only'],
            110 => ['association_id' => $this->id(12), 'actions' => 'ongoing'],
            111 => ['archived_at' => '2026-02-01', 'actions' => 'restore'],
            112 => ['status' => 'planned', 'archived_at' => '2026-02-01', 'actions' => 'restore'],
            113 => ['status' => 'completed', 'archived_at' => '2026-02-01', 'actions' => 'read-only'],
            114 => ['status' => 'terminated', 'archived_at' => '2026-02-01', 'actions' => 'read-only'],
        ];
        foreach ($cases as $id => $case) {
            unset($case['actions']);
            $this->project($id, $case + ['title' => 'Project '.$id]);
        }

        foreach ([false, true] as $archived) {
            $response = $this->get('/'.$prefix.'/projects'.($archived ? '?status=archived' : ''))->assertOk();
            $response->assertDontSee('@else', false)->assertDontSee('@endif', false)->assertDontSee('@if(', false);
            $document = new \DOMDocument();
            @$document->loadHTML($response->getContent());
            $xpath = new \DOMXPath($document);
            foreach ($cases as $id => $case) {
                if (isset($case['archived_at']) !== $archived) continue;
                $viewUrl = route($prefix.'.projects.show', ['project' => $this->id($id), 'return_to' => '/'.$prefix.'/projects'.($archived ? '?status=archived' : '')]);
                $rows = $xpath->query('//table/tbody/tr[th/a[@href="'.$viewUrl.'"]]');
                if ($prefix === 'field-officer' && in_array($id, [109, 110], true)) {
                    $this->assertSame(0, $rows->length);
                    continue;
                }
                $this->assertSame(1, $rows->length, 'Missing project '.$id);
                $cell = $xpath->query('./td[last()]', $rows->item(0))->item(0);
                $links = [];
                foreach ($xpath->query('.//a', $cell) as $link) $links[trim($link->textContent)] = $link->getAttribute('href');
                $expectedLinks = ['View' => $viewUrl];
                if (in_array($case['actions'], ['editable', 'ongoing'], true)) {
                    $expectedLinks['Edit Project'] = route($prefix.'.projects.edit', $this->id($id));
                }
                if ($case['actions'] === 'ongoing') {
                    foreach (['Complete Project' => 'completed', 'Terminate Project' => 'terminated'] as $label => $outcome) {
                        $expectedLinks[$label] = route($prefix.'.projects.show', ['project' => $this->id($id), 'finalize' => $outcome]);
                    }
                }
                $this->assertSame($expectedLinks, $links);
                $menus = $xpath->query('.//details[@data-user-actions]', $cell);
                $readOnly = $case['actions'] === 'read-only';
                $this->assertSame($readOnly ? 0 : 1, $menus->length);
                $this->assertSame($readOnly, str_contains($cell->textContent, 'Read-only'));
                $dialogs = $xpath->query('//dialog[@id="project-action-'.$this->id($id).'"]');
                $this->assertSame($readOnly ? 0 : 1, $dialogs->length);
                if ($readOnly) continue;
                $summary = $xpath->query('./summary', $menus->item(0))->item(0);
                $this->assertSame('Actions for '.($case['title'] ?? 'Project '.$id), $summary->getAttribute('aria-label'));
                $button = $xpath->query('.//button', $cell)->item(0);
                $this->assertSame($case['actions'] === 'restore' ? 'Restore Project' : 'Archive Project', trim($button->textContent));
                $this->assertSame('project-action-'.$this->id($id), $button->getAttribute('data-dialog'));
                $form = $xpath->query('.//form', $dialogs->item(0))->item(0);
                $this->assertSame(route($prefix.'.projects.'.($archived ? 'unarchive' : 'archive'), $this->id($id)), $form->getAttribute('action'));
                $this->assertSame('POST', $form->getAttribute('method'));
                $this->assertSame('PATCH', $xpath->query('.//input[@name="_method"]', $form)->item(0)->getAttribute('value'));
                $this->assertNotEmpty($xpath->query('.//input[@name="_token"]', $form)->item(0)->getAttribute('value'));
            }
        }
    }

    #[DataProvider('managerRoles')]
    public function test_project_action_menu_keeps_listing_queries_constant(string $prefix, int $actor): void
    {
        $this->identity = $this->id($actor);
        $this->project(101);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/'.$prefix.'/projects')->assertOk();
        $before = count(DB::getQueryLog());
        DB::disableQueryLog();
        foreach (range(102, 130) as $id) $this->project($id, ['status' => $id === 130 ? 'completed' : 'ongoing']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $response = $this->get('/'.$prefix.'/projects')->assertOk();
        $after = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame(8, $before);
        $this->assertSame($before, $after);
        $response->assertSee('Test Barangay')->assertSee('Test Municipality');
        $this->assertCount(25, $response->viewData('projects'));
        $this->assertSame(30, $response->viewData('projects')->total());
        app(SupabaseAuthService::class)->shouldHaveReceived('getUser')->times(2);
        fwrite(STDOUT, "\n/{$prefix}/projects: {$before} -> {$after} queries (1 -> 30 projects)\n");
    }

    #[DataProvider('managerRoles')]
    public function test_project_action_destinations_keep_finalization_confirmation_and_version_fields(string $prefix, int $actor): void
    {
        $this->identity = $this->id($actor);
        $this->project(101);
        foreach (['completed' => ['close', 'COMPLETED', 'completion_confirmation'], 'terminated' => ['terminate', 'TERMINATE', 'termination_confirmation']] as $outcome => [$action, $word, $field]) {
            $this->get('/'.$prefix.'/projects/'.$this->id(101).'?finalize='.$outcome)->assertOk()
                ->assertSee('action="'.route($prefix.'.projects.'.$action, $this->id(101)).'"', false)
                ->assertSee('name="_token"', false)->assertSee('name="expected_updated_at" value="2026-01-01T00:00:00.000000+00:00"', false)
                ->assertSee('data-confirmation-word="'.$word.'"', false)->assertSee('name="'.$field.'"', false)
                ->assertSee('data-open-finalization="true"', false)->assertSee('data-project-finalization-submit disabled', false);
        }
    }

    public function test_project_create_editor_choices_are_lazy_bounded_and_scoped(): void
    {
        foreach (range(40, 69) as $id) DB::table('associations')->insert(['id' => $this->id($id), 'name' => 'Choice '.$id, 'status' => 'active', 'primary_field_officer_id' => $this->id(2)]);
        $this->get('/admin/projects')->assertOk()->assertDontSee('id="project-form"', false);
        $this->get('/admin/projects?editor=create')->assertOk()->assertSee('id="project-form"', false)->assertSee('Save Project')->assertSee('Find an association');
        $this->identity = $this->id(2);
        $response = $this->getJson('/field-officer/projects?lookup=associations')->assertOk();
        $this->assertCount(25, $response->json('options'));
        $this->assertTrue($response->json('has_more'));
        $this->getJson('/field-officer/projects?lookup=associations&term=Association%2012')->assertOk()->assertExactJson(['options' => [], 'page' => 1, 'has_more' => false]);
        $this->getJson('/field-officer/projects?lookup=associations&lookup_page=2')->assertOk()->assertJsonCount(6, 'options');
    }

    public function test_program_filters_are_allowlisted(): void
    {
        foreach (['sort=DROP', 'status=unknown', 'q[]=x', 'year=1900', 'page=-1', 'lookup=unknown', 'lookup_page=-1'] as $query) {
            $this->getJson('/admin/projects?'.$query)->assertUnprocessable();
        }
    }

    public function test_training_register_uses_supported_statuses_and_bounded_participant_counts(): void
    {
        $this->project(101);
        foreach (range(201, 230) as $id) $this->training($id, ['related_project_id' => $this->id(101)]);
        $this->training(231, ['status' => 'completed']);
        $this->training(232, ['status' => 'cancelled']);
        $this->training(233, ['status' => 'completed', 'archived_at' => '2026-01-02']);
        $this->member(401); $this->participant(401);
        $response = $this->get('/admin/trainings')->assertOk()->assertSee('Training Management')->assertSee('Livelihood Training');
        $this->assertSame(32, $response->viewData('trainings')->total());
        $this->assertCount(25, $response->viewData('trainings'));
        $this->assertSame(1, $response->viewData('trainings')->first()->participants_count);
        $this->assertFalse($response->viewData('trainings')->first()->relationLoaded('participants'));
        $this->assertSame(['current' => 32, 'archived' => 1, 'scheduled' => 30, 'completed' => 1, 'cancelled' => 1], $response->viewData('summary'));
        $this->get('/admin/trainings?status=archived')->assertOk()->assertSee('Completed')->assertSee('Archived');
        $this->get('/admin/trainings?status=completed')->assertOk()->assertSee('Edit');
        $this->get('/admin/trainings?status=cancelled')->assertOk()->assertSee('Cancelled');
        $this->assertSame(0, $this->get('/admin/trainings?q=NoMatch')->assertOk()->viewData('trainings')->total());
        $this->getJson('/admin/trainings?status=pending')->assertUnprocessable();
    }

    public function test_training_role_scope_and_parent_read_only_rules_are_preserved(): void
    {
        $this->project(101, ['status' => 'terminated']);
        $this->training(201, ['related_project_id' => $this->id(101)]);
        $this->training(202, ['association_id' => $this->id(12), 'title' => 'Other Officer Training']);
        $this->training(203, ['association_id' => $this->id(13), 'title' => 'Archived Association Training']);
        $this->training(204, ['association_id' => $this->id(14), 'title' => 'Inactive Association Training']);
        $this->identity = $this->id(2);
        $response = $this->get('/field-officer/trainings')->assertOk()->assertDontSee('Other Officer Training')
            ->assertDontSee('Archived Association Training')->assertDontSee('Inactive Association Training')
            ->assertDontSee('training-action-'.$this->id(201))->assertSee('project finalized');
        $this->assertSame(1, $response->viewData('summary')['current']);
        foreach ([202, 203, 204] as $id) $this->get('/field-officer/trainings/'.$this->id($id))->assertForbidden();
        $this->get('/field-officer/trainings/'.$this->id(201).'/edit')->assertForbidden();
        $this->identity = $this->id(1);
        $this->get('/admin/trainings/'.$this->id(203))->assertOk()->assertSee('Historical details remain available');
        $this->get('/admin/trainings/'.$this->id(204).'/edit')->assertForbidden();
        $this->identity = $this->id(4);
        $this->get('/admin/trainings')->assertForbidden();
        $this->get('/field-officer/trainings')->assertForbidden();
    }

    #[DataProvider('managerRoles')]
    public function test_training_participant_paging_retains_history_attendance_and_explicit_removals(string $prefix, int $actor): void
    {
        $this->identity = $this->id($actor);
        $this->project(101);
        $this->training(201, ['related_project_id' => $this->id(101)]);
        foreach (range(401, 430) as $id) { $this->member($id); $this->participant($id); }
        DB::table('members')->where('id', $this->id(401))->update(['member_status' => 'inactive', 'archived_at' => '2026-02-01']);
        $this->member(431);
        $this->getJson('/'.$prefix.'/trainings?lookup=members&association_id='.$this->id(11).'&training_id='.$this->id(201))
            ->assertOk()->assertJsonCount(1, 'options')->assertJsonPath('options.0.value', $this->id(431));
        $response = $this->get('/'.$prefix.'/trainings/'.$this->id(201))->assertOk()->assertSee('Historical participation');
        $this->assertCount(25, $response->viewData('participants'));
        $this->assertSame(30, $response->viewData('row')['participant_count']);
        $editor = $this->get('/'.$prefix.'/trainings/'.$this->id(201).'/edit')->assertOk()->assertSee('removed_participant_ids[]', false)
            ->assertSee('expected_updated_at')->assertSee('Participants on other pages are retained');
        $this->assertCount(25, $editor->viewData('row')['participants']);
        $this->assertCount(5, $this->get('/'.$prefix.'/trainings/'.$this->id(201).'/edit?participant_page=2')->assertOk()->viewData('row')['participants']);
        $retained = (array) DB::table('training_participants')->where('member_id', $this->id(430))->first();
        $payload = $this->trainingPayload() + ['related_project_id' => $this->id(101), 'participants' => [
            ['id' => $this->id(1401), 'member_id' => $this->id(401), 'attendance_status' => 'absent', 'remarks' => 'Corrected attendance'],
            ['member_id' => $this->id(431), 'attendance_status' => 'present']], 'removed_participant_ids' => [$this->id(1402)]];
        $this->patch('/'.$prefix.'/trainings/'.$this->id(201), $payload)->assertRedirect(route($prefix.'.trainings.index'));
        $this->assertDatabaseHas('trainings', ['id' => $this->id(201), 'related_project_id' => $this->id(101)]);
        $this->assertDatabaseCount('training_participants', 30);
        $this->assertDatabaseMissing('training_participants', ['id' => $this->id(1402)]);
        $this->assertDatabaseHas('training_participants', ['id' => $this->id(1401), 'attendance_status' => 'absent']);
        $this->assertSame($retained, (array) DB::table('training_participants')->where('member_id', $this->id(430))->first());
        $this->patchJson('/'.$prefix.'/trainings/'.$this->id(201), $payload)->assertConflict();
        $this->assertDatabaseCount('training_participants', 30);
    }

    public function test_training_lookups_are_bounded_and_do_not_expose_ineligible_records(): void
    {
        foreach (range(101, 130) as $id) $this->project($id);
        foreach (range(401, 430) as $id) $this->member($id);
        $this->project(131, ['status' => 'completed', 'title' => 'Finalized Choice']);
        $this->project(132, ['association_id' => $this->id(12), 'title' => 'Other Choice']);
        $this->member(431, ['association_id' => $this->id(12), 'first_name' => 'OtherMember']);
        $this->member(432, ['member_status' => 'inactive', 'first_name' => 'InactiveMember']);
        $this->identity = $this->id(2);
        $response = $this->getJson('/field-officer/trainings?lookup=training_choices&association_id='.$this->id(11))->assertOk();
        $this->assertCount(25, $response->json('projects.options'));
        $this->assertCount(25, $response->json('members.options'));
        $this->assertTrue($response->json('members.has_more'));
        $this->getJson('/field-officer/trainings?lookup=members&association_id='.$this->id(12))->assertOk()->assertJsonCount(0, 'options');
        $this->getJson('/field-officer/trainings?lookup=projects&association_id='.$this->id(11).'&term=Finalized')->assertOk()->assertJsonCount(0, 'options');
        $this->getJson('/field-officer/trainings?lookup=members&association_id='.$this->id(11).'&term=InactiveMember')->assertOk()->assertJsonCount(0, 'options');
        $this->getJson('/field-officer/trainings?lookup=members&association_id='.$this->id(11).'&lookup_page=2')->assertOk()->assertJsonCount(5, 'options');
        $this->get('/field-officer/trainings?editor=create')->assertOk()->assertSee('Save Training')->assertSee('Find a member to add');
    }

    #[DataProvider('managerRoles')]
    public function test_training_creation_and_cross_association_validation_remain_authoritative(string $prefix, int $actor): void
    {
        $this->identity = $this->id($actor);
        $this->member(401);
        $this->member(402, ['association_id' => $this->id(12)]);
        $payload = ['association_id' => $this->id(11), 'saad_activity_id' => 2, 'title' => 'Created Training', 'program_year' => 2026,
            'start_date' => '2026-01-01', 'status' => 'scheduled', 'participants' => [['member_id' => $this->id(401), 'attendance_status' => 'present']]];
        $this->post('/'.$prefix.'/trainings', $payload)->assertRedirect(route($prefix.'.trainings.index'));
        $this->assertDatabaseCount('trainings', 1);
        $this->assertDatabaseCount('training_participants', 1);
        $payload['participants'][0]['member_id'] = $this->id(402);
        $this->postJson('/'.$prefix.'/trainings', $payload)->assertUnprocessable()->assertJsonValidationErrors('participants');
        $this->assertDatabaseCount('trainings', 1);
        $this->assertDatabaseCount('training_participants', 1);
    }

    #[DataProvider('managerRoles')]
    public function test_project_forms_keep_resource_identity_and_monitoring_navigation(string $prefix, int $actor): void
    {
        $this->identity = $this->id($actor);
        $payload = ['association_id' => $this->id(11), 'fisheries_category_id' => 1, 'commodity_id' => 1,
            'title' => 'Created Project', 'program_year' => 2026, 'implementation_start_date' => '2026-01-01', 'status' => 'planned',
            'materials' => [['item_name' => 'Cage', 'quantity_planned' => 5, 'quantity_delivered' => 2, 'unit' => 'units', 'delivery_status' => 'partially_delivered']],
            'products' => [['product_name' => 'Fish', 'product_type' => 'raw', 'is_active' => true]]];
        $this->post('/'.$prefix.'/projects', $payload)->assertRedirect(route($prefix.'.projects.index'));
        $project = Project::with(['materials', 'products'])->sole();
        $materialId = $project->materials->sole()->id;
        $productId = $project->products->sole()->id;
        $payload['title'] = 'Edited Project';
        $payload['materials'][0]['id'] = $materialId;
        $payload['products'][0]['id'] = $productId;
        $this->patch('/'.$prefix.'/projects/'.$project->id, $payload)->assertRedirect(route($prefix.'.projects.index'));
        $this->assertDatabaseHas('project_materials', ['id' => $materialId, 'project_id' => $project->id]);
        $this->assertDatabaseHas('project_products', ['id' => $productId, 'project_id' => $project->id]);
        $this->assertDatabaseCount('project_materials', 1);
        $this->assertDatabaseCount('project_products', 1);
        $query = '?from_monitoring=1&project_id='.$project->id.'&resource_section=materials&monitoring_record='.$this->id(999);
        $this->get('/'.$prefix.'/projects'.$query)->assertRedirect(route($prefix.'.projects.edit', ['project' => $project->id,
            'from_monitoring' => 1, 'resource_section' => 'materials', 'monitoring_record' => $this->id(999)]));
        $this->get('/'.$prefix.'/projects/'.$project->id.'/edit'.$query)->assertOk()->assertSee('data-return-monitoring', false);
    }

    #[DataProvider('managerRoles')]
    public function test_activity_register_cards_filters_and_pagination_preserve_role_scope(string $prefix, int $actor): void
    {
        $this->identity = $this->id($actor);
        foreach (range(201, 230) as $id) $this->activity($id);
        foreach ([231 => 'ongoing', 232 => 'completed', 233 => 'cancelled'] as $id => $status) $this->activity($id, compact('status'));
        $this->activity(234, ['archived_at' => '2026-02-01']);
        $this->activity(235, ['association_id' => null, 'title' => 'Program-wide Activity']);
        $this->activity(236, ['association_id' => $this->id(12), 'title' => 'Other Officer Activity']);
        $this->activity(237, ['association_id' => $this->id(13), 'title' => 'Archived Owner Activity']);
        $this->activity(238, ['association_id' => $this->id(14), 'title' => 'Inactive Owner Activity']);
        $response = $this->get('/'.$prefix.'/program-activities')->assertOk()->assertSee('Program Activity Management')
            ->assertSee('Organizational Development')->assertSee('id="program-extra-filters" class="program-filter-panel" hidden', false)
            ->assertDontSee('id="program-activity-form"', false)->assertDontSee('@else', false)->assertDontSee('@endif', false);
        $this->assertCount(25, $response->viewData('records'));
        $this->assertSame($prefix === 'admin' ? 37 : 33, $response->viewData('records')->total());
        $summary = $response->viewData('summary');
        $this->assertSame($prefix === 'admin' ? 34 : 30, $summary['planned']);
        foreach (['ongoing', 'completed', 'cancelled', 'archived'] as $status) $this->assertSame(1, $summary[$status]);
        foreach (['planned', 'ongoing', 'completed', 'cancelled', 'archived'] as $status) {
            $filtered = $this->get('/'.$prefix.'/program-activities?status='.$status)->assertOk()->assertSee('aria-current="page"', false);
            foreach ($filtered->viewData('records') as $record) $this->assertTrue($status === 'archived' ? $record->archived_at !== null : $record->status === $status);
        }
        $response = $this->get('/'.$prefix.'/program-activities?q=Activity&association=Association%2011&year=2026&status=planned&sort=za')->assertOk();
        $this->assertSame(30, $response->viewData('records')->total());
        $this->assertSame('Activity 230', $response->viewData('records')->first()->title);
        $next = $response->viewData('records')->nextPageUrl();
        parse_str(parse_url($next, PHP_URL_QUERY), $query);
        $this->assertSame(['q' => 'Activity', 'association' => 'Association 11', 'year' => '2026', 'status' => 'planned', 'sort' => 'za', 'page' => '2'], $query);
        $this->assertCount(5, $this->get($next)->assertOk()->viewData('records'));
        $this->assertSame('planned', $this->get('/'.$prefix.'/program-activities')->assertOk()->viewData('records')->first()->status);
        $this->assertSame($prefix === 'admin' ? 37 : 33, $this->get('/'.$prefix.'/program-activities?q=Organizational')->assertOk()->viewData('records')->total());
        foreach (['sort=DROP', 'status=terminated', 'q[]=x', 'year=1900', 'lookup=members', 'lookup_page=-1', 'lookup=projects'] as $query) {
            $this->getJson('/'.$prefix.'/program-activities?'.$query)->assertUnprocessable();
        }
        $this->get('/'.$prefix.'/program-activities?q=Missing')->assertOk()->assertSee('No matching program activities');
    }

    #[DataProvider('managerRoles')]
    public function test_activity_index_keeps_exact_view_data_and_html_even_when_json_is_requested(string $prefix, int $actor): void
    {
        $this->identity = $this->id($actor);
        $this->project(101);
        $this->activity(201, ['related_project_id' => $this->id(101)]);
        $expected = [
            'id' => $this->id(201), 'project_finalized' => false, 'association_archived' => false,
            'parents_operational' => true, 'can_manage' => true, 'can_view_project' => true,
            'saad_activity_id' => 3, 'activity' => 'Organizational Development', 'activity_code' => 'SP_ORG',
            'association_id' => $this->id(11), 'association' => 'Association 11',
            'municipality' => 'Test Municipality', 'barangay' => 'Test Barangay',
            'related_project_id' => $this->id(101), 'related_project' => 'Test Project',
            'title' => 'Activity 201', 'description' => null, 'program_year' => 2026,
            'activity_date' => '2026-01-01', 'venue' => null, 'status' => 'Planned', 'status_value' => 'planned',
            'conducted_by' => null, 'remarks' => null, 'archived' => false,
            'update_url' => route($prefix.'.program-activities.update', $this->id(201)),
            'archive_url' => route($prefix.'.program-activities.archive', $this->id(201)),
            'unarchive_url' => route($prefix.'.program-activities.unarchive', $this->id(201)),
        ];
        $counts = [];
        foreach (['get', 'getJson'] as $method) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $page = $this->{$method}('/'.$prefix.'/program-activities')->assertOk()->assertViewIs('program-activities.register');
                $counts[] = count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
            }
            $this->assertStringStartsWith('text/html', $page->headers->get('Content-Type'));
            $this->assertSame($prefix, $page->viewData('prefix'));
            $this->assertSame(['q' => '', 'association' => '', 'year' => '', 'status' => '', 'sort' => 'year'], $page->viewData('filters'));
            $this->assertSame(['current' => 1, 'archived' => 0, 'planned' => 1, 'ongoing' => 0, 'completed' => 0, 'cancelled' => 0], $page->viewData('summary'));
            $this->assertSame([$expected], $page->viewData('rows')->all());
            $this->assertSame(false, $page->viewData('showEditor'));
            $this->assertArrayNotHasKey('associations', $page->original->getData());
            $this->assertSame(1, $page->viewData('records')->total());
            $this->assertSame(25, $page->viewData('records')->perPage());
        }
        $this->assertSame($counts[0], $counts[1]);
        fwrite(STDOUT, "\n/{$prefix}/program-activities HTML / JSON Accept: ".implode(' / ', $counts)." queries\n");
    }

    #[DataProvider('managerRoles')]
    public function test_activity_lookup_keeps_exact_json_and_skips_register_queries(string $prefix, int $actor): void
    {
        $this->identity = $this->id($actor);
        $this->project(101);
        $this->activity(201);
        $associations = [['value' => $this->id(11), 'label' => 'Association 11']];
        if ($prefix === 'admin') {
            $associations[] = ['value' => $this->id(12), 'label' => 'Association 12'];
        }
        $choices = [
            'associations' => $associations,
            'activities' => [['value' => '3', 'label' => 'Organizational Development']],
            'projects' => [['value' => $this->id(101), 'label' => 'Test Project', 'association_id' => $this->id(11)]],
        ];
        $counts = [];
        foreach ($choices as $kind => $options) {
            $query = ['lookup' => $kind] + ($kind === 'projects' ? ['association_id' => $this->id(11)] : []);
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $this->get('/'.$prefix.'/program-activities?'.http_build_query($query))->assertOk()
                    ->assertHeader('Content-Type', 'application/json')
                    ->assertExactJson(['options' => $options, 'page' => 1, 'has_more' => false]);
                $queries = DB::getQueryLog();
                $counts[$kind] = count($queries);
                foreach ($queries as $executed) {
                    $this->assertStringNotContainsString('program_activity_records', $executed['query']);
                }
            } finally {
                DB::disableQueryLog();
            }
        }
        fwrite(STDOUT, "\n/{$prefix}/program-activities lookups: ".json_encode($counts)." queries\n");
    }

    public function test_activity_register_and_lookups_reject_wrong_roles_and_respect_revoked_assignments(): void
    {
        $this->activity(201);
        $this->identity = $this->id(2);
        DB::table('associations')->where('id', $this->id(11))->update(['primary_field_officer_id' => $this->id(3)]);
        $before = DB::table('program_activity_records')->get()->toJson();
        $page = $this->get('/field-officer/program-activities')->assertOk();
        $this->assertSame(0, $page->viewData('records')->total());
        $this->assertSame([], $page->viewData('rows')->all());
        $this->getJson('/field-officer/program-activities?lookup=associations')->assertOk()
            ->assertExactJson(['options' => [], 'page' => 1, 'has_more' => false]);
        foreach ([[1, 'field-officer'], [2, 'admin'], [4, 'admin'], [4, 'field-officer']] as [$actor, $prefix]) {
            $this->identity = $this->id($actor);
            foreach (['', '?lookup=associations'] as $query) {
                $this->getJson('/'.$prefix.'/program-activities'.$query)->assertForbidden();
            }
        }
        $this->flushSession();
        $this->get('/field-officer/program-activities')->assertRedirect(route('login'));
        $this->getJson('/field-officer/program-activities?lookup=associations')->assertRedirect(route('login'));
        $this->assertSame($before, DB::table('program_activity_records')->get()->toJson());
    }

    #[DataProvider('managerRoles')]
    public function test_activity_row_actions_keep_parent_lifecycle_routes_and_confirmations(string $prefix, int $actor): void
    {
        $this->identity = $this->id($actor);
        $this->project(101);
        $this->project(102, ['status' => 'completed']);
        $this->project(103, ['status' => 'terminated']);
        $this->activity(201, ['related_project_id' => $this->id(101), 'title' => 'ARCHIVED <Activity>']);
        $this->activity(202, ['status' => 'completed']);
        $this->activity(203, ['related_project_id' => $this->id(102)]);
        $this->activity(204, ['related_project_id' => $this->id(103)]);
        $this->activity(205, ['association_id' => $this->id(13)]);
        $this->activity(206, ['association_id' => $this->id(14)]);
        $this->activity(207, ['archived_at' => '2026-02-01']);
        $this->activity(208, ['association_id' => null]);
        foreach ([false, true] as $archived) {
            $response = $this->get('/'.$prefix.'/program-activities'.($archived ? '?status=archived' : ''))->assertOk()
                ->assertDontSee('@else', false)->assertDontSee('@endif', false);
            $document = new \DOMDocument();
            @$document->loadHTML($response->getContent());
            $xpath = new \DOMXPath($document);
            foreach (range(201, 208) as $id) {
                if (($id === 207) !== $archived) continue;
                $url = route($prefix.'.program-activities.show', ['programActivity' => $this->id($id), 'return_to' => '/'.$prefix.'/program-activities'.($archived ? '?status=archived' : '')]);
                $rows = $xpath->query('//table/tbody/tr[th/a[@href="'.$url.'"]]');
                if ($prefix === 'field-officer' && in_array($id, [205, 206, 208], true)) {
                    $this->assertSame(0, $rows->length);
                    continue;
                }
                $this->assertSame(1, $rows->length);
                $cell = $xpath->query('./td[last()]', $rows->item(0))->item(0);
                $this->assertSame($url, $xpath->query('.//a', $cell)->item(0)->getAttribute('href'));
                $readOnly = in_array($id, [203, 204, 205, 206], true);
                $this->assertSame($readOnly ? 0 : 1, $xpath->query('.//details', $cell)->length);
                $this->assertSame($readOnly, str_contains($cell->textContent, 'Read-only'));
                if ($readOnly) {
                    $this->get('/'.$prefix.'/program-activities/'.$this->id($id).'/edit')->assertForbidden();
                    continue;
                }
                $this->assertSame('Actions for '.($id === 201 ? 'ARCHIVED <Activity>' : 'Activity '.$id), $xpath->query('.//summary', $cell)->item(0)->getAttribute('aria-label'));
                $this->assertSame($archived ? 1 : 2, $xpath->query('.//a', $cell)->length);
                $form = $xpath->query('//dialog[@id="program-activity-action-'.$this->id($id).'"]//form')->item(0);
                $this->assertSame(route($prefix.'.program-activities.'.($archived ? 'unarchive' : 'archive'), $this->id($id)), $form->getAttribute('action'));
                $this->assertSame('PATCH', $xpath->query('.//input[@name="_method"]', $form)->item(0)->getAttribute('value'));
                $this->assertNotEmpty($xpath->query('.//input[@name="_token"]', $form)->item(0)->getAttribute('value'));
            }
        }
        if ($prefix === 'field-officer') {
            $this->activity(209, ['association_id' => $this->id(12)]);
            foreach ([205, 206, 208, 209] as $id) $this->get('/field-officer/program-activities/'.$this->id($id))->assertForbidden();
            $this->getJson('/field-officer/program-activities?q=ARCHIVED')->assertOk();
        }
        $this->identity = $this->id(4);
        $this->get('/'.$prefix.'/program-activities')->assertForbidden();
    }

    #[DataProvider('managerRoles')]
    public function test_activity_create_edit_archive_and_relationship_validation_remain_authoritative(string $prefix, int $actor): void
    {
        $this->identity = $this->id($actor);
        $this->activity(201);
        $this->project(101);
        $this->project(102, ['association_id' => $this->id(12)]);
        $this->project(103, ['status' => 'completed']);
        $payload = $this->activityPayload() + ['related_project_id' => $this->id(101), 'description' => '<script>History</script>', 'remarks' => "Line one\nLine two"];
        $this->post('/'.$prefix.'/program-activities', $payload)->assertRedirect(route($prefix.'.program-activities.index'));
        $record = ProgramActivityRecord::where('title', 'Saved Activity')->sole();
        $this->get('/'.$prefix.'/program-activities/'.$record->id)->assertOk()->assertSee('General Information')
            ->assertSee('Program Information and Relationships')->assertSee('Activity Schedule')->assertSee('Status and Historical Details')
            ->assertSee('&lt;script&gt;History&lt;/script&gt;', false)->assertSee('Line one');
        $this->get('/'.$prefix.'/program-activities/'.$record->id.'/edit')->assertOk()->assertSee('value="'.$record->id.'"', false)
            ->assertSee('value="'.$this->id(101).'" selected', false)->assertSee('name="_token"', false);
        $payload['title'] = 'Edited Activity';
        $this->patch('/'.$prefix.'/program-activities/'.$record->id, $payload)->assertRedirect(route($prefix.'.program-activities.index'));
        foreach (['archive', 'unarchive'] as $action) {
            $this->patch('/'.$prefix.'/program-activities/'.$record->id.'/'.$action)->assertRedirect(route($prefix.'.program-activities.index'));
            $this->assertSame($action === 'archive', $record->fresh()->archived_at !== null);
        }
        $this->assertDatabaseHas('program_activity_records', ['id' => $record->id, 'association_id' => $this->id(11), 'related_project_id' => $this->id(101), 'title' => 'Edited Activity']);
        foreach ([102, 103] as $invalid) {
            $payload['related_project_id'] = $this->id($invalid);
            $this->patchJson('/'.$prefix.'/program-activities/'.$record->id, $payload)->assertUnprocessable()->assertJsonValidationErrors('related_project_id');
        }
        $payload['related_project_id'] = null;
        $payload['saad_activity_id'] = 1;
        $this->postJson('/'.$prefix.'/program-activities', $payload)->assertUnprocessable()->assertJsonValidationErrors('saad_activity_id');
        $payload['saad_activity_id'] = 3;
        $payload['association_id'] = null;
        if ($prefix === 'admin') {
            $this->post('/admin/program-activities', $payload)->assertRedirect();
            $this->assertDatabaseHas('program_activity_records', ['association_id' => null, 'title' => 'Edited Activity']);
        } else {
            $this->postJson('/field-officer/program-activities', $payload)->assertUnprocessable()->assertJsonValidationErrors('association_id');
        }
    }

    #[DataProvider('managerRoles')]
    public function test_activity_editor_and_all_lookup_kinds_are_bounded_and_scope_checked(string $prefix, int $actor): void
    {
        $this->identity = $this->id($actor);
        $this->activity(201);
        foreach (range(40, 69) as $id) {
            DB::table('associations')->insert(['id' => $this->id($id), 'name' => 'Choice '.$id, 'status' => 'active', 'primary_field_officer_id' => $this->id(2)]);
            DB::table('saad_activities')->insert(['id' => $id, 'code' => 'CUSTOM_'.$id, 'name' => 'SAAD Choice '.$id, 'is_active' => true]);
            $this->project($id + 100);
        }
        $this->project(170, ['association_id' => $this->id(12), 'title' => 'Other Project']);
        $this->project(171, ['status' => 'terminated', 'title' => 'Finalized Project']);
        $editor = $this->get('/'.$prefix.'/program-activities?editor=create')->assertOk()->assertSee('id="program-activity-form"', false)->assertSee('Find a SAAD activity');
        $this->assertCount(25, $editor->viewData('associations'));
        $this->assertCount(25, $editor->viewData('activities'));
        $this->assertCount(0, $editor->viewData('projects'));
        foreach (['associations', 'activities', 'projects'] as $kind) {
            $url = '/'.$prefix.'/program-activities?lookup='.$kind.($kind === 'projects' ? '&association_id='.$this->id(11) : '');
            $lookup = $this->getJson($url)->assertOk()->assertJsonCount(25, 'options')->assertJsonPath('has_more', true);
            $this->assertIsString($lookup->json('options.0.value'));
            $this->getJson($url.'&lookup_page=2')->assertOk();
        }
        $this->getJson('/'.$prefix.'/program-activities?lookup=activities&term=Livelihood')->assertOk()->assertJsonCount(0, 'options');
        $this->getJson('/'.$prefix.'/program-activities?lookup=projects&association_id='.$this->id(11).'&term=Finalized')->assertOk()->assertJsonCount(0, 'options');
        if ($prefix === 'field-officer') $this->getJson('/field-officer/program-activities?lookup=projects&association_id='.$this->id(12))->assertOk()->assertJsonCount(0, 'options');
        $url = '/'.$prefix.'/program-activities/'.$this->id(201).'/edit';
        $payload = $this->activityPayload() + ['program_activity_form' => 'editor', 'program_activity_record_id' => $this->id(201), 'related_project_id' => $this->id(169)];
        $payload['title'] = '';
        $this->from($url)->patch('/'.$prefix.'/program-activities/'.$this->id(201), $payload)->assertRedirect($url)->assertSessionHasErrors('title');
        $this->get($url)->assertOk()->assertSee('value="'.$this->id(169).'" selected', false)->assertSee('data-program-activity-errors', false);
        $this->flushSession();
        $this->withSession(['supabase_access_token' => 'synthetic-program-session']);
        $payload['program_activity_record_id'] = '';
        $this->from('/'.$prefix.'/program-activities')->post('/'.$prefix.'/program-activities', $payload)->assertSessionHasErrors('title');
        $this->get('/'.$prefix.'/program-activities')->assertOk()->assertSee('id="program-activity-editor"', false)->assertSee('data-program-activity-errors', false);
    }

    public function test_activity_retained_cross_owner_project_is_visible_without_new_field_officer_access_or_silent_unlinking(): void
    {
        $this->identity = $this->id(2);
        $this->project(101, ['association_id' => $this->id(12), 'title' => 'Retained Project', 'archived_at' => '2026-02-01']);
        $this->activity(201, ['related_project_id' => $this->id(101)]);
        $projectUrl = route('field-officer.projects.show', $this->id(101));
        $this->get('/field-officer/program-activities/'.$this->id(201))->assertOk()->assertSee('Retained Project')->assertDontSee('href="'.$projectUrl.'"', false);
        $this->get('/field-officer/program-activities/'.$this->id(201).'/edit')->assertOk()->assertSee('value="'.$this->id(101).'" selected', false);
        $this->patchJson('/field-officer/program-activities/'.$this->id(201), $this->activityPayload() + ['related_project_id' => $this->id(101)])
            ->assertUnprocessable()->assertJsonValidationErrors('related_project_id');
        $this->assertDatabaseHas('program_activity_records', ['id' => $this->id(201), 'related_project_id' => $this->id(101)]);
    }

    public function test_activity_ownership_change_guard_still_returns_conflict_without_overwriting_history(): void
    {
        $this->activity(201);
        $stale = ProgramActivityRecord::findOrFail($this->id(201));
        DB::table('program_activity_records')->where('id', $stale->id)->update(['association_id' => $this->id(12)]);
        try {
            app(ProgramActivityService::class)->update($stale, $this->activityPayload(), UserProfile::with('role')->findOrFail($this->id(1)));
            $this->fail('A changed owner must not be overwritten.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertSame(409, $exception->status);
        }
        $this->assertDatabaseHas('program_activity_records', ['id' => $stale->id, 'association_id' => $this->id(12), 'title' => 'Activity 201']);
    }

    public function test_activity_rejected_owner_selection_is_retained_without_exposing_foreign_choices_or_changing_history(): void
    {
        $this->identity = $this->id(2);
        $this->project(101);
        $this->project(102, ['association_id' => $this->id(12), 'title' => 'Foreign Private Project']);
        $this->activity(201, ['related_project_id' => $this->id(101)]);
        $url = '/field-officer/program-activities/'.$this->id(201).'/edit';
        $payload = array_replace($this->activityPayload(), ['association_id' => $this->id(12), 'related_project_id' => $this->id(102),
            'program_activity_form' => 'editor', 'program_activity_record_id' => $this->id(201)]);
        $this->from($url)->patch('/field-officer/program-activities/'.$this->id(201), $payload)->assertSessionHasErrors('association_id');
        $this->get($url)->assertOk()->assertSee('Previous selection unavailable')
            ->assertSee('value="'.$this->id(12).'" selected', false)->assertSee('value="'.$this->id(102).'" selected', false)
            ->assertDontSee('Foreign Private Project')->assertDontSee('Association 12');
        $this->assertDatabaseHas('program_activity_records', ['id' => $this->id(201), 'association_id' => $this->id(11), 'related_project_id' => $this->id(101)]);
    }

    public function test_activity_search_treats_sql_wildcards_and_injection_text_as_literal_input(): void
    {
        $this->activity(201, ['title' => '100%_Complete']);
        $this->activity(202, ['title' => 'Other Activity']);
        $this->assertSame(1, $this->get('/admin/program-activities?q='.urlencode('%_'))->assertOk()->viewData('records')->total());
        $this->assertSame(0, $this->get('/admin/program-activities?q='.urlencode("' OR 1=1--"))->assertOk()->viewData('records')->total());
    }

    #[DataProvider('managerRoles')]
    public function test_activity_listing_query_count_does_not_grow_with_rows(string $prefix, int $actor): void
    {
        $this->identity = $this->id($actor);
        $this->project(101);
        $this->activity(201, ['related_project_id' => $this->id(101)]);
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->get('/'.$prefix.'/program-activities')->assertOk();
        $before = count(DB::getQueryLog());
        DB::disableQueryLog();
        foreach (range(202, 230) as $id) $this->activity($id, ['related_project_id' => $this->id(101)]);
        DB::enableQueryLog(); DB::flushQueryLog();
        $response = $this->get('/'.$prefix.'/program-activities')->assertOk();
        $after = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($before, $after);
        $this->assertCount(25, $response->viewData('records'));
        $this->assertSame(30, $response->viewData('records')->total());
        app(SupabaseAuthService::class)->shouldHaveReceived('getUser')->times(2);
        fwrite(STDOUT, "\n/{$prefix}/program-activities: {$before} -> {$after} queries (1 -> 30 activities)\n");
    }

    public function test_project_and_training_queries_stay_bounded_as_rows_and_participants_increase(): void
    {
        $this->project(101);
        $this->training(201, ['related_project_id' => $this->id(101)]);
        $this->member(401); $this->participant(401);
        $before = [];
        foreach (['admin', 'field-officer'] as $prefix) {
            $this->identity = $this->id($prefix === 'admin' ? 1 : 2);
            foreach (['projects', 'trainings'] as $module) {
                DB::enableQueryLog(); DB::flushQueryLog();
                $this->get('/'.$prefix.'/'.$module)->assertOk();
                $before[$prefix][$module] = count(DB::getQueryLog());
            }
        }
        DB::disableQueryLog();
        foreach (range(102, 130) as $id) {
            $this->project($id, ['status' => $id === 130 ? 'completed' : 'ongoing']);
            $this->training($id + 100, ['related_project_id' => $this->id($id), 'status' => $id === 130 ? 'completed' : 'scheduled']);
            $this->member($id + 300);
            $this->participant($id + 300, $id + 100);
        }
        foreach (range(501, 530) as $id) { $this->member($id); $this->participant($id); }
        foreach (['admin', 'field-officer'] as $prefix) {
            $this->identity = $this->id($prefix === 'admin' ? 1 : 2);
            foreach (['projects', 'trainings'] as $module) {
                DB::enableQueryLog(); DB::flushQueryLog();
                $response = $this->get('/'.$prefix.'/'.$module)->assertOk();
                $count = count(DB::getQueryLog());
                $this->assertSame($before[$prefix][$module], $count, $prefix.' '.$module.' added per-row queries');
                $this->assertCount(25, $response->viewData($module));
                $this->assertSame(30, $response->viewData($module)->total());
                if ($module === 'trainings') {
                    foreach ($response->viewData($module) as $training) $this->assertFalse($training->relationLoaded('participants'));
                }
                fwrite(STDOUT, "\nBounded /{$prefix}/{$module}: {$before[$prefix][$module]} -> {$count} queries (1 -> 30 records)\n");
            }
        }
        DB::disableQueryLog();
        app(SupabaseAuthService::class)->shouldHaveReceived('getUser')->times(8);
    }
}
