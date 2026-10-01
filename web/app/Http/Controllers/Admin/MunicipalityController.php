<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMunicipalityRequest;
use App\Models\Municipality;
use App\Services\MunicipalityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use App\Models\Barangay;
use App\Models\Association;
use Illuminate\Database\QueryException;

class MunicipalityController extends Controller
{
public function index(): View
{
    $catalog = require resource_path('ui/admin.php');

    $municipalities = Municipality::query()
        ->orderBy('name')
        ->get();

    $catalog['options']['municipality'] = $municipalities->pluck('name')->values()->all();
    $catalog['options']['municipality_records'] = $municipalities
    ->where('is_active', true)
    ->map(function (Municipality $municipality) {
        return [
            'value' => $municipality->id,
            'label' => $municipality->name,
        ];
    })
    ->values()
    ->all();
    
    $barangays = Barangay::query()
    ->with('municipality')
    ->orderBy('name')
    ->get();

    $associationCounts = Association::query()
        ->join('areas', 'associations.area_id', '=', 'areas.id')
        ->join('barangays', 'areas.barangay_id', '=', 'barangays.id')
        ->select('barangays.id as barangay_id', 'barangays.municipality_id')
        ->selectRaw('COUNT(*) as association_count')
        ->groupBy('barangays.id', 'barangays.municipality_id')
        ->get();

    $barangayAssociationCounts = $associationCounts->pluck('association_count', 'barangay_id');
    $municipalityAssociationCounts = $associationCounts
        ->groupBy('municipality_id')
        ->map(fn ($counts) => $counts->sum('association_count'));

$module = $catalog['modules']['areas'];

$municipalityRows = $municipalities
    ->map(function (Municipality $municipality) use ($municipalityAssociationCounts) {
        return [
            'id' => $municipality->id,
            'name' => $municipality->name,
            'created_at' => $municipality->created_at?->toIso8601String(),
            'area_type' => $municipality->locality_type === 'city'
                ? 'City'
                : 'Municipality',
            'municipality' => $municipality->name,
            'province' => $municipality->province,
            'region' => $municipality->region,
            'update_url' => route('admin.municipalities.update', $municipality),
            'deactivate_url' => route('admin.municipalities.deactivate', $municipality),
            'associations_count' => (int) ($municipalityAssociationCounts[$municipality->id] ?? 0),
            'psgc_code' => $municipality->psgc_code,
            'source' => $municipality->source === 'psgc'
                ? 'PSGC'
                : 'Manual',
            'status' => $municipality->is_active
                ? 'Active'
                : 'Inactive',
        ];
    });

$barangayRows = $barangays
    ->map(function (Barangay $barangay) use ($barangayAssociationCounts) {
        return [
            'id' => $barangay->id,
            'name' => $barangay->name,
            'created_at' => $barangay->created_at?->toIso8601String(),
            'area_type' => 'Barangay',
            'municipality' => $barangay->municipality?->name ?? '—',
            'municipality_id' => $barangay->municipality_id,
            'province' => $barangay->municipality?->province,
            'region' => $barangay->municipality?->region,
            'update_url' => route('admin.barangays.update', $barangay),
            'deactivate_url' => route('admin.barangays.deactivate', $barangay),
            'associations_count' => (int) ($barangayAssociationCounts[$barangay->id] ?? 0),
            'psgc_code' => $barangay->psgc_code,
            'source' => $barangay->source === 'psgc'
                ? 'PSGC'
                : 'Manual',
            'status' => $barangay->is_active
                ? 'Active'
                : 'Inactive',
        ];
    });

$module['rows'] = $municipalityRows
    ->concat($barangayRows)
    ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
    ->values()
    ->all();

    return view('dashboard.index', [
        'catalog' => $catalog,
        'module' => $module,
    ]);
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
