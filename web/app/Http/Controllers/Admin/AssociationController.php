<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAssociationRequest;
use App\Http\Requests\AssociationIndexRequest;
use App\Services\Associations\AssociationDirectoryQueryService;
use App\Models\Association;
use App\Services\Associations\AssociationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AssociationController extends Controller
{
    public function index(AssociationIndexRequest $request, AssociationDirectoryQueryService $directory): View
    {
        $actor = $request->attributes->get('current_user');
        $prefix = 'admin';
        $officerDirectory = false;
        $filters = $request->filters();
        $associations = $directory->records($actor, $filters);
        $summary = $directory->summary($actor);
        $choices = $directory->filterChoices($actor);

        return view('associations.register', compact('prefix', 'officerDirectory', 'filters', 'associations', 'summary', 'choices')
            + $directory->editorData($request));
    }

    public function show(AssociationIndexRequest $request, Association $association, AssociationDirectoryQueryService $directory): View
    {
        $prefix = 'admin';
        $filters = $request->filters($request->validated('origin') === 'officers');
        $parameters = $filters + ['page' => $request->validated('page'), 'origin' => $request->validated('origin')];
        $backUrl = route($prefix.'.'.($request->validated('origin') === 'officers' ? 'association-officers.index' : 'associations.index'), $parameters);
        $data = $directory->details($request->attributes->get('current_user'), $association, $request->validated('section') ?? 'members', $parameters);

        return view('associations.show', $data + compact('prefix', 'filters', 'parameters', 'backUrl') + $directory->editorData($request, $association));
    }

    public function store(
        StoreAssociationRequest $request,
        AssociationService $associationService
    ): RedirectResponse {
        $currentUser = $request->attributes->get('current_user');

        $associationService->create(
            $request->validated(),
            $currentUser
        );

        return redirect()
            ->route('admin.associations.index')
            ->with('success', 'Association added successfully.');
    }

    public function update(
        StoreAssociationRequest $request,
        Association $association,
        AssociationService $associationService
    ): RedirectResponse {
        $currentUser = $request->attributes->get('current_user');

        $associationService->update(
            $association,
            $request->validated(),
            $currentUser
        );

        return redirect()
            ->route('admin.associations.index')
            ->with('success', 'Association updated successfully.');
    }

    public function unarchive(
        Request $request,
        Association $association,
        AssociationService $associationService
    ): RedirectResponse {
        $currentUser = $request->attributes->get('current_user');
        $associationService->unarchive($association, $currentUser);

        return redirect()
            ->route('admin.associations.index')
            ->with('success', 'Association restored successfully.');
    }

    public function archive(
        Request $request,
        Association $association,
        AssociationService $associationService
    ): RedirectResponse {
        $currentUser = $request->attributes->get('current_user');

        $associationService->archive(
            $association,
            $currentUser
        );

        return redirect()
            ->route('admin.associations.index')
            ->with('success', 'Association archived successfully.');
    }
}
