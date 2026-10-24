<?php

namespace Tests\Feature;

use App\Models\Training;
use App\Services\GisQueryService;
use App\Services\SupabaseAuthService;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RecordNavigationTest extends TestCase
{
    use \Tests\Support\MonitoringIncomeFixture;

    private string $identity = self::ADMIN;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        Http::swap((new Factory)->preventStrayRequests());
        $this->withoutVite();
        $this->schema();
        $this->recordSchema();
        $this->fixtures();
        DB::table('projects')->where('id', self::PROJECT)->update([
            'saad_activity_id' => 1, 'fisheries_category_id' => 1, 'implementation_start_date' => '2026-01-01',
        ]);
        foreach ([1 => 'FPL_LPD', 2 => 'SP_LT', 3 => 'SYNTHETIC_ACTIVITY'] as $id => $code) {
            DB::table('saad_activities')->insert(['id' => $id, 'code' => $code, 'name' => $code, 'is_active' => true]);
        }
        DB::table('fisheries_project_categories')->insert(['id' => 1, 'name' => 'Synthetic category', 'is_active' => true]);
        DB::table('members')->insert(['id' => self::ACCOUNT, 'association_id' => self::ASSOCIATION,
            'first_name' => 'Synthetic', 'last_name' => 'Participant', 'member_status' => 'active']);
        DB::table('trainings')->insert(['id' => self::PRODUCT, 'updated_at' => '2026-01-01 00:00:00'] + $this->data('trainings'));
        DB::table('training_participants')->insert(['id' => self::RECORD, 'training_id' => self::PRODUCT,
            'member_id' => self::ACCOUNT, 'attendance_status' => 'present']);
        DB::table('program_activity_records')->insert(['id' => self::PRODUCT] + $this->data('program-activities'));
        $auth = Mockery::mock(SupabaseAuthService::class);
        $auth->shouldReceive('getUser')->andReturnUsing(fn () => ['id' => $this->identity]);
        $this->app->instance(SupabaseAuthService::class, $auth);
        $gis = Mockery::mock(GisQueryService::class);
        $gis->shouldReceive('projectLocations')->andReturn([]);
        $this->app->instance(GisQueryService::class, $gis);
        $this->withSession(['supabase_access_token' => 'synthetic-test-token']);
    }

    protected function tearDown(): void
    {
        try { Http::assertNothingSent(); parent::tearDown(); }
        finally { HandleExceptions::flushState($this); }
    }

    private function recordSchema(): void
    {
        Schema::table('associations', function (Blueprint $table) { $table->integer('area_id')->nullable(); });
        Schema::table('projects', function (Blueprint $table) {
            $table->integer('saad_activity_id')->nullable(); $table->integer('fisheries_category_id')->nullable();
            $table->integer('commodity_id')->nullable(); $table->text('description')->nullable();
            $table->date('implementation_start_date')->nullable(); $table->date('implementation_end_date')->nullable();
            $table->text('remarks')->nullable(); $table->text('closure_remarks')->nullable();
            $table->uuid('closed_by')->nullable(); $table->uuid('created_by')->nullable(); $table->uuid('updated_by')->nullable();
        });
        Schema::table('project_products', function (Blueprint $table) { $table->text('description')->nullable(); $table->timestamps(); });
        Schema::table('project_materials', function (Blueprint $table) {
            $table->string('item_name')->nullable(); $table->decimal('quantity_planned')->nullable();
            $table->decimal('quantity_delivered')->nullable(); $table->string('unit')->nullable();
            $table->decimal('unit_cost')->nullable(); $table->string('delivery_status')->nullable();
            $table->date('delivery_date')->nullable(); $table->text('remarks')->nullable(); $table->timestamps();
        });
        Schema::create('saad_activities', function (Blueprint $table) {
            $table->id(); $table->string('code'); $table->string('name'); $table->boolean('is_active'); $table->timestamps();
        });
        Schema::create('fisheries_project_categories', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->boolean('is_active'); $table->timestamps();
        });
        Schema::create('commodities', function (Blueprint $table) {
            $table->id(); $table->integer('category_id'); $table->string('name'); $table->string('source')->nullable();
            $table->boolean('is_active'); $table->timestamps();
        });
        Schema::create('members', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('association_id'); $table->string('first_name');
            $table->string('last_name'); $table->string('middle_name')->nullable(); $table->string('suffix')->nullable();
            $table->string('member_status'); $table->timestamp('archived_at')->nullable(); $table->timestamps();
        });
        foreach (['trainings', 'program_activity_records'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->uuid('id')->primary(); $table->uuid('association_id')->nullable(); $table->uuid('related_project_id')->nullable();
                $table->integer('saad_activity_id'); $table->string('title'); $table->text('description')->nullable();
                $table->integer('program_year'); $table->string('venue')->nullable(); $table->string('conducted_by')->nullable();
                $table->string('status'); $table->text('remarks')->nullable(); $table->timestamp('archived_at')->nullable();
                $table->uuid('archived_by')->nullable(); $table->uuid('created_by')->nullable(); $table->uuid('updated_by')->nullable();
                if ($name === 'trainings') {
                    $table->date('start_date'); $table->date('end_date')->nullable(); $table->decimal('training_cost')->nullable();
                } else {
                    $table->date('activity_date'); $table->uuid('conducted_by_user_id')->nullable();
                }
                $table->timestamps();
            });
        }
        Schema::create('training_participants', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('training_id'); $table->uuid('member_id');
            $table->string('attendance_status'); $table->text('remarks')->nullable(); $table->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->string('entity_type'); $table->uuid('entity_id');
            $table->string('action_type'); $table->timestamp('performed_at');
        });
    }

    private function data(string $module): array
    {
        $data = ['association_id' => self::ASSOCIATION, 'title' => 'Synthetic '.$module, 'program_year' => 2026];
        return $data + match ($module) {
            'projects' => ['fisheries_category_id' => 1, 'implementation_start_date' => '2026-01-01', 'status' => 'ongoing'],
            'trainings' => ['saad_activity_id' => 2, 'related_project_id' => self::PROJECT, 'start_date' => '2026-01-01', 'status' => 'scheduled'],
            default => ['saad_activity_id' => 3, 'related_project_id' => self::PROJECT, 'activity_date' => '2026-01-01', 'status' => 'planned'],
        };
    }

    private function payload(string $module): array
    {
        $data = $this->data($module);
        $marker = match ($module) { 'projects' => 'project', 'trainings' => 'training', default => 'program_activity' };
        $data[$marker.'_form'] = 'editor';
        $data[$marker.'_record_id'] = $this->id($module);
        if ($module === 'trainings') {
            $data['expected_updated_at'] = Training::findOrFail(self::PRODUCT)->updated_at->format('Y-m-d\TH:i:s.uP');
            $data['participants'] = [['id' => self::RECORD, 'member_id' => self::ACCOUNT, 'attendance_status' => 'absent', 'remarks' => 'Corrected']];
        }
        if ($module === 'projects') {
            $data['products'] = [['id' => self::PRODUCT, 'product_name' => 'Synthetic fish', 'product_type' => 'raw', 'default_unit' => 'kg', 'is_active' => true]];
        }
        return $data;
    }

    private function id(string $module): string { return $module === 'projects' ? self::PROJECT : self::PRODUCT; }
    private function table(string $module): string { return $module === 'program-activities' ? 'program_activity_records' : $module; }
    private function url(string $module, string $action, string $prefix = 'admin'): string
    {
        return route($prefix.'.'.$module.'.'.$action, in_array($action, ['store', 'index'], true) ? [] : $this->id($module));
    }

    private function document(string $html): DOMXPath
    {
        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        return new DOMXPath($document);
    }

    public static function modules(): array { return [['projects'], ['trainings'], ['program-activities']]; }
    public static function managerModules(): array
    {
        $rows = [];
        foreach (self::modules() as [$module]) foreach (['admin', 'field-officer'] as $prefix) $rows[] = [$module, $prefix];
        return $rows;
    }

    #[DataProvider('managerModules')]
    public function test_record_pages_and_list_actions_use_pages_while_create_stays_modal(string $module, string $prefix): void
    {
        $this->identity = $prefix === 'admin' ? self::ADMIN : self::OFFICER;
        $listing = $this->get($this->url($module, 'index', $prefix))->assertOk();
        $dom = $this->document($listing->getContent());
        $creationForm = '//dialog//form[@action="'.$this->url($module, 'store', $prefix).'"]';
        $this->assertSame(0, $dom->query($creationForm)->length);
        $addLinks = $dom->query('//a[contains(@href,"editor=create")]');
        $this->assertSame(1, $addLinks->length);
        $creation = $this->get($addLinks->item(0)->getAttribute('href'))->assertOk();
        $creationDom = $this->document($creation->getContent());
        $this->assertSame(1, $creationDom->query($creationForm)->length);
        $this->assertSame('POST', $creationDom->evaluate('string('.$creationForm.'/@method)'));
        $this->assertSame(1, $creationDom->query($creationForm.'//input[@name="_token"]')->length);
        $this->assertNotEmpty($creationDom->evaluate('string('.$creationForm.'//input[@name="_token"]/@value)'));
        $this->assertSame(0, $dom->query('//dialog[contains(@id,"-view-")]')->length);
        $this->assertGreaterThan(0, $dom->query('//a[@href="'.$this->url($module, 'show', $prefix).'"]')->length);
        $this->assertSame(1, $dom->query('//a[@href="'.$this->url($module, 'edit', $prefix).'"]')->length);
        $this->get($this->url($module, 'show', $prefix))->assertOk()->assertSee($this->url($module, 'edit', $prefix), false);
        $edit = $this->get($this->url($module, 'edit', $prefix))->assertOk()->assertSee('Synthetic');
        $dom = $this->document($edit->getContent());
        $form = '//form[@action="'.$this->url($module, 'update', $prefix).'"]';
        $this->assertSame(1, $dom->query($form.'[not(ancestor::dialog)]')->length);
        $this->assertSame('PATCH', $dom->evaluate('string('.$form.'//input[@name="_method"]/@value)'));
        $this->assertSame(1, $dom->query($form.'//input[@name="_token"]')->length);
        $this->assertSame(0, $dom->query('//a//a')->length);
    }

    #[DataProvider('managerModules')]
    public function test_existing_update_and_create_handlers_still_save(string $module, string $prefix): void
    {
        $this->identity = $prefix === 'admin' ? self::ADMIN : self::OFFICER;
        $this->patch($this->url($module, 'update', $prefix), array_replace($this->payload($module), ['title' => 'Updated title']))
            ->assertRedirect($this->url($module, 'index', $prefix))->assertSessionHas('success');
        $this->assertDatabaseHas($this->table($module), ['id' => $this->id($module), 'title' => 'Updated title', 'association_id' => self::ASSOCIATION]);
        $this->assertDatabaseHas('project_products', ['id' => self::PRODUCT, 'project_id' => self::PROJECT]);
        $this->assertDatabaseHas('training_participants', ['id' => self::RECORD, 'member_id' => self::ACCOUNT]);
        $this->assertDatabaseCount('training_participants', 1);
        $this->post($this->url($module, 'store', $prefix), array_replace($this->data($module), ['title' => 'Created title']))
            ->assertRedirect($this->url($module, 'index', $prefix))->assertSessionHas('success');
        $this->assertDatabaseHas($this->table($module), ['title' => 'Created title', 'association_id' => self::ASSOCIATION]);
    }

    #[DataProvider('managerModules')]
    public function test_invalid_updates_restore_edit_page_input_and_creation_restores_modal(string $module, string $prefix): void
    {
        $this->identity = $prefix === 'admin' ? self::ADMIN : self::OFFICER;
        $payload = array_replace($this->payload($module), ['title' => '', 'remarks' => '<script>retained input</script>']);
        $this->from($this->url($module, 'edit', $prefix))->patch($this->url($module, 'update', $prefix), $payload)
            ->assertRedirect($this->url($module, 'edit', $prefix))->assertSessionHasErrors('title');
        $this->get($this->url($module, 'edit', $prefix))->assertOk()->assertSee('retained input')->assertDontSee('<script>retained input</script>', false);
        $marker = match ($module) { 'projects' => 'project', 'trainings' => 'training', default => 'program_activity' };
        $payload = array_replace($this->data($module), [
            'title' => '',
            'remarks' => '<script>creation input</script>',
            $marker.'_form' => 'editor',
        ]);
        $this->from($this->url($module, 'index', $prefix))->post($this->url($module, 'store', $prefix), $payload)
            ->assertRedirect($this->url($module, 'index', $prefix))->assertSessionHasErrors('title');
        $recovered = $this->get($this->url($module, 'index', $prefix))->assertOk()
            ->assertSee('creation input')->assertDontSee('<script>creation input</script>', false);
        $dom = $this->document($recovered->getContent());
        $formMarker = str_replace('_', '-', $marker);
        $creationForm = '//dialog[@id="'.$formMarker.'-editor"]//form[@action="'.$this->url($module, 'store', $prefix).'"]';
        $this->assertSame(1, $dom->query($creationForm.'[@data-feedback-error-bag="default"]')->length);
        $errors = $dom->query($creationForm.'//*[@data-'.$formMarker.'-errors]');
        $this->assertSame(1, $errors->length);
        $this->assertStringContainsString('title', strtolower($errors->item(0)->textContent));
        $this->assertSame('', $dom->evaluate('string('.$creationForm.'//input[@name="title"]/@value)'));
        $this->assertSame($payload['remarks'], $dom->evaluate('string('.$creationForm.'//textarea[@name="remarks"])'));
        $this->assertDatabaseHas($this->table($module), ['id' => $this->id($module), 'title' => 'Synthetic '.($module === 'projects' ? 'Project' : $module)]);
    }

    #[DataProvider('modules')]
    public function test_assignment_and_role_guards_protect_pages_and_existing_writes(string $module): void
    {
        $this->identity = self::OFFICER;
        DB::table('associations')->update(['primary_field_officer_id' => self::ADMIN]);
        foreach (['show', 'edit'] as $action) $this->get($this->url($module, $action, 'field-officer'))->assertForbidden();
        $this->get('/field-officer/members?association='.self::ASSOCIATION)->assertNotFound();
        Schema::create('sex_categories', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->boolean('is_active');
        });
        foreach (['active', 'inactive', 'archived'] as $status) {
            $members = $this->get('/field-officer/members?status='.$status)->assertOk();
            $this->assertCount(0, $members->viewData('members'));
            $this->assertCount(0, $members->viewData('associations'));
        }
        $this->patchJson($this->url($module, 'update', 'field-officer'), $this->payload($module))->assertUnprocessable();
        $this->postJson($this->url($module, 'store', 'field-officer'), $this->data($module))->assertUnprocessable();
        $this->identity = self::ACCOUNT;
        foreach (['admin', 'field-officer'] as $prefix) {
            foreach (['show', 'edit'] as $action) $this->get($this->url($module, $action, $prefix))->assertForbidden();
            $this->patchJson($this->url($module, 'update', $prefix), $this->payload($module))->assertForbidden();
            $this->postJson($this->url($module, 'store', $prefix), $this->data($module))->assertForbidden();
        }
        $this->flushSession();
        $this->get($this->url($module, 'show'))->assertRedirect(route('login'));
        $this->get($this->url($module, 'edit'))->assertRedirect(route('login'));
    }

    public static function readOnlyRecords(): array
    {
        $rows = [];
        foreach (self::modules() as [$module]) foreach (['archived', 'inactive_association', 'archived_association', 'completed', 'terminated', 'closed_at'] as $state) $rows[] = [$module, $state];
        return $rows;
    }

    #[DataProvider('readOnlyRecords')]
    public function test_historical_records_remain_viewable_but_get_edit_and_updates_are_blocked(string $module, string $state): void
    {
        if ($state === 'archived') DB::table($this->table($module))->where('id', $this->id($module))->update(['archived_at' => now()]);
        elseif ($state === 'inactive_association') DB::table('associations')->update(['status' => 'inactive']);
        elseif ($state === 'archived_association') DB::table('associations')->update(['archived_at' => now()]);
        elseif ($state === 'closed_at') DB::table('projects')->update(['closed_at' => now()]);
        else DB::table('projects')->update(['status' => $state]);
        $before = DB::table($this->table($module))->where('id', $this->id($module))->first();
        $this->get($this->url($module, 'show'))->assertOk()->assertDontSee($this->url($module, 'edit'), false);
        $this->get($this->url($module, 'edit'))->assertForbidden();
        $this->patchJson($this->url($module, 'update'), array_replace($this->payload($module), ['title' => 'Forbidden change']))->assertUnprocessable();
        $this->assertEquals($before, DB::table($this->table($module))->where('id', $this->id($module))->first());
        if (in_array($state, ['completed', 'terminated'], true) && $module !== 'projects') {
            $this->postJson($this->url($module, 'store'), $this->data($module))->assertUnprocessable();
        }
    }

    public function test_program_wide_activity_is_admin_only_and_missing_records_are_safe(): void
    {
        DB::table('program_activity_records')->update(['association_id' => null, 'related_project_id' => null]);
        $this->get($this->url('program-activities', 'show'))->assertOk()->assertSee('Program-wide');
        $this->get($this->url('program-activities', 'edit'))->assertOk();
        $this->identity = self::OFFICER;
        $this->get($this->url('program-activities', 'show', 'field-officer'))->assertForbidden();
        $this->get($this->url('program-activities', 'edit', 'field-officer'))->assertForbidden();
        $this->identity = self::ADMIN;
        foreach (self::modules() as [$module]) foreach (['not-a-uuid', '99999999-9999-4999-8999-999999999999'] as $id) {
            $this->get('/admin/'.$module.'/'.$id)->assertNotFound();
            $this->get('/admin/'.$module.'/'.$id.'/edit')->assertNotFound();
        }
    }

    public function test_training_retains_inactive_participant_identity_and_rejects_stale_version(): void
    {
        DB::table('members')->update(['member_status' => 'inactive', 'archived_at' => now()]);
        $this->get($this->url('trainings', 'edit'))->assertOk()->assertSee('Synthetic Participant');
        $payload = $this->payload('trainings');
        $this->patch($this->url('trainings', 'update'), $payload)->assertRedirect($this->url('trainings', 'index'));
        $this->assertDatabaseHas('training_participants', ['id' => self::RECORD, 'member_id' => self::ACCOUNT, 'attendance_status' => 'absent']);
        $this->patchJson($this->url('trainings', 'update'), $payload)->assertStatus(409);
        $this->assertDatabaseCount('training_participants', 1);
    }

    public function test_monitoring_shortcut_and_legacy_resource_links_open_the_project_edit_page(): void
    {
        $query = ['from_monitoring' => 1, 'project_id' => self::PROJECT, 'resource_section' => 'products', 'monitoring_record' => self::RECORD];
        $this->get(route('admin.projects.index', $query))->assertRedirect(route('admin.projects.edit', ['project' => self::PROJECT] + array_diff_key($query, ['project_id' => true])));
        $this->get(route('admin.projects.edit', ['project' => self::PROJECT] + $query))->assertOk()
            ->assertSee(route('admin.monitoring.workspace', [self::RECORD, 'production']), false);
        $record = \App\Models\MonitoringRecord::with('project.association')->findOrFail(self::RECORD);
        $html = view('monitoring.partials.project-shortcuts', [
            'record' => $record, 'canManage' => true, 'section' => 'production', 'monitoringPrefix' => 'admin.monitoring',
        ])->render();
        $this->assertStringContainsString(e(route('admin.projects.edit', [
            'project' => self::PROJECT, 'from_monitoring' => 1, 'resource_section' => 'products', 'monitoring_record' => self::RECORD,
        ])), $html);
    }

    #[DataProvider('modules')]
    public function test_officer_can_read_finalized_history_but_cannot_open_edit_pages(string $module): void
    {
        $this->identity = self::OFFICER;
        DB::table('projects')->update(['status' => 'completed']);
        $this->get($this->url($module, 'show', 'field-officer'))->assertOk()
            ->assertDontSee($this->url($module, 'edit', 'field-officer'), false);
        $this->get($this->url($module, 'edit', 'field-officer'))->assertForbidden();
        $this->patchJson($this->url($module, 'update', 'field-officer'), $this->payload($module))->assertUnprocessable();
    }

    #[DataProvider('modules')]
    public function test_archived_parent_history_has_view_links_without_operational_forms(string $module): void
    {
        DB::table('associations')->update(['archived_at' => '2026-01-20']);
        foreach ([false, true] as $archived) {
            DB::table($this->table($module))->where('id', $this->id($module))->update(['archived_at' => $archived ? '2026-01-21' : null]);
            $listing = $this->get($this->url($module, 'index').($archived ? '?status=archived' : ''))->assertOk();
            $dom = $this->document($listing->getContent());
            $this->assertGreaterThan(0, $dom->query('//a[@href="'.$this->url($module, 'show').'"]')->length);
            $this->assertSame(0, $dom->query('//a[@href="'.$this->url($module, 'edit').'"]')->length);
            foreach (['archive', 'unarchive'] as $action) {
                $this->assertSame(0, $dom->query('//form[@action="'.$this->url($module, $action).'"]')->length);
                $this->patchJson($this->url($module, $action))->assertUnprocessable();
            }
            $details = $this->get($this->url($module, 'show'))->assertOk();
            $detailDom = $this->document($details->getContent());
            $notice = $detailDom->query('//p[contains(@class,"notice")][contains(.,"Association Archived")]');
            $this->assertSame(1, $notice->length);
            $this->assertStringContainsString('Read Only', $notice->item(0)->textContent);
            $this->assertSame(0, $detailDom->query('//a[@href="'.$this->url($module, 'edit').'"]')->length);
        }
        $this->identity = self::OFFICER;
        $listing = $this->get($this->url($module, 'index', 'field-officer').'?status=archived')->assertOk();
        $this->assertSame(0, $this->document($listing->getContent())->query('//a[@href="'.$this->url($module, 'show', 'field-officer').'"]')->length);
        foreach (['show', 'edit'] as $action) $this->get($this->url($module, $action, 'field-officer'))->assertForbidden();
        $this->assertDatabaseHas('training_participants', ['id' => self::RECORD, 'member_id' => self::ACCOUNT]);
        $this->assertDatabaseHas('members', ['id' => self::ACCOUNT, 'member_status' => 'active']);
        $this->assertDatabaseHas('projects', ['id' => self::PROJECT, 'status' => 'ongoing']);
    }

    public function test_cross_owner_links_remain_intact_and_do_not_freeze_unrelated_program_records(): void
    {
        $owner = '99999999-9999-4999-8999-999999999999';
        DB::table('associations')->insert(['id' => $owner, 'name' => 'Archived actual project owner',
            'status' => 'active', 'primary_field_officer_id' => self::OFFICER]);
        DB::table('monitoring_production')->delete();
        DB::table('monitoring_records')->delete();
        $this->patch($this->url('projects', 'update'), array_replace($this->payload('projects'), ['association_id' => $owner]))
            ->assertRedirect()->assertSessionHasNoErrors();
        DB::table('associations')->where('id', $owner)->update(['archived_at' => '2026-01-20']);
        foreach (['trainings', 'program-activities'] as $module) {
            $this->get($this->url($module, 'show'))->assertOk()->assertDontSee($this->url($module, 'edit'), false);
            foreach (['admin', 'field-officer'] as $prefix) {
                $this->identity = $prefix === 'admin' ? self::ADMIN : self::OFFICER;
                $listing = $this->get($this->url($module, 'index', $prefix))->assertOk();
                $this->assertSame(0, $this->document($listing->getContent())->query('//a[@href="'.$this->url($module, 'edit', $prefix).'"]')->length);
                $this->get($this->url($module, 'edit', $prefix))->assertForbidden();
                $this->patchJson($this->url($module, 'update', $prefix), array_replace($this->payload($module), ['related_project_id' => null]))->assertUnprocessable();
            }
            $this->assertDatabaseHas($this->table($module), ['id' => self::PRODUCT, 'association_id' => self::ASSOCIATION, 'related_project_id' => self::PROJECT]);
            $this->identity = self::ADMIN;
            $unrelated = $this->data($module);
            $unrelated['related_project_id'] = null;
            DB::table($this->table($module))->insert(['id' => $owner] + $unrelated);
            $this->get('/admin/'.$module.'/'.$owner.'/edit')->assertOk();
        }
    }

    public function test_archived_details_tolerate_missing_optional_relations(): void
    {
        DB::table('associations')->update(['archived_at' => '2026-01-20']);
        DB::table('projects')->update(['commodity_id' => null, 'fisheries_category_id' => null, 'saad_activity_id' => null]);
        DB::table('members')->delete();
        $this->get($this->url('trainings', 'show'))->assertOk()->assertSee('Member unavailable');
        DB::table('trainings')->update(['related_project_id' => null]);
        DB::table('program_activity_records')->update(['related_project_id' => null, 'conducted_by_user_id' => null]);
        foreach (self::modules() as [$module]) $this->get($this->url($module, 'show'))->assertOk();
        $this->assertDatabaseCount('training_participants', 1);
    }

    public function test_archived_member_http_writes_preserve_the_database_snapshot_with_current_request_contracts(): void
    {
        Schema::create('sex_categories', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->boolean('is_active');
        });
        DB::table('sex_categories')->insert(['id' => 1, 'name' => 'Synthetic sex', 'is_active' => true]);
        DB::table('members')->update(['updated_at' => '2026-01-01 00:00:00']);
        DB::table('associations')->update(['archived_at' => '2026-01-20']);
        $this->identity = self::OFFICER;
        $before = DB::table('members')->orderBy('id')->get()->toJson();
        $version = \App\Models\Member::findOrFail(self::ACCOUNT)->updated_at->format('Y-m-d\TH:i:s.uP');
        foreach (['update', 'archive', 'reactivate'] as $action) {
            $data = ['member_record_id' => self::ACCOUNT, 'expected_updated_at' => $version,
                'member_action' => $action === 'update' ? 'profile' : $action];
            $data += $action === 'update' ? ['first_name' => 'Changed', 'last_name' => 'Participant',
                'birth_date' => '2000-01-01', 'sex_id' => 1] : ['confirm_change' => '1'];
            $this->patchJson(route('field-officer.members.'.$action, self::ACCOUNT), $data)
                ->assertUnprocessable()->assertJsonValidationErrors('member');
            $this->assertSame($before, DB::table('members')->orderBy('id')->get()->toJson());
        }
        $this->assertDatabaseHas('training_participants', ['id' => self::RECORD, 'member_id' => self::ACCOUNT]);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_record_templates_compile_to_valid_php(): void
    {
        foreach (['projects/show', 'projects/edit', 'projects/partials/form', 'trainings/show', 'trainings/edit',
            'trainings/partials/form', 'program-activities/show', 'program-activities/edit', 'program-activities/partials/form',
            'admin/projects/index', 'admin/trainings/index', 'admin/program-activities/index', 'monitoring/dashboard',
            'monitoring/partials/context', 'monitoring/partials/project-shortcuts', 'dashboard/content'] as $template) {
            $compiled = app('blade.compiler')->compileString(file_get_contents(resource_path('views/'.$template.'.blade.php')));
            $this->assertNotEmpty(token_get_all($compiled, TOKEN_PARSE), $template);
        }
    }
}
