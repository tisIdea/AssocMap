<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBarangayRequest;
use App\Http\Requests\AreaIndexRequest;
use App\Services\AreaManagementQueryService;
use App\Services\BarangayService;
use Illuminate\Http\RedirectResponse;
use App\Models\Barangay;
use Illuminate\Database\QueryException;

class BarangayController extends Controller
{
    public function show(AreaIndexRequest $request, Barangay $barangay, AreaManagementQueryService $query): \Illuminate\Http\Response
    {
        $details = $query->barangayDetails($barangay, $request->validated());
        $catalog = $query->editorCatalog($details['record']);
        $areaCreateTypes = ['Barangay', 'Specific Area'];
        $editorRecord = $query->editorRecord($details['record']);
        $backUrl = route('admin.areas.index', array_replace($request->filters(), ['tab' => 'barangays', 'page' => $request->validated('page')]));

        return response()->view('admin.areas.barangay', $details + compact('catalog', 'areaCreateTypes', 'editorRecord', 'backUrl'))
            ->header('Cache-Control', 'no-store, private');
    }

    public function update(StoreBarangayRequest $request, Barangay $barangay, BarangayService $service): RedirectResponse
    {
        try {
            $service->update($barangay, $request->validated());
        } catch (QueryException $exception) {
            report($exception);
            return back()->withInput()->with('error', 'Unable to update the area. Check for duplicate values and try again.');
        }

        return redirect()->route('admin.areas.index')->with('success', 'Area updated successfully.');
    }

    public function deactivate(Barangay $barangay, BarangayService $service): RedirectResponse
    {
        try {
            $service->deactivate($barangay);
        } catch (QueryException $exception) {
            report($exception);
            return back()->with('error', 'Unable to deactivate the area. Please try again.');
        }

        return redirect()->route('admin.areas.index')->with('success', 'Area deactivated successfully.');
    }

    public function store(
        StoreBarangayRequest $request,
        BarangayService $barangayService
    ): RedirectResponse {
        $currentUser = $request->attributes->get('current_user');

        $barangayService->create(
            $request->validated(),
            $currentUser
        );

        return redirect()
            ->route('admin.areas.index')
            ->with('success', 'Barangay added successfully.');
    }
}
