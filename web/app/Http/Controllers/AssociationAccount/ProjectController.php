<?php

namespace App\Http\Controllers\AssociationAccount;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ApiRequestGuard;
use App\Http\Requests\AssociationProjectsRequest;
use App\Models\Project;
use App\Services\Reporting\ReportScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ProjectController extends Controller
{
    public function __construct(private ReportScope $scope)
    {
    }

    public function index(AssociationProjectsRequest $request): Response
    {
        try {
            $association = $this->association($request);
            $filters = $request->filters();
            $query = Project::query()->useWritePdo()->where('association_id', $association->id);
            if ($filters['search'] !== '') {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($filters['search'])).'%';
                $query->whereRaw("LOWER(title) LIKE ? ESCAPE '!'", [$pattern]);
            }
            if ($filters['year'] !== '') $query->where('program_year', $filters['year']);
            if ($filters['status'] === 'archived') $query->whereNotNull('archived_at');
            elseif ($filters['status'] !== '') $query->where('status', $filters['status']);
            $projects = $query->select(['id', 'association_id', 'title', 'fisheries_category_id', 'commodity_id',
                'program_year', 'implementation_start_date', 'implementation_end_date', 'status', 'archived_at'])
                ->with(['fisheriesCategory:id,name', 'commodity:id,name'])
                ->orderByDesc('program_year')->orderBy('title')->orderBy('id')->paginate(25)->appends($filters);
            $this->scope->recheck($request, [], [$association->id]);

            return ApiRequestGuard::secure(response()->view('association.projects.index', compact('association', 'filters', 'projects')));
        } catch (QueryException $exception) {
            return $this->unavailable();
        }
    }

    public function show(AssociationProjectsRequest $request, string $project): Response
    {
        try {
            $association = $this->association($request);
            $project = Project::query()->useWritePdo()->where('association_id', $association->id)->whereKey($project)
                ->firstOrFail(['id', 'association_id', 'saad_activity_id', 'fisheries_category_id', 'commodity_id', 'title',
                    'description', 'program_year', 'implementation_start_date', 'implementation_end_date', 'status', 'archived_at', 'closed_at']);
            $project->load(['saadActivity:id,name', 'fisheriesCategory:id,name', 'commodity:id,name']);
            $pages = $request->safe()->only(['products_page', 'materials_page', 'monitoring_page']);
            $products = $project->products()->useWritePdo()->orderBy('product_name')->orderBy('id')
                ->paginate(25, ['id', 'project_id', 'product_name', 'product_type', 'default_unit', 'description', 'is_active'], 'products_page')
                ->appends($pages)->fragment('project-products');
            $materials = $project->materials()->useWritePdo()->orderBy('item_name')->orderBy('id')
                ->paginate(25, ['id', 'project_id', 'item_name', 'quantity_planned', 'quantity_delivered', 'unit', 'delivery_status', 'delivery_date'], 'materials_page')
                ->appends($pages)->fragment('project-assistance');
            $monitoring = $project->monitoringRecords()->useWritePdo()->with('quarter:id,quarter_number')
                ->orderByDesc('year')->orderByDesc('monitoring_date')->orderBy('id')
                ->paginate(25, ['id', 'project_id', 'year', 'quarter_id', 'monitoring_date', 'project_status', 'site_validated'], 'monitoring_page')
                ->appends($pages)->fragment('project-monitoring');
            $this->scope->recheck($request, [], [$association->id]);
            abort_unless(Project::query()->useWritePdo()->whereKey($project->id)->where('association_id', $association->id)->exists(), 404);

            return ApiRequestGuard::secure(response()->view('association.projects.show', compact('association', 'project', 'products', 'materials', 'monitoring')));
        } catch (QueryException $exception) {
            return $this->unavailable();
        }
    }

    private function association(AssociationProjectsRequest $request): object
    {
        $actor = $this->scope->actor($request);
        abort_unless($actor->role->code === 'association_account', 403);
        $association = $this->scope->associations($actor)->first(['a.id', 'a.name', 'a.area_id', 'ar.address as area_address',
            'b.name as barangay_name', 'mu.name as municipality_name']);
        abort_unless($association, 403);

        return $association;
    }

    private function unavailable(): Response
    {
        Log::warning('Association projects unavailable.', ['code' => 'association_projects_database_unavailable']);

        return ApiRequestGuard::secure(response()->view('association.projects.index', ['unavailable' => true], 503));
    }
}
