<?php

namespace App\Http\Controllers;

use App\Exceptions\MediaLifecycleException;
use App\Models\MonitoringRecord;
use App\Services\Media\PhotoLifecycleService;
use App\Services\Monitoring\MonitoringPhotoService;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class MonitoringPhotoController extends Controller
{
    private const HEADERS = ['Cache-Control' => 'private, no-store, max-age=0',
        'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer'];

    public function __construct(private MonitoringPhotoService $monitoring, private PhotoLifecycleService $photos) {}

    public function index(Request $request, MonitoringRecord $monitoringRecord, string $kind, string $parentId): Response
    {
        return $this->respond(function () use ($request, $monitoringRecord, $kind, $parentId) {
            $parent = $this->monitoring->parent($monitoringRecord, $kind, $parentId);
            $gallery = $this->monitoring->gallery($parent, $this->monitoring->authorize($request, $monitoringRecord, $parent, false));
            $prefix = $request->routeIs('admin.*') ? 'admin.monitoring.photos' : 'field-officer.monitoring.photos';
            foreach ($gallery['photos'] as &$photo) {
                $parameters = [$monitoringRecord, $kind, $parentId, $photo['id']];
                $photo['thumbnail_url'] = route($prefix.'.image', [...$parameters, 'thumbnail']);
                $photo['display_url'] = route($prefix.'.image', [...$parameters, 'display']);
            }

            return response()->json($gallery, 200, self::HEADERS);
        });
    }

    public function store(Request $request, MonitoringRecord $monitoringRecord, string $kind, string $parentId): Response
    {
        return $this->respond(function () use ($request, $monitoringRecord, $kind, $parentId) {
            $parent = $this->monitoring->parent($monitoringRecord, $kind, $parentId);
            $authorize = $this->monitoring->authorize($request, $monitoringRecord, $parent, true);
            // Authorize before expensive image validation; reserve/finalize authorize again.
            DB::transaction(fn () => $authorize($parent));
            $data = $request->validate([
                'photo' => ['required', 'file', 'max:5120'],
                'request_id' => ['required', 'uuid'],
            ], ['photo.max' => 'Each photo must be at most 5 MiB.']);
            $reservation = $this->photos->reserve($parent, $data['photo'], $data['request_id'], $request, $authorize);
            if (in_array($reservation['status'], ['deleting', 'deleted'], true)) {
                throw ValidationException::withMessages(['photo' => 'This upload was removed. Choose the photo again to start a new upload.']);
            }
            $photo = $this->photos->upload($reservation['id'], $data['photo'], $request, $authorize);

            return response()->json(['photo' => $photo], 200, self::HEADERS);
        });
    }

    public function destroy(Request $request, MonitoringRecord $monitoringRecord, string $kind, string $parentId, string $photoId): Response
    {
        return $this->respond(function () use ($request, $monitoringRecord, $kind, $parentId, $photoId) {
            $parent = $this->monitoring->parent($monitoringRecord, $kind, $parentId);
            $this->monitoring->photo($parent, $photoId);
            $authorize = $this->monitoring->authorize($request, $monitoringRecord, $parent, true);
            $this->photos->withdraw($photoId, $request, $authorize);
            $photo = $this->photos->reconcile($photoId, $request, $authorize);

            return response()->json(['status' => $photo['status']], 200, self::HEADERS);
        });
    }

    public function reconcile(Request $request, MonitoringRecord $monitoringRecord, string $kind, string $parentId, string $photoId): Response
    {
        return $this->respond(function () use ($request, $monitoringRecord, $kind, $parentId, $photoId) {
            $parent = $this->monitoring->parent($monitoringRecord, $kind, $parentId);
            $this->monitoring->photo($parent, $photoId);
            $photo = $this->photos->reconcile($photoId, $request,
                $this->monitoring->authorize($request, $monitoringRecord, $parent, true));

            return response()->json(['status' => $photo['status']], 200, self::HEADERS);
        });
    }

    public function image(Request $request, MonitoringRecord $monitoringRecord, string $kind, string $parentId, string $photoId, string $variant): Response
    {
        return $this->respond(function () use ($request, $monitoringRecord, $kind, $parentId, $photoId, $variant) {
            $parent = $this->monitoring->parent($monitoringRecord, $kind, $parentId);
            $this->monitoring->photo($parent, $photoId);
            $image = $this->photos->open($photoId, $variant,
                $this->monitoring->authorize($request, $monitoringRecord, $parent, false));

            return response()->stream(static function () use ($image): void {
                try {
                    fpassthru($image['stream']);
                } finally {
                    fclose($image['stream']);
                }
            }, 200, self::HEADERS + [
                'Content-Type' => $image['mime_type'], 'Content-Length' => (string) $image['bytes'],
                'Content-Disposition' => 'inline; filename="monitoring-photo.'.$image['extension'].'"',
            ]);
        });
    }

    private function respond(Closure $work): Response
    {
        try {
            return $work();
        } catch (ValidationException $exception) {
            return response()->json(['message' => 'Check the photo request.', 'errors' => $exception->errors()], $exception->status, self::HEADERS);
        } catch (MediaLifecycleException $exception) {
            if (! in_array($exception->reason, ['capacity_reached', 'request_conflict', 'operation_pending', 'invalid_photo', 'photo_not_ready'], true)) {
                Log::warning('Monitoring photo lifecycle unavailable.', ['reason' => $exception->reason]);
            }
            [$status, $message] = match ($exception->reason) {
                'capacity_reached' => [409, 'All five photo slots are in use, including pending uploads.'],
                'request_conflict', 'operation_pending' => [409, 'This upload is already pending. Refresh the gallery and check its saved state.'],
                'invalid_photo', 'photo_not_ready' => [404, 'Photo unavailable.'],
                default => [503, 'Photo storage is unavailable or the result is unconfirmed. Refresh the gallery before retrying.'],
            };

            return response()->json(['message' => $message], $status, self::HEADERS);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Photo unavailable.'], 404, self::HEADERS);
        } catch (HttpExceptionInterface $exception) {
            return response()->json(['message' => 'Photo access is unavailable.'], $exception->getStatusCode(), self::HEADERS);
        } catch (Throwable $exception) {
            Log::error('Monitoring photo operation unavailable.', ['exception_type' => $exception::class]);

            // Never serialize SQL, provider messages, credentials, paths or object keys.
            return response()->json(['message' => 'Photos are temporarily unavailable.'], 503, self::HEADERS);
        }
    }
}
