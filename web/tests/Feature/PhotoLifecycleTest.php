<?php

namespace Tests\Feature;

use App\Exceptions\MediaLifecycleException;
use App\Models\GisLocation;
use App\Models\MediaPhoto;
use App\Models\MonitoringMaterial;
use App\Models\MonitoringVisitReport;
use App\Models\Project;
use App\Models\UserProfile;
use App\Services\Audit\AuditLogService;
use App\Services\GIS\GisQueryService;
use App\Services\Media\PhotoLifecycleService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\CreatesLifecycleAuditTable;
use Tests\Support\CreatesMediaFixture;
use Tests\TestCase;

class PhotoLifecycleTest extends TestCase
{
    use CreatesLifecycleAuditTable;
    use CreatesMediaFixture;

    private Request $mediaRequest;

    private PhotoLifecycleService $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mediaFixture();
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->image('synthetic.png', 32, 16);
    }

    private function reserve($parent, ?string $replaces = null, ?string $requestId = null, ?UploadedFile $file = null): array
    {
        return $this->lifecycle->reserve($parent, $file ?? $this->photo(), $requestId ?? (string) Str::uuid(),
            $this->mediaRequest, $this->mediaAuthorization(), $replaces);
    }

    private function ready($parent, ?string $replaces = null): array
    {
        $file = $this->photo();
        $reservation = $this->reserve($parent, $replaces, file: $file);

        return $this->lifecycle->upload($reservation['id'], $file, $this->mediaRequest, $this->mediaAuthorization());
    }

    #[DataProvider('parentTypes')]
    public function test_limits_include_pending_reservations_and_are_independent_per_parent(string $type, int $limit): void
    {
        $parent = $this->mediaParent($type);
        for ($i = 0; $i < $limit; $i++) {
            $this->reserve($parent);
        }
        $other = $this->mediaParent($type);
        $this->assertSame('reserved', $this->reserve($other)['status']);
        $this->expectException(MediaLifecycleException::class);
        $this->expectExceptionMessage('capacity_reached');
        $this->reserve($parent);
    }

    public static function parentTypes(): array
    {
        return [[Project::class, 1], [GisLocation::class, 5], [MonitoringVisitReport::class, 5], [MonitoringMaterial::class, 5]];
    }

    public function test_upload_is_private_safe_to_serialize_and_idempotent(): void
    {
        $parent = $this->mediaParent(GisLocation::class);
        $file = $this->photo();
        $requestId = (string) Str::uuid();
        $reserved = $this->reserve($parent, requestId: $requestId, file: $file);
        $this->assertSame($reserved['id'], $this->reserve($parent, requestId: $requestId, file: $file)['id']);
        $ready = $this->lifecycle->upload($reserved['id'], $file, $this->mediaRequest, $this->mediaAuthorization());
        $this->assertSame('ready', $ready['status']);
        $this->assertSame($ready, $this->lifecycle->upload($reserved['id'], $file, $this->mediaRequest, $this->mediaAuthorization()));
        $this->assertCount(2, Storage::disk('media_private')->allFiles());
        $this->assertCount(1, $this->lifecycle->listing($parent, $this->mediaAuthorization()));
        $row = MediaPhoto::findOrFail($ready['id']);
        $this->assertNull($row->approved_by);
        foreach (['object_prefix', 'disk', 'uploaded_by', 'input_sha256', 'operation_token', 'lease_until'] as $field) {
            $this->assertArrayNotHasKey($field, $row->toArray());
            $this->assertArrayNotHasKey($field, $ready);
        }
        $this->assertSame('media_private', $row->disk);
        // Actual S3 visibility is proved by PrivateMediaStorageTest's serialized requests;
        // Windows local fake permissions do not model bucket/object privacy.
    }

    public function test_failed_project_replacement_preserves_old_cover_and_success_retires_it_atomically(): void
    {
        $parent = $this->mediaParent(Project::class);
        $old = $this->ready($parent);
        $file = $this->photo();
        $next = $this->reserve($parent, $old['id'], file: $file);
        $disk = Storage::disk('media_private');
        $mock = Mockery::mock($disk);
        $mock->shouldReceive('writeStream')->once()->andThrow(new RuntimeException('synthetic secret'));
        Storage::set('media_private', $mock);
        try {
            $this->lifecycle->upload($next['id'], $file, $this->mediaRequest, $this->mediaAuthorization());
            $this->fail('Storage failure was accepted.');
        } catch (MediaLifecycleException $exception) {
            $this->assertSame('upload_unconfirmed', $exception->reason);
            $this->assertStringNotContainsString('synthetic secret', $exception->getMessage());
        }
        $this->assertSame('ready', MediaPhoto::find($old['id'])->state);
        $this->assertSame('uncertain', MediaPhoto::find($next['id'])->state);
        Storage::set('media_private', $disk);
        $this->lifecycle->withdraw($next['id'], $this->mediaRequest, $this->mediaAuthorization());
        $this->expireMedia($next['id']);
        $this->lifecycle->reconcile($next['id'], $this->mediaRequest, $this->mediaAuthorization());
        $final = $this->ready($parent, $old['id']);
        $this->assertSame('ready', $final['status']);
        $this->assertSame('deleting', MediaPhoto::find($old['id'])->state);
        $this->assertCount(1, $this->lifecycle->listing($parent, $this->mediaAuthorization()));
    }

    public function test_lost_write_response_is_recovered_by_hashes_without_reupload_or_blind_deletion(): void
    {
        $parent = $this->mediaParent(GisLocation::class);
        $file = $this->photo();
        $reserved = $this->reserve($parent, file: $file);
        $disk = Storage::disk('media_private');
        $mock = Mockery::mock($disk);
        $calls = 0;
        $mock->shouldReceive('writeStream')->twice()->andReturnUsing(function ($path, $stream, $options) use ($disk, &$calls) {
            $disk->writeStream($path, $stream, $options);
            $this->assertSame(0, DB::transactionLevel());
            if (++$calls === 2) {
                throw new RuntimeException('response lost');
            }

            return true;
        });
        $mock->shouldNotReceive('delete');
        Storage::set('media_private', $mock);
        try {
            $this->lifecycle->upload($reserved['id'], $file, $this->mediaRequest, $this->mediaAuthorization());
        } catch (MediaLifecycleException) {
            $this->assertSame('uncertain', MediaPhoto::find($reserved['id'])->state);
        }
        Storage::set('media_private', $disk);
        $this->expireMedia($reserved['id']);
        $recovered = $this->lifecycle->reconcile($reserved['id'], $this->mediaRequest, $this->mediaAuthorization());
        $this->assertSame('ready', $recovered['status']);
        $this->assertCount(2, $disk->allFiles());
    }

    public function test_database_or_audit_failure_after_upload_keeps_objects_and_old_cover_recoverable(): void
    {
        $parent = $this->mediaParent(Project::class);
        $old = $this->ready($parent);
        $file = $this->photo();
        $next = $this->reserve($parent, $old['id'], file: $file);
        $audit = $this->app->make(AuditLogService::class);
        $audit->shouldReceive('record')->withArgs(fn ($request, $action) => $action === 'FINALIZE PHOTO')
            ->once()->andThrow(new RuntimeException('synthetic database failure'));
        try {
            $this->lifecycle->upload($next['id'], $file, $this->mediaRequest, $this->mediaAuthorization());
            $this->fail('Finalization failure was accepted.');
        } catch (MediaLifecycleException) {
            $this->assertSame('ready', MediaPhoto::find($old['id'])->state);
            $this->assertSame('uncertain', MediaPhoto::find($next['id'])->state);
            $this->assertCount(4, Storage::disk('media_private')->allFiles());
        }
    }

    public function test_withdrawal_waits_for_live_worker_and_cleanup_is_idempotent(): void
    {
        $parent = $this->mediaParent(MonitoringMaterial::class);
        $photo = $this->ready($parent);
        $this->lifecycle->withdraw($photo['id'], $this->mediaRequest, $this->mediaAuthorization());
        $result = $this->lifecycle->reconcile($photo['id'], $this->mediaRequest, $this->mediaAuthorization());
        $this->assertSame('deleted', $result['status']);
        $this->assertSame('deleted', $this->lifecycle->reconcile($photo['id'], $this->mediaRequest, $this->mediaAuthorization())['status']);
        $this->assertCount(0, Storage::disk('media_private')->allFiles());
    }

    public function test_abandoned_reservation_frees_capacity_without_storage_requests(): void
    {
        $parent = $this->mediaParent(Project::class);
        $photo = $this->reserve($parent);
        $this->expireMedia($photo['id']);
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldNotReceive('delete');
        Storage::set('media_private', $disk);
        $result = $this->lifecycle->reconcileAbandoned($this->mediaRequest, $this->mediaAuthorization());
        $this->assertSame('deleted', $result[$photo['id']]);
        $this->assertSame('reserved', $this->reserve($parent)['status']);
    }

    public function test_approval_requires_media_and_current_public_parent_eligibility(): void
    {
        $parent = $this->mediaParent(GisLocation::class);
        $photo = $this->ready($parent);
        $queries = Mockery::mock(GisQueryService::class);
        $queries->shouldReceive('scoped')->with(null)->andReturnUsing(fn () => GisLocation::query()->from('gis_locations as g')->where('g.is_published', true));
        $this->app->instance(GisQueryService::class, $queries);
        DB::table('gis_locations')->where('id', $parent->id)->update(['is_published' => true]);
        $this->assertNull($this->lifecycle->publicGisMetadata($photo['id']));
        $this->lifecycle->approveGis($photo['id'], true, $this->mediaRequest, $this->mediaAuthorization());
        $this->assertSame($photo['id'], $this->lifecycle->publicGisMetadata($photo['id'])['id']);
        DB::table('gis_locations')->where('id', $parent->id)->update(['is_published' => false]);
        $this->assertNull($this->lifecycle->publicGisMetadata($photo['id']));
    }

    public function test_reorder_checks_complete_parent_bound_set_and_authorization_is_required(): void
    {
        $parent = $this->mediaParent(MonitoringVisitReport::class);
        $a = $this->ready($parent);
        $b = $this->ready($parent);
        $this->lifecycle->reorder($parent, [$b['id'], $a['id']], $this->mediaRequest, $this->mediaAuthorization());
        $this->assertSame([$b['id'], $a['id']], array_column($this->lifecycle->listing($parent, $this->mediaAuthorization()), 'id'));
        $this->expectException(MediaLifecycleException::class);
        $this->expectExceptionMessage('authorization_context_invalid');
        $this->lifecycle->listing($parent, fn () => null);
    }

    public function test_missing_parent_and_unknown_types_are_rejected(): void
    {
        $parent = new Project;
        $parent->setRawAttributes(['id' => (string) Str::uuid()]);
        $this->expectException(MediaLifecycleException::class);
        $this->expectExceptionMessage('invalid_parent');
        $this->reserve($parent);
    }

    public function test_foreign_keys_exact_parent_and_private_disk_constraints_are_enforced(): void
    {
        $parent = $this->mediaParent(Project::class);
        $photo = $this->reserve($parent);
        foreach ([['disk' => 'public'], ['gis_location_id' => (string) Str::uuid()],
            ['project_id' => null], ['uploaded_by' => (string) Str::uuid()]] as $changes) {
            try {
                DB::table('media_photos')->where('id', $photo['id'])->update($changes);
                $this->fail('Invalid metadata was accepted.');
            } catch (QueryException) {
                $this->assertSame('media_private', MediaPhoto::find($photo['id'])->disk);
            }
        }
        $this->expectException(QueryException::class);
        DB::table('projects')->where('id', $parent->id)->delete();
    }

    public function test_external_transaction_is_rejected_before_any_storage_work(): void
    {
        $parent = $this->mediaParent(Project::class);
        DB::beginTransaction();
        try {
            $this->expectException(MediaLifecycleException::class);
            $this->expectExceptionMessage('external_transaction_not_allowed');
            $this->reserve($parent);
        } finally {
            DB::rollBack();
        }
    }

    public function test_partial_unknown_upload_is_retained_until_explicit_withdrawal(): void
    {
        $parent = $this->mediaParent(GisLocation::class);
        $file = $this->photo();
        $photo = $this->reserve($parent, file: $file);
        $disk = Storage::disk('media_private');
        $mock = Mockery::mock($disk);
        $mock->shouldReceive('writeStream')->once()->andReturnUsing(function ($path, $stream, $options) use ($disk) {
            $this->assertSame('private', $options['visibility']);
            $disk->writeStream($path, $stream, $options);
            throw new RuntimeException('synthetic timeout after write');
        });
        $mock->shouldNotReceive('delete');
        Storage::set('media_private', $mock);
        try {
            $this->lifecycle->upload($photo['id'], $file, $this->mediaRequest, $this->mediaAuthorization());
            $this->fail('Unknown write outcome was accepted.');
        } catch (MediaLifecycleException) {
            $this->assertSame('uncertain', MediaPhoto::find($photo['id'])->state);
        }
        Storage::set('media_private', $disk);
        $this->expireMedia($photo['id']);
        try {
            $this->lifecycle->reconcile($photo['id'], $this->mediaRequest, $this->mediaAuthorization());
            $this->fail('Partial photo was finalized.');
        } catch (MediaLifecycleException) {
            $this->assertSame('uncertain', MediaPhoto::find($photo['id'])->state);
            $this->assertCount(1, $disk->allFiles());
        }
        $this->lifecycle->withdraw($photo['id'], $this->mediaRequest, $this->mediaAuthorization());
        $this->expireMedia($photo['id']);
        $this->lifecycle->reconcile($photo['id'], $this->mediaRequest, $this->mediaAuthorization());
        $this->assertCount(0, $disk->allFiles());
    }

    public function test_actual_database_failure_after_upload_can_be_reconciled_without_reupload(): void
    {
        $parent = $this->mediaParent(Project::class);
        $file = $this->photo();
        $photo = $this->reserve($parent, file: $file);
        DB::unprepared("CREATE TRIGGER media_reject_finalization BEFORE UPDATE OF state ON media_photos
            WHEN NEW.state = 'ready' BEGIN SELECT RAISE(ABORT, 'synthetic finalization failure'); END");
        try {
            $this->lifecycle->upload($photo['id'], $file, $this->mediaRequest, $this->mediaAuthorization());
            $this->fail('Database failure was accepted.');
        } catch (MediaLifecycleException) {
            $this->assertSame('uncertain', MediaPhoto::find($photo['id'])->state);
            $this->assertCount(2, Storage::disk('media_private')->allFiles());
        }
        DB::unprepared('DROP TRIGGER media_reject_finalization');
        $this->expireMedia($photo['id']);
        $this->assertSame('ready', $this->lifecycle->reconcile($photo['id'], $this->mediaRequest, $this->mediaAuthorization())['status']);
    }

    public function test_withdrawal_during_upload_fences_finalization_and_defers_cleanup(): void
    {
        $parent = $this->mediaParent(Project::class);
        $file = $this->photo();
        $photo = $this->reserve($parent, file: $file);
        $disk = Storage::disk('media_private');
        $mock = Mockery::mock($disk);
        $mock->shouldReceive('writeStream')->once()->andReturnUsing(function ($path, $stream, $options) use ($photo, $disk) {
            $this->assertSame(0, DB::transactionLevel());
            $disk->writeStream($path, $stream, $options);
            $this->lifecycle->withdraw($photo['id'], $this->mediaRequest, $this->mediaAuthorization());

            return true;
        });
        Storage::set('media_private', $mock);
        try {
            $this->lifecycle->upload($photo['id'], $file, $this->mediaRequest, $this->mediaAuthorization());
            $this->fail('Withdrawn worker finalized.');
        } catch (MediaLifecycleException) {
            $this->assertSame('deleting', MediaPhoto::find($photo['id'])->state);
        }
        Storage::set('media_private', $disk);
        $this->assertSame('deleting', $this->lifecycle->reconcile($photo['id'], $this->mediaRequest, $this->mediaAuthorization())['status']);
        $this->assertCount(1, $disk->allFiles());
        $this->expireMedia($photo['id']);
        $this->assertSame('deleted', $this->lifecycle->reconcile($photo['id'], $this->mediaRequest, $this->mediaAuthorization())['status']);
        $this->assertCount(0, $disk->allFiles());
    }

    public function test_invalid_processing_releases_reservation_and_different_request_payload_conflicts(): void
    {
        $parent = $this->mediaParent(Project::class);
        $invalid = UploadedFile::fake()->createWithContent('bad.jpg', 'not an image');
        $photo = $this->reserve($parent, file: $invalid);
        try {
            $this->lifecycle->upload($photo['id'], $invalid, $this->mediaRequest, $this->mediaAuthorization());
            $this->fail('Invalid photo was accepted.');
        } catch (ValidationException) {
            $this->assertSame('deleted', MediaPhoto::find($photo['id'])->state);
        }
        $request = (string) Str::uuid();
        $this->reserve($parent, requestId: $request);
        $this->expectException(MediaLifecycleException::class);
        $this->expectExceptionMessage('request_conflict');
        $this->reserve($parent, requestId: $request,
            file: UploadedFile::fake()->image('different.png', 64, 32));
    }

    public function test_denied_finalization_keeps_photo_private_and_recoverable(): void
    {
        $parent = $this->mediaParent(Project::class);
        $file = $this->photo();
        $photo = $this->reserve($parent, file: $file);
        $authorize = function ($parent, $action) {
            if ($action === 'finalize') {
                throw new MediaLifecycleException('permission_revoked');
            }

            return ($this->mediaAuthorization())($parent);
        };
        try {
            $this->lifecycle->upload($photo['id'], $file, $this->mediaRequest, $authorize);
            $this->fail('Denied finalization was accepted.');
        } catch (MediaLifecycleException) {
            $this->assertSame('uncertain', MediaPhoto::find($photo['id'])->state);
            $this->assertCount(0, $this->lifecycle->listing($parent, $this->mediaAuthorization()));
        }
    }

    public function test_unsupported_parent_cross_parent_order_and_monitoring_approval_are_rejected(): void
    {
        try {
            $this->reserve(new UserProfile);
            $this->fail('Unsupported parent was accepted.');
        } catch (MediaLifecycleException $exception) {
            $this->assertSame('invalid_parent', $exception->reason);
        }
        $parent = $this->mediaParent(MonitoringVisitReport::class);
        $other = $this->mediaParent(MonitoringVisitReport::class);
        $a = $this->ready($parent);
        $b = $this->ready($other);
        try {
            $this->lifecycle->reorder($parent, [$b['id']], $this->mediaRequest, $this->mediaAuthorization());
            $this->fail('Cross-parent reorder was accepted.');
        } catch (MediaLifecycleException $exception) {
            $this->assertSame('order_conflict', $exception->reason);
        }
        $this->expectException(MediaLifecycleException::class);
        $this->expectExceptionMessage('approval_not_allowed');
        $this->lifecycle->approveGis($a['id'], true, $this->mediaRequest, $this->mediaAuthorization());
    }

    public function test_business_validation_after_writes_retains_uncertain_media(): void
    {
        $parent = $this->mediaParent(Project::class);
        $file = $this->photo();
        $photo = $this->reserve($parent, file: $file);
        $authorize = function ($parent, $action) {
            if ($action === 'finalize') {
                throw ValidationException::withMessages(['photo' => 'The parent changed.']);
            }

            return ($this->mediaAuthorization())($parent);
        };
        try {
            $this->lifecycle->upload($photo['id'], $file, $this->mediaRequest, $authorize);
            $this->fail('Changed business state was accepted.');
        } catch (MediaLifecycleException $exception) {
            $this->assertSame('upload_unconfirmed', $exception->reason);
            $this->assertSame('uncertain', MediaPhoto::find($photo['id'])->state);
            $this->assertCount(2, Storage::disk('media_private')->allFiles());
        }
    }

    public function test_existing_audit_service_records_lifecycle_events_without_object_details(): void
    {
        $this->createLifecycleAuditTable();
        $actor = Mockery::mock(UserProfile::class)->makePartial();
        $actor->setRawAttributes(['id' => $this->mediaRequest->attributes->get('current_user')->id], true);
        $actor->exists = true;
        $actor->shouldReceive('canAccessApplication')->andReturn(true);
        $this->mediaRequest->attributes->set('current_user', $actor);
        $this->app->instance(AuditLogService::class, new AuditLogService);
        $this->lifecycle = app(PhotoLifecycleService::class);
        $parent = $this->mediaParent(GisLocation::class);
        $photo = $this->ready($parent);
        $this->lifecycle->approveGis($photo['id'], true, $this->mediaRequest, $this->mediaAuthorization());
        $this->lifecycle->withdraw($photo['id'], $this->mediaRequest, $this->mediaAuthorization());
        $events = DB::table('audit_logs')->get();
        $this->assertCount(4, $events);
        $this->assertEqualsCanonicalizing(['RESERVE PHOTO', 'FINALIZE PHOTO', 'APPROVE PHOTO', 'WITHDRAW PHOTO'],
            $events->pluck('action_type')->all());
        $this->assertStringNotContainsString(MediaPhoto::find($photo['id'])->object_prefix, json_encode($events));
        foreach ($events as $event) {
            $this->assertSame($actor->id, $event->actor_user_id);
            $this->assertNull($event->old_values);
            $this->assertNull($event->new_values);
        }
    }
}
