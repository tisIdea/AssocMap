<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMunicipalityRequest;
use App\Models\Municipality;
use App\Services\MunicipalityService;
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

    $catalog['options']['municipality'] = $municipalities
        ->pluck('name')
        ->values()
        ->all();

    $module = $catalog['modules']['areas'];

    $module['rows'] = $municipalities
        ->map(function (Municipality $municipality) {
            return [
                'id' => $municipality->id,
                'name' => $municipality->name,
                'area_type' => 'Municipality',
                'municipality' => $municipality->name,
                'psgc_code' => $municipality->psgc_code ?? '—',
                'source' => $municipality->source === 'psgc'
                    ? 'PSGC'
                    : 'Manual',
                'status' => $municipality->is_active
                    ? 'Active'
                    : 'Inactive',
            ];
        })
        ->values()
        ->all();

    return view('admin.areas.index', [
        'catalog' => $catalog,
        'module' => $module,
    ]);
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