<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTrainingRequest;
use App\Http\Requests\ProgramDirectoryRequest;
use App\Services\Associations\AssociationLifecycleService;
use App\Services\Programs\ProgramDirectoryQueryService;
use Illuminate\Http\JsonResponse;
use App\Models\Training;
use App\Services\Programs\ProgramEditorQueryService;
use App\Services\Programs\TrainingService;
use App\Services\Projects\ProjectLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class TrainingController extends Controller
{
    public function index(ProgramDirectoryRequest $request, ProgramDirectoryQueryService $directory, ProgramEditorQueryService $editor): View|JsonResponse
    {
        if ($request->filled('lookup')) return response()->json($editor->lookup($request));
        $actor = $request->attributes->get('current_user');
        $prefix = 'admin';
        $filters = $request->filters();
        $summary = $directory->summary($actor, 'trainings');
        $trainings = $directory->records($actor, 'trainings', $filters, $summary);
        $showEditor = $request->validated('editor') === 'create'
            || ($request->old('training_form') === 'editor' && !$request->old('training_record_id'));
        $editorData = $showEditor ? $editor->trainingData($request) : [];

        return view('trainings.register', compact('prefix', 'filters', 'summary', 'trainings', 'showEditor') + $editorData);
    }

    public function show(Request $request, Training $training): View
    {
        return view('trainings.show', $this->recordData($request, $training));
    }

    public function edit(Request $request, Training $training, ProgramEditorQueryService $editor): View
    {
        $data = $this->recordData($request, $training);
        abort_unless($data['canEdit'], 403, 'This training is read-only.');
        return view('trainings.edit', $data + $editor->trainingData($request, $training));
    }

    private function recordData(Request $request, Training $training): array
    {
        $actor = $request->attributes->get('current_user');
        abort_unless($actor->canAccessApplication() && in_array($actor->role?->code, ['system_admin', 'field_officer'], true), 403);
        $request->validate(['participant_page' => ['nullable', 'integer', 'min:1', 'max:1000000']]);
        $training->load([...app(\App\Services\Associations\AssociationGeographyService::class)->relations('association'), 'relatedProject.association', 'saadActivity']);
        $prefix = $actor->role->code === 'system_admin' ? 'admin' : 'field-officer';
        $lifecycle = app(AssociationLifecycleService::class);
        if ($prefix === 'field-officer') {
            abort_unless($training->association && $lifecycle->isOperational($training->association), 403);
            app(ProjectLifecycleService::class)->authorize($actor, $training->association);
        }
        $canEdit = $training->archived_at === null && $training->association && $lifecycle->isOperational($training->association)
            && !($training->relatedProject?->isFinalized() ?? false)
            && (!$training->relatedProject || ($training->relatedProject->association
                && $lifecycle->isOperational($training->relatedProject->association)));
        $canViewProject = $training->relatedProject?->association
            && ($prefix === 'admin' || ($training->relatedProject->association->archived_at === null
                && $training->relatedProject->association->primary_field_officer_id === $actor->id));
        $participants = $training->participants()->with('member:id,association_id,first_name,middle_name,last_name,suffix,member_status,archived_at')
            ->orderBy('id')->paginate(25, ['*'], 'participant_page')->withQueryString();
        $training->setRelation('participants', $participants->getCollection());
        $row = $this->mapTrainingRow($training);
        $row['update_url'] = route($prefix.'.trainings.update', $training);
        $row['participant_count'] = $participants->total();

        return compact('training', 'prefix', 'row', 'canEdit', 'canViewProject', 'participants');
    }

    public function store(
        StoreTrainingRequest $request,
        TrainingService $trainingService
    ): RedirectResponse {
        $currentUser =
            $request->attributes->get(
                'current_user'
            );

        $trainingService->create(
            $request->validated(),
            $currentUser
        );

        return redirect()
            ->route(
                'admin.trainings.index'
            )
            ->with(
                'success',
                'Training created successfully.'
            );
    }

    public function update(
        StoreTrainingRequest $request,
        Training $training,
        TrainingService $trainingService
    ): RedirectResponse {
        $currentUser =
            $request->attributes->get(
                'current_user'
            );

        $trainingService->update(
            $training,
            $request->validated(),
            $currentUser
        );

        return redirect()
            ->route(
                'admin.trainings.index'
            )
            ->with(
                'success',
                'Training updated successfully.'
            );
    }

    public function archive(
        Request $request,
        Training $training,
        TrainingService $trainingService
    ): RedirectResponse {
        $currentUser =
            $request->attributes->get(
                'current_user'
            );

        $trainingService->archive(
            $training,
            $currentUser
        );

        return redirect()
            ->route(
                'admin.trainings.index'
            )
            ->with(
                'success',
                'Training archived successfully.'
            );
    }

    public function unarchive(
        Request $request,
        Training $training,
        TrainingService $trainingService
    ): RedirectResponse {
        $currentUser =
            $request->attributes->get(
                'current_user'
            );

        $trainingService->unarchive(
            $training,
            $currentUser
        );

        return redirect()
            ->route(
                'admin.trainings.index'
            )
            ->with(
                'success',
                'Training restored successfully.'
            );
    }

    private function mapTrainingRow(
        Training $training
    ): array {
        $association =
            $training->association;

        $area =
            $association?->area;

        $barangay =
            $association?->locationBarangay();

        $municipality =
            $barangay?->municipality;

        return [
            'id' =>
                $training->id,

            'project_finalized' => $training->relatedProject?->isFinalized() ?? false,
            'association_archived' => $association?->archived_at !== null,
            'parents_operational' => $association && app(AssociationLifecycleService::class)->isOperational($association)
                && (!$training->relatedProject || ($training->relatedProject->association
                    && app(AssociationLifecycleService::class)->isOperational($training->relatedProject->association))),
            'expected_updated_at' => $training->updated_at?->format('Y-m-d\TH:i:s.uP'),

            'association_id' =>
                $training->association_id,

            'association' =>
                $association?->name ?? '—',

            'municipality' =>
                $municipality?->name ?? '—',

            'barangay' =>
                $barangay?->name ?? '—',

            'related_project_id' =>
                $training->related_project_id,

            'related_project' =>
                $training->relatedProject?->title
                    ?? '—',

            'saad_activity_id' =>
                $training->saad_activity_id,

            'saad_activity' =>
                $training->saadActivity?->name
                    ?? '—',

            'title' =>
                $training->title,

            'description' =>
                $training->description,

            'program_year' =>
                $training->program_year,

            'start_date' =>
                $training->start_date
                    ? Carbon::parse(
                        $training->start_date
                    )->format('Y-m-d')
                    : null,

            'end_date' =>
                $training->end_date
                    ? Carbon::parse(
                        $training->end_date
                    )->format('Y-m-d')
                    : null,

            'venue' =>
                $training->venue,

            'conducted_by' =>
                $training->conducted_by,

            'training_cost' =>
                $training->training_cost,

            'status' =>
                ucfirst(
                    $training->status
                ),

            'status_value' =>
                $training->status,

            'remarks' =>
                $training->remarks,

            'participants' =>
                $training->participants
                    ->map(
                        fn ($participant) => [
                            'member_name' => collect([$participant->member?->first_name, $participant->member?->middle_name,
                                $participant->member?->last_name, $participant->member?->suffix])->filter(fn ($part) => filled($part))->implode(' '),
                            'id' =>
                                $participant->id,

                            'member_id' =>
                                $participant->member_id,

                            'attendance_status' =>
                                $participant->attendance_status,

                            'remarks' =>
                                $participant->remarks,
                        ]
                    )
                    ->values()
                    ->all(),

            'participant_count' =>
                $training
                    ->participants
                    ->count(),

            'archived' =>
                $training->archived_at
                    !== null,

            'update_url' =>
                route(
                    'admin.trainings.update',
                    $training
                ),

            'archive_url' =>
                route(
                    'admin.trainings.archive',
                    $training
                ),

            'unarchive_url' =>
                route(
                    'admin.trainings.unarchive',
                    $training
                ),
        ];
    }
}
