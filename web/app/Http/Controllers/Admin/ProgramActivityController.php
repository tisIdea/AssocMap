<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\ProgramActivityPageData;
use App\Http\Requests\ProgramDirectoryRequest;
use App\Http\Requests\StoreProgramActivityRequest;
use App\Models\ProgramActivityRecord;
use App\Services\Associations\AssociationLifecycleService;
use App\Services\Programs\ProgramActivityService;
use App\Services\Programs\ProgramDirectoryQueryService;
use App\Services\Programs\ProgramEditorQueryService;
use App\Services\Projects\ProjectLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProgramActivityController extends Controller
{
    public function index(ProgramDirectoryRequest $request, ProgramDirectoryQueryService $directory, ProgramEditorQueryService $editor): View|JsonResponse
    {
        if ($request->filled('lookup')) return response()->json($editor->lookup($request));

        return view('program-activities.register', app(ProgramActivityPageData::class)->indexData($request, $directory, $editor, 'admin'));
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
        $programActivity->load([...app(\App\Services\Associations\AssociationGeographyService::class)->relations('association'), 'relatedProject.association', 'saadActivity']);
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
        return app(ProgramActivityPageData::class)->row($record, $prefix, $actorId);
    }
}
