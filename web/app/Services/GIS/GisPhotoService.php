<?php

namespace App\Services\GIS;

use App\Models\GisLocation;
use App\Models\MediaPhoto;
use App\Models\UserProfile;
use App\Services\Media\PhotoRules;
use App\Services\Projects\ProjectLifecycleService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class GisPhotoService
{
    public function __construct(private GisAccessService $access, private GisQueryService $queries,
        private ProjectLifecycleService $projects) {}

    public function authorize(Request $request, string $location, bool $write): Closure
    {
        $previous = $request->attributes->get('current_user');

        return function (Model $parent) use ($previous, $location, $write): GisLocation {
            abort_unless($previous instanceof UserProfile, 403);
            abort_unless($parent instanceof GisLocation && $parent->id === $location, 404);
            $actor = $this->access->actor($previous);
            $locked = $this->access->location($location, $actor, ! $write);
            if ($write) {
                abort_if($locked->archived_at !== null, 409, 'Archived locations are read-only.');
                $this->projects->assertNotFinalized($locked->project, 'photo');
            }

            return $locked;
        };
    }

    public function publicAuthorization(string $location, ?string $photo = null): Closure
    {
        return function (Model $parent) use ($location, $photo): GisLocation {
            abort_unless($parent instanceof GisLocation && $parent->id === $location, 404);
            $locked = $this->access->location($location, null);
            // Reuse the full public policy, including publication metadata and readable geometry.
            abort_unless($this->queries->scoped(null)->where('g.id', $location)->exists(), 404);
            if ($photo !== null) {
                abort_unless(MediaPhoto::where('gis_location_id', $location)->whereKey($photo)
                    ->where('state', 'ready')->whereNotNull('approved_by')->whereNotNull('approved_at')
                    ->lockForUpdate()->first(), 404);
            }

            return $locked;
        };
    }

    public function gallery(GisLocation $parent, Closure $authorize, bool $public = false): array
    {
        return DB::transaction(function () use ($parent, $authorize, $public) {
            $parent = $authorize($parent);
            $query = MediaPhoto::where('gis_location_id', $parent->id)->where('state', '!=', 'deleted');
            if ($public) $query->where('state', 'ready')->whereNotNull('approved_by')->whereNotNull('approved_at');
            $rows = $query->orderBy('display_order')->orderBy('id')->get();
            $photos = $rows->where('state', 'ready')->values()->map(function ($row) use ($public) {
                $metadata = $public ? $row->only(['id', 'caption', 'display_order', 'display_width', 'display_height',
                    'thumbnail_width', 'thumbnail_height']) : $row->toArray() + ['approved' => $row->approved_by !== null && $row->approved_at !== null];

                return $metadata;
            })->all();
            if ($public) return ['photos' => $photos];

            return ['photos' => $photos, 'can_manage' => $parent->archived_at === null
                    && $parent->project->archived_at === null && ! $parent->project->isFinalized()
                    && $parent->project->association->status === 'active' && $parent->project->association->archived_at === null,
                'pending' => $rows->where('state', '!=', 'ready')->values()->map(fn ($row) => [
                    'id' => $row->id, 'status' => $row->state,
                    'retry_after' => max(0, (int) ceil(now()->diffInSeconds($row->lease_until ?? now(), false))),
                ])->all(),
                'used_slots' => $rows->whereIn('state', PhotoRules::LIVE_STATES)->count(), 'limit' => 5];
        });
    }

    public function parent(string $location): GisLocation
    {
        $parent = new GisLocation;
        $parent->setRawAttributes(['id' => $location], true);

        return $parent;
    }

    public function photo(string $location, string $photo): MediaPhoto
    {
        return MediaPhoto::where('gis_location_id', $location)->whereKey($photo)->firstOrFail();
    }
}
