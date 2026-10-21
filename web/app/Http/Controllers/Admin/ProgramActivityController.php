<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProgramDirectoryRequest;
use App\Http\Requests\StoreProgramActivityRequest;
use App\Models\ProgramActivityRecord;
use App\Services\AssociationLifecycleService;
use App\Services\ProgramActivityService;
use App\Services\ProgramDirectoryQueryService;
use App\Services\ProgramEditorQueryService;
use App\Services\ProjectLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProgramActivityController extends Controller
{
    public function index(ProgramDirectoryRequest $request, ProgramDirectoryQueryService $directory, ProgramEditorQueryService $editor): View|JsonResponse
    {
        if ($request->filled('lookup')) return response()->json($editor->lookup($request));
        $actor = $request->attributes->get('current_user');
        $prefix = $actor->role->code === 'system_admin' ? 'admin' : 'field-officer';
        $filters = $request->filters();
        $summary = $directory->summary($actor, 'program-activities');
        $records = $directory->records($actor, 'program-activities', $filters, $summary);
        $rows = $records->getCollection()->map(fn ($record) => $this->mapActivityRow($record, $prefix, $actor->id));
        $showEditor = $request->validated('editor') === 'create'
            || ($request->old('program_activity_form') === 'editor'
                && !$request->old('program_activity_record_id'));
        $editorData = $showEditor ? $editor->activityData($request) : [];

        return view('program-activities.register', compact('prefix', 'filters', 'summary', 'records', 'rows', 'showEditor') + $editorData);
    }

    public function show(Request $request, ProgramActivityRecord $programActivity): View
    {
        return view('program-activities.show', $this->recordData($request, $programActivity));
    }

    public function edit(Request $request, ProgramActivityRecord $programActivity, ProgramEditorQueryService $editor): View
    {
        $data = $this->recordData($request, $programActivity);
        abort_unless($data['canEdit'], 403, 'This activity is read-only.');
        return view('program-activities.edit', $data + $editor->activityData($request, $programActivity));
    }

    private function recordData(Request $request, ProgramActivityRecord $programActivity): array
    {
        $actor = $request->attributes->get('current_user');
        abort_unless($actor->canAccessApplication() && in_array($actor->role?->code, ['system_admin', 'field_officer'], true), 403);
        $programActivity->load([...app(\App\Services\AssociationGeographyService::class)->relations('association'), 'relatedProject.association', 'saadActivity']);
        $prefix = $actor->role->code === 'system_admin' ? 'admin' : 'field-officer';
        $lifecycle = app(AssociationLifecycleService::class);
        if ($prefix === 'field-officer') {
            abort_unless($programActivity->association && $lifecycle->isOperational($programActivity->association), 403);
            app(ProjectLifecycleService::class)->authorize($actor, $programActivity->association);
        }
        $record = $programActivity;
        $row = $this->mapActivityRow($record, $prefix, $actor->id);
        $canEdit = !$row['archived'] && $row['can_manage'];
        $canViewProject = $row['can_view_project'];

        return compact('record', 'prefix', 'row', 'canEdit', 'canViewProject');
    }

    public function store(
        StoreProgramActivityRequest $request,
        ProgramActivityService $programActivityService
    ): RedirectResponse {
        $currentUser =
            $request->attributes->get(
                'current_user'
            );

        $programActivityService->create(
            $request->validated(),
            $currentUser
        );

        return redirect()
            ->route(
                'admin.program-activities.index'
            )
            ->with(
                'success',
                'Program activity created successfully.'
            );
    }

    public function update(
        StoreProgramActivityRequest $request,
        ProgramActivityRecord $programActivity,
        ProgramActivityService $programActivityService
    ): RedirectResponse {
        $currentUser =
            $request->attributes->get(
                'current_user'
            );

        $programActivityService->update(
            $programActivity,
            $request->validated(),
            $currentUser
        );

        return redirect()
            ->route(
                'admin.program-activities.index'
            )
            ->with(
                'success',
                'Program activity updated successfully.'
            );
    }

    public function archive(
        Request $request,
        ProgramActivityRecord $programActivity,
        ProgramActivityService $programActivityService
    ): RedirectResponse {
        $currentUser =
            $request->attributes->get(
                'current_user'
            );

        $programActivityService->archive(
            $programActivity,
            $currentUser
        );

        return redirect()
            ->route(
                'admin.program-activities.index'
            )
            ->with(
                'success',
                'Program activity archived successfully.'
            );
    }

    public function unarchive(
        Request $request,
        ProgramActivityRecord $programActivity,
        ProgramActivityService $programActivityService
    ): RedirectResponse {
        $currentUser =
            $request->attributes->get(
                'current_user'
            );

        $programActivityService->unarchive(
            $programActivity,
            $currentUser
        );

        return redirect()
            ->route(
                'admin.program-activities.index'
            )
            ->with(
                'success',
                'Program activity restored successfully.'
            );
    }

    private function mapActivityRow(ProgramActivityRecord $record, string $prefix, string $actorId): array
    {
        $association = $record->association;
        $project = $record->relatedProject;
        $lifecycle = app(AssociationLifecycleService::class);
        $parentsOperational = (!$record->association_id || ($association && $lifecycle->isOperational($association)))
            && (!$project || ($project->association && $lifecycle->isOperational($project->association)));
        $finalized = $project?->isFinalized() ?? false;

        return [
            'id' => $record->id,
            'project_finalized' => $finalized,
            'association_archived' => $association?->archived_at !== null,
            'parents_operational' => $parentsOperational,
            'can_manage' => $parentsOperational && !$finalized,
            'can_view_project' => $project?->association && ($prefix === 'admin'
                || ($project->association->archived_at === null && $project->association->primary_field_officer_id === $actorId)),
            'saad_activity_id' => $record->saad_activity_id,
            'activity' => $record->saadActivity?->name ?? 'Not recorded',
            'activity_code' => $record->saadActivity?->code,
            'association_id' => $record->association_id,
            'association' => $record->association_id ? ($association?->name ?? 'Association unavailable') : 'Program-wide',
            'municipality' => $association?->locationBarangay()?->municipality?->name ?? 'Not recorded',
            'barangay' => $association?->locationBarangay()?->name ?? 'Not recorded',
            'related_project_id' => $record->related_project_id,
            'related_project' => $project?->title ?? 'No linked project',
            'title' => $record->title,
            'description' => $record->description,
            'program_year' => $record->program_year,
            'activity_date' => $record->activity_date?->format('Y-m-d'),
            'venue' => $record->venue,
            'status' => ucfirst($record->status),
            'status_value' => $record->status,
            'conducted_by' => $record->conducted_by,
            'remarks' => $record->remarks,
            'archived' => $record->archived_at !== null,
            'update_url' => route($prefix.'.program-activities.update', $record),
            'archive_url' => route($prefix.'.program-activities.archive', $record),
            'unarchive_url' => route($prefix.'.program-activities.unarchive', $record),
        ];
    }
}
