<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Support\Carbon;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\ProgramDirectoryRequest;
use App\Services\ProgramDirectoryQueryService;
use App\Services\ProgramEditorQueryService;
use Illuminate\Http\JsonResponse;
use App\Models\Association;
use App\Models\Project;
use App\Services\ProjectService;
use App\Services\AssociationLifecycleService;
use App\Services\ProjectLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function index(ProgramDirectoryRequest $request, ProgramDirectoryQueryService $directory, ProgramEditorQueryService $editor): View|RedirectResponse|JsonResponse
    {
        if ($request->filled('lookup')) return response()->json($editor->lookup($request));
        if ($request->boolean('from_monitoring') && $request->filled('project_id') && $request->filled('resource_section')) {
            return redirect()->route('admin.projects.edit', ['project' => $request->query('project_id')]
                + $request->only('from_monitoring', 'resource_section', 'monitoring_record'));
        }
        $actor = $request->attributes->get('current_user');
        $prefix = 'admin';
        $filters = $request->filters();
        $summary = $directory->summary($actor, 'projects');
        $projects = $directory->records($actor, 'projects', $filters, $summary);
        $showEditor = $request->validated('editor') === 'create'
            || ($request->old('project_form') === 'editor' && !$request->old('project_record_id'));
        $editorData = $showEditor ? $editor->projectData($request) : [];

        return view('projects.register', compact('prefix', 'filters', 'summary', 'projects', 'showEditor') + $editorData);
    }

    public function edit(Request $request, Project $project, ProjectLifecycleService $lifecycle, AssociationLifecycleService $associations, ProgramEditorQueryService $editor): View
    {
        $project->load([...app(\App\Services\AssociationGeographyService::class)->relations('association'), 'saadActivity', 'fisheriesCategory', 'commodity', 'materials', 'products']);
        abort_unless($project->association, 404);
        $actor = $request->attributes->get('current_user');
        $lifecycle->authorize($actor, $project->association);
        abort_unless($associations->isOperational($project->association)
            && $project->archived_at === null && !$project->isFinalized(), 403, 'This project is read-only.');
        $prefix = $actor->role->code === 'system_admin' ? 'admin' : 'field-officer';
        $catalog = $editor->projectData($request, $project)['catalog'];
        $editRow = $this->mapProjectRow($project);
        $editRow['update_url'] = route($prefix.'.projects.update', $project);

        return view('projects.edit', compact('project', 'prefix', 'catalog', 'editRow'));
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
            ->route('admin.projects.index')
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
            ->route('admin.projects.index', $navigation)
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
            ->route('admin.projects.index')
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
            ->route('admin.projects.index')
            ->with(
                'success',
                'Project restored successfully.'
            );
    }

    private function mapProjectRow(Project $project): array
    {
        $association = $project->association;
        $area = $association?->area;
        $barangay = $association?->locationBarangay();
        $municipality = $barangay?->municipality;

        return [
            'id' => $project->id,
            'detail_url' => route('admin.projects.show', $project),
            'can_mutate' => $association && app(AssociationLifecycleService::class)->isOperational($association)
                && !$project->isFinalized(),

            'association_id' =>
                $project->association_id,

            'association' =>
                $association?->name ?? '—',

            'municipality' =>
                $municipality?->name ?? '—',

            'barangay' =>
                $barangay?->name ?? '—',

            'area' =>
                $area?->address ?? '—',

            'title' =>
                $project->title,

            'description' =>
                $project->description,

            'component' =>
                $project->saadActivity?->name
                    ?? 'Livelihood Project Delivery',

            'fisheries_category_id' =>
                $project->fisheries_category_id,

            'fisheries_category' =>
                $project->fisheriesCategory?->name
                    ?? '—',

            'commodity_id' =>
                $project->commodity_id,

            'commodity' =>
                $project->commodity?->name
                    ?? '—',

            'program_year' =>
                $project->program_year,

            'implementation_date' =>
                $project->implementation_start_date
                    ? Carbon::parse(
                        $project->implementation_start_date
                    )->format('Y-m-d')
                    : null,

            'implementation_start_date' =>
                $project->implementation_start_date
                    ? Carbon::parse(
                        $project->implementation_start_date
                    )->format('Y-m-d')
                    : null,

            'implementation_end_date' =>
                $project->implementation_end_date
                    ? Carbon::parse(
                        $project->implementation_end_date
                    )->format('Y-m-d')
                    : null,

            'status' =>
                ucfirst($project->status),

            'status_value' =>
                $project->status,

            'remarks' =>
                $project->remarks,

            'materials' =>
                $project->materials
                    ->map(fn ($material) => [
                        'id' => $material->id,
                        'item_name' => $material->item_name,
                        'quantity_planned' =>
                            $material->quantity_planned,
                        'quantity_delivered' =>
                            $material->quantity_delivered,
                        'unit' => $material->unit,
                        'unit_cost' => $material->unit_cost,
                        'delivery_status' =>
                            $material->delivery_status,
                        'delivery_date' =>
                            $material->delivery_date?->format(
                                'Y-m-d'
                            ),
                        'remarks' => $material->remarks,
                    ])
                    ->values()
                    ->all(),

            'products' => $project->products
                ->map(fn ($product) => [
                    'id' => $product->id,
                    'product_name' => $product->product_name,
                    'product_type' => $product->product_type,
                    'default_unit' => $product->default_unit,
                    'description' => $product->description,
                    'is_active' => $product->is_active,
                ])
                ->values()
                ->all(),

            'archived' =>
                $project->archived_at !== null
                    ? 'Yes'
                    : 'No',

            'archived_at' =>
                $project->archived_at?->toIso8601String(),

            'update_url' =>
                route(
                    'admin.projects.update',
                    $project
                ),

            'archive_url' =>
                route(
                    'admin.projects.archive',
                    $project
                ),

            'unarchive_url' =>
                route(
                    'admin.projects.unarchive',
                    $project
                ),
        ];
    }
}
