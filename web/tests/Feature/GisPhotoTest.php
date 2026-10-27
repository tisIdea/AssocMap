<?php

namespace Tests\Feature;

use App\Models\GisLocation;
use App\Models\MediaPhoto;
use App\Models\UserProfile;
use App\Services\Auth\SupabaseAuthService;
use App\Services\GIS\GisPhotoService;
use App\Services\GIS\GisQueryService;
use App\Services\Media\PhotoLifecycleService;
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
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CreatesLifecycleAuditTable;
use Tests\Support\GisSqliteConnection;
use Tests\Support\MonitoringMaterialsFixture;
use Tests\TestCase;

class GisPhotoTest extends TestCase
{
    use CreatesLifecycleAuditTable;
    use MonitoringMaterialsFixture;

    private const LOCATION = '99999999-9999-4999-8999-999999999999';
    private string $identity = self::ADMIN;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('g', 32))]);
        DB::purge('sqlite');
        DB::extend('sqlite', fn ($config) => new GisSqliteConnection(new PDO('sqlite::memory:'), ':memory:', '', $config));
        $this->schema();
        $this->fixtures();
        $this->createLifecycleAuditTable();
        Schema::table('user_profiles', function (Blueprint $t) {
            $t->timestampTz('auth_sessions_valid_after')->nullable(); $t->uuid('email_change_hold_id')->nullable();
        });
        Schema::table('gis_locations', function (Blueprint $t) {
            $t->uuid('project_id'); $t->uuid('association_id')->nullable(); $t->string('location_type');
            $t->boolean('is_published')->default(false); $t->uuid('published_by')->nullable();
            $t->timestamp('published_at')->nullable(); $t->timestamp('archived_at')->nullable(); $t->string('public_title')->nullable(); $t->timestamps();
        });
        DB::table('gis_locations')->insert(['id' => self::LOCATION, 'project_id' => self::PROJECT, 'location_type' => 'project_point', 'public_title' => 'Public site']);
        $auth = Mockery::mock(SupabaseAuthService::class);
        $auth->shouldReceive('getUser')->andReturnUsing(fn () => ['id' => $this->identity]);
        $this->app->instance(SupabaseAuthService::class, $auth);
        $this->withSession(['supabase_access_token' => 'synthetic-gis-session']);
        Storage::fake('media_private', ['visibility' => 'private']);
        // SQLite checks endpoint/lifecycle decisions. Native tests use the full PostGIS public policy.
        $queries = Mockery::mock(GisQueryService::class);
        $queries->shouldReceive('scoped')->with(null)->andReturnUsing(fn () => GisLocation::query()->from('gis_locations as g')
            ->where('g.is_published', true)->whereNotNull('g.published_by')->whereNotNull('g.published_at')
            ->whereNotNull('g.public_title')->whereNull('g.archived_at'));
        $this->app->instance(GisQueryService::class, $queries);
    }

    protected function tearDown(): void
    {
        try { Http::assertNothingSent(); parent::tearDown(); }
        finally { HandleExceptions::flushState($this); }
    }

    private function url(?string $photo = null, ?string $suffix = null, string $prefix = 'admin', ?string $location = null): string
    {
        return '/'.$prefix.'/'.($prefix === 'map' ? '' : 'gis/').($location ?? self::LOCATION).'/photos'
            .($photo ? '/'.$photo : '').($suffix ? '/'.$suffix : '');
    }

    private function upload(?UploadedFile $file = null, ?string $requestId = null, string $prefix = 'admin')
    {
        return $this->post($this->url(prefix: $prefix), ['photo' => $file ?? UploadedFile::fake()->image('site.png', 64, 32),
            'request_id' => $requestId ?? (string) Str::uuid()], ['Accept' => 'application/json']);
    }

    private function publish(): void
    {
        DB::table('gis_locations')->update(['is_published' => true, 'published_by' => self::ADMIN, 'published_at' => now()]);
    }

    private function approve(string $id, bool $approved = true)
    {
        return $this->patchJson($this->url($id, 'approval'), ['approved' => $approved]);
    }

    public function test_private_upload_variants_approval_withdrawal_and_safe_public_metadata(): void
    {
        $file = UploadedFile::fake()->image('site.png', 640, 480); $token = (string) Str::uuid();
        $id = $this->upload($file, $token)->assertOk()->assertJsonPath('photo.status', 'ready')->json('photo.id');
        $this->upload($file, $token)->assertOk()->assertJsonPath('photo.id', $id);
        $this->assertCount(2, Storage::disk('media_private')->allFiles());
        $gallery = $this->getJson($this->url())->assertOk()->assertJsonPath('photos.0.approved', false)->assertJsonPath('used_slots', 1);
        $this->getJson($this->url(prefix: 'map'))->assertNotFound();
        $this->publish();
        $this->getJson($this->url(prefix: 'map'))->assertOk()->assertJsonCount(0, 'photos');
        $this->get($this->url($id, 'thumbnail', 'map'))->assertNotFound();
        $this->approve($id)->assertOk();
        $public = $this->getJson($this->url(prefix: 'map'))->assertOk()->assertJsonCount(1, 'photos')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        foreach (['object_prefix', 'media/', 'uploaded_by', 'approved_by', 'lease_until', 'operation_token', 'input_sha256', 'disk', 'pending', 'created_at'] as $secret) {
            $this->assertStringNotContainsString($secret, $public->getContent());
        }
        foreach (['thumbnail', 'display'] as $variant) {
            foreach ([$gallery, $public] as $metadata) {
                $response = $this->get($metadata->json('photos.0.'.$variant.'_url'))->assertOk()->assertHeader('Content-Type', 'image/png')
                    ->assertHeader('Cache-Control', 'max-age=0, no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
                $this->assertSame(MediaPhoto::find($id)->{$variant.'_sha256'}, hash('sha256', $response->streamedContent()));
            }
        }
        $this->approve($id, false)->assertOk();
        $this->get($public->json('photos.0.thumbnail_url'))->assertNotFound();
        $this->getJson($this->url(prefix: 'map'))->assertJsonCount(0, 'photos');
        $this->assertSame(1, DB::table('audit_logs')->where('action_type', 'APPROVE PHOTO')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action_type', 'UNAPPROVE PHOTO')->count());
    }

    public function test_five_slot_capacity_counts_reservations_and_removal_frees_capacity(): void
    {
        $service = app(PhotoLifecycleService::class); $gis = app(GisPhotoService::class);
        $request = Request::create('/synthetic'); $request->attributes->set('current_user', UserProfile::with('role')->find(self::ADMIN));
        $ready = $this->upload()->assertOk()->json('photo.id');
        for ($i = 0; $i < 4; $i++) $service->reserve($gis->parent(self::LOCATION), UploadedFile::fake()->image('site.png'),
            (string) Str::uuid(), $request, $gis->authorize($request, self::LOCATION, true));
        $this->getJson($this->url())->assertJsonPath('used_slots', 5)->assertJsonCount(4, 'pending');
        $this->upload()->assertStatus(409);
        $this->deleteJson($this->url($ready))->assertOk()->assertJsonPath('status', 'deleted');
        $this->upload()->assertOk();
    }

    public function test_invalid_uploads_and_narrow_gis_request_limit(): void
    {
        foreach ([UploadedFile::fake()->create('site.gif', 1, 'image/gif'), UploadedFile::fake()->create('site.png', 5121, 'image/png'),
            UploadedFile::fake()->createWithContent('site.png', 'invalid bytes')] as $file) $this->upload($file)->assertUnprocessable();
        $this->postJson('/admin/gis', ['padding' => str_repeat('x', 196609)])->assertStatus(413);
        $this->withServerVariables(['CONTENT_LENGTH' => 6 * 1024 * 1024 + 1])->upload()->assertStatus(413);
    }

    public function test_valid_jpeg_and_large_png_uploads_pass_the_photo_envelope_only(): void
    {
        $this->upload(UploadedFile::fake()->image('site.jpg', 64, 32))->assertOk();
        $image = UploadedFile::fake()->image('site.png', 64, 32);
        $large = UploadedFile::fake()->createWithContent('site.png', file_get_contents($image->getPathname()).str_repeat("\0", 262144));
        $this->upload($large)->assertOk();
        $this->getJson($this->url())->assertJsonPath('used_slots', 2)->assertJsonPath('photos.0.approved', false);
    }

    public function test_cross_location_cross_association_and_nonstaff_access(): void
    {
        $id = $this->upload()->assertOk()->json('photo.id'); $other = (string) Str::uuid();
        DB::table('gis_locations')->insert(['id' => $other, 'project_id' => self::PROJECT, 'location_type' => 'project_point']);
        $this->get($this->url($id, 'thumbnail', location: $other))->assertNotFound();
        $this->patchJson($this->url($id, 'approval', location: $other), ['approved' => true])->assertNotFound();
        $this->deleteJson($this->url($id, location: $other))->assertNotFound();
        $association = (string) Str::uuid();
        DB::table('associations')->insert(['id' => $association, 'name' => 'Other', 'status' => 'active', 'primary_field_officer_id' => self::ADMIN]);
        DB::table('projects')->update(['association_id' => $association]);
        $this->identity = self::OFFICER;
        $this->getJson($this->url(prefix: 'field-officer'))->assertNotFound();
        $this->upload(prefix: 'field-officer')->assertNotFound();
        $this->identity = self::ACCOUNT;
        $this->getJson($this->url())->assertForbidden();
        $this->getJson($this->url(prefix: 'association'))->assertNotFound();
        $this->flushSession(); $this->get($this->url())->assertRedirect(route('login'));
    }

    public static function eligibilityChanges(): array
    {
        return array_map(fn ($value) => [$value], ['reassigned', 'revoked', 'role', 'role_inactive', 'finalized', 'archived', 'location_archived', 'association_archived', 'association_inactive']);
    }

    #[DataProvider('eligibilityChanges')]
    public function test_staff_writes_revalidate_current_scope_and_lifecycle(string $change): void
    {
        $id = $this->upload()->assertOk()->json('photo.id'); $this->identity = self::OFFICER;
        match ($change) {
            'reassigned' => DB::table('associations')->update(['primary_field_officer_id' => self::ADMIN]),
            'revoked' => DB::table('user_profiles')->where('id', self::OFFICER)->update(['is_active' => false]),
            'role' => DB::table('user_profiles')->where('id', self::OFFICER)->update(['role_id' => 3]),
            'role_inactive' => DB::table('roles')->where('id', 2)->update(['is_active' => false]),
            'finalized' => DB::table('projects')->update(['status' => 'completed', 'closed_at' => now()]),
            'archived' => DB::table('projects')->update(['archived_at' => now()]),
            'location_archived' => DB::table('gis_locations')->update(['archived_at' => now()]),
            'association_archived' => DB::table('associations')->update(['archived_at' => now()]),
            'association_inactive' => DB::table('associations')->update(['status' => 'inactive']),
        };
        $this->assertFalse($this->upload(prefix: 'field-officer')->isSuccessful());
        $this->assertFalse($this->patchJson($this->url($id, 'approval', 'field-officer'), ['approved' => true])->isSuccessful());
        $this->assertFalse($this->deleteJson($this->url($id, prefix: 'field-officer'))->isSuccessful());
        if (in_array($change, ['finalized', 'location_archived'])) {
            $this->getJson($this->url(prefix: 'field-officer'))->assertOk()->assertJsonPath('can_manage', false);
            $this->get($this->url($id, 'thumbnail', 'field-officer'))->assertOk();
        }
        $this->assertSame('ready', MediaPhoto::find($id)->state);
    }

    public function test_stale_session_snapshot_is_denied_at_each_transaction(): void
    {
        $request = Request::create('/synthetic'); $request->attributes->set('current_user', UserProfile::with('role')->find(self::ADMIN));
        $service = app(GisPhotoService::class); $parent = $service->parent(self::LOCATION);
        $authorize = $service->authorize($request, self::LOCATION, true);
        DB::transaction(fn () => $authorize($parent));
        DB::table('user_profiles')->where('id', self::ADMIN)->update(['auth_sessions_valid_after' => now()]);
        foreach ([false, true] as $write) {
            try { DB::transaction(fn () => ($service->authorize($request, self::LOCATION, $write))($parent)); $this->fail('Stale actor accepted.'); }
            catch (HttpException $exception) { $this->assertSame(403, $exception->getStatusCode()); }
        }
    }

    public function test_reorder_requires_exact_ready_photo_set(): void
    {
        $a = $this->upload()->assertOk()->json('photo.id'); $b = $this->upload()->assertOk()->json('photo.id');
        $this->patchJson($this->url().'/order', ['ids' => [$b, $a]])->assertOk();
        $this->getJson($this->url())->assertJsonPath('photos.0.id', $b);
        $this->patchJson($this->url().'/order', ['ids' => [$a]])->assertStatus(409);
        $this->patchJson($this->url().'/order', ['ids' => [$b, $b]])->assertUnprocessable();
    }

    public static function readChanges(): array
    {
        return array_map(fn ($value) => [$value], ['approval', 'publication', 'location_archive', 'project_archive', 'association_archive', 'missing', 'corrupt', 'timeout']);
    }

    #[DataProvider('readChanges')]
    public function test_public_read_rechecks_after_storage_io_and_never_streams_unverified_bytes(string $change): void
    {
        $id = $this->upload()->assertOk()->json('photo.id'); $this->approve($id)->assertOk(); $this->publish();
        $disk = Storage::disk('media_private'); $mock = Mockery::mock($disk);
        $mock->shouldReceive('readStream')->once()->andReturnUsing(function ($key) use ($disk, $change, $id) {
            $this->assertSame(0, DB::transactionLevel());
            match ($change) {
                'approval' => DB::table('media_photos')->where('id', $id)->update(['approved_at' => null, 'approved_by' => null]),
                'publication' => DB::table('gis_locations')->update(['is_published' => false]),
                'location_archive' => DB::table('gis_locations')->update(['archived_at' => now()]),
                'project_archive' => DB::table('projects')->update(['archived_at' => now()]),
                'association_archive' => DB::table('associations')->update(['archived_at' => now()]),
                'missing' => $disk->delete($key), 'corrupt' => $disk->put($key, 'corrupt'),
                'timeout' => throw new RuntimeException('secret provider details'),
            };
            return $disk->readStream($key);
        });
        Storage::set('media_private', $mock);
        $response = $this->get($this->url($id, 'thumbnail', 'map'));
        $response->assertStatus(in_array($change, ['missing', 'corrupt', 'timeout']) ? 503 : 404);
        $this->assertStringNotContainsString('secret', $response->getContent());
        $this->assertStringNotContainsString('corrupt', $response->getContent());
    }

    public function test_uncertain_upload_recovery_and_reassignment_fence(): void
    {
        $disk = Storage::disk('media_private'); $mock = Mockery::mock($disk); $writes = 0;
        $this->identity = self::OFFICER;
        $mock->shouldReceive('writeStream')->twice()->andReturnUsing(function ($key, $stream, $options) use ($disk, &$writes) {
            $this->assertSame(0, DB::transactionLevel()); $disk->writeStream($key, $stream, $options);
            if (++$writes === 2) DB::table('associations')->update(['primary_field_officer_id' => self::ADMIN]);
            return true;
        });
        Storage::set('media_private', $mock); $this->upload(prefix: 'field-officer')->assertStatus(503);
        $row = MediaPhoto::firstOrFail(); $this->assertSame('uncertain', $row->state);
        Storage::set('media_private', $disk); DB::table('media_photos')->update(['lease_until' => now()->subMinute()]);
        $this->postJson($this->url($row->id, 'reconcile', 'field-officer'))->assertNotFound();
        $this->identity = self::ADMIN;
        $this->postJson($this->url($row->id, 'reconcile'))->assertOk()->assertJsonPath('status', 'ready');
        $this->getJson($this->url())->assertJsonPath('photos.0.approved', false);
    }

    public function test_failed_storage_cleanup_revokes_public_access_and_can_be_reconciled(): void
    {
        $id = $this->upload()->assertOk()->json('photo.id'); $this->approve($id)->assertOk(); $this->publish();
        $disk = Storage::disk('media_private'); $mock = Mockery::mock($disk);
        $mock->shouldReceive('delete')->once()->andThrow(new RuntimeException('secret storage timeout'));
        Storage::set('media_private', $mock);
        $response = $this->deleteJson($this->url($id))->assertStatus(503);
        $this->assertStringNotContainsString('secret', $response->getContent());
        $this->assertSame('deleting', MediaPhoto::find($id)->state);
        Storage::set('media_private', $disk);
        $this->get($this->url($id, 'thumbnail', 'map'))->assertNotFound();
        DB::table('media_photos')->where('id', $id)->update(['lease_until' => now()->subMinute()]);
        $this->postJson($this->url($id, 'reconcile'))->assertOk()->assertJsonPath('status', 'deleted');
        $this->assertSame([], Storage::disk('media_private')->allFiles());
    }

    public static function privateReadChanges(): array
    {
        return [['reassigned'], ['revoked'], ['role'], ['cutoff']];
    }

    #[DataProvider('privateReadChanges')]
    public function test_private_read_rechecks_actor_and_assignment_after_storage_io(string $change): void
    {
        $id = $this->upload()->assertOk()->json('photo.id'); $this->identity = self::OFFICER;
        $disk = Storage::disk('media_private'); $mock = Mockery::mock($disk);
        $mock->shouldReceive('readStream')->once()->andReturnUsing(function ($key) use ($disk, $change) {
            $this->assertSame(0, DB::transactionLevel());
            match ($change) {
                'reassigned' => DB::table('associations')->update(['primary_field_officer_id' => self::ADMIN]),
                'revoked' => DB::table('user_profiles')->where('id', self::OFFICER)->update(['is_active' => false]),
                'role' => DB::table('user_profiles')->where('id', self::OFFICER)->update(['role_id' => 1]),
                'cutoff' => DB::table('user_profiles')->where('id', self::OFFICER)->update(['auth_sessions_valid_after' => now()]),
            };
            return $disk->readStream($key);
        });
        Storage::set('media_private', $mock);
        $this->get($this->url($id, 'thumbnail', 'field-officer'))->assertStatus($change === 'reassigned' ? 404 : 403);
    }

    public function test_csrf_is_required_for_every_staff_write(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        foreach ([['post', $this->url()], ['patch', $this->url(self::LOCATION, 'approval')],
            ['delete', $this->url(self::LOCATION)], ['patch', $this->url().'/order'],
            ['post', $this->url(self::LOCATION, 'reconcile')]] as [$method, $url]) {
            $this->{$method.'Json'}($url, [])->assertStatus(419);
        }
        $this->assertSame(0, MediaPhoto::count());
    }

    public function test_valid_component_csrf_header_accepts_photo_upload_and_approval(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $token = 'synthetic-gis-csrf';
        $this->withSession(['_token' => $token]);
        $id = $this->withHeader('X-CSRF-TOKEN', $token)->upload()->assertOk()->json('photo.id');
        $this->approve($id)->assertOk();
    }

    public function test_gallery_markup_and_optional_browser_fixture(): void
    {
        foreach ([true, false] as $manager) {
            $html = view('components.gis-photos', ['gisManager' => $manager])->render();
            $this->assertStringContainsString('data-photo-viewer', $html);
            $this->assertSame($manager, str_contains($html, 'data-photo-upload'));
            if ($directory = getenv('ASSOCMAP_GISMEDIA_UI_DIRECTORY')) {
                $this->assertMatchesRegularExpression('/\Aassocmap-gismedia-ui-[a-f0-9]{32}\z/', basename($directory));
                $this->assertSame(realpath(sys_get_temp_dir()), realpath(dirname($directory)));
                file_put_contents($directory.'/'.($manager ? 'staff' : 'public').'.html', $html);
                $image = UploadedFile::fake()->image('site.png');
                file_put_contents($directory.'/photo.png', file_get_contents($image->getPathname()));
            }
        }
    }
}
