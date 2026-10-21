<?php

namespace Tests\Feature;

use App\Exceptions\InvitationActivationException;
use App\Models\Association;
use App\Models\UserProfile;
use App\Services\AuditLogService;
use App\Services\FieldOfficerAssignmentService;
use App\Services\InvitationActivationService;
use App\Services\MemberApplicationPhotoService;
use App\Services\ProfileAuthIdentityService;
use App\Services\UserAccessRevocationService;
use App\Services\UserManagementQueryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    private const ADMIN = '11111111-1111-4111-8111-111111111111';

    private const OFFICER_A = '22222222-2222-4222-8222-222222222222';

    private const OFFICER_B = '33333333-3333-4333-8333-333333333333';

    private const ACCOUNT = '44444444-4444-4444-8444-444444444444';

    private const ASSOCIATION = '55555555-5555-4555-8555-555555555555';

    private const SECOND = '66666666-6666-4666-8666-666666666666';

    private const OTHER = '77777777-7777-4777-8777-777777777777';

    private const ARCHIVED = '88888888-8888-4888-8888-888888888888';

    private const APPLICATION = '99999999-9999-4999-8999-999999999999';

    private const LINK = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const INVITATION = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private string $actor = self::ADMIN;

    private ?Collection $authIdentities;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.user_management_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ], 'services.supabase.url' => 'https://auth.example.test',
            'services.supabase.publishable_key' => 'sb_publishable_synthetic_test_only']);
        DB::setDefaultConnection('user_management_test');
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        $this->withoutVite();
        $this->schema();
        $this->fixture();
        $this->authIdentities = collect([
            self::ADMIN => (object) ['id' => self::ADMIN, 'email' => 'administrator@example.test'],
            self::OFFICER_A => (object) ['id' => self::OFFICER_A, 'email' => 'officer-a@example.test'],
            self::OFFICER_B => (object) ['id' => self::OFFICER_B, 'email' => 'officer-b@example.test'],
            self::ACCOUNT => (object) ['id' => self::ACCOUNT, 'email' => 'association@example.test'],
        ]);
        $reader = Mockery::mock(ProfileAuthIdentityService::class);
        $reader->shouldReceive('read')->andReturnUsing(function (array $ids) {
            $this->assertLessThanOrEqual(25, count($ids));

            return $this->authIdentities?->only($ids);
        });
        $this->app->instance(ProfileAuthIdentityService::class, $reader);
        $this->app->instance(MemberApplicationPhotoService::class, Mockery::mock(MemberApplicationPhotoService::class));
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(['https://auth.example.test/auth/v1/user' => function () {
            return Http::response(['id' => $this->actor, 'email' => 'actor@example.test']);
        }]);
    }

    protected function tearDown(): void
    {
        Association::flushEventListeners();
        DB::purge('user_management_test');
        parent::tearDown();
    }

    private function schema(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->boolean('is_active');
            $table->timestamps();
            $table->text('description')->nullable();
        });
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('role_id');
            $table->string('display_name');
            $table->boolean('is_active');
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
        });
        Schema::create('associations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('status');
            $table->uuid('primary_field_officer_id');
            $table->uuid('updated_by')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->foreign('primary_field_officer_id')->references('id')->on('user_profiles');
        });
        Schema::create('association_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('association_id')->unique();
            $table->uuid('user_id')->unique();
            $table->boolean('is_active');
            $table->timestamps();
        });
        Schema::create('user_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email');
            $table->uuid('auth_user_id')->nullable();
            $table->string('status');
            $table->string('last_error_code')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('actor_user_id')->nullable();
            $table->string('action_type');
            $table->string('module');
            $table->string('entity_type')->nullable();
            $table->string('entity_id')->nullable();
            $table->text('old_values')->nullable();
            $table->text('new_values')->nullable();
            $table->timestamp('performed_at');
        });
        Schema::create('member_applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('association_id');
            $table->string('status');
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamps();
        });
    }

    private function fixture(): void
    {
        foreach ([1 => 'system_admin', 2 => 'field_officer', 3 => 'association_account'] as $id => $code) {
            DB::table('roles')->insert(['id' => $id, 'code' => $code, 'name' => ucwords(str_replace('_', ' ', $code)),
                'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([self::ADMIN => [1, 'Synthetic Administrator'], self::OFFICER_A => [2, 'Synthetic Officer A'],
            self::OFFICER_B => [2, 'Synthetic Officer B'], self::ACCOUNT => [3, 'Synthetic Association Account']] as $id => $values) {
            DB::table('user_profiles')->insert(['id' => $id, 'role_id' => $values[0], 'display_name' => $values[1],
                'is_active' => true, 'activated_at' => now()->subDay(), 'created_at' => now()->subDays(2), 'updated_at' => now()]);
        }
        foreach ([self::ASSOCIATION => [self::OFFICER_A, 'First Synthetic Association', 'active', null],
            self::SECOND => [self::OFFICER_A, 'Second Synthetic Association', 'inactive', null],
            self::OTHER => [self::OFFICER_B, 'Other Synthetic Association', 'active', null],
            self::ARCHIVED => [self::OFFICER_A, 'Archived Synthetic Association', 'active', now()]] as $id => $values) {
            DB::table('associations')->insert(['id' => $id, 'primary_field_officer_id' => $values[0],
                'name' => $values[1], 'status' => $values[2], 'archived_at' => $values[3],
                'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('association_accounts')->insert(['id' => self::LINK, 'user_id' => self::ACCOUNT,
            'association_id' => self::ASSOCIATION, 'is_active' => true]);
        DB::table('member_applications')->insert(['id' => self::APPLICATION, 'association_id' => self::ASSOCIATION,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function actorSessionData(): array
    {
        return ['supabase_access_token' => 'synthetic_actor_access'];
    }

    private function assignmentUrl(string $officer = self::OFFICER_B): string
    {
        return '/admin/users/'.$officer.'/associations';
    }

    private function assignmentData(array $ids = [self::ASSOCIATION, self::SECOND]): array
    {
        return ['association_ids' => $ids, 'expected_officers' => array_fill_keys($ids, self::OFFICER_A),
            'confirm_reassignment' => '1'];
    }

    public function test_administrator_register_lists_profiles_current_emails_and_relationships(): void
    {
        DB::table('user_profiles')->where('id', self::OFFICER_B)->update(['is_active' => false]);
        DB::table('user_invitations')->insert(['id' => self::INVITATION, 'auth_user_id' => self::ACCOUNT,
            'email' => 'association@example.test', 'status' => 'accepted', 'accepted_at' => now(),
            'created_at' => now(), 'updated_at' => now()]);
        $response = $this->withSession($this->actorSessionData())->get('/admin/users');
        $response->assertOk()->assertSee('Synthetic Officer A')->assertSee('officer-a@example.test')
            ->assertSee('Inactive')->assertSee('Accepted')->assertSee('First Synthetic Association')
            ->assertSee('3 association(s); 1 archived.')->assertDontSee('synthetic_actor_access');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        Http::assertSentCount(1);
    }

    #[DataProvider('otherActors')]
    public function test_other_roles_cannot_list_users_or_manage_assignments(string $actor): void
    {
        $this->actor = $actor;
        $this->withSession($this->actorSessionData())->get('/admin/users')->assertForbidden();
        $this->get($this->assignmentUrl())->assertForbidden();
        $this->patchJson($this->assignmentUrl(), $this->assignmentData())->assertForbidden();
        $this->assertSame(self::OFFICER_A, Association::findOrFail(self::ASSOCIATION)->primary_field_officer_id);
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public static function otherActors(): array
    {
        return [[self::OFFICER_A], [self::ACCOUNT]];
    }

    public function test_guest_and_disabled_administrator_are_denied(): void
    {
        $this->get('/admin/users')->assertRedirect(route('login'));
        $this->patchJson($this->assignmentUrl(), $this->assignmentData())->assertRedirect(route('login'));
        DB::table('user_profiles')->where('id', self::ADMIN)->update(['is_active' => false]);
        $this->withSession($this->actorSessionData())->get('/admin/users')->assertRedirect(route('login'));
    }

    public function test_assignment_without_csrf_token_cannot_change_records(): void
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
        $this->withSession($this->actorSessionData())->patchJson($this->assignmentUrl(), $this->assignmentData())->assertStatus(419);
        $this->patchJson('/admin/users/'.self::OFFICER_B.'/revoke', ['confirm_revocation' => '1'])->assertStatus(419);
        $this->assertSame(self::OFFICER_A, Association::findOrFail(self::ASSOCIATION)->primary_field_officer_id);
        $this->assertSame(0, DB::table('audit_logs')->count());
        Http::assertNothingSent();
    }

    public function test_missing_auth_identity_is_visible_without_provisioning_or_activation(): void
    {
        $this->authIdentities->forget(self::OFFICER_B);
        $this->withSession($this->actorSessionData())->get('/admin/users')->assertOk()
            ->assertSee('Synthetic Officer B')->assertSee('Matching Auth identity not found in server lookup');
        $this->assertSame(4, DB::table('user_profiles')->count());
        $this->assertSame(0, DB::table('user_invitations')->count());
        $this->assertSame(0, DB::table('audit_logs')->count());
        Http::assertSentCount(1);
    }

    public function test_unavailable_auth_read_does_not_hide_profiles_or_claim_missing_linkage(): void
    {
        $this->authIdentities = null;
        $this->withSession($this->actorSessionData())->get('/admin/users')->assertOk()->assertSee('Synthetic Officer A')
            ->assertSee('Auth metadata lookup unavailable')->assertDontSee('Matching Auth identity not found');
    }

    public function test_auth_only_identity_is_not_an_application_profile(): void
    {
        $this->authIdentities->put('cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            (object) ['id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'email' => 'auth-only@example.test']);
        $this->withSession($this->actorSessionData())->get('/admin/users')->assertOk()->assertDontSee('auth-only@example.test');
        $this->assertSame(4, DB::table('user_profiles')->count());
    }

    public function test_invitation_email_mismatch_is_not_silently_synchronized(): void
    {
        DB::table('user_invitations')->insert(['id' => self::INVITATION, 'auth_user_id' => self::ACCOUNT,
            'email' => 'historical@example.test', 'status' => 'accepted', 'accepted_at' => now(),
            'created_at' => now(), 'updated_at' => now()]);
        $this->withSession($this->actorSessionData())->get('/admin/users')->assertOk()->assertSee('association@example.test')
            ->assertSee('Current Auth email differs from invitation history');
        $this->assertSame('historical@example.test', DB::table('user_invitations')->value('email'));
    }

    public function test_assignment_form_displays_existing_and_selectable_associations(): void
    {
        $this->withSession($this->actorSessionData())->get($this->assignmentUrl(self::OFFICER_A))->assertOk()
            ->assertSee('Current assignments (3)')->assertSee('Archived Synthetic Association')
            ->assertSee('Other Synthetic Association')->assertSee('name="_token"', false);
    }

    public function test_multiple_transfers_are_atomic_and_repeated_submission_creates_no_duplicate_events(): void
    {
        DB::table('associations')->where('id', self::SECOND)->update(['status' => 'active']);
        $this->withSession($this->actorSessionData())->patchJson($this->assignmentUrl(), $this->assignmentData())
            ->assertOk()->assertJsonPath('changed', 2);
        $this->assertSame(3, Association::where('primary_field_officer_id', self::OFFICER_B)->count());
        $this->assertSame(self::OFFICER_A, Association::findOrFail(self::ARCHIVED)->primary_field_officer_id);
        $this->assertSame(self::ADMIN, Association::findOrFail(self::ASSOCIATION)->updated_by);
        $this->assertSame(2, DB::table('audit_logs')->count());
        $this->assertSame(self::ADMIN, DB::table('audit_logs')->value('actor_user_id'));
        $event = DB::table('audit_logs')->where('entity_id', self::ASSOCIATION)->sole();
        $this->assertSame('REASSIGN FIELD OFFICER', $event->action_type);
        $this->assertSame(['primary_field_officer_id' => self::OFFICER_A], json_decode($event->old_values, true));
        $this->assertSame(['primary_field_officer_id' => self::OFFICER_B], json_decode($event->new_values, true));
        $this->assertNotNull($event->performed_at);
        $this->assertSame(self::ASSOCIATION, DB::table('association_accounts')->value('association_id'));
        $this->patchJson($this->assignmentUrl(), $this->assignmentData())->assertOk()->assertJsonPath('changed', 0);
        $this->assertSame(2, DB::table('audit_logs')->count());
        $this->assertSame(4, Association::count());
    }

    public function test_duplicates_missing_snapshots_and_unconfirmed_changes_are_rejected(): void
    {
        $this->withSession($this->actorSessionData())->patchJson($this->assignmentUrl(), $this->assignmentData([self::ASSOCIATION, self::ASSOCIATION]))
            ->assertUnprocessable();
        $data = $this->assignmentData();
        $data['expected_officers'] = [];
        $this->patchJson($this->assignmentUrl(), $data)->assertUnprocessable();
        $data = $this->assignmentData();
        $data['confirm_reassignment'] = '0';
        $this->patchJson($this->assignmentUrl(), $data)->assertUnprocessable();
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_archived_and_missing_associations_roll_back_the_whole_batch(): void
    {
        $this->withSession($this->actorSessionData())->patchJson($this->assignmentUrl(), $this->assignmentData([self::ASSOCIATION, self::ARCHIVED]))
            ->assertUnprocessable();
        $this->assertSame(self::OFFICER_A, Association::findOrFail(self::ASSOCIATION)->primary_field_officer_id);
        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->patchJson($this->assignmentUrl(), $this->assignmentData(['dddddddd-dddd-4ddd-8ddd-dddddddddddd']))
            ->assertUnprocessable();
    }

    public function test_inactive_association_rolls_back_the_whole_assignment_batch(): void
    {
        $this->withSession($this->actorSessionData())->patchJson($this->assignmentUrl(), $this->assignmentData())
            ->assertUnprocessable()->assertJsonValidationErrors('association_ids');
        $this->assertSame(self::OFFICER_A, Association::findOrFail(self::ASSOCIATION)->primary_field_officer_id);
        $this->assertSame(self::OFFICER_A, Association::findOrFail(self::SECOND)->primary_field_officer_id);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_stale_transfer_cannot_overwrite_a_different_current_officer(): void
    {
        $data = $this->assignmentData([self::ASSOCIATION]);
        $data['expected_officers'][self::ASSOCIATION] = self::OFFICER_B;
        $this->withSession($this->actorSessionData())->patchJson($this->assignmentUrl(), $data)->assertStatus(409);
        $this->assertSame(self::OFFICER_A, Association::findOrFail(self::ASSOCIATION)->primary_field_officer_id);
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_ineligible_target_and_revoked_administrator_are_denied_without_writes(): void
    {
        $this->withSession($this->actorSessionData())->patchJson($this->assignmentUrl(self::ACCOUNT), $this->assignmentData())->assertUnprocessable();
        DB::table('roles')->where('id', 2)->update(['is_active' => false]);
        $this->patchJson($this->assignmentUrl(), $this->assignmentData())->assertUnprocessable();
        DB::table('roles')->where('id', 1)->update(['is_active' => false]);
        $this->patchJson($this->assignmentUrl(), $this->assignmentData())->assertRedirect(route('login'));
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_inactive_or_pending_invitation_officer_cannot_receive_assignments(): void
    {
        DB::table('user_profiles')->where('id', self::OFFICER_B)->update(['is_active' => false]);
        $this->withSession($this->actorSessionData())->patchJson($this->assignmentUrl(), $this->assignmentData())->assertUnprocessable();
        DB::table('user_profiles')->where('id', self::OFFICER_B)->update(['is_active' => true]);
        DB::table('user_invitations')->insert(['id' => self::INVITATION, 'auth_user_id' => self::OFFICER_B,
            'email' => 'officer-b@example.test', 'status' => 'sent', 'created_at' => now(), 'updated_at' => now()]);
        $this->patchJson($this->assignmentUrl(), $this->assignmentData())->assertUnprocessable();
        $this->assertSame(self::OFFICER_A, Association::findOrFail(self::ASSOCIATION)->primary_field_officer_id);
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_assignment_payload_cannot_change_actor_or_role(): void
    {
        $data = $this->assignmentData();
        $data['updated_by'] = self::OFFICER_A;
        $data['role_id'] = 1;
        $this->withSession($this->actorSessionData())->patchJson($this->assignmentUrl(), $data)->assertUnprocessable()
            ->assertJsonValidationErrors(['updated_by', 'role_id']);
        $this->assertSame(2, (int) DB::table('user_profiles')->where('id', self::OFFICER_B)->value('role_id'));
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_audit_failure_rolls_back_assignment_changes(): void
    {
        DB::table('associations')->where('id', self::SECOND)->update(['status' => 'active']);
        Schema::drop('audit_logs');
        $this->withSession($this->actorSessionData())->patchJson($this->assignmentUrl(), $this->assignmentData())->assertStatus(503);
        $this->assertSame(self::OFFICER_A, Association::findOrFail(self::ASSOCIATION)->primary_field_officer_id);
        $this->assertSame(self::OFFICER_A, Association::findOrFail(self::SECOND)->primary_field_officer_id);
    }

    public function test_old_officer_loses_review_scope_and_new_officer_receives_it(): void
    {
        Schema::create('monitoring_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('monitored_by');
            $table->text('remarks');
        });
        DB::table('monitoring_records')->insert(['id' => self::APPLICATION, 'project_id' => self::ASSOCIATION,
            'monitored_by' => self::OFFICER_A, 'remarks' => 'Retained historical monitoring attribution']);
        $monitoringHistory = DB::table('monitoring_records')->get()->toJson();
        $this->withSession($this->actorSessionData())->patchJson($this->assignmentUrl(), $this->assignmentData([self::ASSOCIATION]))->assertOk();
        $this->actor = self::OFFICER_A;
        $url = '/field-officer/member-applications/'.self::APPLICATION.'/reject';
        $this->patchJson($url, ['rejection_reason' => 'Synthetic decision'])->assertUnprocessable()->assertJsonValidationErrors('reviewer');
        $this->assertSame('pending', DB::table('member_applications')->value('status'));
        $this->actor = self::OFFICER_B;
        $this->patchJson($url, ['rejection_reason' => 'Synthetic decision'])->assertRedirect();
        $this->assertSame('rejected', DB::table('member_applications')->value('status'));
        $this->assertSame(self::OFFICER_B, DB::table('member_applications')->value('reviewed_by'));
        $this->assertSame(self::ACCOUNT, DB::table('association_accounts')->value('user_id'));
        $this->assertSame(self::ASSOCIATION, DB::table('association_accounts')->value('association_id'));
        $this->assertSame($monitoringHistory, DB::table('monitoring_records')->get()->toJson());
    }

    private function realIdentityLookup(): void
    {
        DB::statement("ATTACH DATABASE ':memory:' AS auth");
        Schema::create('auth.users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email');
        });
        DB::table('auth.users')->insert($this->authIdentities->map(fn ($identity) => (array) $identity)->values()->all());
        $this->app->instance(ProfileAuthIdentityService::class, new ProfileAuthIdentityService);
    }

    private function registerUrl(array $filters): string
    {
        return '/admin/users?'.http_build_query($filters);
    }

    private function registerDom(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    public function test_summary_cards_filter_profiles_and_match_global_counts(): void
    {
        DB::table('user_profiles')->where('id', self::OFFICER_B)->update(['is_active' => false]);
        $expected = [
            'total' => [4, [self::ADMIN, self::ACCOUNT, self::OFFICER_A, self::OFFICER_B]],
            'system_admin' => [1, [self::ADMIN]],
            'field_officer' => [2, [self::OFFICER_A, self::OFFICER_B]],
            'association_account' => [1, [self::ACCOUNT]],
            'inactive' => [1, [self::OFFICER_B]],
        ];
        $response = $this->withSession($this->actorSessionData())->get('/admin/users')->assertOk();
        $this->assertSame(array_map(fn ($entry) => $entry[0], $expected), $response->viewData('summary'));
        $dom = $this->registerDom($response->getContent());
        foreach ($expected as $key => [$count, $ids]) {
            $card = $dom->query('//a[@data-user-summary="'.$key.'"]')->item(0);
            $this->assertNotNull($card);
            $filtered = $this->get($card->getAttribute('href'))->assertOk();
            $this->assertSame($count, $filtered->viewData('users')->total());
            $this->assertSame($ids, $filtered->viewData('users')->getCollection()->pluck('id')->all());
            $selected = $this->registerDom($filtered->getContent())->query('//a[@data-user-summary and @aria-current="page"]');
            $this->assertSame(1, $selected->length);
            $this->assertSame($key, $selected->item(0)->getAttribute('data-user-summary'));
        }
        $manual = $this->get('/admin/users?role=field_officer&status=inactive')->assertOk();
        $this->assertSame([self::OFFICER_B], $manual->viewData('users')->getCollection()->pluck('id')->all());
        $this->assertSame(0, $this->registerDom($manual->getContent())->query('//a[@data-user-summary and @aria-current]')->length);
    }

    public function test_name_and_current_email_search_are_case_insensitive_and_do_not_list_auth_only_records(): void
    {
        $this->realIdentityLookup();
        DB::table('auth.users')->insert(['id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'email' => 'auth-only@example.test']);
        DB::table('user_invitations')->insert(['id' => self::INVITATION, 'auth_user_id' => self::ACCOUNT,
            'email' => 'historical@example.test', 'status' => 'accepted', 'accepted_at' => now(),
            'created_at' => now(), 'updated_at' => now()]);
        foreach ([
            '  OFFICER A  ' => [self::OFFICER_A],
            'OFFICER-B@EXAMPLE.TEST' => [self::OFFICER_B],
            'auth-only@example.test' => [],
            'historical@example.test' => [],
            "' OR 1=1 --" => [],
        ] as $term => $ids) {
            $response = $this->withSession($this->actorSessionData())->get($this->registerUrl(['q' => $term]))->assertOk();
            $this->assertSame($ids, $response->viewData('users')->getCollection()->pluck('id')->all());
            $this->assertSame(4, $response->viewData('summary')['total']);
        }
        $response = $this->get('/admin/users?q=officer&role=field_officer&status=active')->assertOk();
        $this->assertSame(2, $response->viewData('users')->total());
        $this->assertSame(4, DB::table('user_profiles')->count());
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_search_treats_like_wildcards_literally_and_escapes_rendered_names(): void
    {
        $this->realIdentityLookup();
        DB::table('user_profiles')->where('id', self::OFFICER_A)->update(['display_name' => 'Rate 50%_! <script>alert(1)</script>']);
        foreach (['%', '_', '!', '50%_!'] as $term) {
            $response = $this->withSession($this->actorSessionData())->get($this->registerUrl(['q' => $term]))->assertOk();
            $this->assertSame([self::OFFICER_A], $response->viewData('users')->getCollection()->pluck('id')->all());
            $response->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        }
    }

    public function test_all_sort_options_use_stable_server_ordering(): void
    {
        foreach ([self::ADMIN => '2020-01-01', self::OFFICER_A => '2022-01-01',
            self::OFFICER_B => '2022-01-01', self::ACCOUNT => '2023-01-01'] as $id => $created) {
            DB::table('user_profiles')->where('id', $id)->update(['created_at' => $created]);
        }
        foreach ([
            'name' => [self::ADMIN, self::ACCOUNT, self::OFFICER_A, self::OFFICER_B],
            'name_desc' => [self::OFFICER_B, self::OFFICER_A, self::ACCOUNT, self::ADMIN],
            'newest' => [self::ACCOUNT, self::OFFICER_A, self::OFFICER_B, self::ADMIN],
            'oldest' => [self::ADMIN, self::OFFICER_A, self::OFFICER_B, self::ACCOUNT],
        ] as $sort => $ids) {
            $response = $this->withSession($this->actorSessionData())->get('/admin/users?sort='.$sort)->assertOk();
            $this->assertSame($ids, $response->viewData('users')->getCollection()->pluck('id')->all());
        }
    }

    public function test_pagination_retains_filters_and_cards_reset_page_without_losing_search_or_sort(): void
    {
        $this->realIdentityLookup();
        for ($number = 1; $number <= 27; $number++) {
            DB::table('user_profiles')->insert(['id' => sprintf('eeeeeeee-eeee-4eee-8eee-%012d', $number),
                'role_id' => 2, 'display_name' => sprintf('Officer extra %02d', $number), 'is_active' => true,
                'created_at' => now(), 'updated_at' => now()]);
        }
        $filters = ['q' => 'officer', 'role' => 'field_officer', 'status' => 'active', 'sort' => 'name_desc'];
        $response = $this->withSession($this->actorSessionData())->get($this->registerUrl($filters))->assertOk();
        $users = $response->viewData('users');
        $this->assertSame(29, $users->total());
        $this->assertCount(25, $users);
        $next = $this->get($users->nextPageUrl())->assertOk();
        $this->assertSame($filters, $next->viewData('filters'));
        $this->assertCount(4, $next->viewData('users'));
        $dom = $this->registerDom($next->getContent());
        foreach ($dom->query('//a[@data-user-summary]') as $card) {
            parse_str(parse_url($card->getAttribute('href'), PHP_URL_QUERY) ?? '', $query);
            $this->assertArrayNotHasKey('page', $query);
            $this->assertSame('officer', $query['q']);
            $this->assertSame('name_desc', $query['sort']);
        }
        $detailLink = $dom->query('//a[normalize-space(.)="View Account Details"]')->item(0);
        $details = $this->get($detailLink->getAttribute('href'))->assertOk();
        parse_str(parse_url($details->viewData('backUrl'), PHP_URL_QUERY), $backQuery);
        $this->assertSame($filters + ['page' => '2'], $backQuery);
        $this->assertSame(31, $next->viewData('summary')['total']);
    }

    #[DataProvider('invalidRegisterFilters')]
    public function test_malformed_filters_are_rejected_before_queries(array $filters): void
    {
        $reader = Mockery::mock(ProfileAuthIdentityService::class);
        $reader->shouldNotReceive('read');
        $reader->shouldNotReceive('matchingEmails');
        $this->app->instance(ProfileAuthIdentityService::class, $reader);
        $this->withSession($this->actorSessionData())->getJson($this->registerUrl($filters))->assertUnprocessable();
        $this->getJson('/admin/users/'.self::OFFICER_A.'?'.http_build_query($filters))->assertUnprocessable();
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public static function invalidRegisterFilters(): array
    {
        return [
            'role array' => [['role' => ['system_admin']]],
            'unsupported role' => [['role' => 'public']],
            'status array' => [['status' => ['active']]],
            'unsupported status' => [['status' => 'deleted']],
            'sort injection' => [['sort' => 'name DESC; DROP TABLE user_profiles']],
            'sort array' => [['sort' => ['name']]],
            'search array' => [['q' => ['officer']]],
            'long search' => [['q' => str_repeat('x', 121)]],
            'invalid page' => [['page' => 0]],
            'invalid assignment page' => [['assigned_page' => -1]],
        ];
    }

    public function test_invalid_html_filter_redirects_to_a_renderable_register(): void
    {
        $this->withSession($this->actorSessionData())->get('/admin/users?role=invalid')->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors('role');
        $this->get('/admin/users')->assertOk()->assertSee('id="user-filter-errors"', false);
    }

    public function test_dedicated_details_show_only_actual_assignments_and_preserve_records(): void
    {
        DB::table('user_profiles')->where('id', self::OFFICER_A)->update(['activated_at' => '2025-01-01 00:00:00']);
        $before = DB::table('associations')->orderBy('id')->get()->toJson();
        $response = $this->withSession($this->actorSessionData())->get('/admin/users/'.self::OFFICER_A)->assertOk()
            ->assertViewIs('admin.users.details')->assertSee('Synthetic Officer A')->assertSee('officer-a@example.test')
            ->assertSee('First Synthetic Association')->assertSee('Second Synthetic Association')->assertSee('Archived Synthetic Association')
            ->assertDontSee('Other Synthetic Association')->assertSee('Jan 01, 2025 8:00 AM')->assertSee('Manage assignments')
            ->assertDontSee('synthetic_actor_access');
        $this->assertSame(3, $response->viewData('assigned')->total());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertSame($before, DB::table('associations')->orderBy('id')->get()->toJson());
        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->get('/admin/users/'.self::ACCOUNT)->assertOk()->assertSee('First Synthetic Association')
            ->assertSee('Linked association')->assertDontSee('Manage assignments');
        $this->get('/admin/users/'.self::OFFICER_B)->assertOk()->assertSee('Other Synthetic Association')
            ->assertDontSee('First Synthetic Association');
    }

    public function test_assignment_details_paginate_with_a_stable_return_link(): void
    {
        for ($number = 1; $number <= 27; $number++) {
            DB::table('associations')->insert(['id' => sprintf('dddddddd-dddd-4ddd-8ddd-%012d', $number),
                'primary_field_officer_id' => self::OFFICER_A, 'name' => sprintf('Extra association %02d', $number),
                'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        }
        $response = $this->withSession($this->actorSessionData())->get('/admin/users/'.self::OFFICER_A.'?role=field_officer&page=2')->assertOk();
        $assigned = $response->viewData('assigned');
        $this->assertSame(30, $assigned->total());
        $this->assertCount(25, $assigned);
        $next = $this->get($assigned->nextPageUrl())->assertOk();
        $this->assertCount(5, $next->viewData('assigned'));
        $this->assertSame($response->viewData('backUrl'), $next->viewData('backUrl'));
    }

    #[DataProvider('otherActors')]
    public function test_nonadministrators_cannot_access_details_even_with_filter_parameters(string $actor): void
    {
        $this->actor = $actor;
        $reader = Mockery::mock(ProfileAuthIdentityService::class);
        $reader->shouldNotReceive('read');
        $this->app->instance(ProfileAuthIdentityService::class, $reader);
        $this->withSession($this->actorSessionData())->get('/admin/users/'.self::OFFICER_A.'?role=system_admin')->assertForbidden();
        $this->get('/admin/users?role=system_admin&status=active')->assertForbidden();
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_details_reject_guests_revoked_administrators_and_missing_records(): void
    {
        $url = '/admin/users/'.self::OFFICER_A;
        $this->get($url)->assertRedirect(route('login'));
        $this->withSession($this->actorSessionData())->get('/admin/users/not-a-uuid')->assertNotFound();
        $this->get('/admin/users/cccccccc-cccc-4ccc-8ccc-cccccccccccc')->assertNotFound();
        DB::table('roles')->where('id', 1)->update(['is_active' => false]);
        $this->get($url)->assertRedirect(route('login'));
        DB::table('roles')->where('id', 1)->update(['is_active' => true]);
        DB::table('user_profiles')->where('id', self::ADMIN)->update(['is_active' => false]);
        $this->withSession($this->actorSessionData())->get($url)->assertRedirect(route('login'));
    }

    public function test_details_do_not_activate_pending_or_inactive_accounts_and_report_unavailable_metadata(): void
    {
        DB::table('user_profiles')->where('id', self::OFFICER_B)->update(['is_active' => false]);
        DB::table('user_invitations')->insert(['id' => self::INVITATION, 'auth_user_id' => self::OFFICER_B,
            'email' => 'officer-b@example.test', 'status' => 'sent', 'created_at' => now(), 'updated_at' => now()]);
        $this->authIdentities = null;
        $this->withSession($this->actorSessionData())->get('/admin/users/'.self::OFFICER_B)->assertOk()
            ->assertSee('Inactive')->assertSee('Sent')->assertSee('Auth metadata lookup unavailable')
            ->assertDontSee('Matching Auth identity not found')->assertDontSee('Manage assignments');
        $this->assertFalse((bool) DB::table('user_profiles')->where('id', self::OFFICER_B)->value('is_active'));
        $this->assertSame('sent', DB::table('user_invitations')->value('status'));
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_read_failures_return_generic_unavailable_states_without_partial_search_results(): void
    {
        $this->realIdentityLookup();
        Schema::drop('auth.users');
        $this->withSession($this->actorSessionData())->get('/admin/users?q=Synthetic')->assertStatus(503)
            ->assertSee('User records are unavailable')->assertDontSee('SQLSTATE')->assertDontSee('no such table');
        Schema::drop('associations');
        $this->get('/admin/users/'.self::OFFICER_A)->assertStatus(503)
            ->assertSee('Account details are unavailable')->assertDontSee('SQLSTATE')->assertDontSee('no such table');
    }

    public function test_actions_keep_native_disclosure_and_route_revocation_through_account_details(): void
    {
        $response = $this->withSession($this->actorSessionData())->get('/admin/users')->assertOk();
        $dom = $this->registerDom($response->getContent());
        $this->assertSame(4, $dom->query('//details[@data-user-actions]/summary[@aria-label]')->length);
        $this->assertSame(4, $dom->query('//details[@data-user-actions]//a[normalize-space(.)="View Account Details"]')->length);
        $this->assertSame(4, $dom->query('//details[@data-user-actions]//button[@type="button" and @disabled and contains(.,"Reset Password")]')->length);
        $this->assertSame(3, $dom->query('//details[@data-user-actions]//a[contains(@href,"#user-access") and contains(.,"Manage access")]')->length);
        $this->assertSame(1, $dom->query('//details[@data-user-actions]//button[@disabled and contains(.,"Your account")]')->length);
        $this->assertSame(0, $dom->query('//details[@data-user-actions]//form')->length);
        $this->assertSame(1, $dom->query('//a[@href="'.route('admin.user-invitations.create').'" and contains(.,"Invite User")]')->length);
        $this->assertSame(0, DB::table('user_invitations')->count());
        $this->assertSame(0, DB::table('audit_logs')->count());
        Http::assertSentCount(1);
    }

    public static function revocationTargets(): array
    {
        return [[self::OFFICER_A, 'field_officer'], [self::ACCOUNT, 'association_account']];
    }

    #[DataProvider('revocationTargets')]
    public function test_revocation_preserves_records_cancels_open_invitations_and_records_one_audit(string $target, string $role): void
    {
        $pending = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        foreach ([[self::INVITATION, 'accepted'], [$pending, 'sent']] as [$id, $status]) {
            DB::table('user_invitations')->insert(['id' => $id, 'auth_user_id' => $target,
                'email' => 'target@example.test', 'status' => $status,
                'accepted_at' => $status === 'accepted' ? now()->subDay() : null,
                'created_at' => $status === 'accepted' ? now()->subDays(2) : now(), 'updated_at' => now()]);
        }
        $before = [];
        foreach (['associations', 'association_accounts', 'member_applications'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $url = '/admin/users/'.$target.'/revoke';
        $this->withSession($this->actorSessionData())->patchJson($url, ['confirm_revocation' => '1'])
            ->assertOk()->assertJsonPath('changed', true);

        $profile = UserProfile::with('role')->findOrFail($target);
        $this->assertFalse($profile->is_active);
        $this->assertSame($role, $profile->role->code);
        $this->assertSame('cancelled', DB::table('user_invitations')->where('id', $pending)->value('status'));
        $this->assertSame('accepted', DB::table('user_invitations')->where('id', self::INVITATION)->value('status'));
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->orderBy('id')->get()->toJson());
        }
        $audit = DB::table('audit_logs')->sole();
        $this->assertSame(self::ADMIN, $audit->actor_user_id);
        $this->assertSame(UserAccessRevocationService::ACTION, $audit->action_type);
        $this->assertSame($target, $audit->entity_id);
        $this->assertNotEmpty($audit->performed_at);
        $this->assertSame(['is_active' => true, 'invitation_statuses' => [$pending => 'sent']], json_decode($audit->old_values, true));
        $this->assertSame(['is_active' => false, 'cancelled_invitation_ids' => [$pending]], json_decode($audit->new_values, true));
        $this->patchJson($url, ['confirm_revocation' => '1'])->assertOk()->assertJsonPath('changed', false);
        $this->assertSame(1, DB::table('audit_logs')->count());
        $this->get('/admin/users/'.$target)->assertOk()->assertSee('Access revoked on')->assertDontSee('name="confirm_revocation"', false);
        $this->get('/admin/users?status=inactive')->assertOk()->assertSee('Revoked');
        try {
            app(InvitationActivationService::class)->identify(['auth_user_id' => $target, 'email' => 'target@example.test']);
            $this->fail('A cancelled invitation remained eligible for activation.');
        } catch (InvitationActivationException $exception) {
            $this->assertFalse(UserProfile::findOrFail($target)->is_active);
        }
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    #[DataProvider('revocationTargets')]
    public function test_revoked_accounts_cannot_reuse_existing_web_sessions_or_bearer_tokens(string $target, string $role): void
    {
        Route::middleware(['web', 'supabase.auth', 'role:'.$role])->get('/_tests/revocation-probe',
            fn () => response('Synthetic protected content'));
        $this->actor = $target;
        $this->withSession($this->actorSessionData())->get('/_tests/revocation-probe')->assertOk();
        $this->actor = self::ADMIN;
        $this->patchJson('/admin/users/'.$target.'/revoke', ['confirm_revocation' => '1'])->assertOk();
        $this->actor = $target;
        $this->get('/_tests/revocation-probe')->assertRedirect(route('login'))
            ->assertSessionMissing('supabase_access_token');
        $this->withHeader('Authorization', 'Bearer synthetic.header.signature')->getJson('/api/v1/me')->assertForbidden();
        $this->get('/_tests/revocation-probe')->assertRedirect(route('login'));
    }

    public function test_self_revocation_missing_confirmation_and_account_mutation_are_rejected(): void
    {
        $this->withSession($this->actorSessionData())->patchJson('/admin/users/'.self::ADMIN.'/revoke', ['confirm_revocation' => '1'])
            ->assertUnprocessable()->assertJsonValidationErrors('confirm_revocation');
        $this->patchJson('/admin/users/'.self::OFFICER_A.'/revoke', [])->assertUnprocessable();
        $this->patchJson('/admin/users/'.self::OFFICER_A.'/revoke', [
            'confirm_revocation' => '1', 'is_active' => true, 'role_id' => 1,
        ])->assertUnprocessable();
        $this->assertSame(4, DB::table('user_profiles')->where('is_active', true)->count());
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    #[DataProvider('otherActors')]
    public function test_non_administrators_cannot_revoke_access(string $actor): void
    {
        $this->actor = $actor;
        $this->withSession($this->actorSessionData())->patchJson('/admin/users/'.self::OFFICER_B.'/revoke', ['confirm_revocation' => '1'])
            ->assertForbidden();
        $this->assertTrue(UserProfile::findOrFail(self::OFFICER_B)->is_active);
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_guest_and_inactive_administrator_cannot_revoke_access(): void
    {
        $url = '/admin/users/'.self::OFFICER_B.'/revoke';
        $this->patchJson($url, ['confirm_revocation' => '1'])->assertRedirect(route('login'));
        DB::table('user_profiles')->where('id', self::ADMIN)->update(['is_active' => false]);
        $this->withSession($this->actorSessionData())->patchJson($url, ['confirm_revocation' => '1'])->assertRedirect(route('login'));
        $this->assertTrue(UserProfile::findOrFail(self::OFFICER_B)->is_active);
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_another_administrator_can_be_revoked_while_the_remaining_administrator_is_preserved(): void
    {
        $other = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
        DB::table('user_profiles')->insert(['id' => $other, 'role_id' => 1, 'display_name' => 'Second Synthetic Administrator',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->withSession($this->actorSessionData())->patchJson('/admin/users/'.$other.'/revoke', ['confirm_revocation' => '1'])->assertOk();
        $this->assertSame(1, DB::table('user_profiles')->where('role_id', 1)->where('is_active', true)->count());
        $this->actor = $other;
        $this->patchJson('/admin/users/'.self::ADMIN.'/revoke', ['confirm_revocation' => '1'])->assertRedirect(route('login'));
        $this->assertTrue(UserProfile::findOrFail(self::ADMIN)->is_active);
        $this->assertSame(1, DB::table('audit_logs')->count());
    }

    public function test_revoking_a_pending_inactive_account_also_cancels_its_invitation(): void
    {
        DB::table('user_profiles')->where('id', self::OFFICER_B)->update(['is_active' => false]);
        DB::table('user_invitations')->insert(['id' => self::INVITATION, 'auth_user_id' => self::OFFICER_B,
            'email' => 'target@example.test', 'status' => 'sent', 'created_at' => now(), 'updated_at' => now()]);
        $this->withSession($this->actorSessionData())->patchJson('/admin/users/'.self::OFFICER_B.'/revoke', ['confirm_revocation' => '1'])
            ->assertOk()->assertJsonPath('changed', true);
        $this->assertFalse(UserProfile::findOrFail(self::OFFICER_B)->is_active);
        $this->assertSame('cancelled', DB::table('user_invitations')->where('id', self::INVITATION)->value('status'));
        $this->assertSame(1, DB::table('audit_logs')->count());
    }

    public function test_revocation_rechecks_administrator_status_after_request_authorization(): void
    {
        $actor = UserProfile::with('role')->findOrFail(self::ADMIN);
        $this->assertTrue($actor->canAccessApplication());
        $request = Request::create('/admin/users/'.self::OFFICER_B.'/revoke', 'PATCH', ['confirm_revocation' => '1']);
        $request->attributes->set('current_user', $actor);
        DB::table('user_profiles')->where('id', self::ADMIN)->update(['is_active' => false]);
        try {
            app(UserAccessRevocationService::class)->revoke($request, UserProfile::findOrFail(self::OFFICER_B));
            $this->fail('A stale Administrator authorization allowed revocation.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertTrue(UserProfile::findOrFail(self::OFFICER_B)->is_active);
        $this->assertSame(0, DB::table('audit_logs')->count());
        Http::assertNothingSent();
    }

    public function test_audit_failure_rolls_back_revocation_and_invitation_cancellation(): void
    {
        DB::table('user_invitations')->insert(['id' => self::INVITATION, 'auth_user_id' => self::OFFICER_B,
            'email' => 'target@example.test', 'status' => 'sent', 'created_at' => now(), 'updated_at' => now()]);
        $audit = Mockery::mock(AuditLogService::class);
        $audit->shouldReceive('record')->once()->andThrow(new \RuntimeException('Synthetic private audit failure'));
        $this->app->instance(AuditLogService::class, $audit);
        $this->withSession($this->actorSessionData())->patchJson('/admin/users/'.self::OFFICER_B.'/revoke', ['confirm_revocation' => '1'])
            ->assertStatus(503)->assertDontSee('Synthetic private audit failure');
        $this->assertTrue(UserProfile::findOrFail(self::OFFICER_B)->is_active);
        $this->assertSame('sent', DB::table('user_invitations')->where('id', self::INVITATION)->value('status'));
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_register_query_count_is_constant_as_page_size_grows_and_identity_reads_stay_batched(): void
    {
        $this->realIdentityLookup();
        $query = $this->app->make(UserManagementQueryService::class);
        DB::enableQueryLog();
        $query->users();
        $before = DB::getQueryLog();
        DB::disableQueryLog();
        for ($number = 1; $number <= 21; $number++) {
            DB::table('user_profiles')->insert(['id' => sprintf('eeeeeeee-eeee-4eee-8eee-%012d', $number),
                'role_id' => 2, 'display_name' => 'Extra Officer '.$number, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now()]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $users = $query->users();
        $after = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(25, $users);
        $this->assertSame(count($before), count($after));
        $identityReads = array_values(array_filter($after, fn ($entry) => str_contains($entry['query'], '"auth"."users"')));
        $this->assertCount(1, $identityReads);
        $this->assertCount(25, $identityReads[0]['bindings']);
        $this->assertLessThanOrEqual(9, count($after));
    }

    public function test_linked_association_is_explicit_and_retained_separately_from_revoked_access(): void
    {
        $response = $this->withSession($this->actorSessionData())->get('/admin/users')->assertOk();
        $account = $response->viewData('users')->getCollection()->firstWhere('id', self::ACCOUNT);
        $this->assertSame('First Synthetic Association', $account['association']);
        $this->assertSame('Active relationship', $account['association_relationship_status']);
        $response->assertSee('Linked association');
        $this->patchJson('/admin/users/'.self::ACCOUNT.'/revoke', ['confirm_revocation' => '1'])->assertOk();
        $this->get('/admin/users/'.self::ACCOUNT)->assertOk()->assertSee('Revoked')
            ->assertSee('First Synthetic Association')->assertSee('Active relationship')
            ->assertSee('Account access and the retained association relationship are separate.');
        $this->assertSame(self::LINK, DB::table('association_accounts')->value('id'));
    }

    public function test_missing_and_inconsistent_association_relationships_are_not_mislabeled(): void
    {
        DB::table('association_accounts')->where('id', self::LINK)->update(['association_id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff']);
        $this->withSession($this->actorSessionData())->get('/admin/users/'.self::ACCOUNT)->assertOk()
            ->assertSee('Relationship needs review')->assertDontSee('No association linked');
        DB::table('association_accounts')->delete();
        $this->get('/admin/users/'.self::ACCOUNT)->assertOk()->assertSee('No association linked')
            ->assertDontSee('Relationship needs review');
    }

    public function test_invitation_references_identify_exact_current_email_and_association_matches(): void
    {
        $this->realIdentityLookup();
        $query = app(UserManagementQueryService::class);
        $references = $query->invitationReferences('ASSOCIATION@EXAMPLE.TEST', self::ASSOCIATION);
        $this->assertSame([self::ACCOUNT], $references->pluck('id')->unique()->values()->all());
        $this->assertCount(2, $references);
        $this->assertCount(0, $query->invitationReferences('association', null));
        $this->assertCount(0, $query->invitationReferences('association_@example.test', null));
    }

    public function test_assignment_eligibility_does_not_require_an_existing_association_or_change_login_rules(): void
    {
        DB::table('associations')->where('primary_field_officer_id', self::OFFICER_B)->update(['primary_field_officer_id' => self::OFFICER_A]);
        $officer = UserProfile::with('role')->findOrFail(self::OFFICER_B);
        $this->assertSame(0, $officer->assignedAssociations()->count());
        $this->assertTrue($officer->canAccessApplication());
        Route::middleware(['web', 'supabase.auth', 'role:field_officer'])->get('/_tests/unassigned-officer', fn () => response('Authorized officer'));
        $this->actor = self::OFFICER_B;
        $this->withSession($this->actorSessionData())->get('/_tests/unassigned-officer')->assertOk();
        $this->patchJson('/field-officer/member-applications/'.self::APPLICATION.'/reject', ['rejection_reason' => 'Unauthorized direct attempt'])
            ->assertUnprocessable()->assertJsonValidationErrors('reviewer');
        $this->assertSame('pending', DB::table('member_applications')->value('status'));
        $this->actor = self::ADMIN;
        $this->patchJson($this->assignmentUrl(), $this->assignmentData([self::ASSOCIATION]))->assertOk()->assertJsonPath('changed', 1);
        $this->assertSame(1, $officer->assignedAssociations()->count());
        $this->assertSame(1, DB::table('audit_logs')->count());
    }

    public function test_assignment_cannot_select_a_revoked_officer_and_preserves_their_existing_designations(): void
    {
        $request = new Request;
        $request->attributes->set('current_user', UserProfile::with('role')->findOrFail(self::ADMIN));
        app(UserAccessRevocationService::class)->revoke($request, UserProfile::findOrFail(self::OFFICER_B));
        $before = DB::table('associations')->orderBy('id')->get()->toJson();
        $this->withSession($this->actorSessionData())->patchJson($this->assignmentUrl(), $this->assignmentData([self::ASSOCIATION]))
            ->assertUnprocessable()->assertJsonValidationErrors('officer');
        $this->assertSame($before, DB::table('associations')->orderBy('id')->get()->toJson());
        $this->assertSame(UserAccessRevocationService::ACTION, DB::table('audit_logs')->sole()->action_type);
        $this->assertFalse(UserProfile::findOrFail(self::OFFICER_B)->is_active);
    }

    public static function assignmentTargetChanges(): array
    {
        return [['inactive'], ['never_activated'], ['role_inactive'], ['pending']];
    }

    #[DataProvider('assignmentTargetChanges')]
    public function test_assignment_reloads_the_selected_officer_after_the_form_was_opened(string $state): void
    {
        $officer = UserProfile::with('role')->findOrFail(self::OFFICER_B);
        $this->assertTrue($officer->canAccessApplication());
        match ($state) {
            'inactive' => DB::table('user_profiles')->where('id', $officer->id)->update(['is_active' => false]),
            'never_activated' => DB::table('user_profiles')->where('id', $officer->id)->update(['activated_at' => null]),
            'role_inactive' => DB::table('roles')->where('id', 2)->update(['is_active' => false]),
            'pending' => DB::table('user_invitations')->insert(['id' => self::INVITATION, 'auth_user_id' => $officer->id,
                'email' => 'synthetic@example.test', 'status' => 'sent', 'created_at' => now(), 'updated_at' => now()]),
        };
        $before = DB::table('associations')->orderBy('id')->get()->toJson();
        $request = new Request;
        $request->attributes->set('current_user', UserProfile::with('role')->findOrFail(self::ADMIN));
        try {
            app(FieldOfficerAssignmentService::class)->assign($request, $officer, $this->assignmentData([self::ASSOCIATION]));
            $this->fail('The selected officer must be rechecked before assignment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('officer', $exception->errors());
        }
        $this->assertSame($before, DB::table('associations')->orderBy('id')->get()->toJson());
        $this->assertSame(0, DB::table('audit_logs')->count());
    }
}
