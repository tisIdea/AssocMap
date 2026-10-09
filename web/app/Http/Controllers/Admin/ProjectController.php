<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Support\Carbon;
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
        $catalog = require resource_path('ui/admin.php');

        $statusFilter = $request->query('status', '');

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
            ->whereNull('archived_at')
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        $categories = FisheriesProjectCategory::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $commodities = Commodity::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $catalog['options']['association_records'] =
            $associations
                ->map(fn (Association $association) => [
                    'value' => $association->id,
                    'label' => $association->name,
                ])
                ->values()
                ->all();

        $catalog['options']['fisheries_category_records'] =
            $categories
                ->map(fn (FisheriesProjectCategory $category) => [
                    'value' => $category->id,
                    'label' => $category->name,
                ])
                ->values()
                ->all();

        $catalog['options']['commodity_records'] =
            $commodities
                ->map(fn (Commodity $commodity) => [
                    'value' => $commodity->id,
                    'label' => $commodity->name,
                    'category_id' => $commodity->category_id,
                    'source' => $commodity->source,
                ])
                ->values()
                ->all();

        $module = $catalog['modules']['projects'];
        $module['status_filter'] = $statusFilter;

        $module['rows'] = $projects
            ->map(fn (Project $project) =>
                $this->mapProjectRow($project)
            )
            ->values()
            ->all();

        return view(
            'admin.projects.index',
            [
                'catalog' => $catalog,
                'module' => $module,
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

        return redirect()
            ->route('admin.projects.index')
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
        $barangay = $area?->barangay;
        $municipality = $barangay?->municipality;

        return [
            'id' => $project->id,

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
