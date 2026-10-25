<?php

namespace App\Http\Controllers\FieldOfficer;

use App\Http\Controllers\Controller;
use App\Http\ProgramActivityPageData;
use App\Http\Requests\ProgramDirectoryRequest;
use App\Http\Requests\StoreProgramActivityRequest;
use App\Models\ProgramActivityRecord;
use App\Services\ProgramActivityService;
use App\Services\ProgramDirectoryQueryService;
use App\Services\ProgramEditorQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProgramActivityController extends Controller
{
    public function index(ProgramDirectoryRequest $request, ProgramDirectoryQueryService $directory, ProgramEditorQueryService $editor): View|JsonResponse
    {
        if ($request->filled('lookup')) {
            return response()->json($editor->lookup($request));
        }

        return view('program-activities.register', app(ProgramActivityPageData::class)->indexData($request, $directory, $editor, 'field-officer'));
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
                'field-officer.program-activities.index'
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
                'field-officer.program-activities.index'
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
                'field-officer.program-activities.index'
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
                'field-officer.program-activities.index'
            )
            ->with(
                'success',
                'Program activity restored successfully.'
            );
    }

}
