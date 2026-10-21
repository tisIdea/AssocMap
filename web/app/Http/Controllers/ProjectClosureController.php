<?php

namespace App\Http\Controllers;

use App\Http\Requests\CloseProjectRequest;
use App\Http\Requests\TerminateProjectRequest;
use App\Models\MonitoringRecord;
use App\Models\ProgramActivityRecord;
use App\Models\Project;
use App\Models\Training;
use App\Services\AssociationLifecycleService;
use App\Services\ProjectClosureService;
use App\Services\ProjectLifecycleService;
use App\Services\GisQueryService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ProjectClosureController extends Controller
{
    public function show(Request $request, Project $project, ProjectLifecycleService $lifecycle, AssociationLifecycleService $associations, GisQueryService $gis): View
    {
        $project->load(app(\App\Services\AssociationGeographyService::class)->relations('association'));
        abort_unless($project->association, 404);
        $actor = $request->attributes->get('current_user');
        $lifecycle->authorize($actor, $project->association);
        abort_if($actor->role->code === 'field_officer' && $project->association->archived_at !== null, 403);
        $prefix = $actor->role->code === 'system_admin' ? 'admin' : 'field-officer';
        $project->load(['fisheriesCategory', 'commodity', 'saadActivity', 'materials', 'products', 'closedBy']);
        $parentOperational = $associations->isOperational($project->association);
        $canEdit = $parentOperational && $project->archived_at === null && !$project->isFinalized();
        $gisLocations = $gis->projectLocations($actor, [$project->id]);
        $locationRow = ['id' => $project->id, 'area' => $project->association->area?->address,
            'barangay' => $project->association->locationBarangay()?->name,
            'municipality' => $project->association->locationBarangay()?->municipality?->name,
            'gis_locations' => $gisLocations[$project->id] ?? [], 'gis_locations_available' => $gisLocations !== null];
        $canClose = $parentOperational && $project->archived_at === null
            && $project->status === 'ongoing' && $project->closed_at === null;
        $canViewHistory = $prefix === 'admin' || $parentOperational;
        $canViewMonitoring = $canViewHistory && ($prefix === 'admin' || $project->archived_at === null);
        $monitoring = $canViewMonitoring ? MonitoringRecord::where('project_id', $project->id)->with('quarter')
            ->orderByDesc('monitoring_date')->orderBy('id')->paginate(10, ['*'], 'monitoring_page')->withQueryString() : null;
        $trainings = $canViewHistory ? Training::where('related_project_id', $project->id)
            ->when($prefix !== 'admin', fn ($query) => $query->where('association_id', $project->association_id))
            ->orderByDesc('start_date')->orderBy('id')->paginate(10, ['*'], 'training_page')->withQueryString() : null;
        $activities = $canViewHistory ? ProgramActivityRecord::where('related_project_id', $project->id)
            ->when($prefix !== 'admin', fn ($query) => $query->where('association_id', $project->association_id))
            ->orderByDesc('activity_date')->orderBy('id')->paginate(10, ['*'], 'activity_page')->withQueryString() : null;
        $closureAudit = $prefix === 'admin' && $project->isFinalized()
            ? DB::table('audit_logs')->where('entity_type', $project->status === 'terminated' ? 'project_termination' : 'project_closure')
                ->where('entity_id', $project->id)->where('action_type', $project->status === 'terminated' ? 'TERMINATE PROJECT' : 'CLOSE PROJECT')
                ->orderByDesc('performed_at')->first(['id']) : null;

        return view('projects.show', compact('project', 'prefix', 'canClose', 'canEdit', 'locationRow', 'monitoring', 'trainings', 'activities', 'closureAudit'));
    }

    public function store(CloseProjectRequest $request, Project $project, ProjectClosureService $service): RedirectResponse|JsonResponse
    {
        return $this->finalize($request, $project, $service, 'completed');
    }

    public function terminate(TerminateProjectRequest $request, Project $project, ProjectClosureService $service): RedirectResponse|JsonResponse
    {
        return $this->finalize($request, $project, $service, 'terminated');
    }

    private function finalize(CloseProjectRequest $request, Project $project, ProjectClosureService $service, string $outcome): RedirectResponse|JsonResponse
    {
        $prefix = $request->attributes->get('current_user')->role->code === 'system_admin' ? 'admin' : 'field-officer';
        $destination = route($prefix.'.projects.show', $project);
        $bag = $outcome === 'completed' ? 'projectClosure' : 'projectTermination';
        try {
            if ($outcome === 'completed') $service->close($request, $project, $request->validated());
            else $service->terminate($request, $project, $request->validated());
        } catch (ValidationException $exception) {
            throw $exception->errorBag($bag)->redirectTo($destination);
        } catch (QueryException|RuntimeException $exception) {
            if ($exception instanceof HttpExceptionInterface || $exception instanceof ModelNotFoundException) throw $exception;
            Log::warning('Project finalization failed.', ['code' => 'project_finalization_failed']);
            $message = 'Finalization could not be confirmed. Reload the project to check its current status before retrying, or contact the administrator.';
            if ($request->expectsJson()) return response()->json(['message' => $message], 503);

            return redirect()->to($destination)->withErrors(['project' => $message], $bag)->withInput($request->only('closure_remarks'));
        }
        $message = 'Project '.$outcome.'. It is permanently read-only. Historical records were preserved.';
        if ($request->expectsJson()) return response()->json(['message' => $message]);

        return redirect()->to($destination)->with('success', $message);
    }
}
