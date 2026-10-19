<?php

namespace App\Http\Controllers\FieldOfficer;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssociationIndexRequest;
use App\Models\Association;
use App\Services\AssociationDirectoryQueryService;
use Illuminate\View\View;

class AssociationController extends Controller
{
    public function index(AssociationIndexRequest $request, AssociationDirectoryQueryService $directory): View
    {
        $actor = $request->attributes->get('current_user');
        $prefix = 'field-officer';
        $officerDirectory = false;
        $filters = $request->filters();
        $associations = $directory->records($actor, $filters);
        $summary = null;
        $choices = $directory->filterChoices($actor);

        return view('associations.register', compact('prefix', 'officerDirectory', 'filters', 'associations', 'summary', 'choices'));
    }

    public function show(AssociationIndexRequest $request, Association $association, AssociationDirectoryQueryService $directory): View
    {
        $prefix = 'field-officer';
        $filters = $request->filters($request->validated('origin') === 'officers');
        $parameters = $filters + ['page' => $request->validated('page'), 'origin' => $request->validated('origin')];
        $backUrl = route($prefix.'.'.($request->validated('origin') === 'officers' ? 'association-officers.index' : 'associations.index'), $parameters);
        $data = $directory->details($request->attributes->get('current_user'), $association, $request->validated('section') ?? 'members', $parameters);

        return view('associations.show', $data + compact('prefix', 'filters', 'parameters', 'backUrl'));
    }
}
