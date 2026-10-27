<?php

namespace Tests\Feature;

use App\Models\MediaPhoto;
use App\Models\MonitoringRecord;
use App\Models\MonitoringVisitReport;
use App\Models\Project;
use App\Models\UserProfile;
use App\Services\Audit\AuditLogService;
use App\Services\Auth\SupabaseAuthService;
use App\Services\Media\PhotoLifecycleService;
use App\Services\Media\PhotoRules;
use App\Services\Projects\ProjectCoverService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CreatesLifecycleAuditTable;
use Tests\Support\MonitoringMaterialsFixture;
use Tests\TestCase;

class ProjectCoverTest extends TestCase
{
    use CreatesLifecycleAuditTable;
    use MonitoringMaterialsFixture;

    private string $identity = self::ADMIN;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->schema();
        $this->fixtures();
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->timestampTz('auth_sessions_valid_after')->nullable();
            $table->uuid('email_change_hold_id')->nullable();
        });
        $this->createLifecycleAuditTable();
        $auth = Mockery::mock(SupabaseAuthService::class);
        $auth->shouldReceive('getUser')->andReturnUsing(fn () => ['id' => $this->identity]);
        $this->app->instance(SupabaseAuthService::class, $auth);
        $this->withSession(['supabase_access_token' => 'synthetic-cover-session', '_token' => 'synthetic-cover-csrf']);
        Storage::fake('media_private', ['visibility' => 'private']);
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

    private function url(?string $photo = null, ?string $suffix = null, string $prefix = 'admin', string $project = self::PROJECT): string
    {
        return '/'.$prefix.'/projects/'.$project.'/cover'.($photo ? '/'.$photo : '').($suffix ? '/'.$suffix : '');
    }

    private function upload(?string $replaces = null, ?UploadedFile $file = null, ?string $requestId = null, string $prefix = 'admin')
    {
        return $this->post($this->url(prefix: $prefix), ['photo' => $file ?? UploadedFile::fake()->image('cover.png', 64, 32),
            'request_id' => $requestId ?? (string) Str::uuid(), 'replaces' => $replaces], ['Accept' => 'application/json']);
    }

    private function expire(string $id): void
    {
        DB::table('media_photos')->where('id', $id)->update(['lease_until' => now()->subMinute()]);
    }

    public function test_initial_cover_is_optional_private_audited_idempotent_and_does_not_change_project_data(): void
    {
        $before = (array) DB::table('projects')->first();
        $this->getJson($this->url())->assertOk()->assertJsonPath('cover', null)->assertJsonPath('can_manage', true);
        $file = UploadedFile::fake()->image('cover.png', 640, 480);
        $requestId = (string) Str::uuid();
        $id = $this->upload(file: $file, requestId: $requestId)->assertOk()->assertJsonPath('photo.status', 'ready')->json('photo.id');
        $this->upload(file: $file, requestId: $requestId)->assertOk()->assertJsonPath('photo.id', $id);
        $this->assertSame(1, MediaPhoto::where('state', 'ready')->count());
        $this->assertCount(2, Storage::disk('media_private')->allFiles());
        $this->assertSame(1, DB::table('audit_logs')->where('action_type', 'FINALIZE PHOTO')->count());
        $response = $this->getJson($this->url())->assertOk()->assertJsonPath('cover.id', $id)
            ->assertJsonPath('upload_pending', false)->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        foreach (['object_prefix', 'media/', 'uploaded_by', 'input_sha256', 'operation_token', 'lease_until', 'disk'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $row = MediaPhoto::find($id);
        foreach (['thumbnail', 'display'] as $variant) {
            $image = $this->get($response->json('cover.'.$variant.'_url'))->assertOk()->assertHeader('Content-Type', 'image/png')
                ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'max-age=0, no-store, private')
                ->assertHeader('Content-Disposition', 'inline; filename="project-cover.png"')
                ->assertHeader('Content-Length', (string) $row->{$variant.'_bytes'});
            $this->assertSame($row->{$variant.'_sha256'}, hash('sha256', $image->streamedContent()));
        }
        $this->get($this->url($id, 'original'))->assertNotFound();
        $this->assertSame($before, (array) DB::table('projects')->first());
    }

    public function test_assigned_field_officer_can_upload_replace_and_remove_a_cover(): void
    {
        $this->identity = self::OFFICER;
        $old = $this->upload(prefix: 'field-officer')->assertOk()->json('photo.id');
        $this->getJson($this->url(prefix: 'field-officer'))->assertOk()->assertJsonPath('cover.id', $old);
        $new = $this->upload($old, prefix: 'field-officer')->assertOk()->json('photo.id');
        $this->assertSame('deleted', MediaPhoto::find($old)->state);
        $this->deleteJson($this->url($new, prefix: 'field-officer'))->assertOk()->assertJsonPath('status', 'deleted');
        $this->getJson($this->url(prefix: 'field-officer'))->assertOk()->assertJsonPath('cover', null);
    }

    public function test_exact_five_mib_is_accepted_and_larger_or_invalid_images_cannot_replace_existing_cover(): void
    {
        $image = UploadedFile::fake()->image('boundary.png', 32, 16);
        $file = UploadedFile::fake()->createWithContent('boundary.png',
            str_pad(file_get_contents($image->getPathname()), PhotoRules::MAX_BYTES, "\0"));
        $this->assertSame(PhotoRules::MAX_BYTES, $file->getSize());
        $old = $this->upload(file: $file)->assertOk()->json('photo.id');
        foreach ([UploadedFile::fake()->createWithContent('large.png', str_repeat('x', PhotoRules::MAX_BYTES + 1)),
            UploadedFile::fake()->image('wrong.gif'), UploadedFile::fake()->createWithContent('corrupt.png', 'invalid')] as $invalid) {
            $this->upload($old, $invalid)->assertUnprocessable();
            $this->assertSame('ready', MediaPhoto::find($old)->state);
        }
        $this->assertSame(1, MediaPhoto::whereIn('state', PhotoRules::LIVE_STATES)->count());
        $this->assertCount(2, Storage::disk('media_private')->allFiles());
    }

    public function test_replacement_keeps_original_available_until_finalization_and_retires_only_its_objects(): void
    {
        $old = $this->upload()->assertOk()->json('photo.id');
        $disk = Storage::disk('media_private');
        $mock = Mockery::mock($disk);
        $mock->shouldReceive('writeStream')->twice()->andReturnUsing(function ($key, $stream, $options) use ($disk, $old) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('ready', MediaPhoto::find($old)->state);
            $this->assertSame(1, MediaPhoto::where('state', 'ready')->count());
            $this->assertSame('private', $options['visibility']);

            return $disk->writeStream($key, $stream, $options);
        });
        $mock->shouldReceive('delete')->twice()->andReturnUsing(fn ($key) => $disk->delete($key));
        Storage::set('media_private', $mock);
        $file = UploadedFile::fake()->image('replacement.png', 48, 32);
        $requestId = (string) Str::uuid();
        $new = $this->upload($old, $file, $requestId)->assertOk()->json('photo.id');
        Storage::set('media_private', $disk);
        $this->assertNotSame($old, $new);
        $this->assertSame('deleted', MediaPhoto::find($old)->state);
        $this->assertSame('ready', MediaPhoto::find($new)->state);
        $this->assertSame(1, MediaPhoto::where('state', 'ready')->count());
        $this->assertCount(2, $disk->allFiles());
        $this->get($this->url($old, 'thumbnail'))->assertNotFound();
        $this->getJson($this->url())->assertJsonPath('cover.id', $new);
        $this->upload($old, $file, $requestId)->assertOk()->assertJsonPath('photo.id', $new);
        $this->upload($old)->assertStatus(409);
        $this->upload()->assertStatus(409);
    }

    public function test_a_reserved_replacement_excludes_competing_replacements_and_stale_targets(): void
    {
        $old = $this->upload()->json('photo.id');
        $request = Request::create('/synthetic-cover');
        $request->attributes->set('current_user', UserProfile::with('role')->find(self::ADMIN));
        $project = Project::find(self::PROJECT);
        $pending = app(PhotoLifecycleService::class)->reserve($project, UploadedFile::fake()->image('new.png'),
            (string) Str::uuid(), $request, app(ProjectCoverService::class)->authorize($request, $project, true), $old);
        $this->upload($old)->assertStatus(409);
        $this->getJson($this->url())->assertJsonPath('cover.id', $old)->assertJsonPath('upload_pending', true)->assertJsonCount(1, 'pending');
        $this->get($this->url($old, 'thumbnail'))->assertOk();
        $this->get($this->url($pending['id'], 'thumbnail'))->assertNotFound();
        $this->deleteJson($this->url($pending['id']))->assertOk()->assertJsonPath('status', 'deleting');
        $this->expire($pending['id']);
        $this->postJson($this->url($pending['id'], 'reconcile'))->assertOk()->assertJsonPath('status', 'deleted');
        $this->assertSame('ready', MediaPhoto::find($old)->state);
        $this->upload($old)->assertOk();
    }

    public function test_write_timeout_and_incomplete_objects_never_remove_the_original_cover(): void
    {
        $old = $this->upload()->json('photo.id');
        $disk = Storage::disk('media_private');
        $mock = Mockery::mock($disk);
        $mock->shouldReceive('writeStream')->once()->andThrow(new RuntimeException('secret timeout'));
        Storage::set('media_private', $mock);
        $this->upload($old)->assertStatus(503);
        Storage::set('media_private', $disk);
        $pending = MediaPhoto::where('state', 'uncertain')->firstOrFail();
        $this->assertCount(2, $disk->allFiles());
        $this->expire($pending->id);
        $this->postJson($this->url($pending->id, 'reconcile'))->assertStatus(503);
        $this->assertSame('ready', MediaPhoto::find($old)->state);
        $this->deleteJson($this->url($pending->id))->assertOk();
        $this->expire($pending->id);
        $this->postJson($this->url($pending->id, 'reconcile'))->assertOk()->assertJsonPath('status', 'deleted');
        $this->assertSame('ready', MediaPhoto::find($old)->state);
    }

    public function test_lost_provider_response_recovers_both_variants_without_losing_original_or_reuploading(): void
    {
        $old = $this->upload()->json('photo.id');
        $disk = Storage::disk('media_private');
        $mock = Mockery::mock($disk);
        $writes = 0;
        $mock->shouldReceive('writeStream')->twice()->andReturnUsing(function ($key, $stream, $options) use ($disk, &$writes) {
            $disk->writeStream($key, $stream, $options);
            if (++$writes === 2) {
                throw new RuntimeException('secret provider endpoint media/sensitive');
            }

            return true;
        });
        Storage::set('media_private', $mock);
        $response = $this->upload($old)->assertStatus(503);
        $this->assertStringNotContainsString('secret', $response->getContent());
        $pending = MediaPhoto::where('state', 'uncertain')->firstOrFail();
        $this->assertSame('ready', MediaPhoto::find($old)->state);
        Storage::set('media_private', $disk);
        $this->postJson($this->url($pending->id, 'reconcile'))->assertOk()->assertJsonPath('status', 'uncertain');
        $this->expire($pending->id);
        $this->postJson($this->url($pending->id, 'reconcile'))->assertOk()->assertJsonPath('status', 'ready');
        $this->getJson($this->url())->assertJsonPath('cover.id', $pending->id)->assertJsonCount(0, 'pending');
        $this->assertSame('deleted', MediaPhoto::find($old)->state);
        $this->assertCount(2, $disk->allFiles());
    }

    public function test_database_finalization_failure_preserves_original_and_durable_recovery_metadata(): void
    {
        $fail = false;
        $actual = new AuditLogService;
        $audit = Mockery::mock(AuditLogService::class);
        $audit->shouldReceive('record')->andReturnUsing(function (...$arguments) use (&$fail, $actual) {
            if ($fail && $arguments[1] === 'FINALIZE PHOTO') {
                throw new RuntimeException('synthetic database finalization failure');
            }

            return $actual->record(...$arguments);
        });
        $this->app->instance(AuditLogService::class, $audit);
        $old = $this->upload()->json('photo.id');
        $fail = true;
        $this->upload($old)->assertStatus(503);
        $pending = MediaPhoto::where('state', 'uncertain')->firstOrFail();
        $this->assertSame('ready', MediaPhoto::find($old)->state);
        $this->assertCount(4, Storage::disk('media_private')->allFiles());
        $fail = false;
        $this->expire($pending->id);
        $this->postJson($this->url($pending->id, 'reconcile'))->assertOk()->assertJsonPath('status', 'ready');
    }

    public function test_cleanup_failure_does_not_turn_a_confirmed_replacement_into_a_failed_upload(): void
    {
        $old = $this->upload()->json('photo.id');
        $disk = Storage::disk('media_private');
        $mock = Mockery::mock($disk);
        $mock->shouldReceive('writeStream')->twice()->andReturnUsing(fn ($key, $stream, $options) => $disk->writeStream($key, $stream, $options));
        $mock->shouldReceive('delete')->once()->andThrow(new RuntimeException('secret deletion timeout'));
        Storage::set('media_private', $mock);
        $new = $this->upload($old)->assertOk()->json('photo.id');
        $this->assertSame('deleting', MediaPhoto::find($old)->state);
        $this->assertSame('ready', MediaPhoto::find($new)->state);
        Storage::set('media_private', $disk);
        $this->getJson($this->url())->assertJsonPath('cover.id', $new)->assertJsonPath('upload_pending', false)->assertJsonCount(1, 'pending');
        $this->expire($old);
        $this->postJson($this->url($old, 'reconcile'))->assertOk()->assertJsonPath('status', 'deleted');
        $this->assertCount(2, $disk->allFiles());
    }

    public function test_explicit_removal_is_immediate_private_and_failed_delete_remains_recoverable(): void
    {
        $id = $this->upload()->json('photo.id');
        $disk = Storage::disk('media_private');
        $mock = Mockery::mock($disk);
        $mock->shouldReceive('delete')->once()->andThrow(new RuntimeException('secret delete'));
        Storage::set('media_private', $mock);
        $this->deleteJson($this->url($id))->assertStatus(503);
        Storage::set('media_private', $disk);
        $this->get($this->url($id, 'display'))->assertNotFound();
        $this->getJson($this->url())->assertJsonPath('cover', null);
        $this->expire($id);
        $this->postJson($this->url($id, 'reconcile'))->assertOk()->assertJsonPath('status', 'deleted');
        $this->assertSame([], $disk->allFiles());
    }

    public static function restrictions(): array
    {
        $cases = [];
        foreach (['admin', 'field-officer'] as $prefix) {
            foreach (['archived', 'completed', 'terminated', 'association_inactive', 'association_archived', 'revoked', 'role_inactive'] as $state) {
                $cases[] = [$prefix, $state];
            }
        }
        $cases[] = ['field-officer', 'reassigned'];

        return $cases;
    }

    #[DataProvider('restrictions')]
    public function test_current_scope_and_lifecycle_block_cover_mutations(string $prefix, string $state): void
    {
        $id = $this->upload()->json('photo.id');
        $this->identity = $prefix === 'admin' ? self::ADMIN : self::OFFICER;
        match ($state) {
            'archived' => DB::table('projects')->update(['archived_at' => now()]),
            'completed', 'terminated' => DB::table('projects')->update(['status' => $state, 'closed_at' => now()]),
            'association_inactive' => DB::table('associations')->update(['status' => 'inactive']),
            'association_archived' => DB::table('associations')->update(['archived_at' => now()]),
            'reassigned' => DB::table('associations')->update(['primary_field_officer_id' => self::ADMIN]),
            'revoked' => DB::table('user_profiles')->where('id', $this->identity)->update(['is_active' => false]),
            'role_inactive' => DB::table('roles')->where('id', $prefix === 'admin' ? 1 : 2)->update(['is_active' => false]),
        };
        foreach ([$this->upload($id, prefix: $prefix), $this->deleteJson($this->url($id, prefix: $prefix)),
            $this->postJson($this->url($id, 'reconcile', $prefix))] as $response) {
            $this->assertFalse($response->isSuccessful());
        }
        $image = $this->get($this->url($id, 'thumbnail', $prefix));
        $readable = ! in_array($state, ['revoked', 'role_inactive', 'reassigned'], true)
            && ! ($prefix === 'field-officer' && $state === 'association_archived');
        if ($readable) {
            $image->assertOk();
            $this->getJson($this->url(prefix: $prefix))->assertJsonPath('can_manage', false);
        } else {
            $this->assertFalse($image->isSuccessful());
        }
        $this->assertSame('ready', MediaPhoto::find($id)->state);
    }

    public function test_assignment_is_rechecked_after_writes_and_an_administrator_can_reconcile_after_expiry(): void
    {
        $old = $this->upload()->json('photo.id');
        $this->identity = self::OFFICER;
        $disk = Storage::disk('media_private');
        $mock = Mockery::mock($disk);
        $count = 0;
        $mock->shouldReceive('writeStream')->twice()->andReturnUsing(function ($key, $stream, $options) use ($disk, &$count) {
            $this->assertSame(0, DB::transactionLevel());
            $disk->writeStream($key, $stream, $options);
            if (++$count === 2) {
                DB::table('associations')->update(['primary_field_officer_id' => self::ADMIN]);
            }

            return true;
        });
        Storage::set('media_private', $mock);
        $this->upload($old, prefix: 'field-officer')->assertStatus(503);
        Storage::set('media_private', $disk);
        $pending = MediaPhoto::where('state', 'uncertain')->firstOrFail();
        $this->assertSame('ready', MediaPhoto::find($old)->state);
        $this->expire($pending->id);
        $this->postJson($this->url($pending->id, 'reconcile', 'field-officer'))->assertForbidden();
        $this->identity = self::ADMIN;
        $this->postJson($this->url($pending->id, 'reconcile'))->assertOk()->assertJsonPath('status', 'ready');
    }

    public function test_an_officer_cannot_access_a_cover_in_another_association(): void
    {
        $association = (string) Str::uuid();
        DB::table('associations')->insert(array_replace((array) DB::table('associations')->first(),
            ['id' => $association, 'primary_field_officer_id' => self::ADMIN]));
        DB::table('projects')->update(['association_id' => $association]);
        $id = $this->upload()->assertOk()->json('photo.id');
        $this->identity = self::OFFICER;
        $this->getJson($this->url(prefix: 'field-officer'))->assertForbidden();
        $this->get($this->url($id, 'thumbnail', 'field-officer'))->assertForbidden();
        $this->upload($id, prefix: 'field-officer')->assertForbidden();
        $this->deleteJson($this->url($id, prefix: 'field-officer'))->assertForbidden();
        $this->postJson($this->url($id, 'reconcile', 'field-officer'))->assertForbidden();
    }

    public function test_stale_session_cutoff_is_rejected_within_both_read_and_write_transactions(): void
    {
        $request = Request::create('/synthetic-cover');
        $request->attributes->set('current_user', UserProfile::with('role')->find(self::ADMIN));
        DB::table('user_profiles')->where('id', self::ADMIN)->update(['auth_sessions_valid_after' => now()]);
        foreach ([false, true] as $write) {
            try {
                $authorize = app(ProjectCoverService::class)->authorize($request, Project::find(self::PROJECT), $write);
                DB::transaction(fn () => $authorize(Project::find(self::PROJECT)));
                $this->fail('Stale actor was accepted.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
    }

    public function test_cross_project_gis_and_public_access_cannot_retrieve_or_replace_a_cover(): void
    {
        $id = $this->upload()->json('photo.id');
        $other = (string) Str::uuid();
        DB::table('projects')->insert(array_replace((array) DB::table('projects')->first(), ['id' => $other]));
        $this->get($this->url($id, 'display', project: $other))->assertNotFound();
        $this->deleteJson($this->url($id, project: $other))->assertNotFound();
        $this->postJson($this->url($id, 'reconcile', project: $other))->assertNotFound();
        $this->post($this->url(project: $other), ['photo' => UploadedFile::fake()->image('other.png'),
            'request_id' => (string) Str::uuid(), 'replaces' => $id], ['Accept' => 'application/json'])->assertStatus(409);
        $gis = (string) Str::uuid();
        $gisPhoto = (string) Str::uuid();
        DB::table('gis_locations')->insert(['id' => $gis]);
        DB::table('media_photos')->insert(array_replace(MediaPhoto::find($id)->getAttributes(),
            ['id' => $gisPhoto, 'project_id' => null, 'gis_location_id' => $gis,
                'request_id' => (string) Str::uuid(), 'object_prefix' => 'media/'.Str::uuid()]));
        $this->get($this->url($gisPhoto, 'thumbnail'))->assertNotFound();
        $this->deleteJson($this->url($gisPhoto))->assertNotFound();
        $this->identity = self::ACCOUNT;
        $this->getJson($this->url())->assertForbidden();
        $this->upload()->assertForbidden();
        $this->get('/association/projects/'.self::PROJECT.'/cover')->assertNotFound();
        $this->get('/map/projects/'.self::PROJECT.'/cover')->assertNotFound();
        $this->flushSession();
        $this->get($this->url($id, 'thumbnail'))->assertRedirect(route('login'));
        $this->assertSame('ready', MediaPhoto::find($id)->state);
    }

    public function test_private_read_handles_missing_corrupt_storage_and_revocation_during_retrieval(): void
    {
        $id = $this->upload()->json('photo.id');
        $disk = Storage::disk('media_private');
        $row = MediaPhoto::find($id);
        $disk->put($row->object_prefix.'/thumbnail.png', 'corrupt');
        $this->get($this->url($id, 'thumbnail'))->assertStatus(503);
        $disk->delete($row->object_prefix.'/display.png');
        $this->get($this->url($id, 'display'))->assertStatus(503);
        $new = $this->upload($id)->json('photo.id');
        $mock = Mockery::mock($disk);
        $mock->shouldReceive('readStream')->once()->andReturnUsing(function ($key) use ($disk) {
            $this->assertSame(0, DB::transactionLevel());
            DB::table('user_profiles')->where('id', self::ADMIN)->update(['is_active' => false]);

            return $disk->readStream($key);
        });
        Storage::set('media_private', $mock);
        $this->get($this->url($new, 'thumbnail'))->assertForbidden();
    }

    public function test_actual_csrf_enforcement_rejects_missing_tokens_and_accepts_component_headers_for_both_modules(): void
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
        $this->upload()->assertStatus(419);
        $this->withHeaders(['X-CSRF-TOKEN' => 'synthetic-cover-csrf']);
        $this->upload()->assertOk();
        DB::table('monitoring_visit_reports')->insert(['id' => self::RECORD, 'monitoring_record_id' => self::RECORD,
            'monitoring_date' => '2026-01-15', 'site_validated' => true]);
        $url = '/admin/monitoring/'.self::RECORD.'/photos/visits/'.self::RECORD;
        $html = view('monitoring.partials.photos', ['monitoringPrefix' => 'admin.monitoring',
            'record' => MonitoringRecord::find(self::RECORD),
            'photoParent' => MonitoringVisitReport::find(self::RECORD), 'photoKind' => 'visits', 'canManage' => true])->render();
        $this->assertStringContainsString('data-csrf="synthetic-cover-csrf"', $html);
        $this->withHeaders(['X-CSRF-TOKEN' => 'wrong-token']);
        $this->post($url, ['photo' => UploadedFile::fake()->image('monitoring.png'),
            'request_id' => (string) Str::uuid()], ['Accept' => 'application/json'])->assertStatus(419);
        $this->withHeaders(['X-CSRF-TOKEN' => 'synthetic-cover-csrf']);
        $this->post($url, ['photo' => UploadedFile::fake()->image('monitoring.png'),
            'request_id' => (string) Str::uuid()], ['Accept' => 'application/json'])->assertOk();
    }

    public function test_component_and_synthetic_browser_fixture_use_private_routes_and_separate_forms(): void
    {
        $project = Project::find(self::PROJECT);
        $html = view('projects.partials.cover', ['project' => $project, 'prefix' => 'admin', 'canManage' => true])->render();
        $readonly = view('projects.partials.cover', ['project' => $project, 'prefix' => 'admin', 'canManage' => false])->render();
        $this->assertStringContainsString('data-cover-upload', $html);
        $this->assertStringNotContainsString('data-cover-upload', $readonly);
        $this->assertStringNotContainsString('media/', $html);
        $directory = getenv('ASSOCMAP_PROJECTMEDIA_UI_DIRECTORY');
        if ($directory) {
            $this->assertMatchesRegularExpression('/\\Aassocmap-projectmedia-ui-[a-f0-9]{32}\\z/', basename($directory));
            $this->assertSame(realpath(sys_get_temp_dir()), realpath(dirname($directory)));
            $wrapper = fn ($content) => '<!doctype html><html lang="en"><head><meta name="viewport" content="width=device-width"><meta name="csrf-token" content="synthetic-csrf"></head><body><main class="program-management">'.$content.'</main></body></html>';
            file_put_contents($directory.'/cover.html', $wrapper($html));
            file_put_contents($directory.'/readonly.html', $wrapper($readonly));
            $image = UploadedFile::fake()->image('synthetic.png', 64, 32);
            file_put_contents($directory.'/photo.png', file_get_contents($image->getPathname()));
        }
    }
}
