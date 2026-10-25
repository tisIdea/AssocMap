<?php

namespace Tests\Feature;

use App\Services\MemberApplicationPhotoService;
use App\Services\SupabaseAuthService;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class MemberDirectoryUiTest extends TestCase
{
    private string $identity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        config(['app.key' => 'base64:'.base64_encode(str_repeat('s', 32))]);
        config(['associations.geography_schema_ready' => false, 'associations.direct_geography' => false]);
        $this->withoutVite();
        $this->identity = $this->id(1);
        $auth = Mockery::mock(SupabaseAuthService::class);
        $auth->shouldReceive('getUser')->with('synthetic-register-session')->andReturnUsing(fn () => ['id' => $this->identity]);
        $this->app->instance(SupabaseAuthService::class, $auth);
        $this->withSession(['supabase_access_token' => 'synthetic-register-session']);
        $this->schema();
        $this->fixtures();
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

    private function id(int $value): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $value);
    }

    private function schema(): void
    {
        // This schema exists only in the guarded SQLite in-memory connection.
        DB::unprepared(<<<'SQL'
            CREATE TABLE roles (id INTEGER PRIMARY KEY, code TEXT, is_active BOOLEAN, name TEXT, description TEXT, created_at TEXT, updated_at TEXT);
            CREATE TABLE user_profiles (id TEXT PRIMARY KEY, role_id INTEGER, display_name TEXT, is_active BOOLEAN, created_at TEXT, updated_at TEXT);
            CREATE TABLE user_invitations (id TEXT PRIMARY KEY, auth_user_id TEXT, status TEXT, last_error_code TEXT, accepted_at TEXT, last_attempt_at TEXT, created_at TEXT);
            CREATE TABLE municipalities (id TEXT PRIMARY KEY, name TEXT);
            CREATE TABLE barangays (id TEXT PRIMARY KEY, municipality_id TEXT, name TEXT);
            CREATE TABLE areas (id TEXT PRIMARY KEY, barangay_id TEXT, address TEXT);
            CREATE TABLE associations (id TEXT PRIMARY KEY, name TEXT, acronym TEXT, area_id TEXT, status TEXT, archived_at TEXT, primary_field_officer_id TEXT);
            CREATE TABLE association_accounts (id TEXT PRIMARY KEY, user_id TEXT, association_id TEXT, is_active BOOLEAN);
            CREATE TABLE sex_categories (id INTEGER PRIMARY KEY, name TEXT, is_active BOOLEAN);
            CREATE TABLE association_positions (id INTEGER PRIMARY KEY, name TEXT, code TEXT, is_active BOOLEAN, is_officer BOOLEAN, sort_order INTEGER);
            CREATE TABLE member_classifications (id INTEGER PRIMARY KEY, name TEXT, is_active BOOLEAN);
            CREATE TABLE members (id TEXT PRIMARY KEY, association_id TEXT, source_application_id TEXT, first_name TEXT, middle_name TEXT, last_name TEXT, suffix TEXT, birth_date TEXT, sex_id INTEGER, contact_number TEXT, fishr_registration_number TEXT, position_id INTEGER, position_other_text TEXT, date_registered TEXT, member_status TEXT, archived_at TEXT, archived_by TEXT, created_at TEXT, updated_at TEXT);
            CREATE TABLE member_classification_assignments (member_id TEXT, classification_id INTEGER);
            CREATE TABLE member_applications (id TEXT PRIMARY KEY, association_id TEXT, submitted_by_user_id TEXT, first_name TEXT, middle_name TEXT, last_name TEXT, suffix TEXT, birth_date TEXT, sex_id INTEGER, contact_number TEXT, fishr_registration_number TEXT, position_id INTEGER, position_other_text TEXT, endorsement_letter TEXT, applicant_photo_path TEXT, status TEXT, reviewed_by TEXT, reviewed_at TEXT, rejection_reason TEXT, created_at TEXT, updated_at TEXT);
            CREATE TABLE member_application_classifications (member_application_id TEXT, classification_id INTEGER);
            SQL);
    }

    public function test_cutover_members_and_applications_keep_geography_and_ownership_without_areas(): void
    {
        DB::statement('ALTER TABLE associations ADD COLUMN barangay_id TEXT');
        DB::statement('UPDATE associations SET barangay_id=(SELECT barangay_id FROM areas WHERE areas.id=associations.area_id), area_id=NULL');
        config(['associations.geography_schema_ready' => true, 'associations.direct_geography' => true]);
        $this->member(101);
        $this->member(102, ['association_id' => $this->id(12), 'first_name' => 'OutsideOwner']);
        $this->application(201);
        $this->application(202, ['association_id' => $this->id(12), 'first_name' => 'OutsideApplicant']);
        $this->get('/admin/members')->assertOk()->assertSee('Test Municipality')->assertSee('Test Barangay');
        $this->identity = $this->id(2);
        $this->get('/field-officer/members')->assertOk()->assertSee('Test Municipality')->assertDontSee('OutsideOwner');
        $this->get('/field-officer/member-applications')->assertOk()->assertSee('Test Barangay')->assertDontSee('OutsideApplicant');
        $this->identity = $this->id(4);
        $this->get('/association/member-applications')->assertOk()->assertSee('Test Municipality')->assertSee('Test Barangay')->assertDontSee('OutsideApplicant');
        $this->get('/admin/members')->assertForbidden();
        $this->assertDatabaseCount('members', 2);
        $this->assertDatabaseHas('member_applications', ['id' => $this->id(201), 'association_id' => $this->id(11), 'submitted_by_user_id' => $this->id(4)]);
    }

    private function fixtures(): void
    {
        foreach ([1 => 'system_admin', 2 => 'field_officer', 3 => 'association_account'] as $id => $code) {
            DB::table('roles')->insert(['id' => $id, 'code' => $code, 'is_active' => true]);
        }
        foreach ([1 => 1, 2 => 2, 3 => 2, 4 => 3, 5 => 3] as $id => $role) {
            DB::table('user_profiles')->insert(['id' => $this->id($id), 'role_id' => $role, 'display_name' => 'Synthetic Actor', 'is_active' => true]);
        }
        DB::table('municipalities')->insert(['id' => $this->id(33), 'name' => 'Test Municipality']);
        DB::table('barangays')->insert(['id' => $this->id(32), 'municipality_id' => $this->id(33), 'name' => 'Test Barangay']);
        DB::table('areas')->insert(['id' => $this->id(31), 'barangay_id' => $this->id(32), 'address' => 'Test Area']);
        foreach ([11 => 'active', 12 => 'active', 13 => 'archived', 14 => 'inactive'] as $id => $status) {
            DB::table('associations')->insert(['id' => $this->id($id), 'name' => 'Association '.$id, 'area_id' => $this->id(31),
                'status' => $status === 'archived' ? 'inactive' : $status, 'archived_at' => $status === 'archived' ? '2026-01-01' : null,
                'primary_field_officer_id' => $this->id($id === 12 ? 3 : 2)]);
        }
        DB::table('association_accounts')->insert(['id' => $this->id(21), 'user_id' => $this->id(4), 'association_id' => $this->id(11), 'is_active' => true]);
        DB::table('sex_categories')->insert(['id' => 1, 'name' => 'Test Sex', 'is_active' => true]);
        DB::table('association_positions')->insert(['id' => 1, 'name' => 'Member', 'code' => 'MEMBER', 'is_active' => true, 'is_officer' => false, 'sort_order' => 1]);
        DB::table('member_classifications')->insert(['id' => 1, 'name' => 'Test Classification', 'is_active' => true]);
    }

    private function member(int $id, array $overrides = []): void
    {
        DB::table('members')->insert(array_replace(['id' => $this->id($id), 'association_id' => $this->id(11),
            'first_name' => 'Jane', 'last_name' => 'Doe', 'birth_date' => '2000-01-01', 'sex_id' => 1, 'position_id' => 1,
            'member_status' => 'active', 'date_registered' => '2026-01-01', 'updated_at' => '2026-01-01 00:00:00'], $overrides));
        DB::table('member_classification_assignments')->insert(['member_id' => $this->id($id), 'classification_id' => 1]);
    }

    private function application(int $id, array $overrides = []): void
    {
        DB::table('member_applications')->insert(array_replace(['id' => $this->id($id), 'association_id' => $this->id(11),
            'submitted_by_user_id' => $this->id(4), 'first_name' => 'Jane', 'last_name' => 'Applicant', 'birth_date' => '2000-01-01',
            'sex_id' => 1, 'position_id' => 1, 'status' => 'pending', 'endorsement_letter' => '<script>Private letter</script>',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'], $overrides));
        DB::table('member_application_classifications')->insert(['member_application_id' => $this->id($id), 'classification_id' => 1]);
    }

    public function test_admin_register_is_paginated_view_only_and_preserves_details(): void
    {
        foreach (range(101, 130) as $id) $this->member($id);
        $first = $this->get('/admin/members')->assertOk()->assertSee('Member Management')->assertSee('View only')
            ->assertSee('Test Classification')->assertSee('Test Municipality')->assertDontSee('Save Changes')->assertDontSee('Add Member');
        $members = $first->viewData('members');
        $this->assertCount(25, $members);
        $this->assertSame(30, $members->total());
        $second = $this->get('/admin/members?page=2')->assertOk()->viewData('members');
        $this->assertCount(5, $second);
        $this->assertSame([], array_intersect($members->pluck('id')->all(), $second->pluck('id')->all()));
        $this->assertSame(30, $first->viewData('summary')['current']);
    }

    public function test_field_officer_member_filters_and_summaries_never_cross_assignment_scope(): void
    {
        $this->identity = $this->id(2);
        $this->member(101);
        $this->member(102, ['member_status' => 'inactive']);
        $this->member(103, ['member_status' => 'inactive', 'archived_at' => '2026-01-02']);
        $this->member(104, ['association_id' => $this->id(12), 'first_name' => 'ForbiddenOther']);
        $this->member(105, ['association_id' => $this->id(13), 'first_name' => 'ForbiddenArchived']);
        $response = $this->get('/field-officer/members')->assertOk()->assertSee('Save Changes')->assertSee('expected_updated_at')
            ->assertDontSee('ForbiddenOther')->assertDontSee('ForbiddenArchived');
        $this->assertSame(['current' => 2, 'active' => 1, 'inactive' => 1, 'archived' => 1], $response->viewData('summary'));
        $this->get('/field-officer/members?status=inactive')->assertOk()->assertDontSee('Save Changes')->assertSee('view only');
        $this->get('/field-officer/members?status=archived')->assertOk()->assertSee('Reactivate Member')->assertSee('confirm_change');
        $this->get('/field-officer/members?q=ForbiddenOther')->assertOk()->assertDontSee('ForbiddenOther</th>', false);
        $this->get('/field-officer/members?association='.$this->id(12))->assertNotFound();
        $this->get('/field-officer/members?association='.$this->id(13))->assertNotFound();
    }

    public function test_search_is_literal_sorting_is_stable_and_pagination_keeps_filters(): void
    {
        foreach (range(101, 130) as $id) $this->member($id);
        $this->member(131, ['first_name' => 'Zed', 'fishr_registration_number' => 'RF%_1', 'date_registered' => '2026-02-01']);
        $this->assertSame($this->id(131), $this->get('/admin/members?sort=za')->assertOk()->viewData('members')->first()->id);
        $this->assertSame($this->id(131), $this->get('/admin/members?sort=newest')->assertOk()->viewData('members')->first()->id);
        $this->assertSame($this->id(101), $this->get('/admin/members?sort=oldest')->assertOk()->viewData('members')->first()->id);
        $response = $this->get('/admin/members?q=Jane%20Doe&sort=az&association='.$this->id(11))->assertOk();
        $this->assertSame(30, $response->viewData('members')->total());
        $this->assertStringContainsString('q=Jane%20Doe', $response->viewData('members')->nextPageUrl());
        $this->assertStringContainsString('association='.$this->id(11), $response->viewData('members')->nextPageUrl());
        $this->assertSame(1, $this->get('/admin/members?q=%25_')->assertOk()->viewData('members')->total());
        $this->get('/admin/members?q=unmatched')->assertOk()->assertSee('No matching members');
    }

    public function test_application_account_isolation_counts_and_private_evidence_are_preserved(): void
    {
        $this->identity = $this->id(4);
        $this->application(201);
        $this->application(202, ['status' => 'approved', 'reviewed_by' => $this->id(2), 'reviewed_at' => '2026-02-01']);
        $this->application(203, ['status' => 'rejected', 'rejection_reason' => 'Test decision']);
        $this->application(204, ['submitted_by_user_id' => $this->id(5), 'first_name' => 'ForbiddenSubmitter']);
        $this->application(205, ['association_id' => $this->id(12), 'first_name' => 'ForbiddenAssociation']);
        $response = $this->get('/association/member-applications')->assertOk()->assertSee('Submit Member Application')
            ->assertSee('enctype="multipart/form-data"', false)->assertSee('classification_ids[]', false)
            ->assertSee('&lt;script&gt;Private letter&lt;/script&gt;', false)->assertDontSee('<script>Private letter</script>', false)
            ->assertDontSee('ForbiddenSubmitter')->assertDontSee('ForbiddenAssociation');
        $this->assertSame(['total' => 3, 'pending' => 1, 'approved' => 1, 'rejected' => 1], $response->viewData('summary'));
        $this->assertSame(3, $response->viewData('applications')->total());
        $this->assertRegisterColumns($response->getContent());
        $this->get('/association/member-applications?status=rejected&q=Jane')->assertOk()->assertSee('Test decision');
        $this->getJson('/association/member-applications?association='.$this->id(12))->assertUnprocessable()->assertJsonValidationErrors('association');
    }

    public function test_review_and_member_actions_follow_existing_association_write_restrictions(): void
    {
        $this->identity = $this->id(2);
        $this->application(201);
        $this->application(202, ['association_id' => $this->id(12), 'first_name' => 'ForbiddenApplicant']);
        $this->application(203, ['association_id' => $this->id(13)]);
        $this->application(204, ['association_id' => $this->id(14)]);
        $response = $this->get('/field-officer/member-applications')->assertOk()->assertDontSee('ForbiddenApplicant')
            ->assertSee('approve-'.$this->id(201))->assertDontSee('approve-'.$this->id(203))->assertDontSee('reject-'.$this->id(204))
            ->assertSee('Association read only');
        $this->assertSame(3, $response->viewData('summary')['pending']);
        $this->assertRegisterColumns($response->getContent());
        $this->get('/field-officer/member-applications?association='.$this->id(12))->assertNotFound();
        $this->get('/field-officer/member-applications?association='.$this->id(13))->assertOk()->assertSee('Application history is read-only');
        $this->member(101, ['association_id' => $this->id(14)]);
        $this->get('/field-officer/members')->assertOk()->assertDontSee('member-profile-'.$this->id(101));
        $this->identity = $this->id(4);
        DB::table('associations')->where('id', $this->id(11))->update(['status' => 'inactive']);
        $this->get('/association/member-applications')->assertOk()->assertSee('Association read only')->assertDontSee('data-dialog="submit-member-application"', false);
    }

    public function test_invalid_filters_and_wrong_roles_are_rejected(): void
    {
        foreach (['sort=DROP%20TABLE', 'status=unknown', 'q[]=x', 'page=-1', 'association=invalid'] as $query) {
            $this->getJson('/admin/members?'.$query)->assertUnprocessable();
        }
        $this->identity = $this->id(4);
        $this->get('/admin/members')->assertForbidden();
        $this->get('/field-officer/members')->assertForbidden();
        $this->get('/field-officer/member-applications')->assertForbidden();
        $this->identity = $this->id(2);
        $this->get('/association/member-applications')->assertForbidden();
    }

    public function test_register_queries_remain_bounded_as_records_increase(): void
    {
        $this->member(101);
        $this->application(201, ['reviewed_by' => $this->id(2)]);
        $urls = [1 => '/admin/members', 2 => '/field-officer/members', 4 => '/association/member-applications', 3 => '/field-officer/member-applications'];
        $before = [];
        foreach ($urls as $key => $url) {
            $this->identity = $this->id($key === 3 ? 2 : $key);
            DB::enableQueryLog(); DB::flushQueryLog();
            $this->get($url)->assertOk();
            $before[$key] = count(DB::getQueryLog());
        }
        DB::disableQueryLog();
        foreach (range(102, 130) as $id) {
            $this->member($id);
            $this->application($id + 100, ['reviewed_by' => $this->id(2)]);
        }
        foreach ($urls as $key => $url) {
            $this->identity = $this->id($key === 3 ? 2 : $key);
            DB::enableQueryLog(); DB::flushQueryLog();
            $response = $this->get($url)->assertOk();
            $count = count(DB::getQueryLog());
            $this->assertSame($before[$key], $count, $url.' added per-record queries');
            $paginator = $response->viewData(str_ends_with($url, '/members') ? 'members' : 'applications');
            $this->assertCount(25, $paginator);
            $this->assertSame(30, $paginator->total());
            $this->assertRegisterColumns($response->getContent());
            fwrite(STDOUT, "\nBounded query count {$url}: {$before[$key]} -> {$count} (1 -> 30 records; 25 per page)\n");
        }
        DB::disableQueryLog();
        app(SupabaseAuthService::class)->shouldHaveReceived('getUser')->times(8);
    }

    public function test_application_photo_http_responses_preserve_private_headers_and_bytes_for_each_allowed_role_and_state(): void
    {
        config(['services.supabase_storage.photo_transport' => 'local']);
        Storage::fake('member_application_photos');
        foreach (['jpg' => 'image/jpeg', 'png' => 'image/png'] as $extension => $mime) {
            foreach (['pending', 'approved', 'rejected'] as $offset => $status) {
                $id = ($extension === 'jpg' ? 201 : 211) + $offset;
                $this->application($id, ['status' => $status]);
                $contents = $this->storeApplicationPhoto($id, $extension);
                foreach ([4 => 'association', 2 => 'field-officer'] as $actor => $area) {
                    $this->identity = $this->id($actor);
                    $response = $this->get(route($area.'.member-applications.photo', $this->id($id)))
                        ->assertOk()->assertHeader('Content-Type', $mime)
                        ->assertHeader('Content-Disposition', 'inline; filename="applicant-photo.'.$extension.'"')
                        ->assertHeader('X-Content-Type-Options', 'nosniff')
                        ->assertHeader('Referrer-Policy', 'no-referrer');
                    $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
                    $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
                    $this->assertStringContainsString('max-age=0', $response->headers->get('Cache-Control'));
                    $this->assertSame($contents, $response->streamedContent());
                }
            }
        }
    }

    public function test_application_photo_http_access_rechecks_submitter_link_assignment_and_role_before_opening_storage(): void
    {
        $this->applicationAuditFixture();
        config(['services.supabase_storage.photo_transport' => 'local']);
        Storage::fake('member_application_photos');
        $this->application(201);
        $this->application(202, ['association_id' => $this->id(12)]);
        $this->application(203, ['submitted_by_user_id' => $this->id(5)]);
        $contents = $this->storeApplicationPhoto(201, 'png');
        foreach ([4 => 'association', 2 => 'field-officer'] as $actor => $area) {
            $this->identity = $this->id($actor);
            $this->assertSame($contents, $this->get(route($area.'.member-applications.photo', $this->id(201)))
                ->assertOk()->streamedContent());
        }
        $photos = Mockery::mock(MemberApplicationPhotoService::class);
        $photos->shouldNotReceive('open');
        $this->app->instance(MemberApplicationPhotoService::class, $photos);
        $before = $this->applicationSnapshot();
        $this->identity = $this->id(4);
        foreach ([202, 203, 999] as $application) {
            $this->get(route('association.member-applications.photo', $this->id($application)))->assertNotFound();
        }
        $link = DB::table('association_accounts')->where('user_id', $this->id(4));
        $link->update(['is_active' => false]);
        $this->get(route('association.member-applications.photo', $this->id(201)))->assertNotFound();
        $link->update(['is_active' => true, 'association_id' => $this->id(12)]);
        $this->get(route('association.member-applications.photo', $this->id(201)))->assertNotFound();
        $this->identity = $this->id(2);
        $this->get(route('field-officer.member-applications.photo', $this->id(202)))->assertNotFound();
        DB::table('associations')->where('id', $this->id(11))->update(['primary_field_officer_id' => $this->id(3)]);
        $this->get(route('field-officer.member-applications.photo', $this->id(201)))->assertNotFound();
        foreach ([1 => ['association', 'field-officer'], 2 => ['association'], 4 => ['field-officer']] as $actor => $areas) {
            $this->identity = $this->id($actor);
            foreach ($areas as $area) {
                $this->get(route($area.'.member-applications.photo', $this->id(201)))->assertForbidden();
            }
        }
        $this->flushSession();
        foreach (['association', 'field-officer'] as $area) {
            $this->get(route($area.'.member-applications.photo', $this->id(201)))->assertRedirect(route('login'));
        }
        $this->assertSame($before, $this->applicationSnapshot());
        $this->assertSame($contents, Storage::disk('member_application_photos')->get(
            DB::table('member_applications')->where('id', $this->id(201))->value('applicant_photo_path')));
    }

    public function test_http_review_decisions_preserve_admission_and_audit_and_reject_replayed_or_invalid_decisions(): void
    {
        $this->applicationAuditFixture();
        DB::table('association_positions')->insert([
            'id' => 2, 'name' => 'President', 'code' => 'PRESIDENT',
            'is_active' => true, 'is_officer' => true, 'sort_order' => 2,
        ]);
        $this->application(201, ['position_id' => 2]);
        $this->application(202);
        $this->application(203);
        $this->identity = $this->id(2);
        $this->get(route('field-officer.member-applications.index'))->assertOk()->assertSee('approve-'.$this->id(201));
        $this->from(route('field-officer.member-applications.index'))
            ->patch(route('field-officer.member-applications.approve', $this->id(201)))
            ->assertRedirect(route('field-officer.member-applications.index'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('member_applications', [
            'id' => $this->id(201), 'status' => 'approved', 'reviewed_by' => $this->id(2), 'rejection_reason' => null,
        ]);
        $member = DB::table('members')->where('source_application_id', $this->id(201))->sole();
        $this->assertSame($this->id(11), $member->association_id);
        $this->assertSame('active', $member->member_status);
        $this->assertSame(2, $member->position_id);
        $this->assertSame([1], DB::table('member_classification_assignments')->where('member_id', $member->id)->pluck('classification_id')->all());
        $this->assertDatabaseHas('audit_logs', [
            'action_type' => 'APPOINT ASSOCIATION OFFICER', 'entity_id' => $member->id, 'actor_user_id' => $this->id(2),
        ]);
        $this->patchJson(route('field-officer.member-applications.reject', $this->id(203)), ['rejection_reason' => 'Synthetic reason'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('member_applications', [
            'id' => $this->id(203), 'status' => 'rejected', 'reviewed_by' => $this->id(2), 'rejection_reason' => 'Synthetic reason',
        ]);
        $before = $this->applicationSnapshot();
        foreach ([201, 203] as $application) {
            foreach (['approve', 'reject'] as $decision) {
                $this->patchJson(route('field-officer.member-applications.'.$decision, $this->id($application)),
                    ['rejection_reason' => 'Another decision'])->assertStatus(409)->assertJsonValidationErrors('application');
                $this->assertSame($before, $this->applicationSnapshot());
            }
        }
        $this->patchJson(route('field-officer.member-applications.reject', $this->id(202)), ['rejection_reason' => ' '])
            ->assertUnprocessable()->assertJsonValidationErrors('rejection_reason');
        $this->assertSame($before, $this->applicationSnapshot());
    }

    public function test_http_review_denials_preserve_applications_members_classifications_and_audit_history(): void
    {
        $this->applicationAuditFixture();
        foreach ([201 => 11, 202 => 12, 203 => 13, 204 => 14] as $application => $association) {
            $this->application($application, ['association_id' => $this->id($association)]);
        }
        $this->identity = $this->id(2);
        $this->patchJson(route('field-officer.member-applications.reject', $this->id(201)), ['rejection_reason' => 'Positive control'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('member_applications', ['id' => $this->id(201), 'status' => 'rejected']);
        $before = $this->applicationSnapshot();
        foreach (['approve', 'reject'] as $decision) {
            foreach ([202 => 'reviewer', 203 => 'association_id', 204 => 'association_id'] as $application => $error) {
                $this->patchJson(route('field-officer.member-applications.'.$decision, $this->id($application)),
                    ['rejection_reason' => 'Synthetic reason'])->assertUnprocessable()->assertJsonValidationErrors($error);
            }
            foreach ([1, 4] as $actor) {
                $this->identity = $this->id($actor);
                $this->patchJson(route('field-officer.member-applications.'.$decision, $this->id(202)),
                    ['rejection_reason' => 'Synthetic reason'])->assertForbidden();
            }
            $this->identity = $this->id(2);
        }
        $this->assertSame($before, $this->applicationSnapshot());
    }

    public function test_http_submission_derives_ownership_and_rejects_registered_fishr_duplicates_and_inactive_links(): void
    {
        $this->applicationAuditFixture();
        $this->identity = $this->id(4);
        $data = [
            'first_name' => 'Synthetic', 'last_name' => 'Applicant', 'birth_date' => '2000-01-01',
            'sex_id' => 1, 'position_id' => 1, 'classification_ids' => [1],
            'endorsement_letter' => 'Private synthetic letter', 'fishr_registration_number' => 'SYNTHETIC-FISHR-15',
            'association_id' => $this->id(12), 'submitted_by_user_id' => $this->id(5),
        ];
        $url = route('association.member-applications.store');
        $this->from(route('association.member-applications.index'))->post($url, $data)->assertRedirect()->assertSessionHasNoErrors();
        $application = DB::table('member_applications')->sole();
        $this->assertSame($this->id(11), $application->association_id);
        $this->assertSame($this->id(4), $application->submitted_by_user_id);
        $this->assertSame('pending', $application->status);
        $this->assertDatabaseCount('members', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        foreach (['pending', 'approved'] as $status) {
            DB::table('member_applications')->where('id', $application->id)->update(['status' => $status]);
            $before = $this->applicationSnapshot();
            $this->postJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('fishr_registration_number');
            $this->assertSame($before, $this->applicationSnapshot());
        }
        DB::table('association_accounts')->where('user_id', $this->id(4))->update(['is_active' => false]);
        $before = $this->applicationSnapshot();
        $this->postJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('account');
        foreach ([1, 2] as $actor) {
            $this->identity = $this->id($actor);
            $this->postJson($url, $data)->assertForbidden();
        }
        $this->assertSame($before, $this->applicationSnapshot());
    }

    private function storeApplicationPhoto(int $application, string $extension): string
    {
        $path = 'photos/'.$this->id($application).'/'.$this->id(901).'.'.$extension;
        $photo = UploadedFile::fake()->image('portrait.'.$extension, 8, 8);
        $contents = file_get_contents($photo->getPathname());
        Storage::disk('member_application_photos')->put($path, $contents, 'private');
        DB::table('member_applications')->where('id', $this->id($application))->update(['applicant_photo_path' => $path]);

        return $contents;
    }

    private function applicationAuditFixture(): void
    {
        DB::statement('CREATE TABLE audit_logs (id TEXT PRIMARY KEY, actor_user_id TEXT, action_type TEXT, module TEXT,
            entity_type TEXT, entity_id TEXT, old_values TEXT, new_values TEXT, performed_at TEXT)');
    }

    private function applicationSnapshot(): array
    {
        $snapshot = [];
        foreach (['member_applications', 'members', 'member_application_classifications', 'member_classification_assignments', 'audit_logs'] as $table) {
            $snapshot[$table] = DB::table($table)->orderByRaw('1, 2')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }

    private function assertRegisterColumns(string $html): void
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($html);
            $xpath = new \DOMXPath($document);
            $tables = $xpath->query('//table[contains(@class, "member-register-table")]');
            $this->assertCount(1, $tables);
            foreach ($xpath->query('.//tbody/tr[th]', $tables->item(0)) as $row) {
                $this->assertCount(6, $xpath->query('./th|./td', $row));
            }
            $this->assertCount(1, $xpath->query('//details[contains(@class, "member-filter-disclosure")]/summary'));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
