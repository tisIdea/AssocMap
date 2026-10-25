<?php

namespace App\Http;

use App\Http\Requests\StoreMonitoringVisitReportRequest;
use App\Models\MonitoringRecord;
use App\Models\MonitoringVisitReport;
use App\Services\MonitoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class MonitoringVisitReportActions
{
    public function index(
        Request $request,
        MonitoringRecord $monitoringRecord,
        MonitoringService $service
    ): JsonResponse {
        return response()->json([
            'data' => $service->getVisitReports($monitoringRecord, $request->attributes->get('current_user')),
        ]);
    }

    public function store(
        StoreMonitoringVisitReportRequest $request,
        MonitoringRecord $monitoringRecord,
        MonitoringService $service,
        string $redirectRoute
    ): JsonResponse|RedirectResponse {
        $service->createVisitReport(
            $monitoringRecord,
            $request->validated(),
            $request->attributes->get('current_user')
        );

        if ($request->expectsJson()) {
            return response()->json([
                'data' => $service->getVisitReports($monitoringRecord, $request->attributes->get('current_user')),
            ], 201);
        }

        return redirect()->route($redirectRoute)
            ->with('success', 'Monitoring visit report created successfully.');
    }

    public function update(
        StoreMonitoringVisitReportRequest $request,
        MonitoringRecord $monitoringRecord,
        MonitoringVisitReport $visitReport,
        MonitoringService $service,
        string $redirectRoute
    ): JsonResponse|RedirectResponse {
        $service->updateVisitReport(
            $monitoringRecord,
            $visitReport->id,
            $request->validated(),
            $request->attributes->get('current_user')
        );

        if ($request->expectsJson()) {
            return response()->json([
                'data' => $service->getVisitReports($monitoringRecord, $request->attributes->get('current_user')),
            ]);
        }

        return redirect()->route($redirectRoute)
            ->with('success', 'Monitoring visit report updated successfully.');
    }
}
