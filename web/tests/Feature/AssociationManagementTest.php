<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Association;
use App\Models\AssociationRegistration;
use App\Models\Member;
use App\Models\UserProfile;
use App\Services\Areas\AreaManagementQueryService;
use App\Services\Associations\AssociationDirectoryQueryService;
use App\Services\Associations\AssociationGeographyService;
use App\Services\Associations\AssociationService;
use App\Services\Associations\FieldOfficerAssignmentService;
use App\Services\Auth\SupabaseAuthService;
use App\Services\Reporting\ReportService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AssociationManagementTest extends TestCase
{
    use \Tests\Support\AssertsWorkflowNavigation;
    protected string $identity;

    protected static function id(int $number): string
    {
        return sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d', $number);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['associations.geography_schema_ready' => false, 'associations.direct_geography' => false]);
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        Http::swap((new Factory)->preventStrayRequests());
        $this->withoutVite();
        $this->schema();
        $this->fixture();
        $this->identity = self::id(1);
        $auth = Mockery::mock(SupabaseAuthService::class);
        $auth->shouldReceive('getUser')->andReturnUsing(fn () => ['id' => $this->identity, 'email' => 'synthetic@example.test']);
        $this->app->instance(SupabaseAuthService::class, $auth);
        $this->withSession(['supabase_access_token' => 'synthetic-test-token']);
    }

    protected function tearDown(): void
    {
        Member::flushEventListeners();
        try {
            Http::assertNothingSent();
            parent::tearDown();
        } finally {
            HandleExceptions::flushState($this);
        }
    }

    public function test_association_contextual_actions_use_distribution_only_without_reporting_queries_on_get(): void
    {
        $reporting = Mockery::mock(ReportService::class);
        $reporting->shouldNotReceive('generate');
        $reporting->shouldNotReceive('options');
        $this->app->instance(ReportService::class, $reporting);
        foreach (['admin' => 1, 'field-officer' => 2] as $prefix => $identity) {
            $this->identity = self::id($identity);
            DB::enableQueryLog();
            DB::flushQueryLog();
            $page = $this->get('/'.$prefix.'/associations')->assertOk();
            $this->assertCount($prefix === 'admin' ? 9 : 7, DB::getQueryLog(), 'Normal association GET retains its pre-change query count.');
            DB::disableQueryLog();
            $page->assertSee('Open related report')->assertDontSee('data-contextual-report-export', false);
            $this->get('/'.$prefix.'/associations?q=Alpha&status=active&municipality=Town&barangay=Test&sort=za')
                ->assertOk()->assertSee('report_type=associations', false)
                ->assertSee('Search, status, municipality, barangay, officer, sorting and pagination selections from this register are not applied.');
            $detail = $this->get('/'.$prefix.'/associations/'.self::id(10))->assertOk();
            $detail->assertSee('Export Association Distribution Report')
                ->assertSee('name="association_id" value="'.self::id(10).'"', false)
                ->assertSee('Current snapshot; no reporting period.');
            foreach (['pdf', 'xlsx'] as $format) {
                $detail->assertSee(route($prefix.'.reports.export', ['format' => $format]));
            }
            $this->get('/'.$prefix.'/association-officers')->assertOk()->assertDontSee('data-contextual-report', false);
        }
    }

    public function test_connected_association_tabs_keep_context_and_member_links_use_valid_ids(): void
    {
        foreach (['admin' => 1, 'field-officer' => 2] as $prefix => $actor) {
            $this->identity = self::id($actor);
            $origin = '/'.$prefix.'/associations?q=Alpha&status=active&sort=za&page=1';
            $parameters = ['q' => 'Alpha', 'status' => 'active', 'sort' => 'za', 'page' => 1, 'section' => 'members', 'return_to' => $origin];
            $page = $this->get('/'.$prefix.'/associations/'.self::id(10).'?'.http_build_query($parameters))->assertOk();
            $dom = new \DOMDocument;
            @$dom->loadHTML($page->getContent());
            $xpath = new \DOMXPath($dom);
            $memberLink = $xpath->query('//a[contains(@href, "/members?") and contains(., "Open member register")]')->item(0);
            $this->assertNotNull($memberLink);
            parse_str(parse_url($memberLink->getAttribute('href'), PHP_URL_QUERY), $query);
            $this->assertSame(self::id(10), $query['association']);
            $this->assertStringContainsString('section=members', $query['return_to']);
            $this->assertSame('related-association-records', parse_url($query['return_to'], PHP_URL_FRAGMENT));
            $page->assertSee('href="'.e(url($origin)).'" data-workflow-back', false);
            $officerLink = $xpath->query('//a[contains(@href, "/associations/'.self::id(10).'/officers") and contains(., "officers")]')->item(0);
            $officers = $this->get($officerLink->getAttribute('href'))->assertOk();
            $officers->assertSee('Back to Alpha', false)->assertSee('data-workflow-back', false);
            foreach (['associations', 'association-officers'] as $register) {
                $origin = '/'.$prefix.'/'.$register.'?q=Alpha&sort=za&page=1';
                $officers = $this->get('/'.$prefix.'/associations/'.self::id(10).'/officers?'.http_build_query(['member' => self::id(51), 'return_to' => $origin]))->assertOk();
                $this->assertWorkflowBack($officers, $origin);
                $officers->assertSee('Save officer position')->assertSee('name="_token"', false);
            }
        }
    }

    private function schema(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('code');
            $t->string('name');
            $t->boolean('is_active');
            $t->timestamps();
            $t->text('description')->nullable();
        });
        Schema::create('user_profiles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->integer('role_id');
            $t->string('display_name');
            $t->boolean('is_active');
            $t->timestamp('activated_at')->nullable();
            $t->timestamps();
        });
        Schema::create('user_invitations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('auth_user_id')->nullable();
            $t->string('email');
            $t->string('status');
            $t->string('last_error_code')->nullable();
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('last_attempt_at')->nullable();
            $t->timestamps();
        });
        Schema::create('municipalities', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('province');
            $t->string('region');
            $t->boolean('is_active');
        });
        Schema::create('barangays', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('municipality_id');
            $t->string('name');
            $t->boolean('is_active');
            $t->foreign('municipality_id')->references('id')->on('municipalities')->restrictOnDelete();
        });
        Schema::create('areas', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('barangay_id');
            $t->text('address');
            $t->boolean('is_active');
            $t->foreign('barangay_id')->references('id')->on('barangays')->restrictOnDelete();
        });
        Schema::create('associations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('acronym')->nullable();
            $t->uuid('area_id');
            $t->uuid('primary_field_officer_id');
            $t->date('saad_phase2_join_date');
            $t->string('status');
            $t->uuid('created_by')->nullable();
            $t->uuid('updated_by')->nullable();
            $t->uuid('archived_by')->nullable();
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
            $t->foreign('area_id')->references('id')->on('areas')->restrictOnDelete();
        });
        Schema::create('association_positions', function (Blueprint $t) {
            $t->id();
            $t->string('code');
            $t->string('name');
            $t->boolean('is_officer');
            $t->boolean('is_active');
            $t->integer('sort_order');
        });
        Schema::create('association_accounts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->unique();
            $t->uuid('association_id')->unique();
            $t->boolean('is_active');
        });
        Schema::create('member_applications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('association_id');
            $t->string('status');
            $t->timestamps();
        });
        Schema::create('members', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('association_id');
            $t->uuid('source_application_id')->nullable();
            $t->string('first_name');
            $t->string('middle_name')->nullable();
            $t->string('last_name');
            $t->string('suffix')->nullable();
            $t->integer('position_id');
            $t->string('position_other_text')->nullable();
            $t->string('member_status');
            $t->string('contact_number')->nullable();
            $t->date('date_registered')->nullable();
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
            $t->foreign('association_id')->references('id')->on('associations')->restrictOnDelete();
        });
        DB::statement("CREATE UNIQUE INDEX test_active_executives ON members (association_id, position_id) WHERE member_status = 'active' AND archived_at IS NULL AND position_id IN (2, 3)");
        foreach (['projects', 'trainings', 'program_activity_records'] as $table) {
            Schema::create($table, function (Blueprint $t) use ($table) {
                $t->uuid('id')->primary();
                $t->uuid('association_id')->nullable();
                $t->string('title');
                $t->string('status');
                if ($table === 'trainings') {
                    $t->date('start_date')->nullable();
                }
                if ($table === 'program_activity_records') {
                    $t->date('activity_date')->nullable();
                }
                $t->timestamp('archived_at')->nullable();
                $t->timestamps();
            });
        }
        Schema::create('registration_agencies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('registration_kind');
            $t->boolean('is_active');
        });
        Schema::create('association_registrations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('association_id');
            $t->integer('agency_id');
            $t->string('registration_number');
            $t->date('registration_date');
            $t->date('expiration_date')->nullable();
            $t->boolean('is_primary');
            $t->text('remarks')->nullable();
            $t->timestamps();
            $t->unique(['agency_id', 'registration_number']);
            $t->foreign('association_id')->references('id')->on('associations');
            $t->foreign('agency_id')->references('id')->on('registration_agencies');
        });
        DB::statement('CREATE UNIQUE INDEX uq_one_primary_registration_per_association ON association_registrations(association_id) WHERE is_primary = 1');
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('actor_user_id')->nullable();
            $t->string('action_type');
            $t->string('module');
            $t->string('entity_type')->nullable();
            $t->string('entity_id')->nullable();
            $t->text('old_values')->nullable();
            $t->text('new_values')->nullable();
            $t->timestamp('performed_at');
        });
    }

    private function fixture(): void
    {
        foreach ([1 => 'system_admin', 2 => 'field_officer', 3 => 'association_account'] as $role => $code) {
            DB::table('roles')->insert(['id' => $role, 'code' => $code, 'name' => $code, 'is_active' => true]);
        }
        foreach ([1 => [1, 'Synthetic Admin'], 2 => [2, 'Assigned Officer'], 3 => [2, 'Other Officer'], 4 => [3, 'Association Account']] as $id => [$role, $name]) {
            DB::table('user_profiles')->insert(['id' => self::id($id), 'role_id' => $role, 'display_name' => $name,
                'is_active' => true, 'activated_at' => now()->subDay(), 'created_at' => now()->subDays(2), 'updated_at' => now()]);
        }
        foreach ([20 => ['Alpha Municipality', true], 21 => ['Other City', false]] as $id => [$name, $active]) {
            DB::table('municipalities')->insert(['id' => self::id($id), 'name' => $name, 'province' => 'Stored Province', 'region' => 'Region VII', 'is_active' => $active]);
        }
        foreach ([30 => [20, 'Alpha Barangay', true], 31 => [20, 'Historical Barangay', false], 32 => [21, 'Other Barangay', true]] as $id => [$parent, $name, $active]) {
            DB::table('barangays')->insert(['id' => self::id($id), 'municipality_id' => self::id($parent), 'name' => $name, 'is_active' => $active]);
        }
        foreach ([40 => [30, 'Purok One', true], 41 => [31, 'Sitio Historical', false], 42 => [32, 'Other Address', true]] as $id => [$parent, $address, $active]) {
            DB::table('areas')->insert(['id' => self::id($id), 'barangay_id' => self::id($parent), 'address' => $address, 'is_active' => $active]);
        }
        foreach ([10 => ['Alpha Association', 'active', null, 40, 2], 11 => ['Inactive Association', 'inactive', null, 40, 2],
            12 => ['Archived Association', 'active', '2025-01-01', 41, 2], 13 => ['Other Association', 'active', null, 42, 3]] as $id => [$name, $status, $archived, $area, $officer]) {
            DB::table('associations')->insert(['id' => self::id($id), 'name' => $name, 'acronym' => 'SYN-'.$id,
                'area_id' => self::id($area), 'primary_field_officer_id' => self::id($officer), 'status' => $status,
                'archived_at' => $archived, 'saad_phase2_join_date' => '2025-01-'.($id - 9).' 00:00:00',
                'created_at' => '2025-01-'.($id - 9).' 00:00:00', 'updated_at' => now()]);
        }
        foreach ([1 => 'MEMBER', 2 => 'PRESIDENT', 3 => 'VICE_PRESIDENT', 4 => 'SECRETARY'] as $id => $code) {
            DB::table('association_positions')->insert(['id' => $id, 'code' => $code, 'name' => $code, 'is_active' => true, 'is_officer' => $id !== 1, 'sort_order' => $id]);
        }
        foreach ([50 => [10, 'Actual', 'President', 2, 'active', null], 51 => [10, 'Eligible', 'Secretary', 4, 'active', null],
            52 => [10, 'Former', 'President', 2, 'active', '2020-01-01'], 53 => [10, 'Inactive', 'President', 2, 'inactive', null],
            54 => [13, 'Unrelated', 'Person', 2, 'active', null]] as $id => [$association, $first, $last, $position, $status, $archived]) {
            DB::table('members')->insert(['id' => self::id($id), 'association_id' => self::id($association), 'first_name' => $first,
                'last_name' => $last, 'position_id' => $position, 'member_status' => $status, 'archived_at' => $archived,
                'contact_number' => 'PRIVATE-CONTACT-NOT-FOR-DIRECTORY', 'created_at' => now(), 'updated_at' => '2025-01-01 00:00:00']);
        }
        foreach (['projects' => [80, 'Project', 'ongoing'], 'trainings' => [60, 'Training', 'scheduled'], 'program_activity_records' => [70, 'Activity', 'planned']] as $table => [$id, $label, $status]) {
            foreach ([0 => 10, 1 => 13] as $offset => $association) {
                $row = ['id' => self::id($id + $offset), 'association_id' => self::id($association),
                    'title' => ($offset ? 'Unrelated ' : 'Linked ').$label, 'status' => $status];
                if ($table === 'trainings') {
                    $row['start_date'] = '2025-02-01';
                }
                if ($table === 'program_activity_records') {
                    $row['activity_date'] = '2025-02-01';
                }
                DB::table($table)->insert($row);
            }
        }
        DB::table('program_activity_records')->insert(['id' => self::id(72), 'title' => 'Program-wide Activity', 'status' => 'planned', 'association_id' => null]);
        DB::table('registration_agencies')->insert(['id' => 1, 'name' => 'Synthetic Agency', 'registration_kind' => 'registration', 'is_active' => true]);
        DB::table('association_registrations')->insert(['id' => self::id(90), 'association_id' => self::id(10), 'agency_id' => 1,
            'registration_number' => 'SYNTHETIC-REG-001', 'registration_date' => '2024-01-01', 'is_primary' => true]);
    }

    private function dom(string $html): \DOMXPath
    {
        $doc = new \DOMDocument;
        $old = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($old);

        return new \DOMXPath($doc);
    }

    private function filters(array $overrides = []): array
    {
        return array_replace(['q' => '', 'status' => '', 'municipality' => '', 'barangay' => '', 'field_officer' => '', 'sort' => 'az'], $overrides);
    }

    private function url(string $prefix = 'admin', array $filters = []): string
    {
        return '/'.$prefix.'/associations?'.http_build_query($filters);
    }

    public function test_admin_cards_preserve_lifecycle_meanings_and_follow_real_server_filters(): void
    {
        $response = $this->get('/admin/associations')->assertOk();
        $this->assertSame(['total' => 4, 'active' => 2, 'inactive' => 1, 'archived' => 1], $response->viewData('summary'));
        $this->assertSame(3, $response->viewData('associations')->total());
        $dom = $this->dom($response->getContent());
        foreach (['total' => 4, 'active' => 2, 'inactive' => 1, 'archived' => 1] as $card => $total) {
            $href = $dom->query('//a[@data-association-summary="'.$card.'"]')->item(0)->getAttribute('href');
            $filtered = $this->get($href)->assertOk();
            $this->assertSame($total, $filtered->viewData('associations')->total());
            $selected = $this->dom($filtered->getContent())->query('//a[@data-association-summary and @aria-current="page"]');
            $this->assertSame(1, $selected->length);
            $this->assertSame($card, $selected->item(0)->getAttribute('data-association-summary'));
        }
    }

    public function test_search_filters_and_allowlisted_sorting_preserve_existing_geographic_links(): void
    {
        foreach ([
            [['q' => '  ALPHA  '], [10, 11]], [['q' => 'Purok One'], [10, 11]],
            [['q' => 'Assigned Officer'], [10, 11]], [['municipality' => 'Other City'], [13]],
            [['barangay' => 'Historical Barangay', 'status' => 'archived'], [12]],
            [['field_officer' => 'Other Officer'], [13]], [['sort' => 'za'], [13, 11, 10]],
            [['sort' => 'newest'], [13, 11, 10]], [['sort' => 'oldest'], [10, 11, 13]],
            [['q' => "' OR 1=1 --"], []],
        ] as [$filters, $ids]) {
            $response = $this->get($this->url('admin', $filters))->assertOk();
            $this->assertSame(array_map(self::id(...), $ids), $response->viewData('associations')->pluck('id')->all());
        }
        DB::table('associations')->where('id', self::id(10))->update(['name' => '50%_! <script>bad()</script>']);
        foreach (['%', '_', '!'] as $q) {
            $response = $this->get($this->url('admin', ['q' => $q]))->assertOk()->assertDontSee('<script>bad()</script>', false);
            $this->assertSame([self::id(10)], $response->viewData('associations')->pluck('id')->all());
        }
    }

    public function test_filter_panel_defaults_closed_and_active_advanced_filters_remain_visible(): void
    {
        $response = $this->get('/admin/associations')->assertOk();
        $dom = $this->dom($response->getContent());
        $this->assertTrue($dom->query('//*[@id="association-filter-panel"]')->item(0)->hasAttribute('hidden'));
        $this->assertSame(1, $dom->query('//input[@id="association-search" and @type="search"]')->length);
        $this->assertSame(1, $dom->query('//label[@for="association-sort"]')->length);
        $active = $this->get('/admin/associations?status=inactive&sort=za')->assertOk();
        $panel = $this->dom($active->getContent());
        $this->assertFalse($panel->query('//*[@id="association-filter-panel"]')->item(0)->hasAttribute('hidden'));
        $this->assertSame('true', $panel->query('//button[@aria-controls="association-filter-panel"]')->item(0)->getAttribute('aria-expanded'));
        $active->assertSee('2 active');
    }

    public function test_admin_records_show_the_assigned_field_officer_above_the_current_president(): void
    {
        $response = $this->get('/admin/associations')->assertOk()->assertSee('Assigned Officers');
        $dom = $this->dom($response->getContent());
        $rows = $dom->query('//table[contains(@class, "association-table")]/tbody/tr');
        foreach ([['Assigned Officer', 'Actual President'], ['Assigned Officer', 'No active President assigned'],
            ['Other Officer', 'Unrelated Person']] as $index => [$officer, $president]) {
            $row = $rows->item($index);
            $this->assertSame(6, $dom->query('./th | ./td', $row)->length);
            $this->assertSame(['Field Officer', 'President'], array_map(fn ($node) => trim($node->textContent),
                iterator_to_array($dom->query('./td[1]/dl/div/dt', $row))));
            $this->assertSame([$officer, $president], array_map(fn ($node) => trim($node->textContent),
                iterator_to_array($dom->query('./td[1]/dl/div/dd', $row))));
        }
        $response->assertDontSee('Former President')->assertDontSee('Inactive President')->assertDontSee('PRIVATE-CONTACT-NOT-FOR-DIRECTORY');
        DB::table('user_profiles')->where('id', self::id(2))->update(['is_active' => false]);
        $this->get('/admin/associations?status=archived')->assertOk()->assertSee('Assigned Officer')->assertSee('No active President assigned');
    }

    public function test_missing_field_officer_metadata_has_an_accurate_fallback_without_substituting_another_person(): void
    {
        $response = $this->get('/admin/associations?q=Alpha')->assertOk();
        $association = $response->viewData('associations')->first();
        $association->setRelation('primaryFieldOfficer', null);
        $dom = $this->dom(view('associations.partials.directory', $response->viewData())->render());
        $names = $dom->query('//dl[@class="association-assigned-officers"]/div/dd');
        $this->assertSame('No Field Officer assigned', trim($names->item(0)->textContent));
        $this->assertSame('Actual President', trim($names->item(1)->textContent));
    }

    public function test_other_directories_keep_the_president_column_without_loading_field_officer_names(): void
    {
        foreach ([['field-officer', 2, 'associations'], ['admin', 1, 'association-officers'],
            ['field-officer', 2, 'association-officers']] as [$prefix, $actor, $directory]) {
            $this->identity = self::id($actor);
            $response = $this->get('/'.$prefix.'/'.$directory)->assertOk()->assertDontSee('Assigned Officers')
                ->assertDontSee('association-assigned-officers', false);
            $this->assertSame(1, $this->dom($response->getContent())->query('//thead/tr/th[text()="President"]')->length);
            foreach ($response->viewData('associations') as $association) {
                $this->assertFalse($association->relationLoaded('primaryFieldOfficer'));
            }
        }
    }

    public function test_required_area_validation_and_existing_populated_addresses_remain_intact(): void
    {
        $areas = DB::table('areas')->orderBy('id')->get()->toJson();
        foreach ([[], ['area_id' => null], ['area_id' => '']] as $location) {
            $data = $this->associationData();
            unset($data['area_id']);
            $data = array_replace($data, $location);
            $this->postJson('/admin/associations', $data)->assertUnprocessable()->assertJsonValidationErrors('area_id');
            $this->patchJson('/admin/associations/'.self::id(10), $data + ['expected_primary_field_officer_id' => self::id(2)])->assertUnprocessable()->assertJsonValidationErrors('area_id');
        }
        $this->assertSame(4, DB::table('associations')->count());
        $this->patch('/admin/associations/'.self::id(10), $this->associationData(['name' => 'Alpha Association', 'acronym' => 'UPDATED'], 10))
            ->assertRedirect(route('admin.associations.index'));
        $association = Association::with('area.barangay.municipality')->findOrFail(self::id(10));
        $this->assertSame('UPDATED', $association->acronym);
        $this->assertSame(self::id(40), $association->area_id);
        $this->assertSame('Purok One', $association->area->address);
        $this->assertSame(self::id(30), $association->area->barangay_id);
        $this->assertSame(self::id(20), $association->area->barangay->municipality_id);
        $this->assertSame($areas, DB::table('areas')->orderBy('id')->get()->toJson());
    }

    public function test_field_officer_listing_and_every_filter_stay_within_current_assignments(): void
    {
        $this->identity = self::id(2);
        foreach ([[], ['status' => 'all'], ['q' => 'Other'], ['municipality' => 'Other City'], ['field_officer' => 'Other Officer'], ['sort' => 'municipality']] as $filters) {
            $response = $this->get($this->url('field-officer', $filters))->assertOk()->assertDontSee('Other Association')
                ->assertDontSee('PRIVATE-CONTACT-NOT-FOR-DIRECTORY');
            $this->assertNull($response->viewData('summary'));
            $this->assertSame(['Alpha Municipality'], $response->viewData('choices')['municipality']);
            foreach ($response->viewData('associations') as $association) {
                $this->assertSame(self::id(2), $association->primary_field_officer_id);
            }
        }
        $this->get('/field-officer/associations?editor=create')->assertForbidden();
        $this->get('/field-officer/associations/'.self::id(13))->assertForbidden();
        DB::table('associations')->where('id', self::id(10))->update(['primary_field_officer_id' => self::id(3)]);
        $this->get('/field-officer/associations/'.self::id(10))->assertForbidden();
    }

    public function test_details_use_the_recorded_president_and_real_location_without_private_member_fields(): void
    {
        $response = $this->get('/admin/associations/'.self::id(10))->assertOk()->assertSee('Actual President')
            ->assertSee('Purok One')->assertSee('Alpha Municipality')->assertDontSee('Unrelated Person')
            ->assertDontSee('PRIVATE-CONTACT-NOT-FOR-DIRECTORY');
        $this->assertSame(self::id(50), $response->viewData('association')->currentPresident->id);
        $this->assertSame(4, $response->viewData('related')->total());
        $dom = $this->dom($response->getContent());
        $this->assertSame(1, $dom->query('//a[@href="'.route('admin.municipalities.show', self::id(20)).'"]')->length);
        $this->get('/admin/associations/'.self::id(11))->assertOk()->assertSee('No active President assigned');
        $this->identity = self::id(2);
        $this->get('/field-officer/associations/'.self::id(10))->assertOk()->assertSee('Actual President')->assertSee('Alpha Municipality')
            ->assertDontSee('/admin/municipalities/', false)->assertDontSee('/admin/barangays/', false);
    }

    public function test_related_sections_use_exact_foreign_keys_and_role_correct_destinations(): void
    {
        foreach (['admin' => 1, 'field-officer' => 2] as $prefix => $actor) {
            $this->identity = self::id($actor);
            foreach (['projects' => ['Linked Project', 80, 'projects'], 'trainings' => ['Linked Training', 60, 'trainings'],
                'activities' => ['Linked Activity', 70, 'program-activities'], 'registrations' => ['SYNTHETIC-REG-001', 90, null]] as $section => [$text, $id, $route]) {
                $response = $this->get('/'.$prefix.'/associations/'.self::id(10).'?section='.$section)->assertOk()->assertSee($text)
                    ->assertDontSee('Unrelated Project')->assertDontSee('Unrelated Training')->assertDontSee('Unrelated Activity')
                    ->assertDontSee('Program-wide Activity');
                $this->assertSame(1, $response->viewData('related')->total());
                $this->assertSame(self::id(10), $response->viewData('related')->first()->association_id);
                if ($route) {
                    $response->assertSee(route($prefix.'.'.$route.'.show', self::id($id)), false);
                }
            }
        }
    }

    public function test_archived_history_requires_more_than_current_officer_assignment(): void
    {
        $this->get('/admin/associations/'.self::id(12))->assertOk()->assertSee('Sitio Historical')->assertSee('Archived');
        $this->identity = self::id(2);
        foreach ([11] as $id) {
            $this->get('/field-officer/associations/'.self::id($id))->assertOk();
            $this->get('/field-officer/associations/'.self::id($id).'?section=projects')->assertOk();
            foreach (['trainings', 'activities'] as $section) {
                $this->get('/field-officer/associations/'.self::id($id).'?section='.$section)->assertForbidden();
            }
        }
        foreach (['members', 'projects', 'trainings', 'activities', 'registrations'] as $section) {
            $this->get('/field-officer/associations/'.self::id(12).'?section='.$section)->assertForbidden();
        }
        $this->get('/field-officer/associations/'.self::id(12).'/officers')->assertForbidden();
        $listing = $this->get('/field-officer/associations?status=all')->assertOk();
        $this->assertNotContains(self::id(12), $listing->viewData('associations')->pluck('id')->all());
        $this->identity = self::id(1);
        $this->get('/admin/associations/'.self::id(12).'/officers')->assertOk();
    }

    public function test_register_and_related_lists_are_bounded_and_preserve_search_and_back_context(): void
    {
        for ($id = 100; $id < 127; $id++) {
            DB::table('associations')->insert(['id' => self::id($id), 'name' => 'Extra '.$id, 'area_id' => self::id(40),
                'status' => 'active', 'primary_field_officer_id' => self::id(2), 'saad_phase2_join_date' => '2024-01-01']);
            DB::table('members')->insert(['id' => self::id($id + 100), 'association_id' => self::id(10), 'first_name' => 'Extra',
                'last_name' => 'Member '.$id, 'member_status' => 'active', 'position_id' => 1]);
        }
        $response = $this->get('/admin/associations?q=Extra&status=active&sort=za')->assertOk();
        $this->assertCount(25, $response->viewData('associations'));
        $next = $this->get($response->viewData('associations')->nextPageUrl())->assertOk();
        $this->assertCount(2, $next->viewData('associations'));
        $this->assertSame($response->viewData('filters'), $next->viewData('filters'));
        $details = $this->get('/admin/associations/'.self::id(10).'?q=Extra&status=active&page=2')->assertOk();
        $this->assertCount(25, $details->viewData('related'));
        $nextRelated = $this->get($details->viewData('related')->nextPageUrl())->assertOk();
        $this->assertCount(6, $nextRelated->viewData('related'));
        $this->assertSame($details->viewData('backUrl'), $nextRelated->viewData('backUrl'));
    }

    public function test_editor_loads_on_demand_preserves_existing_forms_and_restores_validation(): void
    {
        $this->get('/admin/associations')->assertOk()->assertDontSee('id="association-editor"', false);
        $create = $this->get('/admin/associations?editor=create')->assertOk()->assertSee('data-association-auto-open="true"', false)
            ->assertSee('name="_token"', false)->assertSee('registration[agency_id]', false);
        $this->assertSame(route('admin.associations.store'), $this->dom($create->getContent())->query('//form[@data-association-form]')->item(0)->getAttribute('action'));
        $edit = $this->get('/admin/associations?editor='.self::id(10))->assertOk()->assertSee('value="Alpha Association"', false)
            ->assertSee('action="'.route('admin.associations.update', self::id(10)).'"', false);
        $this->get('/admin/associations?editor='.self::id(12))->assertForbidden();
        $this->get('/admin/associations/'.self::id(10).'?editor='.self::id(11))->assertNotFound();
        $this->from('/admin/associations')->post('/admin/associations', ['association_form' => 'editor', 'name' => 'Unsaved association'])
            ->assertRedirect('/admin/associations')->assertSessionHasErrors();
        $this->get('/admin/associations')->assertOk()->assertSee('Unsaved association')->assertSee('data-has-errors="true"', false);
    }

    #[DataProvider('relatedSections')]
    public function test_each_related_record_group_remains_paginated_for_both_roles(string $section, string $table): void
    {
        for ($id = 100; $id < 127; $id++) {
            $row = ['id' => self::id($id), 'association_id' => self::id(10)];
            if ($section === 'registrations') {
                $row += ['agency_id' => 1, 'registration_number' => 'EXTRA-'.$id, 'registration_date' => '2024-01-01', 'is_primary' => false];
            } else {
                $row += ['title' => 'Extra '.$id, 'status' => $section === 'projects' ? 'ongoing' : ($section === 'trainings' ? 'scheduled' : 'planned')];
            }
            DB::table($table)->insert($row);
        }
        foreach (['admin' => 1, 'field-officer' => 2] as $prefix => $actor) {
            $this->identity = self::id($actor);
            $response = $this->get('/'.$prefix.'/associations/'.self::id(10).'?section='.$section)->assertOk();
            $this->assertSame(28, $response->viewData('related')->total());
            $this->assertCount(25, $response->viewData('related'));
            $next = $this->get($response->viewData('related')->nextPageUrl())->assertOk();
            $this->assertCount(3, $next->viewData('related'));
            $this->assertSame($section, $next->viewData('section'));
        }
    }

    public static function relatedSections(): array
    {
        return [['projects', 'projects'], ['trainings', 'trainings'], ['activities', 'program_activity_records'], ['registrations', 'association_registrations']];
    }

    private function associationData(array $overrides = [], ?int $editing = null): array
    {
        return array_replace(['name' => 'Created association', 'area_id' => self::id(40), 'primary_field_officer_id' => self::id(2),
            'saad_phase2_join_date' => '2025-02-01', 'status' => 'active'], $editing === null ? [] : [
                'expected_primary_field_officer_id' => Association::findOrFail(self::id($editing))->primary_field_officer_id,
            ], $overrides);
    }

    public function test_existing_admin_crud_and_reactivation_keep_status_and_relationships(): void
    {
        $this->post('/admin/associations', $this->associationData())->assertRedirect(route('admin.associations.index'));
        $this->assertSame(5, DB::table('associations')->count());
        $before = DB::table('members')->orderBy('id')->get()->toJson();
        $this->patch('/admin/associations/'.self::id(11), $this->associationData(['name' => 'Reactivated association'], 11))->assertRedirect(route('admin.associations.index'));
        $this->assertSame('active', Association::findOrFail(self::id(11))->status);
        $this->patch('/admin/associations/'.self::id(10).'/archive')->assertRedirect(route('admin.associations.index'));
        $this->get('/admin/associations/'.self::id(10))->assertOk();
        $this->patch('/admin/associations/'.self::id(10), $this->associationData([], 10))->assertSessionHasErrors('association');
        $this->patch('/admin/associations/'.self::id(10).'/unarchive')->assertRedirect(route('admin.associations.index'));
        $this->assertNull(Association::findOrFail(self::id(10))->archived_at);
        $this->assertSame($before, DB::table('members')->orderBy('id')->get()->toJson());
    }

    public function test_officer_directories_keep_their_entry_points_search_sort_and_role_scopes(): void
    {
        foreach (['admin' => 1, 'field-officer' => 2] as $prefix => $actor) {
            $this->identity = self::id($actor);
            $response = $this->get('/'.$prefix.'/association-officers?q=Association&sort=za')->assertOk();
            $this->assertTrue($response->viewData('officerDirectory'));
            $this->assertSame($prefix === 'admin' ? 4 : 2, $response->viewData('associations')->total());
            if ($prefix !== 'admin') {
                $response->assertDontSee('Other Association');
            }
            $this->get('/'.$prefix.'/associations/'.self::id(10).'/officers')->assertOk()->assertSee('Association Officers')->assertSee('Actual')
                ->assertSee('Current active officers (2)');
        }
        $this->get('/field-officer/associations/'.self::id(13).'/officers')->assertForbidden();
    }

    #[DataProvider('invalidFilters')]
    public function test_malformed_parameters_are_rejected_on_all_new_read_routes(array $filters): void
    {
        foreach (['admin' => 1, 'field-officer' => 2] as $prefix => $actor) {
            $this->identity = self::id($actor);
            foreach (['/'.$prefix.'/associations', '/'.$prefix.'/associations/'.self::id(10), '/'.$prefix.'/association-officers'] as $url) {
                $this->getJson($url.'?'.http_build_query($filters))->assertUnprocessable();
            }
        }
    }

    public static function invalidFilters(): array
    {
        return [[['q' => ['bad']]], [['q' => str_repeat('x', 121)]], [['status' => 'terminated']], [['status' => ['active']]],
            [['sort' => 'name; DROP TABLE members']], [['municipality' => ['bad']]], [['page' => 0]], [['related_page' => -1]],
            [['section' => 'credentials']], [['origin' => 'admin-dashboard']]];
    }

    public function test_guests_wrong_roles_revoked_profiles_and_missing_records_are_safe(): void
    {
        $this->withSession(['supabase_access_token' => null])->get('/admin/associations')->assertRedirect(route('login'));
        $this->identity = self::id(4);
        $this->withSession(['supabase_access_token' => 'synthetic-test-token']);
        foreach (['admin', 'field-officer'] as $prefix) {
            $this->get('/'.$prefix.'/associations')->assertForbidden();
            $this->get('/'.$prefix.'/associations/'.self::id(10))->assertForbidden();
        }
        $this->identity = self::id(2);
        $this->get('/admin/associations')->assertForbidden();
        $this->post('/admin/associations', $this->associationData())->assertForbidden();
        $this->patch('/admin/associations/'.self::id(10).'/archive')->assertForbidden();
        $this->get('/field-officer/associations/not-a-uuid')->assertNotFound();
        $this->get('/field-officer/associations/'.self::id(999))->assertNotFound();
        DB::table('user_profiles')->where('id', $this->identity)->update(['is_active' => false]);
        $this->get('/field-officer/associations')->assertRedirect(route('login'));
    }

    public function test_csrf_and_native_actions_do_not_grant_field_officer_mutations(): void
    {
        $this->prepareStateThreeGeography();
        $before = $this->associationWriteSnapshot();
        $response = $this->get('/admin/associations')->assertOk();
        $this->assertSame(3, $this->dom($response->getContent())->query('//details[@data-user-actions]/summary[@aria-label]')->length);
        $this->app->bind(ValidateCsrfToken::class, function ($app) {
            return new class($app, $app['encrypter']) extends ValidateCsrfToken
            {
                protected function runningUnitTests()
                {
                    return false;
                }
            };
        });
        $this->post('/admin/associations', $this->associationData())->assertStatus(419);
        $this->patch('/admin/associations/'.self::id(10).'/archive')->assertStatus(419);
        $this->patch('/admin/associations/'.self::id(10), $this->stateThreeUpdateData())->assertStatus(419);
        $this->patch('/admin/associations/'.self::id(10), $this->stateThreeUpdateData(['_token' => 'invalid-csrf-token']))->assertStatus(419);
        $this->assertNull(Association::findOrFail(self::id(10))->archived_at);
        $this->assertSame(4, DB::table('associations')->count());
        $this->assertSame($before, $this->associationWriteSnapshot());
    }

    public function test_archive_and_restore_http_require_admin_and_audit_successful_transitions(): void
    {
        foreach ([2, 4] as $actor) {
            $this->identity = self::id($actor);
            $this->patch('/admin/associations/'.self::id(10).'/archive')->assertForbidden();
            $this->patch('/admin/associations/'.self::id(12).'/unarchive')->assertForbidden();
        }
        $this->withSession(['supabase_access_token' => null]);
        $this->patch('/admin/associations/'.self::id(10).'/archive')->assertRedirect(route('login'));
        $this->patch('/admin/associations/'.self::id(12).'/unarchive')->assertRedirect(route('login'));
        $this->assertDatabaseCount('audit_logs', 0);
        $this->identity = self::id(1);
        $this->withSession(['supabase_access_token' => 'synthetic-test-token']);
        $before = DB::table('members')->orderBy('id')->get()->toJson();
        $this->patch('/admin/associations/'.self::id(10).'/archive')->assertRedirect(route('admin.associations.index'));
        $this->patch('/admin/associations/'.self::id(12).'/unarchive')->assertRedirect(route('admin.associations.index'));
        $this->assertSame($before, DB::table('members')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_query_count_stays_constant_as_records_grow_and_only_one_related_section_is_loaded(): void
    {
        $actor = UserProfile::with('role')->findOrFail(self::id(1));
        $actor->canAccessApplication();
        $directory = app(AssociationDirectoryQueryService::class);
        $measure = function (callable $read) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $value = $read();
            $queries = DB::getQueryLog();
            DB::disableQueryLog();

            return [$value, $queries];
        };
        [, $before] = $measure(fn () => $directory->records($actor, $this->filters()));
        for ($id = 100; $id < 125; $id++) {
            DB::table('associations')->insert(['id' => self::id($id), 'name' => 'Extra '.$id, 'area_id' => self::id(40),
                'status' => 'active', 'primary_field_officer_id' => self::id(2), 'saad_phase2_join_date' => '2024-01-01']);
        }
        [$records, $after] = $measure(function () use ($directory, $actor) {
            $records = $directory->records($actor, $this->filters());
            foreach ($records as $record) {
                $this->assertTrue($record->relationLoaded('primaryFieldOfficer'));
                $this->assertSame($record->primary_field_officer_id, $record->primaryFieldOfficer->id);
            }

            return $records;
        });
        $this->assertCount(25, $records);
        $this->assertSame(count($before), count($after));
        $this->assertLessThanOrEqual(7, count($after));
        $officerQueries = array_filter($after, fn ($query) => str_contains($query['query'], 'from "user_profiles"'));
        $this->assertCount(1, $officerQueries);
        [$data, $details] = $measure(fn () => $directory->details($actor, Association::findOrFail(self::id(10)), 'members', []));
        $this->assertCount(4, $data['related']);
        foreach ($details as $query) {
            $this->assertStringNotContainsString('"trainings"', $query['query']);
            $this->assertStringNotContainsString('"projects"', $query['query']);
            $this->assertStringNotContainsString('"program_activity_records"', $query['query']);
        }
    }

    public function test_directory_and_editor_request_query_contracts_for_each_role(): void
    {
        foreach (['admin' => 1, 'field-officer' => 2] as $prefix => $actor) {
            $this->identity = self::id($actor);
            foreach (['associations', 'association-officers'] as $module) {
                DB::enableQueryLog();
                DB::flushQueryLog();
                $response = $this->get('/'.$prefix.'/'.$module)->assertOk();
                $queries = count(DB::getQueryLog());
                DB::disableQueryLog();
                $this->assertInstanceOf(LengthAwarePaginator::class, $response->viewData('associations'));
                $this->assertSame(25, $response->viewData('associations')->perPage());
                fwrite(STDOUT, "\n/{$prefix}/{$module}: {$queries} queries\n");
            }
        }
        $this->identity = self::id(1);
        foreach (['create', self::id(10)] as $editor) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $response = $this->get('/admin/associations?editor='.$editor)->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();
            $this->assertIsArray($response->viewData('catalog'));
            $this->assertSame($editor === 'create', $response->viewData('associationEditorRecord') === null);
            fwrite(STDOUT, "\n/admin/associations editor ".($editor === 'create' ? 'create' : 'edit').": {$queries} queries\n");
        }
        app(SupabaseAuthService::class)->shouldHaveReceived('getUser')->times(6);
    }

    private function prepareDirectGeography(): void
    {
        Schema::table('areas', fn (Blueprint $table) => $table->unique(['id', 'barangay_id']));
        Schema::table('associations', function (Blueprint $table) {
            $table->uuid('area_id')->nullable()->change();
            $table->uuid('barangay_id')->nullable();
            $table->text('specific_address')->nullable();
        });
        DB::statement('UPDATE associations SET barangay_id = (SELECT barangay_id FROM areas WHERE areas.id = associations.area_id)');
        Schema::table('associations', function (Blueprint $table) {
            $table->uuid('barangay_id')->nullable(false)->change();
            $table->foreign('barangay_id')->references('id')->on('barangays')->restrictOnDelete();
            $table->foreign(['area_id', 'barangay_id'])->references(['id', 'barangay_id'])->on('areas')->restrictOnDelete()->restrictOnUpdate();
        });
        DB::table('municipalities')->insert(['id' => self::id(22), 'name' => 'Area-free City', 'province' => 'Stored Province', 'region' => 'Region VII', 'is_active' => true]);
        DB::table('barangays')->insert(['id' => self::id(33), 'municipality_id' => self::id(22), 'name' => 'Area-free Barangay', 'is_active' => true]);
        config(['associations.geography_schema_ready' => true, 'associations.direct_geography' => true]);
    }

    private function prepareStateThreeGeography(): void
    {
        $this->prepareDirectGeography();
        config(['associations.geography_schema_ready' => true, 'associations.direct_geography' => false]);
    }

    private function stateThreeUpdateData(array $overrides = [], int $id = 10): array
    {
        $association = Association::findOrFail(self::id($id));

        return $this->associationData(array_replace([
            'name' => $association->name, 'acronym' => $association->acronym,
            'area_id' => $association->area_id, 'primary_field_officer_id' => $association->primary_field_officer_id,
            'expected_primary_field_officer_id' => $association->primary_field_officer_id,
            'saad_phase2_join_date' => $association->saad_phase2_join_date->format('Y-m-d'),
            'status' => $association->status,
            'expected_updated_at' => $association->updated_at?->format('Y-m-d\TH:i:s.uP'),
        ], $overrides));
    }

    private function associationWriteSnapshot(): array
    {
        $tables = ['roles', 'user_profiles', 'user_invitations', 'municipalities', 'barangays', 'areas',
            'associations', 'association_positions', 'member_applications', 'members', 'projects', 'trainings',
            'program_activity_records', 'registration_agencies', 'association_registrations', 'audit_logs'];

        return collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    private function directData(array $overrides = [], ?int $editing = null): array
    {
        return $this->associationData(array_replace([
            'municipality_id' => self::id(20), 'barangay_id' => self::id(30),
        ], $editing === null ? [] : ['expected_updated_at' => Association::findOrFail(self::id($editing))->updated_at?->format('Y-m-d\TH:i:s.uP')], $overrides), $editing);
    }

    public function test_batch_one_keeps_area_required_on_both_old_and_expanded_schemas(): void
    {
        $this->assertFalse(config('associations.geography_schema_ready'));
        $this->assertFalse(config('associations.direct_geography'));
        foreach ([false, true] as $expanded) {
            if ($expanded) {
                Schema::table('associations', function (Blueprint $table) {
                    $table->uuid('barangay_id')->nullable();
                    $table->text('specific_address')->nullable();
                });
            }
            $this->postJson('/admin/associations', $this->associationData(['area_id' => null]))->assertUnprocessable()->assertJsonValidationErrors('area_id');
            $this->postJson('/admin/associations', $this->associationData(['barangay_id' => self::id(30), 'specific_address' => 'Independent']))
                ->assertUnprocessable()->assertJsonValidationErrors(['barangay_id', 'specific_address']);
            $this->post('/admin/associations', $this->associationData(['name' => 'Legacy '.(int) $expanded]))->assertSessionHasNoErrors()->assertRedirect();
            $this->get('/admin/associations?editor=create')->assertOk()->assertDontSee('name="specific_address"', false);
        }
        $this->assertDatabaseHas('associations', ['name' => 'Legacy 1', 'area_id' => self::id(40), 'barangay_id' => null, 'specific_address' => null]);
    }

    #[DataProvider('directCombinations')]
    public function test_prepared_registration_supports_the_four_geographic_combinations(?string $area, ?string $address): void
    {
        $this->prepareDirectGeography();
        $data = $this->directData(['area_id' => $area, 'specific_address' => $address]);
        if ($address === null) {
            unset($data['specific_address']);
        }
        $this->post('/admin/associations', $data)->assertSessionHasNoErrors()->assertRedirect();
        $association = Association::where('name', 'Created association')->firstOrFail();
        $this->assertSame(self::id(30), $association->barangay_id);
        $this->assertSame($area, $association->area_id);
        $this->assertSame($address === null ? null : trim($address), $association->specific_address);
        $this->assertSame(self::id(20), $association->barangay->municipality->id);
        $this->assertNotNull($association->barangay->associations()->find($association->id));
        $this->assertArrayNotHasKey('specific_address', $association->toArray());
        $this->assertDatabaseCount('areas', 3);
    }

    public static function directCombinations(): array
    {
        return [[null, null], [self::id(40), null], [null, '  Purok 2, Niño’s St. #4 / Sitio A-B  '], [self::id(40), 'Independent address']];
    }

    public function test_state_four_native_browser_post_clears_existing_area_without_changing_related_records(): void
    {
        $this->prepareAccreditations();
        $this->enforceNativeAssociationCsrf();
        $before = $this->associationWriteSnapshot();
        $original = (array) DB::table('associations')->where('id', self::id(10))->first();
        $this->assertNotNull($original['area_id']);
        $this->assertNull($original['specific_address']);
        $response = $this->get('/admin/associations?editor='.self::id(10))->assertOk();
        $node = $this->dom($response->getContent())->query('//dialog[@id="association-editor"]')->item(0);
        $this->assertNotNull($node);
        $directory = storage_path('framework/testing/native-association');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        $fixture = $directory.'/editor.html';
        $result = $directory.'/submission.json';
        file_put_contents($fixture, $node->ownerDocument->saveHTML($node));
        $process = new Process(['node', '--test', base_path('tests/Frontend/association-native-submission.test.mjs')], base_path(), [
            'ASSOCMAP_ASSOCIATION_EDIT_HTML' => $fixture,
            'ASSOCMAP_ASSOCIATION_SUBMISSION_RESULT' => $result,
        ]);
        $process->setTimeout(60);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        fwrite(STDOUT, "\n".$process->getOutput());
        $states = json_decode(file_get_contents($result), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(3, $states['results']);
        $this->assertSame([], $states['errors']);
        $payload = [];
        parse_str($states['results'][0]['submitted']['urlEncoded'], $payload);
        $this->assertSame('', $payload['area_id']);
        $this->assertSame('PATCH', $payload['_method']);
        $this->assertSame('S4-PERSISTENCE-TEST', $payload['specific_address']);

        $withoutCsrf = $payload;
        unset($withoutCsrf['_token']);
        $this->post('/admin/associations/'.self::id(10), $withoutCsrf)->assertStatus(419);
        $this->assertSame($before, $this->associationWriteSnapshot());
        $this->identity = self::id(2);
        $this->post('/admin/associations/'.self::id(10), $payload)->assertForbidden();
        $this->assertSame($before, $this->associationWriteSnapshot());
        $this->identity = self::id(1);
        $this->post('/admin/associations/'.self::id(10), $payload)->assertSessionHasNoErrors()->assertRedirect();
        $saved = (array) DB::table('associations')->where('id', self::id(10))->first();
        $this->assertNull($saved['area_id']);
        $this->assertSame('S4-PERSISTENCE-TEST', $saved['specific_address']);
        $this->assertSame($original['barangay_id'], $saved['barangay_id']);
        $this->assertSame(self::id(1), $saved['updated_by']);
        foreach (['area_id', 'specific_address', 'updated_at', 'updated_by'] as $field) {
            unset($original[$field], $saved[$field]);
        }
        $this->assertSame($original, $saved);
        $after = $this->associationWriteSnapshot();
        foreach ($before as $table => $rows) {
            if ($table !== 'associations') {
                $this->assertSame($rows, $after[$table], $table);
            }
        }
        $others = fn ($rows) => array_values(array_filter(json_decode($rows, true), fn ($row) => $row['id'] !== self::id(10)));
        $this->assertSame($others($before['associations']), $others($after['associations']));
    }

    public function test_state_four_native_post_rejects_a_stale_area_clear_without_writes(): void
    {
        $this->prepareAccreditations();
        $this->enforceNativeAssociationCsrf();
        $this->get('/admin/associations?editor='.self::id(10))->assertOk();
        $payload = array_replace($this->stateThreeUpdateData(), [
            '_method' => 'PATCH', '_token' => session()->token(),
            'municipality_id' => self::id(20), 'barangay_id' => self::id(30),
            'area_id' => '', 'specific_address' => 'S4-PERSISTENCE-TEST',
        ]);
        DB::table('associations')->where('id', self::id(10))->update(['updated_at' => now()->addMinute()]);
        $before = $this->associationWriteSnapshot();
        $this->post('/admin/associations/'.self::id(10), $payload)->assertSessionHasErrors('association');
        $this->assertSame($before, $this->associationWriteSnapshot());
        $this->assertNotNull(Association::findOrFail(self::id(10))->area_id);
        $this->assertNull(Association::findOrFail(self::id(10))->specific_address);
    }

    private function enforceNativeAssociationCsrf(): void
    {
        $this->app->bind(ValidateCsrfToken::class, function ($app) {
            return new class($app, $app['encrypter']) extends ValidateCsrfToken
            {
                protected function runningUnitTests()
                {
                    return false;
                }
            };
        });
    }

    #[DataProvider('directCombinations')]
    public function test_state_four_edit_supports_all_area_address_combinations_without_unrelated_changes(?string $area, ?string $address): void
    {
        $this->prepareDirectGeography();
        $before = $this->associationWriteSnapshot();
        $record = (array) DB::table('associations')->where('id', self::id(10))->first();
        $data = array_replace($this->stateThreeUpdateData(), ['municipality_id' => self::id(20),
            'barangay_id' => self::id(30), 'area_id' => $area, 'specific_address' => $address]);
        $this->patch('/admin/associations/'.self::id(10), $data)->assertSessionHasNoErrors()->assertRedirect();
        $after = (array) DB::table('associations')->where('id', self::id(10))->first();
        $this->assertSame($area, $after['area_id']);
        $this->assertSame(self::id(30), $after['barangay_id']);
        $this->assertSame($address === null ? null : trim($address), $after['specific_address']);
        foreach (['area_id', 'specific_address', 'updated_at', 'updated_by'] as $field) {
            unset($record[$field], $after[$field]);
        }
        $this->assertSame($record, $after);
        $snapshot = $this->associationWriteSnapshot();
        foreach ($before as $table => $rows) {
            if ($table !== 'associations') {
                $this->assertSame($rows, $snapshot[$table], $table);
            }
        }
        $others = fn ($rows) => array_values(array_filter(json_decode($rows, true), fn ($row) => $row['id'] !== self::id(10)));
        $this->assertSame($others($before['associations']), $others($snapshot['associations']));
    }

    #[DataProvider('requiredDirectParents')]
    public function test_state_four_requires_municipality_and_barangay_for_create_and_edit(string $field, bool $omit): void
    {
        $this->prepareDirectGeography();
        $before = $this->associationWriteSnapshot();
        foreach ([null, 10] as $editing) {
            $data = $this->directData([$field => null], $editing);
            if ($omit) {
                unset($data[$field]);
            }
            $response = $editing === null ? $this->postJson('/admin/associations', $data)
                : $this->patchJson('/admin/associations/'.self::id($editing), $data);
            $response->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->assertSame($before, $this->associationWriteSnapshot());
        }
    }

    public static function requiredDirectParents(): array
    {
        return [['municipality_id', true], ['municipality_id', false], ['barangay_id', true], ['barangay_id', false]];
    }

    #[DataProvider('invalidDirectGeography')]
    public function test_prepared_geography_rejects_invalid_or_ineligible_choices_and_address_shapes(array $overrides, string $field): void
    {
        $this->prepareDirectGeography();
        $this->postJson('/admin/associations', $this->directData($overrides))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('associations', 4);
    }

    public static function invalidDirectGeography(): array
    {
        return [
            [['barangay_id' => 'bad'], 'barangay_id'], [['barangay_id' => []], 'barangay_id'],
            [['barangay_id' => self::id(999)], 'barangay_id'], [['barangay_id' => self::id(31)], 'barangay_id'],
            [['municipality_id' => 'bad'], 'municipality_id'], [['municipality_id' => []], 'municipality_id'],
            [['municipality_id' => self::id(21), 'barangay_id' => self::id(32)], 'municipality_id'],
            [['municipality_id' => self::id(22)], 'barangay_id'],
            [['area_id' => self::id(42)], 'area_id'], [['area_id' => self::id(41)], 'area_id'],
            [['area_id' => self::id(999)], 'area_id'], [['area_id' => 'bad'], 'area_id'], [['area_id' => []], 'area_id'],
            [['specific_address' => ['bad']], 'specific_address'], [['specific_address' => ['nested' => 'bad']], 'specific_address'],
            [['specific_address' => 123], 'specific_address'], [['specific_address' => str_repeat('ñ', 501)], 'specific_address'],
        ];
    }

    public function test_independent_address_updates_preserve_omissions_and_clear_only_explicit_blanks(): void
    {
        $this->prepareDirectGeography();
        DB::table('associations')->where('id', self::id(10))->update(['specific_address' => 'Original independent address']);
        $url = '/admin/associations/'.self::id(10);
        $this->patch($url, $this->directData(['municipality_id' => self::id(22), 'barangay_id' => self::id(33), 'area_id' => null], 10))
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('associations', ['id' => self::id(10), 'area_id' => null, 'barangay_id' => self::id(33), 'specific_address' => 'Original independent address']);
        $this->patch($url, $this->directData(['specific_address' => '  Updated / ñ #2  '], 10))->assertSessionHasNoErrors();
        $this->assertSame('Updated / ñ #2', Association::findOrFail(self::id(10))->specific_address);
        $this->patch($url, $this->directData(['specific_address' => str_repeat('ñ', 500)], 10))->assertSessionHasNoErrors();
        $this->assertSame(500, mb_strlen(Association::findOrFail(self::id(10))->specific_address));
        $this->patch($url, $this->directData(['specific_address' => " \t\r\n "], 10))->assertSessionHasNoErrors();
        $this->assertNull(Association::findOrFail(self::id(10))->specific_address);
    }

    public function test_prepared_editor_rejects_stale_and_missing_revisions_without_overwriting_address(): void
    {
        $this->prepareDirectGeography();
        $data = $this->directData(['specific_address' => 'Stale address'], 10);
        DB::table('associations')->where('id', self::id(10))->update(['updated_at' => now()->addMinute(), 'specific_address' => 'Newer address']);
        $url = '/admin/associations/'.self::id(10);
        $this->patchJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('association');
        unset($data['expected_updated_at']);
        $this->patchJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('expected_updated_at');
        $this->assertSame('Newer address', Association::findOrFail(self::id(10))->specific_address);
    }

    public function test_prepared_editor_supports_area_free_catalogs_restoration_and_escaped_private_details(): void
    {
        $this->prepareDirectGeography();
        $create = $this->get('/admin/associations?editor=create')->assertOk()->assertSee('Area-free City')->assertSee('No Specific Area');
        $this->assertContains(self::id(33), array_column($create->viewData('catalog')['options']['barangay_records'], 'value'));
        $dom = $this->dom($create->getContent());
        $this->assertFalse($dom->query('//select[@name="area_id"]')->item(0)->hasAttribute('required'));
        foreach (['municipality_id', 'barangay_id'] as $field) {
            $this->assertTrue($dom->query('//select[@name="'.$field.'"]')->item(0)->hasAttribute('required'));
        }
        $this->assertSame('500', $dom->query('//textarea[@name="specific_address"]')->item(0)->getAttribute('maxlength'));
        foreach ([['municipality_records', 21], ['barangay_records', 31], ['barangay_records', 32], ['area_records', 41], ['area_records', 42]] as [$catalog, $id]) {
            $this->assertNotContains(self::id($id), array_column($create->viewData('catalog')['options'][$catalog], 'value'));
        }
        DB::table('associations')->where('id', self::id(10))->update(['area_id' => null, 'barangay_id' => self::id(33), 'specific_address' => '<script>private</script>']);
        $this->get('/admin/associations?editor='.self::id(10))->assertOk()->assertSee('name="expected_updated_at"', false)
            ->assertSee('&lt;script&gt;private&lt;/script&gt;', false)->assertDontSee('<script>private</script>', false);
        foreach (['admin' => 1, 'field-officer' => 2] as $prefix => $actor) {
            $this->identity = self::id($actor);
            $this->get('/'.$prefix.'/associations?q=Area-free&sort=municipality')->assertOk()->assertSee('Alpha Association');
            $this->get('/'.$prefix.'/associations/'.self::id(10))->assertOk()->assertSee('Area-free Barangay')->assertSee('&lt;script&gt;private&lt;/script&gt;', false);
        }
        $this->identity = self::id(1);
        $this->from('/admin/associations')->post('/admin/associations', $this->directData([
            'association_form' => 'editor', 'name' => '', 'municipality_id' => self::id(22), 'barangay_id' => self::id(33),
            'area_id' => null, 'specific_address' => 'Unsaved address',
        ]))->assertSessionHasErrors('name');
        $restored = $this->get('/admin/associations')->assertOk()->assertSee('Unsaved address');
        $this->assertSame(1, $this->dom($restored->getContent())->query('//select[@name="barangay_id"]/option[@selected and @value="'.self::id(33).'"]')->length);
    }

    public function test_prepared_geography_preserves_profile_write_roles_archival_and_registration_relationships(): void
    {
        $this->prepareDirectGeography();
        $before = DB::table('association_registrations')->orderBy('id')->get()->toJson();
        $this->patchJson('/admin/associations/'.self::id(12), $this->directData([], 12))->assertUnprocessable()->assertJsonValidationErrors('association');
        foreach ([2, 4] as $actor) {
            $this->identity = self::id($actor);
            $this->postJson('/admin/associations', $this->directData(['area_id' => null]))->assertForbidden();
            $this->patchJson('/admin/associations/'.self::id(10), $this->directData([], 10))->assertForbidden();
        }
        $this->assertSame($before, DB::table('association_registrations')->orderBy('id')->get()->toJson());
    }

    public function test_transaction_rechecks_geographic_eligibility_after_request_validation(): void
    {
        $this->prepareDirectGeography();
        $data = $this->directData();
        validator($data, app(AssociationGeographyService::class)->rules($data, false))->validate();
        DB::table('barangays')->where('id', self::id(30))->update(['is_active' => false]);
        try {
            app(AssociationService::class)->create($data, UserProfile::with('role')->findOrFail(self::id(1)));
            $this->fail('A parent deactivated after validation must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('barangay_id', $exception->errors());
        }
        $this->assertDatabaseCount('associations', 4);
    }

    public function test_state_four_blank_area_and_address_create_nulls_without_copying_recorded_addresses(): void
    {
        $this->prepareDirectGeography();
        foreach (['', self::id(40)] as $index => $area) {
            $this->post('/admin/associations', $this->directData(['name' => 'Blank address '.$index,
                'area_id' => $area, 'specific_address' => " \t\r\n "]))->assertSessionHasNoErrors()->assertRedirect();
            $association = Association::where('name', 'Blank address '.$index)->firstOrFail();
            $this->assertSame($area === '' ? null : $area, $association->area_id);
            $this->assertNull($association->specific_address);
        }
    }

    public function test_state_four_rejects_revoked_administrators_and_requires_csrf_without_writes(): void
    {
        $this->prepareDirectGeography();
        foreach (['user_profiles' => self::id(1), 'roles' => 1] as $table => $id) {
            DB::table($table)->where('id', $id)->update(['is_active' => false]);
            $before = $this->associationWriteSnapshot();
            $this->withSession(['supabase_access_token' => 'synthetic-test-token']);
            $this->post('/admin/associations', $this->directData())->assertRedirect(route('login'));
            $this->patch('/admin/associations/'.self::id(10), $this->directData([], 10))->assertRedirect(route('login'));
            $this->assertSame($before, $this->associationWriteSnapshot());
            DB::table($table)->where('id', $id)->update(['is_active' => true]);
        }
        $this->withSession(['supabase_access_token' => 'synthetic-test-token']);
        $this->app->bind(ValidateCsrfToken::class, function ($app) {
            return new class($app, $app['encrypter']) extends ValidateCsrfToken
            {
                protected function runningUnitTests()
                {
                    return false;
                }
            };
        });
        $before = $this->associationWriteSnapshot();
        $this->post('/admin/associations', $this->directData())->assertStatus(419);
        $this->patch('/admin/associations/'.self::id(10), $this->directData([], 10))->assertStatus(419);
        $this->assertSame($before, $this->associationWriteSnapshot());
    }

    public function test_prepared_directory_query_count_is_bounded_and_catalogs_load_only_for_editing(): void
    {
        $this->prepareDirectGeography();
        $actor = UserProfile::with('role')->findOrFail(self::id(1));
        $actor->canAccessApplication();
        $directory = app(AssociationDirectoryQueryService::class);
        $measure = function () use ($actor, $directory) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $records = $directory->records($actor, $this->filters());
            foreach ($records as $record) {
                $this->assertNotNull($record->barangay?->municipality);
            }
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $before = $measure();
        for ($id = 100; $id < 127; $id++) {
            DB::table('associations')->insert([
                'id' => self::id($id), 'name' => 'Area-free '.$id, 'area_id' => null, 'barangay_id' => self::id(33),
                'status' => 'active', 'primary_field_officer_id' => self::id(2), 'saad_phase2_join_date' => '2024-01-01',
            ]);
        }
        $this->assertSame($before, $measure());
        $this->assertLessThanOrEqual(7, $before);
        foreach (['', '?editor=create', '?editor='.self::id(10)] as $query) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->get('/admin/associations'.$query)->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();
            fwrite(STDOUT, "\nPrepared /admin/associations{$query}: {$count} queries\n");
        }
    }

    public function test_cutover_schema_writes_required_barangay_while_optional_area_activation_is_off(): void
    {
        $this->prepareStateThreeGeography();
        $this->assertTrue(config('associations.geography_schema_ready'));
        $this->assertFalse(config('associations.direct_geography'));
        $this->post('/admin/associations', $this->associationData())->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('associations', ['name' => 'Created association', 'barangay_id' => self::id(30), 'area_id' => self::id(40)]);
        $this->postJson('/admin/associations', $this->associationData(['area_id' => null]))->assertUnprocessable()->assertJsonValidationErrors('area_id');
        DB::table('associations')->where('id', self::id(10))->update(['acronym' => null, 'updated_at' => '2025-01-01 00:00:00']);
        $before = Association::findOrFail(self::id(10))->getRawOriginal();
        $relatedBefore = $this->associationWriteSnapshot();
        $othersBefore = DB::table('associations')->where('id', '<>', self::id(10))->orderBy('id')->get()->toJson();
        $this->patch('/admin/associations/'.self::id(10), $this->stateThreeUpdateData(['acronym' => 'S3-PHASE-B']))
            ->assertSessionHasNoErrors()->assertRedirect();
        $after = Association::findOrFail(self::id(10))->getRawOriginal();
        $this->assertSame('S3-PHASE-B', $after['acronym']);
        $this->assertSame(self::id(1), $after['updated_by']);
        $this->assertNotSame($before['updated_at'], $after['updated_at']);
        $allowedChanges = array_flip(['acronym', 'updated_at', 'updated_by']);
        $this->assertSame(array_diff_key($before, $allowedChanges), array_diff_key($after, $allowedChanges));
        $this->assertSame(DB::table('areas')->where('id', $after['area_id'])->value('barangay_id'), $after['barangay_id']);
        $this->assertSame($othersBefore, DB::table('associations')->where('id', '<>', self::id(10))->orderBy('id')->get()->toJson());
        $relatedAfter = $this->associationWriteSnapshot();
        unset($relatedBefore['associations'], $relatedAfter['associations']);
        $this->assertSame($relatedBefore, $relatedAfter);
        $this->get('/admin/associations?editor='.self::id(10))->assertOk()->assertSee('name="expected_updated_at"', false)
            ->assertDontSee('name="specific_address"', false);
        DB::table('areas')->insert(['id' => self::id(43), 'barangay_id' => self::id(33), 'address' => 'Synthetic alternate Area', 'is_active' => true]);
        $this->patch('/admin/associations/'.self::id(10), $this->stateThreeUpdateData(['area_id' => self::id(43)]))
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('associations', ['id' => self::id(10), 'area_id' => self::id(43), 'barangay_id' => self::id(33), 'acronym' => 'S3-PHASE-B']);
    }

    #[DataProvider('invalidStateThreeGeography')]
    public function test_state_three_rejects_geographic_inputs_without_changing_records(array $overrides, string $field): void
    {
        $this->prepareStateThreeGeography();
        $create = $this->associationData($overrides);
        $update = $this->stateThreeUpdateData($overrides);
        if ($field === 'area_id' && ! array_key_exists('area_id', $overrides)) {
            unset($create['area_id'], $update['area_id']);
        }
        $before = $this->associationWriteSnapshot();
        $this->postJson('/admin/associations', $create)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $this->associationWriteSnapshot());
        $this->patchJson('/admin/associations/'.self::id(10), $update)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $this->associationWriteSnapshot());
    }

    public static function invalidStateThreeGeography(): array
    {
        return [
            'missing Area' => [[], 'area_id'],
            'NULL Area' => [['area_id' => null], 'area_id'],
            'empty Area' => [['area_id' => ''], 'area_id'],
            'direct Barangay' => [['barangay_id' => self::id(30)], 'barangay_id'],
            'independent address' => [['specific_address' => 'Must not persist'], 'specific_address'],
        ];
    }

    public function test_state_three_rejects_missing_and_stale_tokens_without_overwriting_newer_records(): void
    {
        $this->prepareStateThreeGeography();
        $url = '/admin/associations/'.self::id(10);
        $stale = $this->stateThreeUpdateData(['acronym' => 'Stale attempt']);
        DB::table('associations')->where('id', self::id(10))->update(['acronym' => 'Newer record', 'updated_at' => now()->addMinute()]);
        $before = $this->associationWriteSnapshot();
        $this->patchJson($url, $stale)->assertUnprocessable()->assertJsonValidationErrors('association');
        $this->assertSame($before, $this->associationWriteSnapshot());
        unset($stale['expected_updated_at']);
        $this->patchJson($url, $stale)->assertUnprocessable()->assertJsonValidationErrors('expected_updated_at');
        $this->assertSame($before, $this->associationWriteSnapshot());
        $this->patch($url, $this->stateThreeUpdateData(['acronym' => 'Fresh edit']))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('associations', ['id' => self::id(10), 'acronym' => 'Fresh edit', 'area_id' => self::id(40), 'barangay_id' => self::id(30)]);
    }

    public function test_state_three_restricts_association_mutations_to_eligible_administrators(): void
    {
        $this->prepareStateThreeGeography();
        $before = $this->associationWriteSnapshot();
        $update = $this->stateThreeUpdateData();
        $this->withSession(['supabase_access_token' => null]);
        $this->post('/admin/associations', $this->associationData())->assertRedirect(route('login'));
        $this->patch('/admin/associations/'.self::id(10), $update)->assertRedirect(route('login'));
        $this->withSession(['supabase_access_token' => 'synthetic-test-token']);
        foreach ([2, 4] as $actor) {
            $this->identity = self::id($actor);
            $this->postJson('/admin/associations', $this->associationData())->assertForbidden();
            $this->patchJson('/admin/associations/'.self::id(10), $update)->assertForbidden();
            $this->patchJson('/admin/associations/'.self::id(13), $this->stateThreeUpdateData([], 13))->assertForbidden();
        }
        $this->identity = self::id(1);
        $this->patchJson('/admin/associations/'.self::id(999), $update)->assertNotFound();
        $this->patchJson('/admin/associations/'.self::id(12), $this->stateThreeUpdateData(['area_id' => self::id(40)], 12))
            ->assertUnprocessable()->assertJsonValidationErrors('association');
        $this->assertSame($before, $this->associationWriteSnapshot());
        DB::table('user_profiles')->where('id', $this->identity)->update(['is_active' => false]);
        $revoked = $this->associationWriteSnapshot();
        $this->post('/admin/associations', $this->associationData())->assertRedirect(route('login'));
        $this->withSession(['supabase_access_token' => 'synthetic-test-token']);
        $this->patch('/admin/associations/'.self::id(10), $update)->assertRedirect(route('login'));
        $this->assertSame($revoked, $this->associationWriteSnapshot());
    }

    public function test_direct_geographic_directories_include_area_less_records_but_area_counts_remain_specific(): void
    {
        $this->prepareDirectGeography();
        DB::table('associations')->where('id', self::id(10))->update(['area_id' => null]);
        $query = app(AreaManagementQueryService::class);
        $this->assertSame(3, (int) $query->municipalities()->findOrFail(self::id(20))->associations_count);
        $this->assertSame(2, (int) $query->barangays()->findOrFail(self::id(30))->associations_count);
        $this->assertSame(1, Area::findOrFail(self::id(40))->associations()->count());
        $this->assertSame(2, $query->associations()->where('barangays.id', self::id(30))->count());
        config(['associations.direct_geography' => false]);
        $this->get('/admin/associations/'.self::id(10))->assertOk()->assertSee('Alpha Municipality')->assertSee('Alpha Barangay');
    }

    private function prepareAccreditations(): void
    {
        $this->prepareDirectGeography();
        foreach ([2 => ['Accrediting Agency', 'accreditation', true], 3 => ['Inactive Agency', 'accreditation', false]] as $id => [$name, $kind, $active]) {
            DB::table('registration_agencies')->insert(['id' => $id, 'name' => $name, 'registration_kind' => $kind, 'is_active' => $active]);
        }
        foreach ([91 => [10, 2], 92 => [10, 2], 93 => [11, 2], 94 => [10, 3]] as $id => [$association, $agency]) {
            DB::table('association_registrations')->insert(['id' => self::id($id), 'association_id' => self::id($association),
                'agency_id' => $agency, 'registration_number' => 'ACC-'.$id, 'registration_date' => '2023-01-01',
                'expiration_date' => '2027-01-01', 'is_primary' => false, 'remarks' => 'Saved <private> remarks',
                'created_at' => '2023-01-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00.000001']);
        }
    }

    private function accreditationData(array $changes = [], int $id = 91): array
    {
        $record = AssociationRegistration::findOrFail(self::id($id));

        return array_replace($this->stateThreeUpdateData(), ['municipality_id' => self::id(20), 'barangay_id' => self::id(30),
            'registration' => array_replace(['id' => $record->id, 'expected_updated_at' => $record->updated_at->format('Y-m-d\TH:i:s.uP'),
                'registration_number' => $record->registration_number, 'registration_date' => $record->registration_date->format('Y-m-d'),
                'expiration_date' => $record->expiration_date?->format('Y-m-d'), 'remarks' => $record->remarks], $changes)]);
    }

    public function test_accreditation_editor_selects_one_record_and_keeps_agency_and_primary_read_only(): void
    {
        $this->prepareAccreditations();
        $url = '/admin/associations?editor='.self::id(10);
        $plain = $this->get($url)->assertOk()->assertSee('Update Accreditation');
        $this->assertSame(0, $this->dom($plain->getContent())->query('//input[@name="registration[id]"]')->length);
        $edit = $this->get($url.'&accreditation='.self::id(91))->assertOk()->assertSee('ACC-91')
            ->assertSee('Saved &lt;private&gt; remarks', false)->assertDontSee('Saved <private> remarks', false);
        $dom = $this->dom($edit->getContent());
        $this->assertSame(self::id(91), $dom->query('//input[@name="registration[id]"]')->item(0)->getAttribute('value'));
        $this->assertSame('2023-01-01', $dom->query('//input[@id="accreditation-date"]')->item(0)->getAttribute('value'));
        $this->assertSame('2027-01-01', $dom->query('//input[@id="accreditation-expiration"]')->item(0)->getAttribute('value'));
        $this->assertSame(0, $dom->query('//fieldset[legend="Update Accreditation"]//*[@name="registration[agency_id]" or @name="registration[is_primary]"]')->length);
        $this->assertSame('Update Accreditation', trim($dom->query('//button[@data-association-submit]')->item(0)->textContent));
        foreach ([90, 93, 94] as $id) {
            $this->get($url.'&accreditation='.self::id($id))->assertNotFound();
        }
        $this->get('/admin/associations?editor=create&accreditation='.self::id(91))->assertNotFound();
        $this->identity = self::id(2);
        $this->get($url.'&accreditation='.self::id(91))->assertForbidden();
    }

    public function test_accreditation_update_changes_only_the_selected_document_and_parent_revision(): void
    {
        $this->prepareAccreditations();
        $before = $this->associationWriteSnapshot();
        $parent = (array) DB::table('associations')->where('id', self::id(10))->first();
        $original = (array) DB::table('association_registrations')->where('id', self::id(91))->first();
        $this->patch('/admin/associations/'.self::id(10), $this->accreditationData([
            'registration_number' => '  ACC-CORRECTED  ', 'registration_date' => '2023-02-01',
            'expiration_date' => '2028-02-01', 'remarks' => '  Corrected <document>  ',
        ]))->assertSessionHasNoErrors()->assertRedirect();
        $after = $this->associationWriteSnapshot();
        $this->assertDatabaseHas('association_registrations', ['id' => self::id(91), 'agency_id' => 2,
            'association_id' => self::id(10), 'registration_number' => 'ACC-CORRECTED', 'is_primary' => false,
            'remarks' => 'Corrected <document>']);
        $savedDocument = AssociationRegistration::findOrFail(self::id(91));
        $this->assertSame('2023-02-01', $savedDocument->registration_date->format('Y-m-d'));
        $this->assertSame('2028-02-01', $savedDocument->expiration_date->format('Y-m-d'));
        $newParent = (array) DB::table('associations')->where('id', self::id(10))->first();
        $this->assertNotSame($parent['updated_at'], $newParent['updated_at']);
        $this->assertSame(self::id(1), $newParent['updated_by']);
        unset($parent['updated_at'], $parent['updated_by'], $newParent['updated_at'], $newParent['updated_by']);
        $this->assertSame($parent, $newParent);
        $record = (array) DB::table('association_registrations')->where('id', self::id(91))->first();
        foreach (['id', 'association_id', 'agency_id', 'is_primary', 'created_at'] as $key) {
            $this->assertSame($original[$key], $record[$key]);
        }
        foreach ($before as $table => $rows) {
            if (! in_array($table, ['associations', 'association_registrations'])) {
                $this->assertSame($rows, $after[$table], $table);
            }
        }
        foreach (['associations' => 10, 'association_registrations' => 91] as $table => $id) {
            $others = fn ($json) => array_values(array_filter(json_decode($json, true), fn ($row) => $row['id'] !== self::id($id)));
            $this->assertSame($others($before[$table]), $others($after[$table]));
        }
        $this->get('/admin/associations/'.self::id(10).'?section=registrations')->assertOk()
            ->assertSee('ACC-CORRECTED')->assertSee('Corrected &lt;document&gt;', false);
        $this->get('/admin/associations?editor='.self::id(10).'&accreditation='.self::id(91))->assertOk()->assertSee('ACC-CORRECTED');
        $this->identity = self::id(2);
        $this->get('/field-officer/associations/'.self::id(10).'?section=registrations')->assertOk()
            ->assertDontSee('Update Accreditation')->assertDontSee('Corrected &lt;document&gt;', false);
        $this->identity = self::id(1);
        $this->patch('/admin/associations/'.self::id(10), $this->accreditationData(['remarks' => 'Second document only'], 92))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('association_registrations', ['id' => self::id(91), 'remarks' => 'Corrected <document>']);
    }

    public function test_accreditation_omissions_and_unrelated_geography_edits_preserve_saved_documents(): void
    {
        $this->prepareAccreditations();
        $before = DB::table('association_registrations')->orderBy('id')->get()->toJson();
        $data = array_replace($this->stateThreeUpdateData(), ['municipality_id' => self::id(22), 'barangay_id' => self::id(33),
            'area_id' => null, 'specific_address' => 'Independent geography address']);
        $this->patch('/admin/associations/'.self::id(10), $data)->assertSessionHasNoErrors();
        $this->assertSame($before, DB::table('association_registrations')->orderBy('id')->get()->toJson());
        $data = $this->accreditationData(['registration_number' => 'ACC-RENUMBERED']);
        unset($data['registration']['expiration_date'], $data['registration']['remarks']);
        $data['municipality_id'] = self::id(22);
        $data['barangay_id'] = self::id(33);
        $this->patch('/admin/associations/'.self::id(10), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('association_registrations', ['id' => self::id(91), 'expiration_date' => '2027-01-01', 'remarks' => 'Saved <private> remarks']);
        $data = $this->accreditationData(['expiration_date' => null, 'remarks' => '']);
        $data['municipality_id'] = self::id(22);
        $data['barangay_id'] = self::id(33);
        $this->patch('/admin/associations/'.self::id(10), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('association_registrations', ['id' => self::id(91), 'expiration_date' => null, 'remarks' => null]);
    }

    #[DataProvider('invalidAccreditation')]
    public function test_accreditation_rejections_preserve_all_existing_rows(array $changes): void
    {
        $this->prepareAccreditations();
        $before = $this->associationWriteSnapshot();
        $this->patchJson('/admin/associations/'.self::id(10), $this->accreditationData($changes))->assertUnprocessable();
        $this->assertSame($before, $this->associationWriteSnapshot());
    }

    public static function invalidAccreditation(): array
    {
        return [
            'ordinary registration' => [['id' => self::id(90)]], 'foreign accreditation' => [['id' => self::id(93)]],
            'inactive agency' => [['id' => self::id(94)]], 'bad id' => [['id' => 'bad']], 'multiple ids' => [['id' => [self::id(91), self::id(92)]]],
            'blank number' => [['registration_number' => '  ']], 'long number' => [['registration_number' => str_repeat('x', 151)]],
            'duplicate number' => [['registration_number' => 'ACC-92']], 'bad date' => [['registration_date' => 'tomorrow']],
            'expiry before registration' => [['expiration_date' => '2022-01-01']], 'bad expiry' => [['expiration_date' => 'bad']],
            'bad remarks' => [['remarks' => []]], 'long remarks' => [['remarks' => str_repeat('x', 4001)]],
            'agency read-only' => [['agency_id' => 2]], 'primary read-only' => [['is_primary' => true]],
            'ownership read-only' => [['association_id' => self::id(11)]], 'missing revision' => [['expected_updated_at' => null]],
        ];
    }

    public function test_accreditation_stale_parent_or_child_tokens_and_missing_payload_keys_cannot_overwrite(): void
    {
        $this->prepareAccreditations();
        $url = '/admin/associations/'.self::id(10);
        $staleParent = $this->accreditationData(['remarks' => 'Stale edit']);
        $this->patch($url, $this->accreditationData(['remarks' => 'Fresh edit']))->assertSessionHasNoErrors();
        $before = $this->associationWriteSnapshot();
        $this->patchJson($url, $staleParent)->assertUnprocessable()->assertJsonValidationErrors('association');
        $this->assertSame($before, $this->associationWriteSnapshot());
        $staleChild = $this->accreditationData(['remarks' => 'Stale child']);
        DB::table('association_registrations')->where('id', self::id(91))->update(['updated_at' => '2026-11-01 00:00:00.000001', 'remarks' => 'External correction']);
        $before = $this->associationWriteSnapshot();
        $this->patchJson($url, $staleChild)->assertUnprocessable()->assertJsonValidationErrors('registration');
        foreach (['id', 'expected_updated_at', 'registration_number', 'registration_date'] as $key) {
            $data = $this->accreditationData();
            unset($data['registration'][$key]);
            $this->patchJson($url, $data)->assertUnprocessable();
        }
        $data = $this->accreditationData();
        $data['registration'] = [];
        $this->patchJson($url, $data)->assertUnprocessable();
        $this->assertSame($before, $this->associationWriteSnapshot());
    }

    public function test_accreditation_authorization_and_lifecycle_cannot_be_bypassed(): void
    {
        $this->prepareAccreditations();
        $data = $this->accreditationData();
        $before = $this->associationWriteSnapshot();
        foreach ([2, 4] as $actor) {
            $this->identity = self::id($actor);
            $this->patchJson('/admin/associations/'.self::id(10), $data)->assertForbidden();
        }
        $this->identity = self::id(1);
        $this->withSession(['supabase_access_token' => null]);
        $this->patch('/admin/associations/'.self::id(10), $data)->assertRedirect(route('login'));
        $this->withSession(['supabase_access_token' => 'synthetic-test-token']);
        foreach (['status' => 'inactive', 'archived_at' => '2026-10-01'] as $key => $value) {
            DB::table('associations')->where('id', self::id(10))->update([$key => $value]);
            $data = $this->accreditationData();
            $data['status'] = 'active';
            $blocked = $this->associationWriteSnapshot();
            $this->patchJson('/admin/associations/'.self::id(10), $data)->assertUnprocessable();
            $this->assertSame($blocked, $this->associationWriteSnapshot());
            DB::table('associations')->where('id', self::id(10))->update([$key => $key === 'status' ? 'active' : null]);
        }
        $this->assertSame($before['association_registrations'], DB::table('association_registrations')->orderBy('id')->get()->toJson());
        $this->app->bind(ValidateCsrfToken::class, function ($app) {
            return new class($app, $app['encrypter']) extends ValidateCsrfToken
            {
                protected function runningUnitTests()
                {
                    return false;
                }
            };
        });
        $before = $this->associationWriteSnapshot();
        $this->patch('/admin/associations/'.self::id(10), $this->accreditationData())->assertStatus(419);
        $this->assertSame($before, $this->associationWriteSnapshot());
    }

    public function test_accreditation_service_rechecks_agency_classification_and_primary_dates(): void
    {
        $this->prepareAccreditations();
        $data = $this->accreditationData();
        DB::table('registration_agencies')->where('id', 2)->update(['registration_kind' => 'registration']);
        $before = $this->associationWriteSnapshot();
        try {
            app(AssociationService::class)->update(Association::findOrFail(self::id(10)), $data, UserProfile::with('role')->findOrFail(self::id(1)));
            $this->fail('A reclassified agency must be rejected under lock.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('registration.id', $e->errors());
        }
        $this->assertSame($before, $this->associationWriteSnapshot());
        DB::table('registration_agencies')->where('id', 2)->update(['registration_kind' => 'accreditation']);
        DB::table('association_registrations')->where('id', self::id(90))->update(['is_primary' => false]);
        DB::table('association_registrations')->where('id', self::id(91))->update(['is_primary' => true]);
        $before = $this->associationWriteSnapshot();
        $this->patchJson('/admin/associations/'.self::id(10), $this->accreditationData(['registration_date' => '2099-01-01', 'expiration_date' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors('registration.registration_date');
        $this->assertSame($before, $this->associationWriteSnapshot());
    }

    public function test_accreditation_validation_recovery_preserves_the_selected_record_and_unsaved_values(): void
    {
        $this->prepareAccreditations();
        $url = '/admin/associations?editor='.self::id(10).'&accreditation='.self::id(91);
        $data = $this->accreditationData(['registration_number' => 'Unsaved correction', 'expiration_date' => 'bad']);
        $data['association_form'] = 'editor';
        $data['association_record_id'] = self::id(10);
        $this->from($url)->patch('/admin/associations/'.self::id(10), $data)->assertRedirect($url)->assertSessionHasErrors();
        $this->get($url)->assertOk()->assertSee('Unsaved correction')->assertSee('Update Accreditation');
        $this->assertDatabaseHas('association_registrations', ['id' => self::id(91), 'registration_number' => 'ACC-91']);
        foreach (['registration_number', 'registration_date', 'expiration_date', 'remarks'] as $field) {
            $data = $this->accreditationData([$field => ['invalid']]);
            $data['association_form'] = 'editor';
            $data['association_record_id'] = self::id(10);
            $this->from($url)->patch('/admin/associations/'.self::id(10), $data)->assertRedirect($url)->assertSessionHasErrors();
            $this->get($url)->assertOk();
        }
    }

    public static function assignmentGeographyModes(): array
    {
        return [['legacy'], ['area_required'], ['direct']];
    }

    private function prepareAssignmentMode(string $mode): void
    {
        if ($mode === 'direct') {
            $this->prepareDirectGeography();
        }
        if ($mode === 'area_required') {
            $this->prepareStateThreeGeography();
        }
    }

    private function assignmentUpdateData(array $overrides = []): array
    {
        if (config('associations.direct_geography')) {
            return $this->directData($overrides, 10);
        }
        if (config('associations.geography_schema_ready')) {
            return $this->stateThreeUpdateData($overrides);
        }

        return $this->associationData($overrides, 10);
    }

    public static function assignmentCreationStates(): array
    {
        $states = [];
        foreach (['legacy', 'area_required', 'direct'] as $mode) {
            foreach (['active', 'inactive'] as $status) {
                $states[] = [$mode, $status];
            }
        }

        return $states;
    }

    #[DataProvider('assignmentCreationStates')]
    public function test_assignment_creation_preserves_both_statuses_and_audits_initial_designation(string $mode, string $status): void
    {
        $this->prepareAssignmentMode($mode);
        $data = $mode === 'direct' ? $this->directData(['status' => $status]) : $this->associationData(['status' => $status]);
        $this->post('/admin/associations', $data)->assertSessionHasNoErrors()->assertRedirect(route('admin.associations.index'));
        $created = Association::where('name', $data['name'])->sole();
        $this->assertSame($status, $created->status);
        $this->assertSame(self::id(2), $created->primary_field_officer_id);
        $this->assertSame(self::id(1), $created->created_by);
        $event = DB::table('audit_logs')->sole();
        $this->assertSame('ASSIGN FIELD OFFICER', $event->action_type);
        $this->assertSame($created->id, $event->entity_id);
        $this->assertSame(['primary_field_officer_id' => null], json_decode($event->old_values, true));
        $this->assertSame(['primary_field_officer_id' => self::id(2)], json_decode($event->new_values, true));
        $options = $this->dom($this->get('/admin/associations?editor=create')->assertOk()->getContent())
            ->query('//form[@data-association-form]//select[@name="status"]/option[@value!=""]');
        $this->assertSame(['active', 'inactive'], array_map(fn ($option) => $option->getAttribute('value'), iterator_to_array($options)));
    }

    public static function assignmentIneligibleOfficers(): array
    {
        return array_map(fn ($state) => [$state], ['inactive', 'never_activated', 'disabled_role', 'wrong_role', 'pending', 'conflict', 'relationship_conflict']);
    }

    #[DataProvider('assignmentIneligibleOfficers')]
    public function test_assignment_target_eligibility_is_shared_by_creation_and_editing(string $state): void
    {
        match ($state) {
            'inactive' => DB::table('user_profiles')->where('id', self::id(3))->update(['is_active' => false]),
            'never_activated' => DB::table('user_profiles')->where('id', self::id(3))->update(['activated_at' => null]),
            'disabled_role' => DB::table('roles')->where('id', 2)->update(['is_active' => false]),
            'wrong_role' => DB::table('user_profiles')->where('id', self::id(3))->update(['role_id' => 3]),
            'pending', 'conflict' => DB::table('user_invitations')->insert(['id' => self::id(900),
                'auth_user_id' => $state === 'pending' ? self::id(3) : null, 'email' => 'synthetic@example.test',
                'status' => 'sent', 'last_error_code' => $state === 'conflict' ? 'profile_identity_conflict:'.self::id(3) : null,
                'created_at' => now(), 'updated_at' => now()]),
            'relationship_conflict' => DB::table('association_accounts')->insert(['id' => self::id(900), 'user_id' => self::id(3),
                'association_id' => self::id(10), 'is_active' => false]),
        };
        $before = $this->associationWriteSnapshot();
        foreach (['active', 'inactive'] as $status) {
            $this->postJson('/admin/associations', $this->associationData(['status' => $status, 'primary_field_officer_id' => self::id(3)]))
                ->assertUnprocessable()->assertJsonValidationErrors('primary_field_officer_id');
        }
        $this->patchJson('/admin/associations/'.self::id(10), $this->assignmentUpdateData(['primary_field_officer_id' => self::id(3)]))
            ->assertUnprocessable()->assertJsonValidationErrors('primary_field_officer_id');
        $this->assertSame($before, $this->associationWriteSnapshot());
    }

    #[DataProvider('assignmentGeographyModes')]
    public function test_assignment_snapshot_blocks_an_editor_after_a_user_management_transfer(string $mode): void
    {
        $this->prepareAssignmentMode($mode);
        $stale = $this->assignmentUpdateData(['acronym' => 'Stale metadata']);
        $request = new Request;
        $request->attributes->set('current_user', UserProfile::with('role')->findOrFail(self::id(1)));
        app(FieldOfficerAssignmentService::class)->assign($request, UserProfile::findOrFail(self::id(3)), [
            'association_ids' => [self::id(10)], 'expected_officers' => [self::id(10) => self::id(2)], 'confirm_reassignment' => '1',
        ]);
        $before = $this->associationWriteSnapshot();
        $this->patchJson('/admin/associations/'.self::id(10), $stale)->assertStatus(409)
            ->assertJsonValidationErrors('expected_primary_field_officer_id');
        $this->assertSame($before, $this->associationWriteSnapshot());
    }

    #[DataProvider('assignmentGeographyModes')]
    public function test_assignment_metadata_edits_retain_unavailable_officers_without_offering_new_assignments(string $mode): void
    {
        $this->prepareAssignmentMode($mode);
        DB::table('user_profiles')->where('role_id', 2)->update(['is_active' => false]);
        $edit = $this->get('/admin/associations?editor='.self::id(10))->assertOk()->assertSee('retained current assignment');
        $document = $this->dom($edit->getContent());
        $this->assertSame(self::id(2), $document->query('//input[@name="expected_primary_field_officer_id"]')->item(0)->getAttribute('value'));
        $this->assertFalse($document->query('//button[@data-association-submit]')->item(0)->hasAttribute('disabled'));
        $create = $this->dom($this->get('/admin/associations?editor=create')->assertOk()->getContent());
        $this->assertSame(0, $create->query('//select[@name="primary_field_officer_id"]/option[@value="'.self::id(2).'"]')->length);
        $this->patch('/admin/associations/'.self::id(10), $this->assignmentUpdateData(['acronym' => 'Metadata only']))
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(self::id(2), Association::findOrFail(self::id(10))->primary_field_officer_id);
        $this->assertSame('Metadata only', Association::findOrFail(self::id(10))->acronym);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    #[DataProvider('assignmentGeographyModes')]
    public function test_assignment_editor_transfers_change_access_once_and_preserve_organizational_history(string $mode): void
    {
        $this->prepareAssignmentMode($mode);
        $history = collect(['members', 'projects', 'trainings', 'program_activity_records'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
        $this->patch('/admin/associations/'.self::id(10), $this->assignmentUpdateData(['primary_field_officer_id' => self::id(3)]))
            ->assertSessionHasNoErrors()->assertRedirect();
        $event = DB::table('audit_logs')->sole();
        $this->assertSame('REASSIGN FIELD OFFICER', $event->action_type);
        $this->assertSame(self::id(1), $event->actor_user_id);
        $this->assertSame(['primary_field_officer_id' => self::id(2)], json_decode($event->old_values, true));
        $this->assertSame(['primary_field_officer_id' => self::id(3)], json_decode($event->new_values, true));
        $this->patch('/admin/associations/'.self::id(10), $this->assignmentUpdateData(['primary_field_officer_id' => self::id(3)]))
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('audit_logs', 1);
        foreach ($history as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->orderBy('id')->get()->toJson());
        }
        $this->identity = self::id(2);
        $this->get('/field-officer/associations/'.self::id(10))->assertForbidden();
        $this->identity = self::id(3);
        $this->get('/field-officer/associations/'.self::id(10))->assertOk();
    }

    public static function assignmentLifecycleStates(): array
    {
        $states = [];
        foreach (['legacy', 'area_required', 'direct'] as $mode) {
            foreach (['inactive', 'archived', 'deactivating'] as $state) {
                $states[] = [$mode, $state];
            }
        }

        return $states;
    }

    #[DataProvider('assignmentLifecycleStates')]
    public function test_assignment_editor_cannot_transfer_a_read_only_or_deactivating_association(string $mode, string $state): void
    {
        $this->prepareAssignmentMode($mode);
        if ($state === 'inactive') {
            DB::table('associations')->where('id', self::id(10))->update(['status' => 'inactive']);
        }
        if ($state === 'archived') {
            DB::table('associations')->where('id', self::id(10))->update(['archived_at' => now()]);
        }
        $before = $this->associationWriteSnapshot();
        $this->patchJson('/admin/associations/'.self::id(10), $this->assignmentUpdateData([
            'primary_field_officer_id' => self::id(3), 'status' => $state === 'deactivating' ? 'inactive' : 'active',
        ]))->assertUnprocessable();
        $this->assertSame($before, $this->associationWriteSnapshot());
        if ($state === 'inactive') {
            $this->patch('/admin/associations/'.self::id(10), $this->assignmentUpdateData(['status' => 'active']))
                ->assertSessionHasNoErrors()->assertRedirect();
            $this->assertSame(self::id(2), Association::findOrFail(self::id(10))->primary_field_officer_id);
            $this->assertDatabaseCount('audit_logs', 0);
            $this->patch('/admin/associations/'.self::id(10), $this->assignmentUpdateData(['primary_field_officer_id' => self::id(3)]))
                ->assertSessionHasNoErrors()->assertRedirect();
            $this->assertDatabaseCount('audit_logs', 1);
        }
    }

    public static function assignmentAuditOperations(): array
    {
        return [['active'], ['inactive'], ['edit']];
    }

    #[DataProvider('assignmentAuditOperations')]
    public function test_assignment_audit_failure_rolls_back_creation_or_the_entire_editor_update(string $operation): void
    {
        $this->withoutExceptionHandling();
        DB::statement("CREATE TRIGGER reject_assignment_audit BEFORE INSERT ON audit_logs WHEN NEW.action_type IN ('ASSIGN FIELD OFFICER', 'REASSIGN FIELD OFFICER') BEGIN SELECT RAISE(ABORT, 'Synthetic assignment audit failure'); END");
        $before = $this->associationWriteSnapshot();
        try {
            if ($operation === 'edit') {
                $this->patch('/admin/associations/'.self::id(10), $this->assignmentUpdateData(['primary_field_officer_id' => self::id(3), 'acronym' => 'Rollback']));
            } else {
                $data = $this->associationData(['status' => $operation]);
                if ($operation === 'active') {
                    $data['registration'] = ['agency_id' => 1, 'registration_number' => 'TASK11B-ROLLBACK', 'registration_date' => '2024-01-01'];
                }
                $this->post('/admin/associations', $data);
            }
            $this->fail('A required assignment audit must not be skipped.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Synthetic assignment audit failure', $exception->getMessage());
        }
        $this->assertSame($before, $this->associationWriteSnapshot());
    }

    public static function assignmentAdministratorChanges(): array
    {
        $states = [];
        foreach (['create', 'edit', 'batch'] as $operation) {
            foreach (['profile', 'role', 'invitation'] as $state) {
                $states[] = [$operation, $state];
            }
        }

        return $states;
    }

    #[DataProvider('assignmentAdministratorChanges')]
    public function test_assignment_writers_recheck_administrators_after_earlier_authorization(string $operation, string $state): void
    {
        $actor = UserProfile::with('role')->findOrFail(self::id(1));
        $this->assertTrue($actor->canAccessApplication());
        match ($state) {
            'profile' => DB::table('user_profiles')->where('id', $actor->id)->update(['is_active' => false]),
            'role' => DB::table('roles')->where('id', 1)->update(['is_active' => false]),
            'invitation' => DB::table('user_invitations')->insert(['id' => self::id(900), 'auth_user_id' => $actor->id,
                'email' => 'synthetic@example.test', 'status' => 'sent', 'created_at' => now(), 'updated_at' => now()]),
        };
        $before = $this->associationWriteSnapshot();
        try {
            if ($operation === 'create') {
                app(AssociationService::class)->create($this->associationData(), $actor);
            }
            if ($operation === 'edit') {
                app(AssociationService::class)->update(Association::findOrFail(self::id(10)), $this->assignmentUpdateData(), $actor);
            }
            if ($operation === 'batch') {
                $request = new Request;
                $request->attributes->set('current_user', $actor);
                app(FieldOfficerAssignmentService::class)->assign($request, UserProfile::findOrFail(self::id(3)), [
                    'association_ids' => [self::id(10)], 'expected_officers' => [self::id(10) => self::id(2)], 'confirm_reassignment' => '1',
                ]);
            }
            $this->fail('The changed Administrator eligibility must be rechecked.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame($before, $this->associationWriteSnapshot());
    }

    public function test_assignment_snapshot_is_required_and_cannot_bypass_new_target_eligibility(): void
    {
        $data = $this->assignmentUpdateData();
        unset($data['expected_primary_field_officer_id']);
        $this->patchJson('/admin/associations/'.self::id(10), $data)->assertUnprocessable()->assertJsonValidationErrors('expected_primary_field_officer_id');
        $this->postJson('/admin/associations', $this->associationData(['expected_primary_field_officer_id' => self::id(2)]))
            ->assertUnprocessable()->assertJsonValidationErrors('expected_primary_field_officer_id');
        DB::table('user_profiles')->where('id', self::id(3))->update(['is_active' => false]);
        $before = $this->associationWriteSnapshot();
        $this->patchJson('/admin/associations/'.self::id(10), $this->assignmentUpdateData([
            'primary_field_officer_id' => self::id(3), 'expected_primary_field_officer_id' => self::id(3),
        ]))->assertStatus(409);
        $this->patchJson('/admin/associations/'.self::id(10), $this->assignmentUpdateData(['primary_field_officer_id' => self::id(3)]))
            ->assertUnprocessable()->assertJsonValidationErrors('primary_field_officer_id');
        $this->assertSame($before, $this->associationWriteSnapshot());
    }
}
