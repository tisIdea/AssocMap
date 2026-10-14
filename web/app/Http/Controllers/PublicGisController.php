<?php

namespace App\Http\Controllers;

use App\Http\Requests\GisParentRequest;
use App\Http\Requests\GisQueryRequest;
use App\Services\GisQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class PublicGisController extends Controller
{
    public function index(): View
    {
        return view('public.gis', ['gisPublic' => true, 'gisManager' => false, 'gisBaseUrl' => route('public.gis'),
            'gisTitle' => 'Public GIS Map', 'gisDescription' => 'Explore approved project locations and boundaries.']);
    }

    public function data(GisQueryRequest $request, GisQueryService $gis): JsonResponse
    {
        return response()->json($gis->features(null, $request->validated()));
    }

    public function associations(GisParentRequest $request, GisQueryService $gis): JsonResponse
    {
        abort_unless($request->validated('kind') === 'association', 422);

        return response()->json($gis->publicAssociations($request->safe()->only(['q', 'selected'])));
    }
}
