<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeleteGisLocationRequest;
use App\Http\Requests\GisLifecycleRequest;
use App\Http\Requests\GisParentRequest;
use App\Http\Requests\GisQueryRequest;
use App\Http\Requests\SaveGisLocationRequest;
use App\Models\UserProfile;
use App\Services\GisLocationService;
use App\Services\GisQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class GisWorkspaceController extends Controller
{
    public function __construct(private GisQueryService $queries, private GisLocationService $locations) {}

    public function index(Request $request): View
    {
        return view('gis-workspace', $this->workspace($this->actor($request)));
    }

    public function data(GisQueryRequest $request): JsonResponse
    {
        return response()->json($this->queries->features($this->actor($request), $request->validated()));
    }

    public function parents(GisParentRequest $request): JsonResponse
    {
        return response()->json($this->queries->parents($this->actor($request), $request->validated()));
    }

    public function show(Request $request, string $location): JsonResponse
    {
        return response()->json($this->queries->detail($this->actor($request), $location));
    }

    public function create(Request $request): View
    {
        $user = $this->actor($request);
        abort_unless($this->queries->manager($user), 403);

        return view('gis-editor', $this->workspace($user) + ['gisFeature' => null, 'gisRequestId' => (string) Str::uuid()]);
    }

    public function edit(Request $request, string $location): View
    {
        $user = $this->actor($request);
        abort_unless($this->queries->manager($user), 403);
        $feature = $this->queries->detail($user, $location);
        abort_if($feature['properties']['archived_at'] !== null, 409);

        return view('gis-editor', $this->workspace($user) + ['gisFeature' => $feature, 'gisRequestId' => null]);
    }

    public function store(SaveGisLocationRequest $request): JsonResponse
    {
        $result = $this->locations->store($request->validated(), $this->actor($request), $request);

        return response()->json($result['feature'], $result['created'] ? 201 : 200);
    }

    public function update(SaveGisLocationRequest $request, string $location): JsonResponse
    {
        return response()->json($this->locations->update($location, $request->validated(), $this->actor($request), $request));
    }

    public function lifecycle(GisLifecycleRequest $request, string $location, string $action): JsonResponse
    {
        return response()->json($this->locations->lifecycle($location, $action, $request->validated('version'), $this->actor($request), $request));
    }

    public function destroy(DeleteGisLocationRequest $request, string $location): JsonResponse
    {
        $this->locations->destroy($location, $request->validated('version'), $this->actor($request));

        return response()->json(['message' => 'GIS location permanently deleted.', 'id' => $location]);
    }

    private function actor(Request $request): UserProfile
    {
        $user = $request->attributes->get('current_user');
        abort_unless($user instanceof UserProfile, 403);

        return $user;
    }

    private function workspace(UserProfile $user): array
    {
        $role = $this->queries->role($user);
        $prefixes = ['system_admin' => 'admin', 'field_officer' => 'field-officer', 'association_account' => 'association'];
        $labels = ['system_admin' => 'Administrator GIS Management', 'field_officer' => 'Field Officer GIS Management', 'association_account' => 'Association GIS Viewer'];

        return ['gisPublic' => false, 'gisManager' => $this->queries->manager($user), 'gisLayout' => 'layouts.'.$prefixes[$role],
            'gisBaseUrl' => route($prefixes[$role].'.gis.index'), 'gisTitle' => $labels[$role],
            'gisDescription' => $role === 'association_account' ? 'View project locations and boundaries for your active linked association.'
                : ($role === 'field_officer' ? 'Manage project GIS locations for your currently assigned associations.' : 'Manage eligible project locations and boundaries.')];
    }
}
