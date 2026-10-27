<?php

namespace Tests\Feature;

use App\Models\MediaPhoto;
use App\Models\MonitoringRecord;
use App\Models\UserProfile;
use App\Services\Audit\AuditLogService;
use App\Services\Auth\SupabaseAuthService;
use App\Services\Monitoring\MaterialsMonitoringService;
use App\Services\Monitoring\MonitoringPhotoService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
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

class MonitoringPhotoTest extends TestCase
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
        DB::table('monitoring_visit_reports')->insert(['id' => self::RECORD, 'monitoring_record_id' => self::RECORD,
            'monitoring_date' => '2026-01-15', 'site_validated' => true]);
        DB::table('monitoring_materials')->insert(['id' => self::RECORD, 'monitoring_record_id' => self::RECORD,
            'project_material_id' => self::MATERIAL, 'condition' => 'Good']);
        $auth = Mockery::mock(SupabaseAuthService::class);
        $auth->shouldReceive('getUser')->andReturnUsing(fn () => ['id' => $this->identity]);
        $this->app->instance(SupabaseAuthService::class, $auth);
        $this->withSession(['supabase_access_token' => 'synthetic-media-session']);
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

    public static function parents(): array
    {
        return [['visits'], ['materials']];
    }

    private function url(string $kind, ?string $photo = null, ?string $variant = null, string $prefix = 'admin'): string
    {
        $path = '/'.$prefix.'/monitoring/'.self::RECORD.'/photos/'.$kind.'/'.self::RECORD;

        return $path.($photo ? '/'.$photo : '').($variant ? '/'.$variant : '');
    }

    private function upload(string $kind, ?string $requestId = null, ?UploadedFile $file = null, string $prefix = 'admin')
    {
        return $this->post($this->url($kind, prefix: $prefix), [
            'photo' => $file ?? UploadedFile::fake()->image('synthetic.png', 64, 32),
            'request_id' => $requestId ?? (string) Str::uuid(),
        ], ['Accept' => 'application/json']);
    }

    #[DataProvider('parents')]
    public function test_upload_list_variants_and_delete_are_private_audited_and_independent(string $kind): void
    {
        $before = DB::table('monitoring_records')->first();
        $file = UploadedFile::fake()->image('synthetic.png', 640, 480);
        $requestId = (string) Str::uuid();
        $id = $this->upload($kind, $requestId, $file)->assertOk()->assertJsonPath('photo.status', 'ready')->json('photo.id');
        $this->upload($kind, $requestId, $file)->assertOk()->assertJsonPath('photo.id', $id);
        $this->assertCount(2, Storage::disk('media_private')->allFiles());
        $gallery = $this->getJson($this->url($kind))->assertOk()->assertJsonPath('used_slots', 1)
            ->assertJsonCount(1, 'photos')->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        foreach (['object_prefix', 'media/', 'uploaded_by', 'lease_until', 'operation_token', 'input_sha256'] as $secret) {
            $this->assertStringNotContainsString($secret, $gallery->getContent());
        }
        $row = MediaPhoto::findOrFail($id);
        foreach (['thumbnail', 'display'] as $variant) {
            $response = $this->get($gallery->json('photos.0.'.$variant.'_url'))->assertOk()
                ->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
                ->assertHeader('Content-Length', (string) $row->{$variant.'_bytes'});
            $this->assertSame($row->{$variant.'_sha256'}, hash('sha256', $response->streamedContent()));
        }
        $this->assertSame((array) $before, (array) DB::table('monitoring_records')->first());
        $this->assertSame(1, DB::table('audit_logs')->where('action_type', 'FINALIZE PHOTO')->count());
        $this->identity = self::OFFICER;
        $this->getJson($this->url($kind, prefix: 'field-officer'))->assertOk();
        $this->deleteJson($this->url($kind, $id, prefix: 'field-officer'))->assertOk()->assertJsonPath('status', 'deleted');
        $this->assertSame([], Storage::disk('media_private')->allFiles());
        $this->get($this->url($kind, $id, 'thumbnail', 'field-officer'))->assertNotFound();
        $this->getJson($this->url($kind, prefix: 'field-officer'))->assertJsonPath('used_slots', 0);
        $this->identity = self::ADMIN;
        $this->upload($kind, $requestId, $file)->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->assertSame(1, MediaPhoto::count());

    }

    #[DataProvider('parents')]
    public function test_capacity_counts_pending_and_is_independent_per_parent(string $kind): void
    {
        for ($index = 0; $index < 5; $index++) {
            $this->upload($kind)->assertOk();
        }
        $this->upload($kind)->assertStatus(409);
        $other = $kind === 'visits' ? 'materials' : 'visits';
        $this->upload($other)->assertOk();
        $this->getJson($this->url($kind))->assertJsonPath('used_slots', 5);
    }

    #[DataProvider('parents')]
    public function test_bad_images_size_and_missing_objects_fail_safely(string $kind): void
    {
        $id = $this->upload($kind)->assertOk()->json('photo.id');
        $this->upload($kind, file: UploadedFile::fake()->create('large.png', 5121, 'image/png'))->assertUnprocessable();
        $this->upload($kind, file: UploadedFile::fake()->createWithContent('bad.png', 'not an image'))->assertUnprocessable();
        $this->upload($kind, file: UploadedFile::fake()->image('bad.gif'))->assertUnprocessable();
        $this->getJson($this->url($kind))->assertJsonPath('used_slots', 1);
        $row = MediaPhoto::find($id);
        Storage::disk('media_private')->delete($row->object_prefix.'/thumbnail.png');
        $this->get($this->url($kind, $id, 'thumbnail'))->assertStatus(503)
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->get($this->url($kind, $id, 'original'))->assertNotFound();
        $this->assertSame('ready', $row->fresh()->state);
    }

    public static function changedScope(): array
    {
        $cases = [];
        foreach (['visits', 'materials'] as $kind) {
            foreach (['reassigned', 'revoked', 'role_inactive', 'archived', 'association_inactive', 'finalized'] as $change) {
                $cases[] = [$kind, $change];
            }
        }

        return $cases;
    }

    #[DataProvider('changedScope')]
    public function test_current_scope_and_lifecycle_are_revalidated(string $kind, string $change): void
    {
        $id = $this->upload($kind)->json('photo.id');
        $this->identity = self::OFFICER;
        match ($change) {
            'reassigned' => DB::table('associations')->update(['primary_field_officer_id' => self::ADMIN]),
            'revoked' => DB::table('user_profiles')->where('id', self::OFFICER)->update(['is_active' => false]),
            'role_inactive' => DB::table('roles')->where('code', 'field_officer')->update(['is_active' => false]),
            'archived' => DB::table('projects')->update(['archived_at' => now()]),
            'association_inactive' => DB::table('associations')->update(['status' => 'inactive']),
            'finalized' => DB::table('projects')->update(['status' => 'completed', 'closed_at' => now()]),
        };
        $this->assertFalse($this->upload($kind, prefix: 'field-officer')->isSuccessful());
        $this->assertFalse($this->deleteJson($this->url($kind, $id, prefix: 'field-officer'))->isSuccessful());
        $response = $this->get($this->url($kind, $id, 'thumbnail', 'field-officer'));
        if ($change === 'finalized') {
            $response->assertOk(); // Historical evidence remains readable within current scope.
        } else {
            $this->assertFalse($response->isSuccessful());
        }
        $this->assertSame('ready', MediaPhoto::find($id)->state);
    }

    public function test_stale_actor_cutoff_is_denied_inside_transaction(): void
    {
        $request = Request::create('/synthetic-media');
        $request->attributes->set('current_user', UserProfile::with('role')->find(self::ADMIN));
        DB::table('user_profiles')->where('id', self::ADMIN)->update(['auth_sessions_valid_after' => now()]);
        $service = app(MonitoringPhotoService::class);
        $record = MonitoringRecord::find(self::RECORD);
        $parent = $service->parent($record, 'visits', self::RECORD);
        foreach ([false, true] as $write) {
            try {
                DB::transaction(fn () => ($service->authorize($request, $record, $parent, $write))($parent));
                $this->fail('Stale session actor accepted.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
    }

    public function test_cross_parent_association_and_guest_access_are_denied(): void
    {
        $id = $this->upload('visits')->json('photo.id');
        $this->get($this->url('materials', $id, 'thumbnail'))->assertNotFound();
        $this->deleteJson($this->url('materials', $id))->assertNotFound();
        $this->postJson($this->url('materials', $id, 'reconcile'))->assertNotFound();
        $foreign = (string) Str::uuid();
        DB::table('monitoring_records')->insert(array_replace((array) DB::table('monitoring_records')->first(), ['id' => $foreign]));
        $this->getJson(str_replace('/monitoring/'.self::RECORD.'/', '/monitoring/'.$foreign.'/', $this->url('visits')))->assertNotFound();
        $this->identity = self::ACCOUNT;
        $this->getJson($this->url('visits'))->assertForbidden();
        $this->upload('visits')->assertForbidden();
        $this->get('/association/monitoring/'.self::RECORD.'/photos/visits/'.self::RECORD)->assertNotFound();
        $this->flushSession();
        $this->get($this->url('visits', $id, 'thumbnail'))->assertRedirect(route('login'));
    }

    public function test_unknown_write_outcome_and_failed_cleanup_preserve_existing_photos_and_recover(): void
    {
        $old = $this->upload('materials')->json('photo.id');
        $disk = Storage::disk('media_private');
        $mock = Mockery::mock($disk);
        $calls = 0;
        $mock->shouldReceive('writeStream')->twice()->andReturnUsing(function ($key, $stream, $options) use ($disk, &$calls) {
            $this->assertSame(0, DB::transactionLevel());
            $disk->writeStream($key, $stream, $options);
            if (++$calls === 2) {
                throw new RuntimeException('secret endpoint token media/sensitive');
            }

            return true;
        });
        Storage::set('media_private', $mock);
        $response = $this->upload('materials')->assertStatus(503);
        $this->assertStringNotContainsString('secret', $response->getContent());
        $pending = MediaPhoto::where('state', 'uncertain')->firstOrFail();
        Storage::set('media_private', $disk);
        $this->getJson($this->url('materials'))->assertJsonPath('used_slots', 2)->assertJsonCount(1, 'pending');
        DB::table('media_photos')->where('id', $pending->id)->update(['lease_until' => now()->subMinute()]);
        $this->postJson($this->url('materials', $pending->id, 'reconcile'))->assertOk()->assertJsonPath('status', 'ready');
        $mock = Mockery::mock($disk);
        $mock->shouldReceive('delete')->once()->andThrow(new RuntimeException('secret delete timeout'));
        Storage::set('media_private', $mock);
        $this->deleteJson($this->url('materials', $pending->id))->assertStatus(503);
        $this->assertSame('deleting', $pending->fresh()->state);
        Storage::set('media_private', $disk);
        $this->get($this->url('materials', $pending->id, 'thumbnail'))->assertNotFound();
        DB::table('media_photos')->where('id', $pending->id)->update(['lease_until' => now()->subMinute()]);
        $this->postJson($this->url('materials', $pending->id, 'reconcile'))->assertOk()->assertJsonPath('status', 'deleted');
        $this->assertSame('ready', MediaPhoto::find($old)->state);
    }

    public function test_reassignment_while_uploading_fences_finalization_and_keeps_reserved_capacity(): void
    {
        $this->identity = self::OFFICER;
        $disk = Storage::disk('media_private');
        $mock = Mockery::mock($disk);
        $writes = 0;
        $mock->shouldReceive('writeStream')->twice()->andReturnUsing(function ($key, $stream, $options) use ($disk, &$writes) {
            $this->assertSame(0, DB::transactionLevel());
            $disk->writeStream($key, $stream, $options);
            if (++$writes === 2) {
                DB::table('associations')->update(['primary_field_officer_id' => self::ADMIN]);
            }

            return true;
        });
        Storage::set('media_private', $mock);
        $this->upload('visits', prefix: 'field-officer')->assertStatus(503);
        $row = MediaPhoto::firstOrFail();
        $this->assertSame('uncertain', $row->state);
        Storage::set('media_private', $disk);
        DB::table('media_photos')->update(['lease_until' => now()->subMinute()]);
        $this->assertFalse($this->postJson($this->url('visits', $row->id, 'reconcile', 'field-officer'))->isSuccessful());
        $this->identity = self::ADMIN;
        $this->getJson($this->url('visits'))->assertJsonPath('used_slots', 1)->assertJsonCount(0, 'photos');
        $this->postJson($this->url('visits', $row->id, 'reconcile'))->assertOk()->assertJsonPath('status', 'ready');
    }

    public function test_current_actor_cannot_read_or_write_photos_of_another_association(): void
    {
        $association = (string) Str::uuid();
        $project = (string) Str::uuid();
        DB::table('associations')->insert(array_replace((array) DB::table('associations')->first(),
            ['id' => $association, 'primary_field_officer_id' => self::ADMIN]));
        DB::table('projects')->insert(array_replace((array) DB::table('projects')->first(),
            ['id' => $project, 'association_id' => $association]));
        DB::table('monitoring_records')->update(['project_id' => $project]);
        $id = $this->upload('visits')->assertOk()->json('photo.id');
        $this->identity = self::OFFICER;
        $this->getJson($this->url('visits', prefix: 'field-officer'))->assertNotFound();
        $this->get($this->url('visits', $id, 'thumbnail', 'field-officer'))->assertNotFound();
        $this->assertFalse($this->upload('visits', prefix: 'field-officer')->isSuccessful());
        $this->assertFalse($this->deleteJson($this->url('visits', $id, prefix: 'field-officer'))->isSuccessful());
    }

    public function test_finalization_database_failure_keeps_durable_recovery_metadata(): void
    {
        $audit = Mockery::mock(AuditLogService::class)->makePartial();
        $audit->shouldReceive('record')->withArgs(fn ($request, $action) => $action === 'FINALIZE PHOTO')
            ->andThrow(new RuntimeException('synthetic transaction failure'));
        $this->app->instance(AuditLogService::class, $audit);
        $this->upload('visits')->assertStatus(503);
        $row = MediaPhoto::firstOrFail();
        $this->assertSame('uncertain', $row->state);
        $this->assertCount(2, Storage::disk('media_private')->allFiles());
        $this->assertSame(0, DB::table('audit_logs')->where('action_type', 'FINALIZE PHOTO')->count());
        $this->app->instance(AuditLogService::class, new AuditLogService);
        DB::table('media_photos')->update(['lease_until' => now()->subMinute()]);
        $this->postJson($this->url('visits', $row->id, 'reconcile'))->assertOk()->assertJsonPath('status', 'ready');
    }

    public function test_withdrawal_or_reassignment_during_private_read_denies_delivery_without_partial_bytes(): void
    {
        foreach (['withdraw', 'reassign', 'revoke', 'corrupt', 'timeout'] as $failure) {
            $id = $this->upload('visits')->assertOk()->json('photo.id');
            $disk = Storage::disk('media_private');
            $mock = Mockery::mock($disk);
            $mock->shouldReceive('readStream')->once()->andReturnUsing(function ($key) use ($disk, $failure, $id) {
                $this->assertSame(0, DB::transactionLevel());
                if ($failure === 'withdraw') {
                    DB::table('media_photos')->where('id', $id)->update(['state' => 'deleting']);
                }
                if ($failure === 'reassign') {
                    DB::table('associations')->update(['primary_field_officer_id' => self::ADMIN]);
                }
                if ($failure === 'revoke') {
                    DB::table('user_profiles')->where('id', self::OFFICER)->update(['is_active' => false]);
                }
                if ($failure === 'timeout') {
                    throw new RuntimeException('secret provider read timeout');
                }
                if ($failure === 'corrupt') {
                    $disk->put($key, 'corrupt');
                }

                return $disk->readStream($key);
            });
            Storage::set('media_private', $mock);
            $this->identity = self::OFFICER;
            $response = $this->get($this->url('visits', $id, 'thumbnail', 'field-officer'));
            $response->assertStatus(in_array($failure, ['withdraw', 'reassign']) ? 404 : ($failure === 'revoke' ? 403 : 503));
            $this->assertStringNotContainsString('secret', $response->getContent());
            $this->assertStringNotContainsString('corrupt', $response->getContent());
            Storage::set('media_private', $disk);
            DB::table('associations')->update(['primary_field_officer_id' => self::OFFICER]);
            DB::table('user_profiles')->where('id', self::OFFICER)->update(['is_active' => true]);
            $this->identity = self::ADMIN;
        }
    }

    public function test_galleries_render_only_for_staff_and_material_photo_history_blocks_parent_deletion_cleanly(): void
    {
        $this->get(route('admin.monitoring.visits', self::RECORD))->assertOk()->assertSee('data-monitoring-photos', false);
        $this->get(route('admin.monitoring.workspace', [self::RECORD, 'materials']))->assertOk()->assertSee('data-monitoring-photos', false);
        $directory = getenv('ASSOCMAP_MEDIA1_UI_DIRECTORY');
        if ($directory) {
            $this->assertMatchesRegularExpression('/\\Aassocmap-media1-ui-[a-f0-9]{32}\\z/', basename($directory));
            $this->assertSame(realpath(sys_get_temp_dir()), realpath(dirname($directory)));
            $image = UploadedFile::fake()->image('synthetic.png', 64, 32);
            file_put_contents($directory.'/photo.png', file_get_contents($image->getPathname()));
            foreach (['visits' => 'visits', 'materials' => 'workspace'] as $kind => $page) {
                $url = route('admin.monitoring.'.$page, $kind === 'visits' ? [self::RECORD] : [self::RECORD, 'materials']);
                file_put_contents($directory.'/'.$kind.'.html', $this->get($url)->assertOk()->getContent());
            }
        }
        DB::table('projects')->update(['status' => 'completed', 'closed_at' => now()]);
        $this->get(route('admin.monitoring.workspace', [self::RECORD, 'materials']))->assertOk()->assertDontSee('data-photo-upload', false);
        DB::table('projects')->update(['status' => 'ongoing', 'closed_at' => null]);
        $this->identity = self::ACCOUNT;
        $this->get(route('association.monitoring.workspace', [self::RECORD, 'materials']))->assertOk()->assertDontSee('data-monitoring-photos', false);
        $this->identity = self::ADMIN;
        $id = $this->upload('materials')->json('photo.id');
        $this->deleteJson($this->url('materials', $id))->assertOk();
        $record = MonitoringRecord::find(self::RECORD);
        $this->patchJson(route('admin.monitoring.section.update', [self::RECORD, 'materials']), [
            'project_id' => self::PROJECT, 'year' => 2026, 'quarter_id' => 11,
            'materials_revision' => app(MaterialsMonitoringService::class)->revision($record),
            'deleted_material_ids' => [self::RECORD],
        ])->assertUnprocessable()->assertJsonValidationErrors('deleted_material_ids');
        $this->assertDatabaseHas('monitoring_materials', ['id' => self::RECORD]);
    }
}
