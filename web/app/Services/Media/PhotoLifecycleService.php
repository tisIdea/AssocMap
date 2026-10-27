<?php

namespace App\Services\Media;

use App\Exceptions\MediaLifecycleException;
use App\Models\GisLocation;
use App\Models\MediaPhoto;
use App\Models\Project;
use App\Models\UserProfile;
use App\Services\Audit\AuditLogService;
use App\Services\GIS\GisQueryService;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class PhotoLifecycleService
{
    public function __construct(private PhotoImageProcessor $images, private AuditLogService $audit) {}

    /**
     * The module callback runs inside each short transaction. It must lock/reload the current
     * actor, ownership and parent in the module's established order and return that authorized parent.
     * No HTTP endpoint or module permission is supplied by this foundation.
     */
    public function reserve(Model $parent, UploadedFile $file, string $requestId, Request $request,
        Closure $authorize, ?string $replaces = null, ?string $caption = null): array
    {
        $this->outsideTransaction();
        $fingerprint = $this->images->fingerprint($file);
        $replaces = $replaces === null ? null : strtolower($replaces);
        if (! Str::isUuid($requestId) || ($replaces !== null && ! Str::isUuid($replaces))
            || ($caption !== null && mb_strlen($caption) > 500)) {
            throw new MediaLifecycleException('invalid_request');
        }

        return $this->withParent($parent, 'reserve', $authorize, function (Model $locked) use ($fingerprint, $requestId, $request, $replaces, $caption) {
            [$column, $limit] = PhotoRules::parent($locked);
            $actorId = $this->actorId($request);
            $existing = MediaPhoto::where('request_id', strtolower($requestId))->first();
            if ($existing) {
                if ((string) $locked->getKey() !== $existing->$column || $existing->uploaded_by !== $actorId
                    || $existing->input_sha256 !== $fingerprint || $existing->replaces_photo_id !== $replaces
                    || $existing->caption !== $caption) {
                    throw new MediaLifecycleException('request_conflict');
                }

                return $this->metadata($existing);
            }
            $live = $this->photos($locked)->whereIn('state', PhotoRules::LIVE_STATES);
            $count = (clone $live)->count();
            if ($replaces !== null) {
                if (! $locked instanceof Project || ! (clone $live)->whereKey($replaces)->where('state', 'ready')->exists()) {
                    throw new MediaLifecycleException('replacement_conflict');
                }
                $count--;
            }
            if ($count >= $limit) {
                throw new MediaLifecycleException('capacity_reached');
            }
            $row = new MediaPhoto;
            $row->forceFill([$column => $locked->getKey(), 'uploaded_by' => $actorId,
                'request_id' => strtolower($requestId), 'disk' => 'media_private', 'object_prefix' => 'media/'.Str::uuid(),
                'input_sha256' => $fingerprint, 'state' => 'reserved', 'replaces_photo_id' => $replaces,
                'caption' => $caption, 'display_order' => $locked instanceof Project ? 0 : min(4, $count),
                'lease_until' => now()->addMinutes(PhotoRules::LEASE_MINUTES)])->save();
            $this->event($request, 'RESERVE PHOTO', $row);

            return $this->metadata($row);
        });
    }

    public function upload(string $id, UploadedFile $file, Request $request, Closure $authorize): array
    {
        $this->outsideTransaction();
        $fingerprint = $this->images->fingerprint($file);
        $row = $this->find($id);
        $token = (string) Str::uuid();
        $claimed = $this->withParent($this->parentOf($row), 'upload', $authorize, function () use ($id, $fingerprint, $token) {
            $row = $this->find($id, true);
            if ($row->input_sha256 !== $fingerprint) {
                throw new MediaLifecycleException('request_conflict');
            }
            if ($row->state === 'ready') {
                return false;
            }
            if ($row->state !== 'reserved') {
                throw new MediaLifecycleException('operation_pending');
            }
            $row->forceFill(['state' => 'processing', 'operation_token' => $token,
                'lease_until' => now()->addMinutes(PhotoRules::LEASE_MINUTES)])->save();

            return true;
        });
        if (! $claimed) {
            return $this->metadata($this->find($id));
        }
        $processed = null;
        $uploading = false;
        try {
            $processed = $this->images->process($file);
            $this->withParent($this->parentOf($row), 'upload', $authorize, function () use ($id, $token, $processed) {
                $row = $this->owned($id, $token, ['processing']);
                $row->forceFill($processed['metadata'] + ['state' => 'uploading',
                    'lease_until' => now()->addMinutes(PhotoRules::LEASE_MINUTES)])->save();
            });
            $uploading = true;
            $row = $this->find($id);
            $disk = Storage::disk('media_private');
            foreach ($this->keys($row) as $variant => $key) {
                $this->owned($id, $token, ['uploading']);
                if (! $disk->writeStream($key, $processed['streams'][$variant],
                    ['visibility' => 'private', 'ContentType' => $row->image_format === 'jpg' ? 'image/jpeg' : 'image/png'])) {
                    throw new MediaLifecycleException('upload_unconfirmed');
                }
            }

            return $this->finalize($id, $token, $request, $authorize);
        } catch (ValidationException $exception) {
            if ($uploading) {
                $this->mark($id, $token, ['state' => 'uncertain', 'last_error_code' => 'upload_unconfirmed']);
                throw new MediaLifecycleException('upload_unconfirmed');
            }
            $this->mark($id, $token, ['state' => 'deleted', 'last_error_code' => 'invalid_image',
                'operation_token' => null, 'lease_until' => null]);
            throw $exception;
        } catch (Throwable) {
            $this->mark($id, $token, ['state' => $uploading ? 'uncertain' : 'processing',
                'last_error_code' => 'upload_unconfirmed']);
            throw new MediaLifecycleException('upload_unconfirmed');
        } finally {
            foreach ($processed['streams'] ?? [] as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }

    public function listing(Model $parent, Closure $authorize): array
    {
        return $this->withParent($parent, 'list', $authorize, fn (Model $locked) => $this->photos($locked)->where('state', 'ready')->orderBy('display_order')->orderBy('id')
            ->get()->map(fn ($photo) => $this->metadata($photo))->all());
    }

    public function reorder(Model $parent, array $ids, Request $request, Closure $authorize): void
    {
        foreach ($ids as $id) {
            if (! is_string($id) || ! Str::isUuid($id)) {
                throw new MediaLifecycleException('order_conflict');
            }
        }
        $this->withParent($parent, 'reorder', $authorize, function (Model $locked) use ($ids, $request) {
            $rows = $this->photos($locked)->where('state', 'ready')->orderBy('id')->get();
            $actual = $rows->modelKeys();
            $provided = $ids;
            sort($actual);
            sort($provided);
            if ($actual !== $provided || count($ids) !== count(array_unique($ids))) {
                throw new MediaLifecycleException('order_conflict');
            }
            foreach (array_values($ids) as $index => $id) {
                $photo = $rows->firstWhere('id', $id);
                $photo->forceFill(['display_order' => $index])->save();
            }
            if ($rows->isNotEmpty()) {
                $this->event($request, 'REORDER PHOTOS', $rows->first());
            }
        });
    }

    public function withdraw(string $id, Request $request, Closure $authorize): array
    {
        $row = $this->find($id);

        return $this->withParent($this->parentOf($row), 'withdraw', $authorize, function () use ($id, $request) {
            $row = $this->find($id, true);
            if (! in_array($row->state, ['deleting', 'deleted'], true)) {
                // Preserve an in-flight lease; cleanup waits for it and finalization is fenced out.
                $row->forceFill(['state' => 'deleting', 'approved_by' => null, 'approved_at' => null,
                    'operation_token' => null, 'last_error_code' => null])->save();
                $this->event($request, 'WITHDRAW PHOTO', $row);
            }

            return $this->metadata($row);
        });
    }

    public function approveGis(string $id, bool $approved, Request $request, Closure $authorize): void
    {
        $row = $this->find($id);
        $this->withParent($this->parentOf($row), 'approve', $authorize, function (Model $parent) use ($id, $approved, $request) {
            $row = $this->find($id, true);
            if (! $parent instanceof GisLocation || $row->state !== 'ready') {
                throw new MediaLifecycleException('approval_not_allowed');
            }
            $row->forceFill(['approved_by' => $approved ? $this->actorId($request) : null,
                'approved_at' => $approved ? now() : null])->save();
            $this->event($request, $approved ? 'APPROVE PHOTO' : 'UNAPPROVE PHOTO', $row);
        });
    }

    /** Eligibility only; it generates no URL and delivers no object bytes. */
    public function publicGisMetadata(string $id): ?array
    {
        $row = $this->find($id);
        if ($row->state !== 'ready' || $row->gis_location_id === null || $row->approved_by === null || $row->approved_at === null) {
            return null;
        }
        if (! app(GisQueryService::class)->scoped(null)->where('g.id', $row->gis_location_id)->exists()) {
            return null;
        }

        return $this->metadata($row);
    }

    public function reconcile(string $id, Request $request, Closure $authorize): array
    {
        $this->outsideTransaction();
        $row = $this->find($id);
        $token = (string) Str::uuid();
        $row = $this->withParent($this->parentOf($row), 'reconcile', $authorize, function () use ($id, $token, $request) {
            $row = $this->find($id, true);
            if ($row->state === 'ready' || ($row->lease_until !== null && $row->lease_until->isFuture())) {
                return null;
            }
            if (in_array($row->state, ['reserved', 'processing'], true)) {
                $row->forceFill(['state' => 'deleting', 'last_error_code' => 'reservation_abandoned'])->save();
                $this->event($request, 'ABANDON PHOTO', $row);
            }
            // Once a worker loses this token it can never finalize.
            $row->forceFill(['operation_token' => $token,
                'lease_until' => now()->addMinutes(PhotoRules::LEASE_MINUTES)])->save();

            return $row;
        });
        if ($row === null) {
            return $this->metadata($this->find($id));
        }
        try {
            $disk = Storage::disk('media_private');
            if (in_array($row->state, ['deleting', 'deleted'], true)) {
                // Only an intentionally retired, exclusively owned prefix may be removed.
                foreach ($this->keys($row) as $key) {
                    $this->owned($id, $token, ['deleting', 'deleted']);
                    if (! $disk->delete($key)) {
                        throw new MediaLifecycleException('cleanup_unconfirmed');
                    }
                }

                return $this->withParent($this->parentOf($row), 'reconcile', $authorize, function () use ($id, $token) {
                    $row = $this->owned($id, $token, ['deleting', 'deleted']);
                    $row->forceFill(['state' => 'deleted', 'operation_token' => null,
                        'lease_until' => null, 'last_error_code' => null])->save();

                    return $this->metadata($row);
                });
            }
            foreach ($this->keys($row) as $variant => $key) {
                $stream = $disk->readStream($key);
                if (! is_resource($stream)) {
                    throw new MediaLifecycleException('recovery_unconfirmed');
                }
                try {
                    $hash = hash_init('sha256');
                    $bytes = hash_update_stream($hash, $stream, PhotoRules::MAX_BYTES + 1);
                    if ($bytes !== $row->{$variant.'_bytes'} || ! hash_equals($row->{$variant.'_sha256'}, hash_final($hash))) {
                        throw new MediaLifecycleException('recovery_unconfirmed');
                    }
                } finally {
                    fclose($stream);
                }
            }

            return $this->finalize($id, $token, $request, $authorize);
        } catch (Throwable) {
            $this->mark($id, $token, ['last_error_code' => 'recovery_unconfirmed',
                'state' => in_array($row->state, ['deleting', 'deleted'], true) ? 'deleting' : 'uncertain']);
            throw new MediaLifecycleException('recovery_unconfirmed');
        }
    }

    public function reconcileAbandoned(Request $request, Closure $authorize, int $limit = 25): array
    {
        $this->outsideTransaction();
        $ids = MediaPhoto::whereIn('state', ['reserved', 'processing', 'uploading', 'uncertain', 'deleting'])
            ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
            ->orderBy('lease_until')->orderBy('id')->limit(max(1, min(100, $limit)))->pluck('id');
        $results = [];
        foreach ($ids as $id) {
            try {
                $results[$id] = $this->reconcile($id, $request, $authorize)['status'];
            } catch (MediaLifecycleException $exception) {
                $results[$id] = $exception->reason;
            }
        }

        return $results;
    }

    private function finalize(string $id, string $token, Request $request, Closure $authorize): array
    {
        $photo = $this->find($id);

        return $this->withParent($this->parentOf($photo), 'finalize', $authorize, function () use ($id, $token, $request) {
            $row = $this->owned($id, $token, ['uploading', 'uncertain']);
            if ($row->replaces_photo_id !== null) {
                $old = $this->photos($this->parentOf($row))->whereKey($row->replaces_photo_id)->lockForUpdate()->first();
                if (! $old || $old->state !== 'ready') {
                    throw new MediaLifecycleException('replacement_conflict');
                }
                $old->forceFill(['state' => 'deleting', 'approved_at' => null, 'approved_by' => null])->save();
            }
            $row->forceFill(['state' => 'ready', 'operation_token' => null, 'lease_until' => null,
                'last_error_code' => null])->save();
            $this->event($request, 'FINALIZE PHOTO', $row);

            return $this->metadata($row);
        });
    }

    private function withParent(Model $parent, string $action, Closure $authorize, Closure $work): mixed
    {
        PhotoRules::parent($parent);
        if (! Str::isUuid((string) $parent->getKey())) {
            throw new MediaLifecycleException('invalid_parent');
        }
        try {
            return DB::transaction(function () use ($parent, $action, $authorize, $work) {
                $authorized = $authorize($parent, $action);
                if (! $authorized instanceof Model || $authorized::class !== $parent::class
                    || (string) $authorized->getKey() !== (string) $parent->getKey()) {
                    throw new MediaLifecycleException('authorization_context_invalid');
                }
                $locked = $parent->newQuery()->whereKey($parent->getKey())->lockForUpdate()->first();
                if (! $locked) {
                    throw new MediaLifecycleException('invalid_parent');
                }

                return $work($locked);
            });
        } catch (QueryException) {
            throw new MediaLifecycleException('metadata_unavailable');
        }
    }

    private function photos(Model $parent): Builder
    {
        [$column] = PhotoRules::parent($parent);

        return MediaPhoto::where($column, $parent->getKey());
    }

    private function find(string $id, bool $lock = false): MediaPhoto
    {
        if (! Str::isUuid($id)) {
            throw new MediaLifecycleException('invalid_photo');
        }

        return MediaPhoto::query()->when($lock, fn ($q) => $q->lockForUpdate())->find($id)
            ?? throw new MediaLifecycleException('invalid_photo');
    }

    private function parentOf(MediaPhoto $row): Model
    {
        foreach (PhotoRules::PARENTS as $class => [$column]) {
            if ($row->$column !== null) {
                $parent = new $class;
                $parent->setRawAttributes(['id' => $row->$column], true);

                return $parent;
            }
        }
        throw new MediaLifecycleException('invalid_parent');
    }

    private function owned(string $id, string $token, array $states): MediaPhoto
    {
        $row = $this->find($id);
        if ($row->operation_token !== $token || ! in_array($row->state, $states, true)
            || $row->lease_until === null || $row->lease_until->isPast()) {
            throw new MediaLifecycleException('operation_pending');
        }

        return $row;
    }

    private function keys(MediaPhoto $row): array
    {
        // No object write was started for reservations without processed metadata.
        if ($row->image_format === null) {
            return [];
        }
        if ($row->disk !== 'media_private'
            || ! preg_match('~\Amedia/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z~', $row->object_prefix)
            || ! in_array($row->image_format, ['jpg', 'png'], true)) {
            throw new MediaLifecycleException('invalid_object_reference');
        }

        return ['display' => $row->object_prefix.'/display.'.$row->image_format,
            'thumbnail' => $row->object_prefix.'/thumbnail.'.$row->image_format];
    }

    private function mark(string $id, string $token, array $changes): void
    {
        try {
            // Conditional fencing only: never overwrite a withdrawal or another worker's lease.
            MediaPhoto::whereKey($id)->where('operation_token', $token)
                ->whereIn('state', ['processing', 'uploading', 'uncertain', 'deleting', 'deleted'])
                ->update($changes + ['updated_at' => now()]);
        } catch (Throwable) {
            // The durable reservation remains available when the database recovers.
        }
    }

    private function metadata(MediaPhoto $row): array
    {
        return $row->toArray() + ['status' => $row->state];
    }

    private function actorId(Request $request): string
    {
        $actor = $request->attributes->get('current_user');
        if (! $actor instanceof UserProfile || ! $actor->exists || ! Str::isUuid($actor->id)) {
            throw new MediaLifecycleException('invalid_actor');
        }

        return $actor->id;
    }

    private function event(Request $request, string $action, MediaPhoto $row): void
    {
        $this->audit->record($request, $action, 'Media', 'media_photo', $row->id);
    }

    private function outsideTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new MediaLifecycleException('external_transaction_not_allowed');
        }
    }
}
