<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ApiRequestGuard;
use App\Services\DashboardService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class DashboardController extends Controller
{
    public function index(Request $request, DashboardService $service): Response
    {
        $role = $request->attributes->get('current_user')?->role?->code;
        $prefix = match ($role) {
            'system_admin' => 'admin', 'field_officer' => 'field-officer', 'association_account' => 'association',
            default => abort(403),
        };
        $layout = 'layouts.'.$prefix;
        $dashboard = null;
        $state = 'ready';
        $status = 200;
        try {
            $dashboard = $service->load($request);
        } catch (QueryException $exception) {
            Log::warning('Dashboard unavailable.', ['code' => 'dashboard_database_unavailable']);
            $state = 'unavailable';
            $status = 503;
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 403) throw $exception;
            $state = 'denied';
            $status = 403;
        }

        return ApiRequestGuard::secure(response()->view('dashboard.page', compact('dashboard', 'role', 'prefix', 'layout', 'state'), $status));
    }

    public function data(Request $request, DashboardService $service): JsonResponse
    {
        abort_unless($request->attributes->get('current_user')?->role?->code === 'association_account', 403);

        return ApiRequestGuard::secure(response()->json(['data' => $service->load($request)]));
    }
}
