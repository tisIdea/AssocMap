<?php

namespace App\Http\Controllers;

use App\Exceptions\MediaLifecycleException;
use App\Http\PrivatePhotoResponse;
use App\Services\GIS\GisPhotoService;
use App\Services\Media\PhotoLifecycleService;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class GisPhotoController extends Controller
{
    public function __construct(private GisPhotoService $gis, private PhotoLifecycleService $photos) {}

    public function index(Request $request, string $location): Response
    {
        return $this->respond(function () use ($request, $location) {
            $public = $request->routeIs('public.*');
            $authorize = $public ? $this->gis->publicAuthorization($location) : $this->gis->authorize($request, $location, false);
            $gallery = $this->gis->gallery($this->gis->parent($location), $authorize, $public);
            $prefix = $public ? 'public.gis.photos' : ($request->routeIs('admin.*') ? 'admin.gis.photos' : 'field-officer.gis.photos');
            foreach ($gallery['photos'] as &$photo) {
                foreach (['thumbnail', 'display'] as $variant) {
                    $photo[$variant.'_url'] = route($prefix.'.image', ['location' => $location, 'photo' => $photo['id'], 'variant' => $variant]);
                }
            }

            return response()->json($gallery, 200, PrivatePhotoResponse::HEADERS);
        });
    }

    public function store(Request $request, string $location): Response
    {
        return $this->respond(function () use ($request, $location) {
            $parent = $this->gis->parent($location);
            $authorize = $this->gis->authorize($request, $location, true);
            DB::transaction(fn () => $authorize($parent));
            $data = $request->validate(['photo' => ['required', 'file', 'max:5120'], 'request_id' => ['required', 'uuid']],
                ['photo.max' => 'Each photo must be at most 5 MiB.']);
            $reservation = $this->photos->reserve($parent, $data['photo'], $data['request_id'], $request, $authorize);
            if (in_array($reservation['status'], ['deleting', 'deleted'], true)) {
                throw ValidationException::withMessages(['photo' => 'This upload was removed. Choose the photo again to start a new upload.']);
            }
            $photo = $this->photos->upload($reservation['id'], $data['photo'], $request, $authorize);

            return response()->json(['photo' => $photo], 200, PrivatePhotoResponse::HEADERS);
        });
    }

    public function approval(Request $request, string $location, string $photo): Response
    {
        return $this->respond(function () use ($request, $location, $photo) {
            $this->gis->photo($location, $photo);
            $data = $request->validate(['approved' => ['required', 'boolean']]);
            $this->photos->approveGis($photo, (bool) $data['approved'], $request, $this->gis->authorize($request, $location, true));

            return response()->json(['approved' => (bool) $data['approved']], 200, PrivatePhotoResponse::HEADERS);
        });
    }

    public function reorder(Request $request, string $location): Response
    {
        return $this->respond(function () use ($request, $location) {
            $data = $request->validate(['ids' => ['present', 'array', 'max:5'], 'ids.*' => ['required', 'uuid', 'distinct']]);
            $this->photos->reorder($this->gis->parent($location), $data['ids'], $request, $this->gis->authorize($request, $location, true));

            return response()->json(['message' => 'Photo order saved.'], 200, PrivatePhotoResponse::HEADERS);
        });
    }

    public function destroy(Request $request, string $location, string $photo): Response
    {
        return $this->respond(function () use ($request, $location, $photo) {
            $this->gis->photo($location, $photo);
            $authorize = $this->gis->authorize($request, $location, true);
            $this->photos->withdraw($photo, $request, $authorize);
            $result = $this->photos->reconcile($photo, $request, $authorize);

            return response()->json(['status' => $result['status']], 200, PrivatePhotoResponse::HEADERS);
        });
    }

    public function reconcile(Request $request, string $location, string $photo): Response
    {
        return $this->respond(function () use ($request, $location, $photo) {
            $this->gis->photo($location, $photo);
            $result = $this->photos->reconcile($photo, $request, $this->gis->authorize($request, $location, true));

            return response()->json(['status' => $result['status']], 200, PrivatePhotoResponse::HEADERS);
        });
    }

    public function image(Request $request, string $location, string $photo, string $variant): Response
    {
        return $this->respond(function () use ($request, $location, $photo, $variant) {
            $this->gis->photo($location, $photo);
            $authorize = $request->routeIs('public.*') ? $this->gis->publicAuthorization($location, $photo)
                : $this->gis->authorize($request, $location, false);

            return PrivatePhotoResponse::stream($this->photos->open($photo, $variant, $authorize), 'gis-photo');
        });
    }

    private function respond(Closure $work): Response
    {
        try {
            return $work();
        } catch (ValidationException $exception) {
            return response()->json(['message' => 'Check the photo request.', 'errors' => $exception->errors()], $exception->status, PrivatePhotoResponse::HEADERS);
        } catch (MediaLifecycleException $exception) {
            [$status, $message] = match ($exception->reason) {
                'capacity_reached' => [409, 'All five photo slots are in use, including pending uploads.'],
                'request_conflict', 'operation_pending' => [409, 'This upload is pending. Refresh the gallery and check its saved state.'],
                'order_conflict' => [409, 'The gallery changed. Refresh before reordering.'],
                'invalid_photo', 'photo_not_ready', 'approval_not_allowed' => [404, 'Photo unavailable.'],
                default => [503, 'Photo storage is unavailable or the result is unconfirmed. Refresh the gallery before retrying.'],
            };
            if ($status === 503) Log::warning('GIS photo lifecycle unavailable.', ['reason' => $exception->reason]);

            return response()->json(['message' => $message], $status, PrivatePhotoResponse::HEADERS);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Photo unavailable.'], 404, PrivatePhotoResponse::HEADERS);
        } catch (HttpExceptionInterface $exception) {
            return response()->json(['message' => 'Photo access is unavailable.'], $exception->getStatusCode(), PrivatePhotoResponse::HEADERS);
        } catch (Throwable $exception) {
            Log::error('GIS photo operation unavailable.', ['exception_type' => $exception::class]);

            return response()->json(['message' => 'Photos are temporarily unavailable.'], 503, PrivatePhotoResponse::HEADERS);
        }
    }
}
