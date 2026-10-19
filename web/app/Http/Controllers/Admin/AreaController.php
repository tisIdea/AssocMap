<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAreaRequest;
use App\Services\AreaService;
use Illuminate\Http\RedirectResponse;

class AreaController extends Controller
{
    public function store(
        StoreAreaRequest $request,
        AreaService $areaService
    ): RedirectResponse {
        $currentUser = $request->attributes->get('current_user');

        $areaService->create(
            $request->validated(),
            $currentUser
        );

        return redirect()
            ->route('admin.areas.index')
            ->with('success', 'Area added successfully.');
    }
}