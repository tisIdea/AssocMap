<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangeAssociationOfficerRequest;
use App\Http\Requests\AssociationIndexRequest;
use App\Models\Association;
use App\Models\AssociationPosition;
use App\Models\Member;
use App\Services\Associations\AssociationDirectoryQueryService;
use App\Services\Associations\AssociationOfficerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Validation\ValidationException;

class AssociationOfficerController extends Controller
{
    public function index(AssociationIndexRequest $request, AssociationOfficerService $service, AssociationDirectoryQueryService $directory): View
    {
        $actor = $request->attributes->get('current_user');
        $service->authorize($actor);
        $prefix = $actor->role->code === 'system_admin' ? 'admin' : 'field-officer';
        $filters = $request->filters(true);
        $associations = $directory->officerRecords($actor, $filters);
        $officerDirectory = true;
        $summary = null;
        $choices = [];

        return view('associations.officers-index', compact('associations', 'prefix', 'filters', 'officerDirectory', 'summary', 'choices'));
    }

    public function show(Request $request, Association $association, AssociationOfficerService $service): View
    {
        $actor = $request->attributes->get('current_user');
        $service->authorize($actor, $association);
        abort_if($actor->role->code === 'field_officer' && $association->archived_at !== null, 403);
        $association->load('currentPresident');
        $request->validateWithBag('officerPosition', ['member' => ['nullable', 'uuid'], 'replace' => ['nullable', 'uuid']]);
        $prefix = $actor->role->code === 'system_admin' ? 'admin' : 'field-officer';
        $columns = ['id', 'association_id', 'first_name', 'last_name', 'position_id', 'updated_at'];
        $officersQuery = Member::query()->where('association_id', $association->id)
            ->where('member_status', 'active')->whereNull('archived_at')
            ->whereHas('position', fn ($query) => $query->where('is_officer', true));
        $officers = (clone $officersQuery)->with('position:id,name,is_active')->orderBy('last_name')
            ->orderBy('id')->paginate(25, $columns, 'officers_page')->withQueryString();
        $positions = AssociationPosition::where('is_active', true)->where('is_officer', true)
            ->orderBy('sort_order')->get(['id', 'code', 'name']);
        $occupied = (clone $officersQuery)->whereHas('position',
            fn ($query) => $query->whereIn('code', ['PRESIDENT', 'VICE_PRESIDENT']))
            ->distinct()->pluck('position_id')->all();
        $eligible = $service->eligibleMembers($association);
        $members = (clone $eligible)->with('position:id,name')->orderBy('last_name')->orderBy('id')
            ->paginate(25, $columns, 'members_page')->withQueryString();
        $selected = $request->filled('member')
            ? (clone $eligible)->with('position:id,name')->whereKey($request->query('member'))->firstOrFail($columns) : null;
        $former = $request->filled('replace')
            ? (clone $officersQuery)->with('position:id,name,is_active')->whereKey($request->query('replace'))->firstOrFail($columns) : null;

        return view('associations.officers', compact('association', 'prefix', 'officers', 'members',
            'positions', 'occupied', 'selected', 'former'));
    }

    public function update(ChangeAssociationOfficerRequest $request, Association $association, AssociationOfficerService $service): RedirectResponse
    {
        try {
            $service->change($request, $association, $request->validated());
        } catch (ValidationException $exception) {
            $prefix = $request->attributes->get('current_user')->role->code === 'system_admin' ? 'admin' : 'field-officer';
            throw $exception->errorBag('officerPosition')
                ->redirectTo(route($prefix.'.association-officers.show', $association));
        }
        $prefix = $request->attributes->get('current_user')->role->code === 'system_admin' ? 'admin' : 'field-officer';

        return redirect()->route($prefix.'.association-officers.show', $association)
            ->with('success', 'Association officer position updated successfully.');
    }
}
