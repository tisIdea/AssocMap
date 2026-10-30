<?php

namespace Tests\Feature;

use App\Services\Auth\SupabaseAuthService;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedDatabaseManager;
use Tests\TestCase;

class AssociationProgramReadTest extends TestCase
{
    private string $identity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertInstanceOf(IsolatedDatabaseManager::class, DB::getFacadeRoot());
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32)), 'app.debug' => false,
            'associations.geography_schema_ready' => true, 'associations.direct_geography' => true]);
        $this->withoutVite();
        $this->schema();
        $this->fixtures();
        $this->identity = $this->id(1);
        $auth = Mockery::mock(SupabaseAuthService::class);
        $auth->shouldReceive('getUser')->andReturnUsing(fn () => ['id' => $this->identity, 'email' => 'synthetic@example.test']);
        $this->app->instance(SupabaseAuthService::class, $auth);
        $this->withSession(['supabase_access_token' => 'synthetic-program-session']);
    }

    protected function tearDown(): void
    {
        try { Http::assertNothingSent(); }
        finally { parent::tearDown(); }
    }

    private function id(int $number): string
    {
        return sprintf('88888888-8888-4888-8888-%012d', $number);
    }

    private function schema(): void
    {
        Schema::create('roles', function (Blueprint $table) { $table->id(); $table->string('code'); $table->boolean('is_active'); $table->string('name')->nullable(); $table->text('description')->nullable(); $table->timestamps(); });
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->integer('role_id'); $table->string('display_name');
            $table->boolean('is_active'); $table->timestamp('activated_at')->nullable(); $table->timestamps();
        });
        Schema::create('user_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('auth_user_id')->nullable(); $table->string('email');
            $table->string('status'); $table->string('last_error_code')->nullable(); $table->timestamp('accepted_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable(); $table->timestamps();
        });
        Schema::create('municipalities', function (Blueprint $table) { $table->id(); $table->string('name'); });
        Schema::create('barangays', function (Blueprint $table) { $table->id(); $table->integer('municipality_id'); $table->string('name'); });
        Schema::create('areas', function (Blueprint $table) { $table->id(); $table->integer('barangay_id'); $table->string('address'); });
        Schema::create('associations', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->string('name'); $table->integer('barangay_id'); $table->integer('area_id')->nullable();
            $table->uuid('primary_field_officer_id'); $table->string('status'); $table->timestamp('archived_at')->nullable();
        });
        // Deliberately permit inconsistent links for denial scenarios.
        Schema::create('association_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('user_id'); $table->uuid('association_id'); $table->boolean('is_active');
        });
        Schema::create('projects', function (Blueprint $table) { $table->uuid('id')->primary(); $table->uuid('association_id'); $table->string('title'); });
        foreach (['trainings', 'program_activity_records'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->uuid('id')->primary(); $table->uuid('association_id')->nullable(); $table->uuid('related_project_id')->nullable();
                $table->string('title'); $table->text('description')->nullable(); $table->integer('program_year')->default(2026);
                $table->string('venue')->nullable(); $table->string('conducted_by')->nullable(); $table->string('status')->default('completed');
                $table->timestamp('archived_at')->nullable(); $table->text('remarks')->nullable();
                if ($name === 'trainings') { $table->date('start_date')->nullable(); $table->date('end_date')->nullable(); }
                else $table->date('activity_date')->nullable();
            });
        }
        Schema::create('members', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('association_id'); $table->string('first_name'); $table->string('middle_name')->nullable();
            $table->string('last_name'); $table->string('suffix')->nullable(); $table->string('contact_number')->nullable();
            $table->date('birth_date')->nullable(); $table->string('member_status')->default('active'); $table->timestamp('archived_at')->nullable();
        });
        Schema::create('training_participants', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('training_id'); $table->uuid('member_id'); $table->string('attendance_status');
            $table->text('remarks')->nullable(); $table->unique(['training_id', 'member_id']);
        });
    }

    private function fixtures(): void
    {
        foreach ([1 => 'association_account', 2 => 'system_admin', 3 => 'field_officer'] as $id => $role) {
            DB::table('roles')->insert(['id' => $id, 'code' => $role, 'is_active' => true]);
        }
        foreach ([1 => 1, 2 => 1, 3 => 2, 4 => 3] as $id => $role) {
            DB::table('user_profiles')->insert(['id' => $this->id($id), 'role_id' => $role,
                'display_name' => 'Synthetic account '.$id, 'is_active' => true, 'activated_at' => now()]);
        }
        DB::table('municipalities')->insert(['id' => 1, 'name' => 'Synthetic municipality']);
        DB::table('barangays')->insert(['id' => 1, 'municipality_id' => 1, 'name' => 'Synthetic barangay']);
        foreach ([10 => 'Own association', 11 => 'Foreign association'] as $id => $name) {
            DB::table('associations')->insert(['id' => $this->id($id), 'name' => $name, 'barangay_id' => 1,
                'area_id' => null, 'primary_field_officer_id' => $this->id(4), 'status' => 'active']);
        }
        foreach ([1 => 10, 2 => 11] as $user => $association) {
            DB::table('association_accounts')->insert(['id' => $this->id(20 + $user), 'user_id' => $this->id($user),
                'association_id' => $this->id($association), 'is_active' => true]);
        }
        DB::table('projects')->insert([
            ['id' => $this->id(201), 'association_id' => $this->id(10), 'title' => 'Own related project'],
            ['id' => $this->id(202), 'association_id' => $this->id(11), 'title' => 'Foreign related project'],
        ]);
        foreach (['trainings' => 100, 'program_activity_records' => 150] as $table => $base) {
            $this->record($table, $base + 1, ['title' => 'Own saved record', 'related_project_id' => $this->id(201),
                'description' => '<b>Saved description</b>', 'venue' => 'Saved venue', 'conducted_by' => 'Saved conductor', 'remarks' => 'Private internal remarks']);
            $this->record($table, $base + 2, ['title' => 'Retained link record', 'related_project_id' => $this->id(202)]);
            $this->record($table, $base + 3, ['title' => 'Foreign saved record', 'association_id' => $this->id(11)]);
            $this->record($table, $base + 4, ['title' => 'Archived saved record', 'archived_at' => '2026-01-01']);
        }
        $this->record('program_activity_records', 155, ['title' => 'Program-wide excluded record', 'association_id' => null]);
        DB::table('program_activity_records')->where('id', $this->id(151))->update(['status' => 'ongoing', 'activity_date' => '2026-10-20']);
        foreach ([301 => ['Present', 10], 302 => ['Absent', 10], 303 => ['Foreign attendee', 11]] as $id => [$name, $association]) {
            DB::table('members')->insert(['id' => $this->id($id), 'association_id' => $this->id($association),
                'first_name' => 'Synthetic', 'last_name' => $name, 'contact_number' => 'PRIVATE-CONTACT-CANARY', 'birth_date' => '1990-01-01']);
        }
        DB::table('members')->where('id', $this->id(302))->update(['archived_at' => '2026-01-01', 'member_status' => 'inactive']);
        foreach ([501 => [101, 301, 'present'], 502 => [101, 302, 'absent'], 503 => [101, 303, 'present'], 504 => [103, 301, 'present']] as $id => [$training, $member, $status]) {
            DB::table('training_participants')->insert(['id' => $this->id($id), 'training_id' => $this->id($training),
                'member_id' => $this->id($member), 'attendance_status' => $status, 'remarks' => 'Private attendance remarks']);
        }
    }

    private function record(string $table, int $id, array $changes = []): void
    {
        DB::table($table)->insert(array_replace(['id' => $this->id($id), 'association_id' => $this->id(10),
            'title' => 'Synthetic record '.$id], $changes));
    }

    private function paths(): array
    {
        return ['/association/trainings', '/association/trainings/'.$this->id(101),
            '/association/program-activities', '/association/program-activities/'.$this->id(151)];
    }

    public function test_connected_account_programs_restore_own_registers_and_never_link_foreign_parents(): void
    {
        foreach (['trainings' => 101, 'program-activities' => 151] as $module => $id) {
            $origin = '/association/'.$module.'?search=Own&year=2026&page=1';
            $page = $this->get('/association/'.$module.'/'.$this->id($id).'?'.http_build_query(['return_to' => $origin]))->assertOk();
            $page->assertSee('href="'.e(url($origin)).'" data-workflow-back', false)
                ->assertSee('href="'.route('association.projects.show', $this->id(201)).'"', false)
                ->assertDontSee('/admin/')->assertDontSee('/field-officer/');
            $foreign = '/association/projects/'.$this->id(202);
            $this->get('/association/'.$module.'/'.$this->id($id + 1).'?'.http_build_query(['return_to' => $foreign]))->assertOk()
                ->assertDontSee($foreign)->assertSee('Back to '.($module === 'trainings' ? 'Trainings' : 'Program Activities'));
        }
    }

    public function test_directories_use_server_ownership_exclude_programwide_and_constrain_related_projects(): void
    {
        foreach (['trainings', 'program-activities'] as $module) {
            $page = $this->get('/association/'.$module.'?association_id='.$this->id(11))->assertOk()
                ->assertSee('Own saved record')->assertSee('Archived saved record')->assertSee('Own related project')
                ->assertSee('Not available within your association')->assertDontSee('Foreign saved record')
                ->assertDontSee('Foreign related project')->assertDontSee($this->id(202))->assertDontSee('Program-wide excluded record');
            $this->assertSame(3, $page->viewData('records')->total());
            $this->assertStringContainsString('no-store', $page->headers->get('Cache-Control'));
        }
    }

    public function test_training_attendance_is_scoped_counts_all_authorized_records_and_minimizes_personal_data(): void
    {
        $before = DB::table('training_participants')->orderBy('id')->get()->toJson();
        $page = $this->get('/association/trainings/'.$this->id(101))->assertOk()
            ->assertSee('Synthetic Present')->assertSee('Synthetic Absent')->assertSee('Present: 1. Absent: 1.')
            ->assertDontSee('Foreign attendee')->assertDontSee('PRIVATE-CONTACT-CANARY')->assertDontSee('1990-01-01')
            ->assertDontSee('Private attendance remarks')->assertDontSee('Private internal remarks')
            ->assertDontSee('<b>Saved description</b>', false)->assertSee('<b>Saved description</b>');
        $this->assertSame(2, $page->viewData('participants')->total());
        $this->assertArrayNotHasKey('contact_number', $page->viewData('participants')->first()->getAttributes());
        $this->assertSame($before, DB::table('training_participants')->orderBy('id')->get()->toJson());
    }

    public function test_detail_pages_show_saved_fields_without_exposing_foreign_project_links(): void
    {
        $this->get('/association/program-activities/'.$this->id(151))->assertOk()->assertSee('Ongoing')
            ->assertSee('Oct 20, 2026')->assertSee('Saved venue')->assertSee('Saved conductor')->assertSee('Own related project')
            ->assertDontSee('Recorded attendance')->assertDontSee('Private internal remarks');
        foreach (['trainings' => 102, 'program-activities' => 152] as $module => $id) {
            $this->get('/association/'.$module.'/'.$this->id($id))->assertOk()->assertSee('Not available within your association')
                ->assertDontSee('Foreign related project')->assertDontSee($this->id(202));
        }
        $this->get('/association/trainings/'.$this->id(104))->assertOk()->assertSee('This training is archived')
            ->assertSee('No attendance recorded for your association')->assertSee('Present: 0. Absent: 0.');
        $this->get('/association/program-activities/'.$this->id(154))->assertOk()->assertSee('This Program Activity is archived');
    }

    public function test_directories_paginate_twenty_five_preserve_valid_filters_and_treat_search_literally(): void
    {
        foreach (['trainings' => 'trainings', 'program-activities' => 'program_activity_records'] as $module => $table) {
            for ($id = 1; $id <= 27; $id++) $this->record($table, 1000 + $id, ['title' => sprintf('Batched %02d', $id), 'program_year' => 2025]);
            $page = $this->get('/association/'.$module.'?search=Batched&year=2025&association_id='.$this->id(11))->assertOk();
            $this->assertSame(27, $page->viewData('records')->total());
            $this->assertCount(25, $page->viewData('records'));
            $next = $page->viewData('records')->nextPageUrl();
            $this->assertStringContainsString('search=Batched', $next);
            $this->assertStringContainsString('year=2025', $next);
            $this->assertStringNotContainsString('association_id', $next);
            $this->assertCount(2, $this->get($next)->assertOk()->viewData('records'));
            $this->record($table, 2000, ['title' => 'Literal 100%_!']);
            $this->assertSame(1, $this->get('/association/'.$module.'?search='.rawurlencode('100%_!'))->assertOk()->viewData('records')->total());
        }
    }

    public function test_attendance_counts_remain_complete_on_the_second_roster_page(): void
    {
        for ($id = 1; $id <= 27; $id++) {
            DB::table('members')->insert(['id' => $this->id(3000 + $id), 'association_id' => $this->id(10),
                'first_name' => 'Roster', 'last_name' => sprintf('Member %02d', $id)]);
            DB::table('training_participants')->insert(['id' => $this->id(4000 + $id), 'training_id' => $this->id(101),
                'member_id' => $this->id(3000 + $id), 'attendance_status' => 'present']);
        }
        $page = $this->get('/association/trainings/'.$this->id(101).'?association_id='.$this->id(11))->assertOk()->assertSee('Present: 28. Absent: 1.');
        $this->assertCount(25, $page->viewData('participants'));
        $next = $page->viewData('participants')->nextPageUrl();
        $this->assertStringContainsString('#training-attendance', $next);
        $this->assertStringNotContainsString('association_id', $next);
        $second = $this->get($next)->assertOk()->assertSee('Present: 28. Absent: 1.');
        $this->assertCount(4, $second->viewData('participants'));
    }

    public function test_foreign_nonexistent_and_programwide_records_are_not_found(): void
    {
        foreach (['trainings' => [103, 999999], 'program-activities' => [153, 155, 999999]] as $module => $ids) {
            foreach ($ids as $id) $this->get('/association/'.$module.'/'.$this->id($id))->assertNotFound();
        }
    }

    #[DataProvider('ineligibleLinks')]
    public function test_new_pages_use_batch_one_association_eligibility(string $restriction): void
    {
        match ($restriction) {
            'inactive_link' => DB::table('association_accounts')->where('user_id', $this->id(1))->update(['is_active' => false]),
            'missing_link' => DB::table('association_accounts')->where('user_id', $this->id(1))->delete(),
            'duplicate_user' => DB::table('association_accounts')->insert(['id' => $this->id(30), 'user_id' => $this->id(1), 'association_id' => $this->id(11), 'is_active' => true]),
            'duplicate_association' => DB::table('association_accounts')->insert(['id' => $this->id(30), 'user_id' => $this->id(3), 'association_id' => $this->id(10), 'is_active' => true]),
            'inactive_association' => DB::table('associations')->where('id', $this->id(10))->update(['status' => 'inactive']),
            'archived_association' => DB::table('associations')->where('id', $this->id(10))->update(['archived_at' => '2026-01-01']),
        };
        foreach ($this->paths() as $path) $this->get($path)->assertForbidden();
    }

    public static function ineligibleLinks(): array
    {
        return array_map(fn ($case) => [$case], ['inactive_link', 'missing_link', 'duplicate_user', 'duplicate_association', 'inactive_association', 'archived_association']);
    }

    public function test_revoked_accounts_and_staff_cannot_use_association_routes(): void
    {
        foreach ([3, 4] as $user) {
            $this->identity = $this->id($user);
            foreach ($this->paths() as $path) $this->get($path)->assertForbidden();
        }
        $this->identity = $this->id(1);
        DB::table('user_profiles')->where('id', $this->identity)->update(['is_active' => false]);
        foreach ($this->paths() as $path) {
            $this->withSession(['supabase_access_token' => 'synthetic-program-session'])->get($path)
                ->assertRedirect(route('login'))->assertSessionMissing('supabase_access_token');
        }
    }

    public function test_guests_are_redirected_before_any_program_information_is_returned(): void
    {
        $this->app['session.store']->forget('supabase_access_token');
        foreach ($this->paths() as $path) {
            $this->get($path)->assertRedirect(route('login'))->assertDontSee('Own saved record');
        }
    }

    public function test_sidebar_renders_visible_program_sections_with_unique_links_and_active_destination(): void
    {
        foreach ($this->paths() as $path) {
            $page = $this->get($path)->assertOk();
            $dom = new DOMDocument();
            @$dom->loadHTML($page->getContent());
            $xpath = new DOMXPath($dom);
            $sidebar = '//aside[@id="association-sidebar" and @data-workspace-sidebar]';
            $navigation = $sidebar.'/nav[@aria-label="Main navigation"]';
            $this->assertSame(1, $xpath->query($navigation)->length);
            $this->assertSame(0, $xpath->query($navigation.'//*[@data-disclosure or @hidden]')->length);
            foreach (['association-projects-activities' => 'PROJECTS & ACTIVITIES',
                'association-monitoring-insights' => 'MONITORING & INSIGHTS'] as $id => $label) {
                $section = $navigation.'/section[@aria-labelledby="'.$id.'"]';
                $this->assertSame(1, $xpath->query($section)->length);
                $this->assertSame($label, $xpath->evaluate('string('.$section.'/h2[@id="'.$id.'"])'));
                $this->assertSame(3, $xpath->query($section.'/a')->length);
            }
            foreach (['projects' => 'Projects', 'trainings' => 'Trainings',
                'monitoring' => 'Monitoring', 'program-activities' => 'Program Activities'] as $module => $label) {
                $url = route('association.'.$module.'.index');
                $links = $xpath->query($navigation.'//a[@href="'.$url.'"]');
                $this->assertSame(1, $links->length);
                $this->assertSame($label, trim($links->item(0)->textContent));
                $this->assertSame($label, $links->item(0)->getAttribute('aria-label'));
            }
            $module = str_contains($path, 'trainings') ? 'trainings' : 'program-activities';
            $active = $xpath->query($navigation.'//a[@aria-current="page"]');
            $this->assertSame(1, $active->length);
            $this->assertSame(route('association.'.$module.'.index'), $active->item(0)->getAttribute('href'));
            $this->assertStringContainsString('active', $active->item(0)->getAttribute('class'));
            $this->assertSame(0, $xpath->query('//*[@data-association-'.$module.']//form[not(@method="GET")]')->length);
        }
    }

    public function test_validation_and_readonly_routes_do_not_enable_writes(): void
    {
        foreach (['trainings' => 101, 'program-activities' => 151] as $module => $id) {
            foreach (['page=0', 'search[]=x', 'year=1900'] as $query) $this->getJson('/association/'.$module.'?'.$query)->assertUnprocessable();
            $this->getJson('/association/'.$module.'/'.$this->id($id).'?page=0')->assertUnprocessable();
            $this->post('/association/'.$module, [])->assertStatus(405);
            $this->patch('/association/'.$module.'/'.$this->id($id), [])->assertStatus(405);
        }
    }

    public function test_database_failure_returns_only_safe_unavailable_information(): void
    {
        Schema::drop('training_participants');
        $this->get('/association/trainings/'.$this->id(101))->assertStatus(503)->assertSee('Training information is temporarily unavailable')
            ->assertDontSee('SQLSTATE')->assertDontSee('Synthetic Present');
        Schema::drop('program_activity_records');
        $this->get('/association/program-activities')->assertStatus(503)->assertSee('Program Activity information is temporarily unavailable')
            ->assertDontSee('SQLSTATE')->assertDontSee('Own saved record');
    }

    public function test_directory_query_count_does_not_grow_with_each_row(): void
    {
        foreach (['trainings' => 'trainings', 'program-activities' => 'program_activity_records'] as $module => $table) {
            $count = function () use ($module): int {
                DB::enableQueryLog();
                DB::flushQueryLog();
                try {
                    $this->get('/association/'.$module)->assertOk();
                    return count(DB::getQueryLog());
                } finally { DB::disableQueryLog(); }
            };
            $before = $count();
            for ($id = 1; $id <= 30; $id++) $this->record($table, 5000 + $id, ['related_project_id' => $this->id(201)]);
            $this->assertSame($before, $count());
        }
    }
}
