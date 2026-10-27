<?php

namespace App\Http\Controllers;

use App\Exceptions\MediaLifecycleException;
use App\Http\PrivatePhotoResponse;
use App\Models\Project;
use App\Services\Media\PhotoLifecycleService;
use App\Services\Projects\ProjectCoverService;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ProjectCoverController extends Controller
{
    public function __construct(private ProjectCoverService $covers, private PhotoLifecycleService $photos) {}

    public function index(Request $request, Project $project): Response
    {
        return $this->respond(function () use ($request, $project) {
            $snapshot = $this->covers->snapshot($project, $this->covers->authorize($request, $project, false));
            if ($snapshot['cover'] !== null) {
                $prefix = $request->routeIs('admin.*') ? 'admin' : 'field-officer';
                foreach (['thumbnail', 'display'] as $variant) {
                    $snapshot['cover'][$variant.'_url'] = route($prefix.'.projects.cover.image',
                        [$project, $snapshot['cover']['id'], $variant]);
                }
            }

            return response()->json($snapshot, 200, PrivatePhotoResponse::HEADERS);
        });
    }

    public function store(Request $request, Project $project): Response
    {
        return $this->respond(function () use ($request, $project) {
            $authorize = $this->covers->authorize($request, $project, true);
            DB::transaction(fn () => $authorize($project));
            $data = $request->validate(['photo' => ['required', 'file', 'max:5120'],
                'request_id' => ['required', 'uuid'], 'replaces' => ['nullable', 'uuid']],
                ['photo.max' => 'The cover photo must be at most 5 MiB.']);
            $reserved = $this->photos->reserve($project, $data['photo'], $data['request_id'],
                $request, $authorize, $data['replaces'] ?? null);
            if (in_array($reserved['status'], ['deleting', 'deleted'], true)) {
                throw ValidationException::withMessages(['photo' => 'This upload was removed. Choose the photo again to start a new upload.']);
            }
            $photo = $this->photos->upload($reserved['id'], $data['photo'], $request, $authorize);
            $this->cleanupPrevious($request, $project, $photo, $authorize);

            return response()->json(['photo' => $photo], 200, PrivatePhotoResponse::HEADERS);
        });
    }

    public function destroy(Request $request, Project $project, string $photoId): Response
    {
        return $this->respond(function () use ($request, $project, $photoId) {
            $this->covers->photo($project, $photoId);
            $authorize = $this->covers->authorize($request, $project, true);
            $this->photos->withdraw($photoId, $request, $authorize);
            $photo = $this->photos->reconcile($photoId, $request, $authorize);

            return response()->json(['status' => $photo['status']], 200, PrivatePhotoResponse::HEADERS);
        });
    }

    public function reconcile(Request $request, Project $project, string $photoId): Response
    {
        return $this->respond(function () use ($request, $project, $photoId) {
            $this->covers->photo($project, $photoId);
            $authorize = $this->covers->authorize($request, $project, true);
            $photo = $this->photos->reconcile($photoId, $request, $authorize);
            $this->cleanupPrevious($request, $project, $photo, $authorize);

            return response()->json(['status' => $photo['status']], 200, PrivatePhotoResponse::HEADERS);
        });
    }

    public function image(Request $request, Project $project, string $photoId, string $variant): Response
    {
        return $this->respond(function () use ($request, $project, $photoId, $variant) {
            $this->covers->photo($project, $photoId);
            $image = $this->photos->open($photoId, $variant, $this->covers->authorize($request, $project, false));

            return PrivatePhotoResponse::stream($image, 'project-cover');
        });
    }

    private function cleanupPrevious(Request $request, Project $project, array $photo, Closure $authorize): void
    {
        if ($photo['status'] !== 'ready') {
            return;
        }
        try {
            $previous = $this->covers->photo($project, $photo['id'])->replaces_photo_id;
            if ($previous !== null && $this->covers->photo($project, $previous)->state !== 'deleted') {
                $this->photos->reconcile($previous, $request, $authorize);
            }
        } catch (Throwable) {
            // The new cover is confirmed; a retired prefix remains tracked for recovery.
            Log::warning('Project cover cleanup pending.', ['reason' => 'cleanup_unconfirmed']);
        }
    }

    private function respond(Closure $work): Response
    {
        try {
            return $work();
        } catch (ValidationException $exception) {
            return response()->json(['message' => 'Check the cover photo request.', 'errors' => $exception->errors()],
                $exception->status, PrivatePhotoResponse::HEADERS);
        } catch (MediaLifecycleException $exception) {
            [$status, $message] = match ($exception->reason) {
                'capacity_reached' => [409, 'A cover or pending upload already exists. Refresh before uploading or replacing it.'],
                'replacement_conflict' => [409, 'The cover changed. Refresh, then choose the photo again to replace the current cover.'],
                'request_conflict', 'operation_pending' => [409, 'The cover changed or an upload is pending. Refresh and check the saved state before retrying.'],
                'invalid_photo', 'photo_not_ready' => [404, 'Cover photo unavailable.'],
                default => [503, 'Cover storage is unavailable or the result is unconfirmed. Refresh before retrying.'],
            };
            if ($status === 503) {
                Log::warning('Project cover lifecycle unavailable.', ['reason' => $exception->reason]);
            }

            return response()->json(['message' => $message], $status, PrivatePhotoResponse::HEADERS);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Cover photo unavailable.'], 404, PrivatePhotoResponse::HEADERS);
        } catch (HttpExceptionInterface $exception) {
            return response()->json(['message' => 'Cover photo access is unavailable.'], $exception->getStatusCode(), PrivatePhotoResponse::HEADERS);
        } catch (Throwable $exception) {
            Log::error('Project cover operation unavailable.', ['exception_type' => $exception::class]);

            return response()->json(['message' => 'Cover photos are temporarily unavailable.'], 503, PrivatePhotoResponse::HEADERS);
        }
    }
}
