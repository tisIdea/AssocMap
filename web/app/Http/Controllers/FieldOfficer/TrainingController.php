<?php

namespace App\Http\Controllers\FieldOfficer;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTrainingRequest;
use App\Http\Requests\ProgramDirectoryRequest;
use App\Services\Programs\ProgramDirectoryQueryService;
use App\Services\Programs\ProgramEditorQueryService;
use Illuminate\Http\JsonResponse;
use App\Models\Training;
use App\Services\Programs\TrainingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TrainingController extends Controller
{
    public function index(ProgramDirectoryRequest $request, ProgramDirectoryQueryService $directory, ProgramEditorQueryService $editor): View|JsonResponse
    {
        if ($request->filled('lookup')) return response()->json($editor->lookup($request));
        $actor = $request->attributes->get('current_user');
        $prefix = 'field-officer';
        $filters = $request->filters();
        $summary = $directory->summary($actor, 'trainings');
        $trainings = $directory->records($actor, 'trainings', $filters, $summary);
        $showEditor = $request->validated('editor') === 'create'
            || ($request->old('training_form') === 'editor' && !$request->old('training_record_id'));
        $editorData = $showEditor ? $editor->trainingData($request) : [];

        return view('trainings.register', compact('prefix', 'filters', 'summary', 'trainings', 'showEditor') + $editorData);
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
                'field-officer.trainings.index'
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
                'field-officer.trainings.index'
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
                'field-officer.trainings.index'
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
                'field-officer.trainings.index'
            )
            ->with(
                'success',
                'Training restored successfully.'
            );
    }
}
