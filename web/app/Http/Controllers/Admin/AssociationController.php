<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAssociationRequest;
use App\Models\Area;
use App\Models\Association;
use App\Models\UserProfile;
use App\Services\AssociationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AssociationController extends Controller
{
    public function index(Request $request): View
    {
        $catalog = require resource_path('ui/admin.php');
        $statusFilter = $request->query('status', '');
        $statusFilter = in_array($statusFilter, ['active', 'inactive', 'archived'], true) ? $statusFilter : '';

        $associations = Association::query()
            ->with([
                'area.barangay.municipality',
                'primaryFieldOfficer',
            ])
            ->when($statusFilter === 'archived',
                fn ($query) => $query->whereNotNull('archived_at'),
                fn ($query) => $query->whereNull('archived_at'))
            ->when(in_array($statusFilter, ['active', 'inactive'], true),
                fn ($query) => $query->where('status', $statusFilter))
            ->orderBy('name')
            ->get();

        $areas = Area::query()
            ->with('barangay.municipality')
            ->where('is_active', true)
            ->orderBy('address')
            ->get();

        $fieldOfficers = UserProfile::query()
            ->where('is_active', true)
            ->whereHas('role', function ($query) {
                $query->where('code', 'field_officer');
            })
            ->orderBy('display_name')
            ->get();

        $catalog['options']['area_records'] = $areas
            ->map(function (Area $area) {
                return [
                    'value' => $area->id,
                    'label' => $area->address,
                    'barangay' => $area->barangay?->name,
                    'barangay_id' => $area->barangay_id,
                    'municipality' => $area->barangay?->municipality?->name,
                    'municipality_id' => $area->barangay?->municipality_id,
                ];
            })
            ->values()
            ->all();

        $catalog['options']['field_officer_records'] = $fieldOfficers
            ->map(function (UserProfile $user) {
                return [
                    'value' => $user->id,
                    'label' => $user->display_name,
                ];
            })
            ->values()
            ->all();

        $module = $catalog['modules']['associations'];
        $module['status_filter'] = $statusFilter;

        $module['rows'] = $associations
            ->map(function (Association $association) {
                return [
                    'id' => $association->id,

                    'name' => $association->name,
                    'acronym' => $association->acronym ?? '—',

                    'area' => $association->area?->address ?? '—',
                    'area_id' => $association->area_id,

                    'barangay' =>
                        $association->area?->barangay?->name ?? '—',

                    'barangay_id' =>
                        $association->area?->barangay_id,

                    'municipality' =>
                        $association->area?->barangay?->municipality?->name ?? '—',

                    'municipality_id' =>
                        $association->area?->barangay?->municipality_id,

                    'field_officer' =>
                        $association->primaryFieldOfficer?->display_name ?? '—',

                    'primary_field_officer_id' =>
                        $association->primary_field_officer_id,

                    'joined_at' => $association->saad_phase2_join_date
                        ? \Illuminate\Support\Carbon::parse($association->saad_phase2_join_date)->format('Y-m-d')
                        : null,

                    'status' =>
                        $association->status === 'active'
                            ? 'Active'
                            : 'Inactive',

                    'status_value' => $association->status,
                    'archived' => $association->archived_at !== null ? 'Yes' : 'No',
                    'archived_at' => $association->archived_at?->toIso8601String(),

                    'update_url' =>
                        route('admin.associations.update', $association),

                    'archive_url' =>
                        route('admin.associations.archive', $association),
                    'unarchive_url' =>
                        route('admin.associations.unarchive', $association),
                ];
            })
            ->values()
            ->all();

        return view('admin.associations.index', [
            'catalog' => $catalog,
            'module' => $module,
        ]);
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
