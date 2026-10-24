<?php

namespace App\Http\Controllers;

use App\Exceptions\ReportDatasetLimitException;
use App\Exceptions\ReportingUnavailableException;
use App\Http\Requests\ReportRequest;
use App\Services\Reporting\ReportCatalog;
use App\Services\Reporting\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ReportController extends Controller
{
    public function index(ReportRequest $request, ReportService $service): Response
    {
        $filters = $request->filters();
        $options = $service->options($request, $filters);
        $result = null;
        $unavailable = false;
        $sizeLimitMessage = null;
        $status = 200;
        try {
            $result = $service->generate($request, $filters)['result'];
        } catch (ReportDatasetLimitException $exception) {
            if ($request->expectsJson()) throw $exception;
            $options = $service->options($request, $filters);
            $sizeLimitMessage = 'This report is too large. Narrow the available filters or choose another report type, then apply the filters again.';
            $status = 422;
        } catch (ReportingUnavailableException $exception) {
            Log::warning('Reporting engine unavailable.', array_merge([
                'code' => 'reporting_engine_unavailable', 'stage' => $request->attributes->get('reporting_stage', 'request'),
            ], $exception->diagnostics()));
            $unavailable = true;
        }
        $role = $request->attributes->get('current_user')->role->code;
        $prefix = match ($role) {
            'system_admin' => 'admin.reports', 'field_officer' => 'field-officer.reports', default => 'association.reports',
        };
        $layout = match ($role) {
            'system_admin' => 'layouts.admin', 'field_officer' => 'layouts.field-officer', default => 'layouts.association',
        };
        $types = ReportCatalog::TYPES;
        $filterMap = array_map(fn ($type) => ReportCatalog::filters($type), array_combine(array_keys($types), array_keys($types)));
        $canExport = $role !== 'association_account';

        return response()->view('reports.page', compact('filters', 'options', 'result', 'unavailable', 'sizeLimitMessage',
            'role', 'prefix', 'layout', 'types', 'filterMap', 'canExport'), $status);
    }

    public function data(ReportRequest $request, ReportService $service): JsonResponse
    {
        return response()->json(['data' => $service->generate($request, $request->filters())['result']]);
    }

    public function options(ReportRequest $request, ReportService $service): JsonResponse
    {
        return response()->json(['data' => $service->options($request, $request->filters())]);
    }

    public function export(ReportRequest $request, ReportService $service, string $format): Response
    {
        $filters = $request->filters();
        $generated = $service->generate($request, $filters, $format);
        $mime = $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        $name = 'assocmap-'.$filters['report_type'].'-'.now('Asia/Manila')->format('Ymd-His').'.'.$format;

        return response($generated['artifact'], 200, [
            'Content-Type' => $mime, 'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Content-Length' => (string) strlen($generated['artifact']),
            'Cache-Control' => 'no-store, private, max-age=0', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
