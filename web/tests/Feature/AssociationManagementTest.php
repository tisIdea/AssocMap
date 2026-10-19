<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\Member;
use App\Models\UserProfile;
use App\Services\AssociationDirectoryQueryService;
use App\Services\SupabaseAuthService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AssociationManagementTest extends TestCase
{
    protected string $identity;

    protected static function id(int $number): string
    {
        return sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d', $number);
    }

    protected function setUp(): void
    {
        parent::setUp();
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
        try { Http::assertNothingSent(); parent::tearDown(); }
        finally { HandleExceptions::flushState($this); }
    }

    private function schema(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->id(); $t->string('code'); $t->string('name'); $t->boolean('is_active'); $t->timestamps();
        });
        Schema::create('user_profiles', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->integer('role_id'); $t->string('display_name'); $t->boolean('is_active'); $t->timestamps();
        });
        Schema::create('user_invitations', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('auth_user_id')->nullable(); $t->string('email'); $t->string('status');
            $t->string('last_error_code')->nullable(); $t->timestamp('accepted_at')->nullable(); $t->timestamp('last_attempt_at')->nullable(); $t->timestamps();
        });
        Schema::create('municipalities', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('name'); $t->string('province'); $t->string('region'); $t->boolean('is_active');
        });
        Schema::create('barangays', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('municipality_id'); $t->string('name'); $t->boolean('is_active');
            $t->foreign('municipality_id')->references('id')->on('municipalities')->restrictOnDelete();
        });
        Schema::create('areas', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('barangay_id'); $t->text('address'); $t->boolean('is_active');
            $t->foreign('barangay_id')->references('id')->on('barangays')->restrictOnDelete();
        });
        Schema::create('associations', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('name'); $t->string('acronym')->nullable(); $t->uuid('area_id');
            $t->uuid('primary_field_officer_id'); $t->date('saad_phase2_join_date'); $t->string('status');
            $t->uuid('created_by')->nullable(); $t->uuid('updated_by')->nullable(); $t->uuid('archived_by')->nullable();
            $t->timestamp('archived_at')->nullable(); $t->timestamps();
            $t->foreign('area_id')->references('id')->on('areas')->restrictOnDelete();
        });
        Schema::create('association_positions', function (Blueprint $t) {
            $t->id(); $t->string('code'); $t->string('name'); $t->boolean('is_officer'); $t->boolean('is_active'); $t->integer('sort_order');
        });
        Schema::create('member_applications', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('association_id'); $t->string('status'); $t->timestamps();
        });
        Schema::create('members', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('association_id'); $t->uuid('source_application_id')->nullable();
            $t->string('first_name'); $t->string('middle_name')->nullable(); $t->string('last_name'); $t->string('suffix')->nullable();
            $t->integer('position_id'); $t->string('position_other_text')->nullable(); $t->string('member_status');
            $t->string('contact_number')->nullable(); $t->date('date_registered')->nullable(); $t->timestamp('archived_at')->nullable(); $t->timestamps();
            $t->foreign('association_id')->references('id')->on('associations')->restrictOnDelete();
        });
        DB::statement("CREATE UNIQUE INDEX test_active_executives ON members (association_id, position_id) WHERE member_status = 'active' AND archived_at IS NULL AND position_id IN (2, 3)");
        foreach (['projects', 'trainings', 'program_activity_records'] as $table) {
            Schema::create($table, function (Blueprint $t) use ($table) {
                $t->uuid('id')->primary(); $t->uuid('association_id')->nullable(); $t->string('title'); $t->string('status');
                if ($table === 'trainings') $t->date('start_date')->nullable();
                if ($table === 'program_activity_records') $t->date('activity_date')->nullable();
                $t->timestamp('archived_at')->nullable(); $t->timestamps();
            });
        }
        Schema::create('registration_agencies', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('registration_kind'); $t->boolean('is_active');
        });
        Schema::create('association_registrations', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('association_id'); $t->integer('agency_id'); $t->string('registration_number')->unique();
            $t->date('registration_date'); $t->date('expiration_date')->nullable(); $t->boolean('is_primary'); $t->text('remarks')->nullable(); $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('actor_user_id')->nullable(); $t->string('action_type'); $t->string('module');
            $t->string('entity_type')->nullable(); $t->string('entity_id')->nullable(); $t->text('old_values')->nullable();
            $t->text('new_values')->nullable(); $t->timestamp('performed_at');
        });
    }

    private function fixture(): void
    {
        foreach ([1 => 'system_admin', 2 => 'field_officer', 3 => 'association_account'] as $role => $code) {
            DB::table('roles')->insert(['id' => $role, 'code' => $code, 'name' => $code, 'is_active' => true]);
        }
        foreach ([1 => [1, 'Synthetic Admin'], 2 => [2, 'Assigned Officer'], 3 => [2, 'Other Officer'], 4 => [3, 'Association Account']] as $id => [$role, $name]) {
            DB::table('user_profiles')->insert(['id' => self::id($id), 'role_id' => $role, 'display_name' => $name,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
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
                if ($table === 'trainings') $row['start_date'] = '2025-02-01';
                if ($table === 'program_activity_records') $row['activity_date'] = '2025-02-01';
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
        $old = libxml_use_internal_errors(true); $doc->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors(); libxml_use_internal_errors($old);

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
            $this->patchJson('/admin/associations/'.self::id(10), $data)->assertUnprocessable()->assertJsonValidationErrors('area_id');
        }
        $this->assertSame(4, DB::table('associations')->count());
        $this->patch('/admin/associations/'.self::id(10), $this->associationData(['name' => 'Alpha Association', 'acronym' => 'UPDATED']))
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
            foreach ($response->viewData('associations') as $association) $this->assertSame(self::id(2), $association->primary_field_officer_id);
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
                if ($route) $response->assertSee(route($prefix.'.'.$route.'.show', self::id($id)), false);
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
            foreach (['trainings', 'activities'] as $section) $this->get('/field-officer/associations/'.self::id($id).'?section='.$section)->assertForbidden();
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

    private function associationData(array $overrides = []): array
    {
        return array_replace(['name' => 'Created association', 'area_id' => self::id(40), 'primary_field_officer_id' => self::id(2),
            'saad_phase2_join_date' => '2025-02-01', 'status' => 'active'], $overrides);
    }

    public function test_existing_admin_crud_and_reactivation_keep_status_and_relationships(): void
    {
        $this->post('/admin/associations', $this->associationData())->assertRedirect(route('admin.associations.index'));
        $this->assertSame(5, DB::table('associations')->count());
        $before = DB::table('members')->orderBy('id')->get()->toJson();
        $this->patch('/admin/associations/'.self::id(11), $this->associationData(['name' => 'Reactivated association']))->assertRedirect(route('admin.associations.index'));
        $this->assertSame('active', Association::findOrFail(self::id(11))->status);
        $this->patch('/admin/associations/'.self::id(10).'/archive')->assertRedirect(route('admin.associations.index'));
        $this->get('/admin/associations/'.self::id(10))->assertOk();
        $this->patch('/admin/associations/'.self::id(10), $this->associationData())->assertSessionHasErrors('association');
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
            if ($prefix !== 'admin') $response->assertDontSee('Other Association');
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
        $response = $this->get('/admin/associations')->assertOk();
        $this->assertSame(3, $this->dom($response->getContent())->query('//details[@data-user-actions]/summary[@aria-label]')->length);
        $this->app->bind(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, function ($app) {
            return new class($app, $app['encrypter']) extends \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken {
                protected function runningUnitTests() { return false; }
            };
        });
        $this->post('/admin/associations', $this->associationData())->assertStatus(419);
        $this->patch('/admin/associations/'.self::id(10).'/archive')->assertStatus(419);
        $this->assertNull(Association::findOrFail(self::id(10))->archived_at);
        $this->assertSame(4, DB::table('associations')->count());
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
            DB::flushQueryLog(); DB::enableQueryLog(); $value = $read(); $queries = DB::getQueryLog(); DB::disableQueryLog();
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
                DB::enableQueryLog(); DB::flushQueryLog();
                $response = $this->get('/'.$prefix.'/'.$module)->assertOk();
                $queries = count(DB::getQueryLog());
                DB::disableQueryLog();
                $this->assertInstanceOf(\Illuminate\Pagination\LengthAwarePaginator::class, $response->viewData('associations'));
                $this->assertSame(25, $response->viewData('associations')->perPage());
                fwrite(STDOUT, "\n/{$prefix}/{$module}: {$queries} queries\n");
            }
        }
        $this->identity = self::id(1);
        foreach (['create', self::id(10)] as $editor) {
            DB::enableQueryLog(); DB::flushQueryLog();
            $response = $this->get('/admin/associations?editor='.$editor)->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();
            $this->assertIsArray($response->viewData('catalog'));
            $this->assertSame($editor === 'create', $response->viewData('associationEditorRecord') === null);
            fwrite(STDOUT, "\n/admin/associations editor ".($editor === 'create' ? 'create' : 'edit').": {$queries} queries\n");
        }
        app(SupabaseAuthService::class)->shouldHaveReceived('getUser')->times(6);
    }
}
