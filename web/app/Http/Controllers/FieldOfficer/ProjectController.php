<?php

namespace App\Http\Controllers\FieldOfficer;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectRequest;
use App\Models\Association;
use App\Models\Commodity;
use App\Models\FisheriesProjectCategory;
use App\Models\Project;
use App\Services\ProjectService;
use App\Services\GisQueryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function index(Request $request, GisQueryService $gis): View|RedirectResponse
    {
        if ($request->boolean('from_monitoring') && \Illuminate\Support\Str::isUuid($request->query('project_id', ''))
            && in_array($request->query('resource_section'), ['products', 'materials'], true)) {
            return redirect()->route('field-officer.projects.edit', ['project' => $request->query('project_id')]
                + $request->only('from_monitoring', 'resource_section', 'monitoring_record'));
        }
        $currentUser =
            $request->attributes->get('current_user');

        $statusFilter =
            $request->query('status', '');

        $statusFilter = in_array(
            $statusFilter,
            [
                'planned',
                'ongoing',
                'completed',
                'terminated',
                'suspended',
                'cancelled',
                'archived',
            ],
            true
        )
            ? $statusFilter
            : '';

        $projects = Project::query()
            ->with([
                'association.area.barangay.municipality',
                'saadActivity',
                'fisheriesCategory',
                'commodity',
                'materials',
                'products',
            ])
            ->whereHas(
                'association',
                fn ($query) =>
                    $query->where(
                        'primary_field_officer_id',
                        $currentUser->id
                    )
            )
            ->when(
                $statusFilter === 'archived',
                fn ($query) =>
                    $query->whereNotNull('archived_at'),
                fn ($query) =>
                    $query->whereNull('archived_at')
            )
            ->when(
                in_array(
                    $statusFilter,
                    [
                        'planned',
                        'ongoing',
                        'completed',
                        'terminated',
                        'suspended',
                        'cancelled',
                    ],
                    true
                ),
                fn ($query) =>
                    $query->where('status', $statusFilter)
            )
            ->orderByDesc('program_year')
            ->orderBy('title')
            ->get();

        $associations = Association::query()
            ->where(
                'primary_field_officer_id',
                $currentUser->id
            )
            ->whereNull('archived_at')
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        $categories =
            FisheriesProjectCategory::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get();

        $commodities =
            Commodity::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get();

        $gisLocations = $gis->projectLocations($currentUser, $projects->modelKeys());

        return view(
            'field-officer.projects.index',
            [
                'projects' =>
                    $projects,

                'associations' =>
                    $associations,

                'categories' =>
                    $categories,

                'commodities' =>
                    $commodities,

                'statusFilter' =>
                    $statusFilter,
                'gisLocationsByProject' => $gisLocations ?? [],
                'gisLocationsAvailable' => $gisLocations !== null,
            ]
        );
    }

    public function store(
        StoreProjectRequest $request,
        ProjectService $projectService
    ): RedirectResponse {
        $currentUser =
            $request->attributes->get('current_user');

        $projectService->create(
            $request->validated(),
            $currentUser
        );

        return redirect()
            ->route('field-officer.projects.index')
            ->with(
                'success',
                'Project created successfully.'
            );
    }

    public function update(
        StoreProjectRequest $request,
        Project $project,
        ProjectService $projectService
    ): RedirectResponse {
        $currentUser =
            $request->attributes->get('current_user');

        $projectService->update(
            $project,
            $request->validated(),
            $currentUser
        );

        $navigation = [];
        $section = $request->query('resource_section');
        if ($request->boolean('from_monitoring') && in_array($section, ['products', 'materials'], true)) {
            $navigation = ['from_monitoring' => 1, 'project_id' => $project->id, 'resource_section' => $section];
            $record = $request->query('monitoring_record');
            if (is_string($record) && \Illuminate\Support\Str::isUuid($record)) {
                $navigation['monitoring_record'] = $record;
            }
        }

        return redirect()
            ->route('field-officer.projects.index', $navigation)
            ->with(
                'success',
                'Project updated successfully.'
            );
    }

    public function archive(
        Request $request,
        Project $project,
        ProjectService $projectService
    ): RedirectResponse {
        $currentUser =
            $request->attributes->get('current_user');

        $projectService->archive(
            $project,
            $currentUser
        );

        return redirect()
            ->route('field-officer.projects.index')
            ->with(
                'success',
                'Project archived successfully.'
            );
    }

    public function unarchive(
        Request $request,
        Project $project,
        ProjectService $projectService
    ): RedirectResponse {
        $currentUser =
            $request->attributes->get('current_user');

        $projectService->unarchive(
            $project,
            $currentUser
        );

        return redirect()
            ->route('field-officer.projects.index')
            ->with(
                'success',
                'Project restored successfully.'
            );
    }
}
