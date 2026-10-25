<?php

namespace App\Http\Controllers\FieldOfficer;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\ProgramDirectoryRequest;
use App\Services\Programs\ProgramDirectoryQueryService;
use App\Services\Programs\ProgramEditorQueryService;
use Illuminate\Http\JsonResponse;
use App\Models\Project;
use App\Services\Projects\ProjectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function index(ProgramDirectoryRequest $request, ProgramDirectoryQueryService $directory, ProgramEditorQueryService $editor): View|RedirectResponse|JsonResponse
    {
        if ($request->filled('lookup')) return response()->json($editor->lookup($request));
        if ($request->boolean('from_monitoring') && $request->filled('project_id') && $request->filled('resource_section')) {
            return redirect()->route('field-officer.projects.edit', ['project' => $request->query('project_id')]
                + $request->only('from_monitoring', 'resource_section', 'monitoring_record'));
        }
        $actor = $request->attributes->get('current_user');
        $prefix = 'field-officer';
        $filters = $request->filters();
        $summary = $directory->summary($actor, 'projects');
        $projects = $directory->records($actor, 'projects', $filters, $summary);
        $showEditor = $request->validated('editor') === 'create'
            || ($request->old('project_form') === 'editor' && !$request->old('project_record_id'));
        $editorData = $showEditor ? $editor->projectData($request) : [];

        return view('projects.register', compact('prefix', 'filters', 'summary', 'projects', 'showEditor') + $editorData);
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
