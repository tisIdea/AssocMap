<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\MonitoringVisitReportActions;
use App\Http\Requests\StoreMonitoringRequest;
use App\Http\Requests\StoreMonitoringVisitReportRequest;
use App\Models\MonitoringRecord;
use App\Models\MonitoringVisitReport;
use App\Models\Project;
use App\Models\Quarter;
use App\Models\UserProfile;
use App\Services\MonitoringService;
use App\Services\ProductionMonitoringService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Carbon;

class MonitoringController extends Controller
{
    private readonly MonitoringVisitReportActions $visitReports;

    public function __construct(MonitoringVisitReportActions $visitReports)
    {
        $this->visitReports = $visitReports;
    }

    public function index(Request $request): View
    {
        $records = MonitoringRecord::query()
            ->with([
                'project.association',
                'quarter',
                'monitoredBy',
                'production.projectProduct',
                'production.income',
                'production.monthlyDetails',
                'materials.projectMaterial',
                'visitReports.conductedByUser:id,display_name',
            ])
            ->orderByDesc('monitoring_date')
            ->orderByDesc('created_at')
            ->get();

        $projects = Project::query()
            ->with([
                'association.primaryFieldOfficer.role',
                'products',
                'materials',
            ])
            ->whereNull('archived_at')
            ->orderBy('title')
            ->get();

        $quarters = Quarter::query()
            ->orderBy('quarter_number')
            ->get();

        $monitoringRows = $records
            ->map(
                fn (MonitoringRecord $record) =>
                    $this->mapMonitoringRecord($record)
            )
            ->values();

        $projectRows = $projects
            ->map(
                fn (Project $project) =>
                    $this->mapProject($project)
            )
            ->values();

        return view(
            'admin.monitoring.index',
            compact(
                'records',
                'monitoringRows',
                'projects',
                'projectRows',
                'quarters'
            )
        );
    }

    public function store(
        StoreMonitoringRequest $request,
        MonitoringService $service
    ): RedirectResponse {
        /** @var UserProfile $currentUser */
        $currentUser =
            $request->attributes->get(
                'current_user'
            );

        $service->create(
            $request->validated(),
            $currentUser
        );

        return redirect()
            ->route('admin.monitoring.index')
            ->with(
                'success',
                'Monitoring record created successfully.'
            );
    }

    public function update(
        StoreMonitoringRequest $request,
        MonitoringRecord $monitoringRecord,
        MonitoringService $service
    ): RedirectResponse {
        /** @var UserProfile $currentUser */
        $currentUser =
            $request->attributes->get(
                'current_user'
            );

        $service->update(
            $monitoringRecord,
            $request->validated(),
            $currentUser
        );

        return redirect()
            ->route('admin.monitoring.index')
            ->with(
                'success',
                'Monitoring record updated successfully.'
            );
    }

    public function indexVisitReports(
        Request $request,
        MonitoringRecord $monitoringRecord,
        MonitoringService $service
    ): JsonResponse {
        return $this->visitReports->index($request, $monitoringRecord, $service);
    }

    public function storeVisitReport(
        StoreMonitoringVisitReportRequest $request,
        MonitoringRecord $monitoringRecord,
        MonitoringService $service
    ): JsonResponse|RedirectResponse {
        return $this->visitReports->store(
            $request,
            $monitoringRecord,
            $service,
            'admin.monitoring.index'
        );
    }

    public function updateVisitReport(
        StoreMonitoringVisitReportRequest $request,
        MonitoringRecord $monitoringRecord,
        MonitoringVisitReport $visitReport,
        MonitoringService $service
    ): JsonResponse|RedirectResponse {
        return $this->visitReports->update(
            $request,
            $monitoringRecord,
            $visitReport,
            $service,
            'admin.monitoring.index'
        );
    }

