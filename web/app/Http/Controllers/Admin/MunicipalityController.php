<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMunicipalityRequest;
use App\Models\Area;
use App\Models\Association;
use App\Models\Barangay;
use App\Models\Municipality;
use App\Services\MunicipalityService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
class MunicipalityController extends Controller
{
public function index(): View
{
    $catalog = require resource_path('ui/admin.php');
    $municipalities = Municipality::query()
        ->orderBy('name')
        ->get();
    $barangays = Barangay::query()
        ->with('municipality')
        ->orderBy('name')
        ->get();

    $this->setOptions($catalog, $municipalities, $barangays);
    $counts = $this->getAssociationCounts();
    $module = $catalog['modules']['areas'];
    $module['rows'] = $this->getAreaRows($municipalities, $barangays, $counts);
    $catalog['options']['barangay_filter_records'] = $barangays->map(fn (Barangay $barangay) => [
        'value' => $barangay->id,
        'label' => $barangay->name.' — '.$barangay->municipality?->name,
    ])->values()->all();
    $areas = Area::query()
        ->with('barangay.municipality')
        ->withCount('associations')
        ->orderBy('address')
        ->get();
    $specificRows = $areas->map(fn (Area $area) => [
        'id' => $area->id,
        'name' => $area->address,
        'address' => $area->address,
        'area_type' => 'Specific Area',
        'barangay' => $area->barangay?->name,
        'barangay_id' => $area->barangay_id,
        'municipality' => $area->barangay?->municipality?->name,
        'municipality_id' => $area->barangay?->municipality_id,
        'province' => $area->barangay?->municipality?->province,
        'region' => $area->barangay?->municipality?->region,
        'associations_count' => (int) $area->associations_count,
        'source' => ucfirst($area->source),
        'status' => $area->is_active ? 'Active' : 'Inactive',
        'created_at' => $area->created_at?->toIso8601String(),
    ]);
    $module['rows'] = collect($module['rows'])->concat($specificRows)
        ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();

    return view('dashboard.index', [
        'catalog' => $catalog,
        'module' => $module,
    ]);
}

/**
 * @param Collection<int, Municipality> $municipalities
 * @param Collection<int, Barangay> $barangays
 */private function setOptions(
    array &$catalog,
    Collection $municipalities,
    Collection $barangays
): void
{
    $catalog['options']['municipality'] = $municipalities->pluck('name')->values()->all();
    $catalog['options']['municipality_records'] = $municipalities
        ->where('is_active', true)
        ->map(fn (Municipality $municipality) => [
            'value' => $municipality->id,
            'label' => $municipality->name,
        ])
        ->values()
        ->all();
    $catalog['options']['barangay_records'] = $barangays
        ->where('is_active', true)
        ->map(fn (Barangay $barangay) => [
            'value' => $barangay->id,
            'label' => $barangay->name,
            'municipality_id' => $barangay->municipality_id,
        ])
        ->values()
        ->all();
}

private function getAssociationCounts(): array
{
    $counts = Association::query()
        ->join('areas', 'associations.area_id', '=', 'areas.id')
        ->join('barangays', 'areas.barangay_id', '=', 'barangays.id')
        ->select('barangays.id as barangay_id', 'barangays.municipality_id')
        ->selectRaw('COUNT(*) as association_count')
        ->groupBy('barangays.id', 'barangays.municipality_id')
        ->get();

    $barangayCounts = [];
    $municipalityCounts = [];
    foreach ($counts as $count) {
        $barangayCounts[$count->barangay_id] = (int) $count->association_count;
        $municipalityCounts[$count->municipality_id] = ($municipalityCounts[$count->municipality_id] ?? 0)
            + (int) $count->association_count;
    }

    return ['barangays' => $barangayCounts, 'municipalities' => $municipalityCounts];
}

/**
 * @param Collection<int, Municipality> $municipalities
 * @param Collection<int, Barangay> $barangays
 * @param array<string, array<string, int>> $counts
 */
private function getAreaRows(
    Collection $municipalities,
    Collection $barangays,
    array $counts
): array
{
    $rows = $municipalities->map(fn (Municipality $municipality) => [
        'id' => $municipality->id,
        'name' => $municipality->name,
        'created_at' => $municipality->created_at?->toIso8601String(),
        'area_type' => $municipality->locality_type === 'city' ? 'City' : 'Municipality',
        'municipality' => $municipality->name,
        'province' => $municipality->province,
        'region' => $municipality->region,
        'update_url' => route('admin.municipalities.update', $municipality),
        'deactivate_url' => route('admin.municipalities.deactivate', $municipality),
        'associations_count' => (int) ($counts['municipalities'][$municipality->id] ?? 0),
        'psgc_code' => $municipality->psgc_code,
        'source' => $municipality->source === 'psgc' ? 'PSGC' : 'Manual',
        'status' => $municipality->is_active ? 'Active' : 'Inactive',
    ]);

    $rows = $rows->concat($barangays->map(fn (Barangay $barangay) => [
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
        'associations_count' => (int) ($counts['barangays'][$barangay->id] ?? 0),
        'psgc_code' => $barangay->psgc_code,
        'source' => $barangay->source === 'psgc' ? 'PSGC' : 'Manual',
        'status' => $barangay->is_active ? 'Active' : 'Inactive',
    ]));

    return $rows->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
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
