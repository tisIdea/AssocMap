<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AreaIndexRequest;
use App\Http\Requests\StoreMunicipalityRequest;
use App\Models\Municipality;
use App\Services\Areas\AreaManagementQueryService;
use App\Services\Areas\MunicipalityService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class MunicipalityController extends Controller
{
    public function index(AreaIndexRequest $request, AreaManagementQueryService $query): Response
    {
        $filters = $request->filters();
        $records = $query->records($filters);
        $summary = $query->summary();
        $municipalityChoices = Municipality::query()->orderBy('name')->get(['id', 'name', 'is_active']);
        $catalog = $query->editorCatalog(municipalities: $municipalityChoices);
        $areaCreateTypes = ['Municipality', 'Barangay'];

        return response()->view('admin.areas.register', compact('filters', 'records', 'summary', 'catalog', 'municipalityChoices', 'areaCreateTypes'))
            ->header('Cache-Control', 'no-store, private');
    }

    public function show(AreaIndexRequest $request, Municipality $municipality, AreaManagementQueryService $query): Response
    {
        $parameters = $request->validated();
        $details = $query->municipalityDetails($municipality, $parameters);
        $catalog = $query->editorCatalog();
        $areaCreateTypes = ['Municipality', 'Barangay'];
        $editorRecord = $query->editorRecord($details['record']);
        $backUrl = route('admin.areas.index', $request->filters() + ['page' => $request->validated('page')]);

        return response()->view('admin.areas.municipality', $details + compact('catalog', 'areaCreateTypes', 'editorRecord', 'backUrl'))
            ->header('Cache-Control', 'no-store, private');
    }

    public function update(StoreMunicipalityRequest $request, Municipality $municipality, MunicipalityService $service): RedirectResponse
    {
        try {
            $service->update($municipality, $request->validated());
        } catch (QueryException $exception) {
            report($exception);
            return back()->withInput()->with('error', 'Unable to update the area. Check for duplicate values and try again.');
        }

        return redirect()->route('admin.areas.index')->with('success', 'Area updated successfully.');
    }

    public function deactivate(Municipality $municipality, MunicipalityService $service): RedirectResponse
    {
        try {
            $service->deactivate($municipality);
        } catch (QueryException $exception) {
            report($exception);
            return back()->with('error', 'Unable to deactivate the area. Please try again.');
        }

        return redirect()->route('admin.areas.index')->with('success', 'Area deactivated successfully.');
    }

    public function store(
        StoreMunicipalityRequest $request,
        MunicipalityService $municipalityService
    ): RedirectResponse {
        $currentUser = $request->attributes->get('current_user');

        $municipalityService->create(
            $request->validated(),
            $currentUser
        );

        return redirect()
            ->route('admin.areas.index')
            ->with('success', 'Municipality added successfully.');
    }
}