    protected function mapMonitoringRecord(
        MonitoringRecord $record
    ): array {
        foreach ($record->production as $production) $production->setRelation('monitoringRecord', $record);
        return [
            'production_revision' => app(ProductionMonitoringService::class)->revision($record),
            'id' =>
                $record->id,

            'project_id' =>
                $record->project_id,

            'project_title' =>
                $record->project?->title,

            'association_id' =>
                $record->project?->association_id,

            'association_name' =>
                $record->project
                    ?->association
                    ?->name,

            'year' =>
                $record->year,

            'quarter_id' =>
                $record->quarter_id,

            'quarter_name' =>
                $record->quarter?->name,

            'quarter_number' =>
                $record->quarter?->quarter_number,

            'monitoring_date' =>
                $record->monitoring_date
                    ? Carbon::parse(
                        $record->monitoring_date
                    )->format('Y-m-d')
                    : null,

            'monitored_by' =>
                $record->monitoredBy
                    ?->display_name
                ?? 'Unknown',

            'site_validated' =>
                (bool) $record->site_validated,

            'project_status' =>
                $record->project_status,

            'issues_encountered' =>
                $record->issues_encountered,

            'actions_taken' =>
                $record->actions_taken,

            'overall_remarks' =>
                $record->overall_remarks,

            'visit_reports' =>
                $record->visitReports->toArray(),

            'production' =>
                $record->production
                    ->map(
                        fn ($production) => [
                            'id' =>
                                $production->id,

                            'project_product_id' =>
                                $production
                                    ->project_product_id,

                            'product_name' =>
                                $production
                                    ->projectProduct
                                    ?->product_name,

                            'target_output' =>
                                $production
                                    ->target_output,

                            'actual_output' =>
                                $production
                                    ->actual_output,

                            'unit' =>
                                $production->unit,

                            'remarks' =>
                                $production->remarks,

                            'monthly_details' => $production->monthlyDetails->toArray(),
                            'can_edit_monthly' => app(ProductionMonitoringService::class)->canEditMonthly($production),
                            'quantity_source' => $production->quantity_source ?? 'aggregate',
                            'monthly_subtotal' => $production->monthlySubtotal(),
                            'income' =>
                                $production->income
                                    ->map(
                                        fn ($income) => [
                                            'id' =>
                                                $income->id,

                                            'period_type' =>
                                                $income
                                                    ->period_type,

                                            'month' =>
                                                $income->month,

                                            'gross_income' =>
                                                $income
                                                    ->gross_income,

                                            'remarks' =>
                                                $income
                                                    ->remarks,
                                        ]
                                    )
                                    ->values(),
                        ]
                    )
                    ->values(),

            'materials' =>
                $record->materials
                    ->map(
                        fn ($material) => [
                            'id' =>
                                $material->id,

                            'project_material_id' =>
                                $material
                                    ->project_material_id,

                            'item_name' =>
                                $material
                                    ->projectMaterial
                                    ?->item_name,

                            'condition' =>
                                $material->condition,

                            'scheduled_maintenance' =>
                                $material
                                    ->scheduled_maintenance
                                    ?->format('Y-m-d'),

                            'actual_maintenance' =>
                                $material
                                    ->actual_maintenance
                                    ?->format('Y-m-d'),

                            'remarks' =>
                                $material->remarks,
                        ]
                    )
                    ->values(),
        ];
    }

    protected function mapProject(
        Project $project
    ): array {
        return [
            'id' =>
                $project->id,

            'title' =>
                $project->title,

            'association_id' =>
                $project->association_id,

            'association_name' =>
                $project->association?->name,

            'assigned_officer' => $project->association?->primaryFieldOfficer?->is_active
                && $project->association?->primaryFieldOfficer?->role?->is_active
                && $project->association?->primaryFieldOfficer?->role?->code === 'field_officer'
                ? ['id' => $project->association->primaryFieldOfficer->id, 'name' => $project->association->primaryFieldOfficer->display_name]
                : null,

            'program_year' =>
                $project->program_year,

            'status' =>
                $project->status,

            'products' =>
                $project->products
                    ->map(
                        fn ($product) => [
                            'id' =>
                                $product->id,

                            'product_name' =>
                                $product->product_name,

                            'product_type' =>
                                $product->product_type,

                            'default_unit' =>
                                $product->default_unit,

                            'is_active' =>
                                (bool) $product->is_active,
                        ]
                    )
                    ->values(),

            'materials' =>
                $project->materials
                    ->map(
                        fn ($material) => [
                            'id' =>
                                $material->id,

                            'item_name' =>
                                $material->item_name,

                            'unit' =>
                                $material->unit,

                            'delivery_status' =>
                                $material
                                    ->delivery_status,
                        ]
                    )
                    ->values(),
        ];
    }
}
