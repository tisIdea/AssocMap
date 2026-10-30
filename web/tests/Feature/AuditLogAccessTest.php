<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Services\Audit\AuditLogService;
use App\Services\Auth\SupabaseAuthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuditLogAccessTest extends TestCase
{
    private const ADMIN = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const OFFICER = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private const ASSOCIATION = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    private const EVENT = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32)), 'app.debug' => false, 'app.timezone' => 'UTC']);
        $this->withoutVite();
        Http::fake([]);
        DB::unprepared(<<<'SQL'
            CREATE TABLE roles (id INTEGER PRIMARY KEY, code TEXT, name TEXT, is_active BOOLEAN, description TEXT, created_at TEXT, updated_at TEXT);
            CREATE TABLE user_profiles (id TEXT PRIMARY KEY, role_id INTEGER, display_name TEXT, is_active BOOLEAN, created_at TEXT, updated_at TEXT);
            CREATE TABLE user_invitations (id TEXT PRIMARY KEY, auth_user_id TEXT, email TEXT, status TEXT, last_error_code TEXT, accepted_at TEXT, last_attempt_at TEXT, created_at TEXT);
            CREATE TABLE audit_logs (id TEXT PRIMARY KEY, actor_user_id TEXT REFERENCES user_profiles(id) ON DELETE SET NULL,
                action_type TEXT NOT NULL, module TEXT NOT NULL, entity_type TEXT, entity_id TEXT,
                old_values TEXT, new_values TEXT, ip_address TEXT, user_agent TEXT, performed_at TEXT NOT NULL);
            SQL);
        foreach ([self::ADMIN => 'system_admin', self::OFFICER => 'field_officer', self::ASSOCIATION => 'association_account'] as $id => $role) {
            $roleId = DB::table('roles')->insertGetId(['code' => $role, 'name' => $role, 'is_active' => true]);
            DB::table('user_profiles')->insert(['id' => $id, 'role_id' => $roleId, 'display_name' => 'Synthetic '.$role, 'is_active' => true]);
        }
        $this->partialMock(SupabaseAuthService::class, function ($mock) {
            $mock->shouldReceive('getUser')->andReturnUsing(fn ($token) => ['id' => $token]);
        });
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    private function event(array $overrides = []): string
    {
        $row = array_replace(['id' => self::EVENT, 'actor_user_id' => self::ADMIN, 'action_type' => 'ARCHIVE MEMBER',
            'module' => 'Member Management', 'entity_type' => 'member', 'entity_id' => 'unavailable-business-record',
            'performed_at' => '2026-10-18T09:00:00.000000+00:00'], $overrides);
        DB::table('audit_logs')->insert($row);

        return $row['id'];
    }

    private function signIn(string $id = self::ADMIN): static
    {
        return $this->withSession(['supabase_access_token' => $id]);
    }

    public function test_admin_can_open_the_empty_audit_register(): void
    {
        $this->withoutExceptionHandling();
        $this->signIn()->get('/admin/audit-logs')->assertOk()->assertSee('No audit records have been recorded.')
            ->assertSee('Filter audit events')->assertDontSee('@section', false)
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }

    public function test_historical_events_render_without_their_actor_or_business_record_and_are_unchanged(): void
    {
        $this->event(['actor_user_id' => null]);
        $before = DB::table('audit_logs')->get()->toJson();
        $this->signIn()->get('/admin/audit-logs')->assertOk()->assertSee('ARCHIVE MEMBER')->assertSee('Deleted or unavailable actor');
        $response = $this->get('/admin/audit-logs/'.self::EVENT)->assertOk()->assertSee('unavailable-business-record')
            ->assertSee('No snapshot was recorded.');
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($response->getContent());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new \DOMXPath($document);
        $this->assertSame(0, $xpath->query('//form[contains(@action, "/admin/audit-logs") and translate(@method, "get", "GET") != "GET"]')->length);
        $this->assertSame($before, DB::table('audit_logs')->get()->toJson());
    }

    public function test_admin_sees_the_current_actor_and_only_permitted_escaped_snapshot_fields(): void
    {
        $this->event(['new_values' => json_encode(['association_id' => '<script>alert(1)</script>',
            'member_status' => 'active', 'password' => 'synthetic-private-password', 'access_token' => 'synthetic-private-token',
            'email' => 'private@example.test', 'contact_number' => 'synthetic-private-phone']),
            'ip_address' => '192.0.2.10', 'user_agent' => 'synthetic-private-agent']);
        $this->signIn()->get('/admin/audit-logs')->assertOk()->assertSee('Synthetic system_admin');
        $response = $this->get('/admin/audit-logs/'.self::EVENT)->assertOk()->assertSee('member_status')
            ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        foreach (['synthetic-private-password', 'synthetic-private-token', 'private@example.test', 'synthetic-private-phone', '192.0.2.10', 'synthetic-private-agent'] as $secret) {
            $response->assertDontSee($secret);
        }
    }

    public function test_gis_classification_changes_and_clearing_are_visible_only_to_the_administrator(): void
    {
        $this->event(['entity_type' => 'gis_location', 'action_type' => 'UPDATE', 'module' => 'GIS Mapping',
            'old_values' => json_encode(['site_type_id' => 41]), 'new_values' => json_encode(['site_type_id' => null])]);
        $before = DB::table('audit_logs')->get()->toJson();
        $response = $this->signIn()->get('/admin/audit-logs/'.self::EVENT)->assertOk()->assertSee('site_type_id');
        $this->assertStringContainsString('"site_type_id": 41', html_entity_decode($response->getContent()));
        $this->assertStringContainsString('"site_type_id": null', html_entity_decode($response->getContent()));
        foreach ([self::OFFICER, self::ASSOCIATION] as $actor) {
            $this->signIn($actor)->get('/admin/audit-logs/'.self::EVENT)->assertForbidden()->assertDontSee('site_type_id');
        }
        $this->assertSame($before, DB::table('audit_logs')->get()->toJson());
    }

    #[DataProvider('restrictedActors')]
    public function test_non_admins_cannot_open_list_or_event_details(string $id): void
    {
        $this->event();
        $before = DB::table('audit_logs')->get()->toJson();
        $this->signIn($id)->get('/admin/audit-logs?user_role=system_admin')->assertForbidden()->assertDontSee('ARCHIVE MEMBER');
        $this->get('/admin/audit-logs/'.self::EVENT)->assertForbidden()->assertDontSee('ARCHIVE MEMBER');
        $this->assertSame($before, DB::table('audit_logs')->get()->toJson());
    }

    public static function restrictedActors(): array
    {
        return ['Field Officer' => [self::OFFICER], 'Association Account' => [self::ASSOCIATION]];
    }

    public function test_guests_are_redirected_to_login_without_reading_audit_records(): void
    {
        $this->event();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->get('/admin/audit-logs')->assertRedirect(route('login'));
            $this->get('/admin/audit-logs/'.self::EVENT)->assertRedirect(route('login'));
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_pagination_keeps_filters_and_a_stable_order(): void
    {
        for ($i = 1; $i <= 26; $i++) {
            $this->event(['id' => sprintf('%08d-dddd-4ddd-8ddd-dddddddddddd', $i)]);
        }
        $this->event(['id' => (string) Str::uuid(), 'action_type' => 'UPDATE MEMBER']);
        $this->signIn()->get('/admin/audit-logs?action_type=ARCHIVE%20MEMBER&per_page=25')
            ->assertOk()->assertViewHas('logs', fn ($logs) => $logs->total() === 26 && $logs->count() === 25
                && $logs->first()->id === '00000026-dddd-4ddd-8ddd-dddddddddddd')
            ->assertSee('page=2', false)->assertSee('action_type=ARCHIVE%20MEMBER', false);
        $this->get('/admin/audit-logs?action_type=ARCHIVE%20MEMBER&page=2&per_page=25')->assertOk()
            ->assertViewHas('logs', fn ($logs) => $logs->count() === 1 && $logs->first()->id === '00000001-dddd-4ddd-8ddd-dddddddddddd');
    }

    public function test_exact_filters_and_inclusive_local_dates_work_together(): void
    {
        config(['app.timezone' => 'Asia/Manila']);
        $this->event(['actor_user_id' => null, 'performed_at' => '2026-10-18T09:00:00+08:00']);
        foreach (['2026-10-17T23:59:59+08:00', '2026-10-18T00:00:00+08:00', '2026-10-18T23:59:59+08:00', '2026-10-19T00:00:00+08:00'] as $timestamp) {
            $this->event(['id' => (string) Str::uuid(), 'actor_user_id' => null, 'performed_at' => $timestamp]);
        }
        $this->event(['id' => (string) Str::uuid(), 'performed_at' => '2026-10-18T09:00:00+08:00']);
        $filters = http_build_query(['action_type' => 'ARCHIVE MEMBER', 'module' => 'Member Management', 'entity_type' => 'member',
            'actor_user_id' => 'unavailable', 'date_from' => '2026-10-18', 'date_to' => '2026-10-18']);
        $this->signIn()->get('/admin/audit-logs?'.$filters)->assertOk()
            ->assertViewHas('logs', fn ($logs) => $logs->total() === 3 && $logs->contains('id', self::EVENT));
        $this->get('/admin/audit-logs?actor_user_id='.self::ADMIN)->assertOk()
            ->assertViewHas('logs', fn ($logs) => $logs->total() === 1 && $logs->first()->actor_user_id === self::ADMIN);
    }

    public function test_invalid_filters_and_query_failures_render_safe_recoverable_pages(): void
    {
        $this->signIn()->get('/admin/audit-logs?per_page=1000')->assertStatus(422)->assertSee('Correct the filter parameters.');
        DB::statement('DROP TABLE audit_logs');
        $this->get('/admin/audit-logs')->assertStatus(503)->assertSee('Audit logs are temporarily unavailable.')
            ->assertDontSee('SQLSTATE')->assertDontSee('no such table');
    }

    public function test_http_write_methods_cannot_change_audit_history(): void
    {
        $this->event();
        $before = DB::table('audit_logs')->get()->toJson();
        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $this->signIn()->{$method}('/admin/audit-logs/'.self::EVENT, ['action_type' => 'TAMPERED'])->assertStatus(405);
            $this->{$method}('/admin/audit-logs', ['action_type' => 'TAMPERED'])->assertStatus(405);
        }
        $this->assertSame($before, DB::table('audit_logs')->get()->toJson());
    }

    public function test_model_updates_and_deletes_are_rejected(): void
    {
        $this->event();
        $event = AuditLog::findOrFail(self::EVENT);
        foreach (['update', 'delete'] as $operation) {
            try {
                if ($operation === 'update') {
                    $event->action_type = 'TAMPERED';
                }
                $operation === 'update' ? $event->save() : $event->delete();
                $this->fail('Audit mutation should have failed.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('cannot be', $exception->getMessage());
            }
        }
        $this->assertSame('ARCHIVE MEMBER', DB::table('audit_logs')->value('action_type'));
    }

    public function test_actor_eager_loading_keeps_the_query_count_constant(): void
    {
        $this->event();
        $countQueries = function (): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $this->signIn()->get('/admin/audit-logs?per_page=100')->assertOk();

                return count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
            }
        };
        $one = $countQueries();
        for ($i = 0; $i < 25; $i++) {
            $this->event(['id' => (string) Str::uuid(), 'actor_user_id' => $i % 2 ? self::OFFICER : self::ASSOCIATION]);
        }
        $this->assertSame(9, $one);
        $this->assertSame($one, $countQueries());
    }

    public function test_writer_keeps_only_allowlisted_fields_and_server_identity_and_time(): void
    {
        $this->signIn()->get('/admin/audit-logs')->assertOk();
        $request = request();
        $request->merge(['actor_user_id' => self::OFFICER, 'performed_at' => '1900-01-01', 'password' => 'synthetic-private-password']);
        $this->freezeTime();
        $id = app(AuditLogService::class)->record($request, 'UPDATE MEMBER', 'Member Management', 'member', 'synthetic-member',
            null, ['member_status' => 'active', 'password' => 'synthetic-private-password', 'changed_fields' => ['session_token' => 'synthetic-private-token']],
            ['member_status', 'changed_fields']);
        $event = AuditLog::findOrFail($id);
        $this->assertSame(self::ADMIN, $event->actor_user_id);
        $this->assertSame(now()->toIso8601String(), $event->performed_at->toIso8601String());
        $this->assertSame(['member_status' => 'active', 'changed_fields' => []], $event->new_values);
        $this->assertNull($event->ip_address);
        $this->assertNull($event->user_agent);
    }

    public function test_filters_use_native_disclosure_and_the_existing_get_contract(): void
    {
        $this->event();
        $response = $this->signIn()->get('/admin/audit-logs')->assertOk();
        $xpath = $this->document($response);
        $this->assertSame(1, $xpath->query('//details[contains(@class, "audit-filter-disclosure") and not(@open)]/summary[@aria-controls="audit-filter-panel"]')->length);
        $this->assertSame(0, $xpath->query('//summary[contains(@class, "audit-filter-summary")]//*[self::a or self::button or self::input]')->length);
        $form = $xpath->query('//form[@id="audit-filter-panel" and @method="GET"]')->item(0);
        $this->assertNotNull($form);
        $this->assertSame(route('admin.audit-logs.index'), $form->getAttribute('action'));
        $names = [];
        foreach ($xpath->query('.//*[@name]', $form) as $field) {
            $names[] = $field->getAttribute('name');
            $this->assertSame(1, $xpath->query('//label[@for="'.$field->getAttribute('id').'"]')->length);
        }
        $this->assertSame(['action_type', 'module', 'entity_type', 'per_page', 'actor_user_id', 'date_from', 'date_to'], $names);
        $this->assertSame(1, $xpath->query('//div[@role="region" and @tabindex="0" and @aria-label="Recorded audit events"]')->length);
        $this->assertSame(5, $xpath->query('//table[contains(@class, "audit-table")]/thead/tr/th[@scope="col"]')->length);
        $this->assertSame(1, $xpath->query('//table[contains(@class, "audit-table")]/tbody/tr/th[@scope="row"]')->length);
        $response->assertSee('Oct 18, 2026')->assertSee('09:00:00 UTC')->assertSee('datetime="2026-10-18T09:00:00+00:00"', false);
    }

    public function test_active_filter_counts_exclude_pagination_and_reset_restores_the_register(): void
    {
        $this->event();
        $this->event(['id' => (string) Str::uuid(), 'action_type' => 'UPDATE MEMBER PROFILE']);
        $response = $this->signIn()->get('/admin/audit-logs?action_type=ARCHIVE%20MEMBER&module=Member%20Management&per_page=50&page=1')
            ->assertOk()->assertSee('matching event(s)')->assertSee('2 active filter(s)');
        $xpath = $this->document($response);
        $this->assertSame(1, $xpath->query('//details[contains(@class, "audit-filter-disclosure") and @open]')->length);
        $this->assertSame('1', $xpath->evaluate('string(//header[contains(@class, "audit-register-heading")]//p/strong)'));
        $reset = $xpath->query('//form[@id="audit-filter-panel"]//a[normalize-space()="Reset filters"]')->item(0);
        $this->assertNotNull($reset);
        $this->assertSame(route('admin.audit-logs.index'), $reset->getAttribute('href'));
        $resetResponse = $this->get($reset->getAttribute('href'))->assertOk()->assertSee('recorded event(s)')->assertDontSee('active filter(s)');
        $resetDocument = $this->document($resetResponse);
        $this->assertSame('2', $resetDocument->evaluate('string(//header[contains(@class, "audit-register-heading")]//p/strong)'));
        $this->assertSame(0, $resetDocument->query('//details[contains(@class, "audit-filter-disclosure") and @open]')->length);
    }

    public function test_zero_valued_filter_and_distinct_empty_states_are_presented_accurately(): void
    {
        $this->event(['action_type' => '0']);
        $this->signIn()->get('/admin/audit-logs?action_type=0')->assertOk()->assertSee('1 active filter(s)')
            ->assertViewHas('logs', fn ($logs) => $logs->total() === 1);
        $this->get('/admin/audit-logs?action_type=0&date_from=2026-10-19')->assertOk()->assertSee('No audit records match these filters.')
            ->assertDontSee('No audit records have been recorded.')->assertSee('Reset filters');
        $response = $this->get('/admin/audit-logs?action_type=0&page=2')->assertOk()
            ->assertSee('No audit records are available on this page.')->assertDontSee('No audit records match these filters.');
        $xpath = $this->document($response);
        $link = $xpath->query('//div[contains(@class, "audit-empty")]/a[normalize-space()="First page"]')->item(0);
        $this->assertNotNull($link);
        $this->assertStringContainsString('action_type=0', $link->getAttribute('href'));
        $this->get($link->getAttribute('href'))->assertOk()->assertViewHas('logs', fn ($logs) => $logs->count() === 1);
    }

    public function test_long_untrusted_labels_remain_escaped_without_changing_saved_events(): void
    {
        DB::table('user_profiles')->where('id', self::ADMIN)->update(['display_name' => '<script>actor</script>'.str_repeat(' Long name', 12)]);
        $this->event(['action_type' => '<script>event</script>', 'module' => '<script>module</script>',
            'entity_type' => '<script>entity</script>', 'entity_id' => str_repeat('long-identifier-', 40)]);
        $before = DB::table('audit_logs')->get()->toJson();
        foreach (['/admin/audit-logs', '/admin/audit-logs/'.self::EVENT] as $url) {
            $this->signIn()->get($url)->assertOk()->assertSee('&lt;script&gt;event&lt;/script&gt;', false)
                ->assertSee('&lt;script&gt;actor&lt;/script&gt;', false)->assertDontSee('<script>event</script>', false)
                ->assertDontSee('<script>actor</script>', false)->assertDontSee('<script>module</script>', false)
                ->assertDontSee('<script>entity</script>', false);
        }
        $this->assertSame($before, DB::table('audit_logs')->get()->toJson());
    }

    public function test_detail_dates_preserve_the_instant_and_gis_deletion_snapshots_stay_hidden(): void
    {
        config(['app.timezone' => 'Asia/Manila']);
        $this->event(['action_type' => 'DELETE GIS', 'entity_type' => 'gis_location',
            'new_values' => json_encode(['geometry' => 'synthetic-private-geometry', 'public_title' => 'synthetic-hidden-title'])]);
        $before = DB::table('audit_logs')->get()->toJson();
        $response = $this->signIn()->get('/admin/audit-logs/'.self::EVENT)->assertOk()->assertSee('Oct 18, 2026')
            ->assertSee('17:00:00.000000 PST')->assertSee('datetime="2026-10-18T09:00:00+00:00"', false)
            ->assertSee('Permanent deletion audits do not display record snapshots.')
            ->assertDontSee('synthetic-private-geometry')->assertDontSee('synthetic-hidden-title');
        $this->assertSame(0, $this->document($response)->query('//pre')->length);
        $this->assertSame($before, DB::table('audit_logs')->get()->toJson());
    }

    public function test_filter_errors_open_the_panel_and_unavailable_counts_are_not_fabricated(): void
    {
        $this->event();
        $response = $this->signIn()->get('/admin/audit-logs?per_page=1000&module=Member%20Management')->assertStatus(422);
        $xpath = $this->document($response);
        $this->assertSame(1, $xpath->query('//details[contains(@class, "audit-filter-disclosure") and @open]')->length);
        $this->assertSame(1, $xpath->query('//form[contains(@aria-describedby, "audit-filter-errors")]')->length);
        $this->assertSame('Member Management', $xpath->evaluate('string(//select[@name="module"]/option[@selected]/@value)'));
        $this->assertSame(0, $xpath->query('//header[contains(@class, "audit-register-heading")]//p/strong')->length);
        DB::statement('DROP TABLE audit_logs');
        $unavailable = $this->get('/admin/audit-logs')->assertStatus(503)->assertSee('Records unavailable');
        $unavailableDocument = $this->document($unavailable);
        $this->assertSame(1, $unavailableDocument->query('//form[@id="audit-filter-panel"]/fieldset[@disabled]')->length);
        $this->assertSame(0, $unavailableDocument->query('//header[contains(@class, "audit-register-heading")]//p/strong')->length);
    }

    public function test_recorded_dropdowns_use_labels_without_submitting_actor_names(): void
    {
        DB::table('user_profiles')->where('id', self::ADMIN)->update(['display_name' => 'System Administrator']);
        $this->event(['entity_type' => 'member_officer_position']);
        $response = $this->signIn()->get('/admin/audit-logs')->assertOk();
        $xpath = $this->document($response);
        foreach (['action_type' => 'ARCHIVE MEMBER', 'module' => 'Member Management', 'entity_type' => 'member_officer_position', 'actor_user_id' => self::ADMIN] as $field => $value) {
            $this->assertSame(1, $xpath->query('//select[@name="'.$field.'"]/option[@value="'.$value.'"]')->length);
            $this->assertSame(0, $xpath->query('//input[@name="'.$field.'"]')->length);
        }
        $this->assertSame(0, $xpath->query('//datalist')->length);
        $this->assertSame('All actors', $xpath->evaluate('string(//select[@name="actor_user_id"]/option[@value=""])'));
        $this->assertSame('System Administrator ('.self::ADMIN.')', $xpath->evaluate('string(//select[@name="actor_user_id"]/option[@value="'.self::ADMIN.'"])'));
        $this->assertSame('Member Officer Position', $xpath->evaluate('string(//select[@name="entity_type"]/option[@value="member_officer_position"])'));
        $this->get('/admin/audit-logs?actor_user_id=System%20Administrator')->assertStatus(422)
            ->assertSee('Choose an actor identifier or unavailable actor.')->assertSee('Reset filters');
        $this->get('/admin/audit-logs?actor_user_id='.self::ADMIN)->assertOk()
            ->assertViewHas('logs', fn ($logs) => $logs->count() === 1 && $logs->first()->actor_user_id === self::ADMIN);
    }

    #[DataProvider('unsupportedDropdownValues')]
    public function test_forged_or_stale_filter_values_are_rejected_without_changing_history(string $field, mixed $value): void
    {
        $this->event();
        $before = DB::table('audit_logs')->get()->toJson();
        $response = $this->signIn()->get('/admin/audit-logs?'.http_build_query([$field => $value]))
            ->assertStatus(422)->assertSee('Correct the filter parameters.')->assertSee('Reset filters')
            ->assertDontSee('SQLSTATE');
        $xpath = $this->document($response);
        $this->assertSame(1, $xpath->query('//details[contains(@class, "audit-filter-disclosure") and @open]')->length);
        $this->assertSame(1, $xpath->query('//select[@name="action_type"]/option[@value="ARCHIVE MEMBER"]')->length);
        $reset = $xpath->query('//form[@id="audit-filter-panel"]//a[normalize-space()="Reset filters"]')->item(0);
        $this->assertNotNull($reset);
        $this->get($reset->getAttribute('href'))->assertOk()->assertDontSee('Correct the filter parameters.');
        $this->assertSame($before, DB::table('audit_logs')->get()->toJson());
    }

    public static function unsupportedDropdownValues(): array
    {
        return [
            'unknown action' => ['action_type', 'UNRECORDED ACTION'],
            'unknown module' => ['module', 'Unrecorded Module'],
            'unknown entity' => ['entity_type', 'unrecorded_entity'],
            'actor name' => ['actor_user_id', 'System Administrator'],
            'profile without audit events' => ['actor_user_id', self::OFFICER],
            'unknown actor UUID' => ['actor_user_id', 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee'],
            'actor array' => ['actor_user_id', ['unavailable']],
            'action array' => ['action_type', ['ARCHIVE MEMBER']],
        ];
    }

    public function test_duplicate_names_inactive_actors_and_profile_renames_preserve_identifier_filtering(): void
    {
        DB::table('user_profiles')->whereIn('id', [self::ADMIN, self::OFFICER])->update(['display_name' => 'Same Name']);
        DB::table('user_profiles')->where('id', self::OFFICER)->update(['is_active' => false]);
        $this->event();
        $officerEvent = $this->event(['id' => (string) Str::uuid(), 'actor_user_id' => self::OFFICER]);
        $response = $this->signIn()->get('/admin/audit-logs')->assertOk();
        $xpath = $this->document($response);
        foreach ([self::ADMIN, self::OFFICER] as $actorId) {
            $this->assertSame('Same Name ('.$actorId.')', $xpath->evaluate('string(//select[@name="actor_user_id"]/option[@value="'.$actorId.'"])'));
        }
        $this->assertSame(0, $xpath->query('//select[@name="actor_user_id"]/option[@value="'.self::ASSOCIATION.'"]')->length);
        $this->get('/admin/audit-logs?actor_user_id='.self::OFFICER)->assertOk()
            ->assertViewHas('logs', fn ($logs) => $logs->count() === 1 && $logs->first()->id === $officerEvent);
        DB::table('user_profiles')->where('id', self::OFFICER)->update(['display_name' => 'Renamed Actor']);
        $renamed = $this->get('/admin/audit-logs?actor_user_id='.self::OFFICER)->assertOk()
            ->assertViewHas('logs', fn ($logs) => $logs->count() === 1 && $logs->first()->id === $officerEvent);
        $this->assertSame('Renamed Actor ('.self::OFFICER.')', $this->document($renamed)->evaluate('string(//select[@name="actor_user_id"]/option[@selected])'));
    }

    public function test_deleted_actors_use_the_existing_null_reference_sentinel(): void
    {
        $this->event(['actor_user_id' => self::OFFICER]);
        DB::table('user_profiles')->where('id', self::OFFICER)->delete();
        $this->assertNull(DB::table('audit_logs')->value('actor_user_id'));
        $before = DB::table('audit_logs')->get()->toJson();
        $response = $this->signIn()->get('/admin/audit-logs?actor_user_id=unavailable')->assertOk()
            ->assertSee('Deleted or unavailable actor')->assertViewHas('logs', fn ($logs) => $logs->count() === 1 && $logs->first()->actor_user_id === null);
        $this->assertSame('unavailable', $this->document($response)->evaluate('string(//select[@name="actor_user_id"]/option[@selected]/@value)'));
        $this->assertSame($before, DB::table('audit_logs')->get()->toJson());
    }

    public function test_all_four_selections_persist_on_refresh_and_paginated_links(): void
    {
        for ($i = 1; $i <= 26; $i++) {
            $this->event(['id' => sprintf('%08d-dddd-4ddd-8ddd-dddddddddddd', $i), 'actor_user_id' => self::OFFICER]);
        }
        $this->event();
        $filters = ['action_type' => 'ARCHIVE MEMBER', 'module' => 'Member Management', 'entity_type' => 'member', 'actor_user_id' => self::OFFICER,
            'date_from' => '2026-10-18', 'date_to' => '2026-10-18', 'per_page' => 25];
        $url = '/admin/audit-logs?'.http_build_query($filters);
        for ($refresh = 0; $refresh < 2; $refresh++) {
            $response = $this->signIn()->get($url)->assertOk()->assertViewHas('logs', fn ($logs) => $logs->total() === 26 && $logs->count() === 25);
            $xpath = $this->document($response);
            foreach (array_slice($filters, 0, 4) as $field => $value) {
                $this->assertSame($value, $xpath->evaluate('string(//select[@name="'.$field.'"]/option[@selected]/@value)'));
            }
        }
        $next = $xpath->query('//nav[@aria-label="Audit pagination"]/a[@rel="next"]')->item(0);
        $this->assertNotNull($next);
        parse_str(parse_url($next->getAttribute('href'), PHP_URL_QUERY), $nextFilters);
        foreach ($filters as $field => $value) {
            $this->assertSame((string) $value, $nextFilters[$field]);
        }
        $this->get($next->getAttribute('href'))->assertOk()->assertViewHas('logs', fn ($logs) => $logs->total() === 26 && $logs->count() === 1);
        $this->get('/admin/audit-logs')->assertOk()->assertViewHas('logs', fn ($logs) => $logs->total() === 27);
    }

    public function test_a_legacy_retained_actor_identifier_remains_filterable_without_a_profile(): void
    {
        $historicalActor = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
        // Simulate legacy data predating the actor foreign key in this memory fixture only.
        DB::statement('PRAGMA foreign_keys = OFF');
        try {
            $this->event(['actor_user_id' => $historicalActor]);
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }
        $before = DB::table('audit_logs')->get()->toJson();
        $response = $this->signIn()->get('/admin/audit-logs?actor_user_id='.$historicalActor)->assertOk()
            ->assertViewHas('logs', fn ($logs) => $logs->count() === 1 && $logs->first()->actor_user_id === $historicalActor);
        $this->assertSame('Unavailable profile ('.$historicalActor.')', $this->document($response)->evaluate('string(//select[@name="actor_user_id"]/option[@selected])'));
        $this->get('/admin/audit-logs?actor_user_id=unavailable')->assertOk()->assertViewHas('logs', fn ($logs) => $logs->total() === 0);
        $this->assertSame($before, DB::table('audit_logs')->get()->toJson());
    }

    public function test_distinct_options_are_not_truncated_at_one_hundred_or_loaded_per_event(): void
    {
        $this->event();
        for ($i = 1; $i <= 105; $i++) {
            $actorId = sprintf('e%07x-eeee-4eee-8eee-eeeeeeeeeeee', $i);
            DB::table('user_profiles')->insert(['id' => $actorId, 'role_id' => 2, 'display_name' => sprintf('Recorded Actor %03d', $i), 'is_active' => false]);
            $this->event(['id' => (string) Str::uuid(), 'actor_user_id' => $actorId, 'action_type' => sprintf('RECORDED ACTION %03d', $i),
                'module' => sprintf('Recorded Module %03d', $i), 'entity_type' => sprintf('recorded_entity_%03d', $i)]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $response = $this->signIn()->get('/admin/audit-logs')->assertOk();
            $this->assertCount(9, DB::getQueryLog());
            $xpath = $this->document($response);
            foreach (['action_type', 'module', 'entity_type'] as $field) {
                $this->assertSame(106, $xpath->query('//select[@name="'.$field.'"]/option[@value!=""]')->length);
            }
            $this->assertSame(106, $xpath->query('//select[@name="actor_user_id"]/option[@value!="" and @value!="unavailable"]')->length);
            DB::flushQueryLog();
            $filters = ['action_type' => 'RECORDED ACTION 105', 'module' => 'Recorded Module 105', 'entity_type' => 'recorded_entity_105', 'actor_user_id' => $actorId];
            $this->get('/admin/audit-logs?'.http_build_query($filters))->assertOk()
                ->assertViewHas('logs', fn ($logs) => $logs->total() === 1 && $logs->first()->actor_user_id === $actorId);
            $this->assertCount(9, DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_assignment_snapshots_display_only_the_approved_officer_identifier(): void
    {
        $this->event(['action_type' => 'REASSIGN FIELD OFFICER', 'entity_type' => 'association', 'entity_id' => self::EVENT,
            'old_values' => json_encode(['primary_field_officer_id' => self::OFFICER]),
            'new_values' => json_encode(['primary_field_officer_id' => self::ASSOCIATION,
                'password' => 'Synthetic hidden password', 'access_token' => 'Synthetic hidden token', 'unexpected' => 'Synthetic hidden data'])]);
        $this->signIn()->get('/admin/audit-logs/'.self::EVENT)->assertOk()->assertSee('primary_field_officer_id')
            ->assertSee(self::OFFICER)->assertSee(self::ASSOCIATION)->assertDontSee('Synthetic hidden');
        $formatted = app(AuditLogService::class)->formatSnapshot(['primary_field_officer_id' => null], 'association');
        $this->assertSame(['primary_field_officer_id' => null], json_decode($formatted['json'], true));
    }

    private function document(TestResponse $response): \DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument;
            $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());

            return new \DOMXPath($document);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
