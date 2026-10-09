<?php

namespace App\Http\Controllers\FieldOfficer;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectRequest;
use App\Models\Association;
use App\Models\Commodity;
use App\Models\FisheriesProjectCategory;
use App\Models\Project;
use App\Services\ProjectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function index(Request $request): View
    {
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

        return redirect()
            ->route('field-officer.projects.index')
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
