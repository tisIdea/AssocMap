<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Admin\MonitoringController;
use App\Http\Requests\MonitoringIndexRequest;
use App\Http\Requests\StoreMonitoringVisitReportRequest;
use App\Http\Requests\UpdateMonitoringSectionRequest;
use App\Models\AssociationAccount;
use App\Models\MonitoringRecord;
use App\Models\Project;
use App\Models\Quarter;
use App\Models\UserProfile;
use App\Services\AssociationLifecycleService;
use App\Services\IncomeMonitoringService;
use App\Services\MaterialsMonitoringService;
use App\Services\MonitoringDirectoryQueryService;
use App\Services\MonitoringService;
use App\Services\ProductionMonitoringService;
use App\Services\DailyHarvestMonitoringService;
use App\Services\ProjectLifecycleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Illuminate\Validation\ValidationException;

class MonitoringWorkspaceController extends MonitoringController
{
    private function context(Request $request, ?MonitoringRecord $record = null): array
    {
        $role = $request->attributes->get('current_user')?->role?->code;
        abort_unless(in_array($role, ['system_admin', 'field_officer', 'association_account'], true), 403);
        $association = $record?->project?->association;
        $operational = $record === null || ($record->project?->archived_at === null
            && !($record->project?->isFinalized() ?? true) && $association
            && app(AssociationLifecycleService::class)->isOperational($association));
        return [
            'monitoringPrefix' => match ($role) {
                'system_admin' => 'admin.monitoring',
                'field_officer' => 'field-officer.monitoring',
                default => 'association.monitoring',
            },
            'monitoringLayout' => match ($role) {
                'system_admin' => 'layouts.admin',
                'field_officer' => 'layouts.field-officer',
                default => 'layouts.association',
            },
            'canManage' => $role !== 'association_account' && $operational,
        ];
    }

    private function projectsFor(Request $request): Builder
    {
        $user = $request->attributes->get('current_user');
        $role = $user?->role?->code;
        $this->context($request);
        $query = Project::query();
        if ($role === 'field_officer') {
            $query->whereNull('archived_at')->whereHas('association', fn ($association) => $association
                ->whereNull('archived_at')->where('status', 'active')->where('primary_field_officer_id', $user->id));
        } elseif ($role === 'association_account') {
            $account = AssociationAccount::query()->where('user_id', $user->id)->where('is_active', true)
                ->has('association')->first();
            if ($account === null) {
                abort(403, 'No active association relationship is available.');
            }
            $query->where('association_id', $account->association_id);
        }
        return $query;
    }

    private function accessibleRecord(Request $request, MonitoringRecord $record, bool $write = false): MonitoringRecord
    {
        $context = $this->context($request);
        abort_if($write && ! $context['canManage'], 403);
        abort_unless($this->projectsFor($request)->whereKey($record->project_id)->exists(), 404);
        $record->load(['project.association', 'quarter', 'monitoredBy']);
        if ($write) {
            abort_unless($record->project?->association, 404);
            app(AssociationLifecycleService::class)->assertOperational($record->project->association);
            app(ProjectLifecycleService::class)->assertNotFinalized($record->project);
            if ($record->project->archived_at !== null) {
                throw ValidationException::withMessages(['project_id' => 'Archived projects are read-only.']);
            }
        }
        return $record;
    }

    public function index(Request $request): View
    {
        $input = app(MonitoringIndexRequest::class);
        $filters = $input->filters();
        $directory = app(MonitoringDirectoryQueryService::class)->records($this->projectsFor($request), $filters);

        return view('monitoring.dashboard', $this->context($request) + $directory + [
            'filters' => $filters, 'sortOptions' => $input->sortOptions(),
        ]);
    }

    public function workspace(Request $request, MonitoringRecord $monitoringRecord, string $section = 'overview'): View
    {
        abort_unless(in_array($section, ['overview', 'production', 'income', 'materials'], true), 404);
        $record = $this->accessibleRecord($request, $monitoringRecord);
        $monthlyReconciled = [];
        $harvestProducts = collect();
        $productionRevision = null;
        if ($section === 'production') {
            $record->load(['production.projectProduct', 'production.monthlyDetails']);
            $productionService = app(ProductionMonitoringService::class);
            foreach ($record->production as $production) {
                $production->setRelation('monitoringRecord', $record);
                $monthlyReconciled[$production->id] = $productionService->canEditMonthly($production);
            }
            $harvestProducts = $record->project->products()->where('is_active', true)
                ->whereNotIn('id', $record->production->pluck('project_product_id'))->orderBy('product_name')->get();
            $productionRevision = $productionService->revision($record);
        }
        if ($section === 'income') $record->load(['production.projectProduct', 'production.income']);
        if ($section === 'materials') $record->load('materials.projectMaterial');
        return view('monitoring.workspace', $this->context($request, $record)
            + compact('record', 'section', 'monthlyReconciled', 'harvestProducts', 'productionRevision'));
    }

    public function harvestPage(Request $request, MonitoringRecord $monitoringRecord, string $productionId, ?string $entryId = null): View
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $monitoringRecord, $productionId, $entryId) {
            // Keep entry values, summaries and the revision in one snapshot of application writes.
            $monitoringRecord = MonitoringRecord::whereKey($monitoringRecord->id)->sharedLock()->firstOrFail();
            $record = $this->accessibleRecord($request, $monitoringRecord, $entryId !== null);
            $production = $record->production()->whereKey($productionId)->where('quantity_source', 'daily_harvest')
                ->with(['projectProduct', 'monthlyDetails'])->firstOrFail();
            $editing = $entryId ? $production->harvestEntries()->whereKey($entryId)->whereNull('voided_at')->firstOrFail() : null;
            $entries = $production->harvestEntries()->paginate(25);
            $calculator = app(ProductionMonitoringService::class);
            $productionRevision = $calculator->revision($record);
            $activeCount = $production->monthlyDetails->sum('activity_count');
            $attainment = $calculator->goalAttainment($production, $activeCount);
            $start = \Carbon\CarbonImmutable::create((int) $record->year,
                (((int) $record->quarter->quarter_number - 1) * 3) + 1, 1, 0, 0, 0, 'Asia/Manila');
            $today = \Carbon\CarbonImmutable::now('Asia/Manila')->toDateString();
            $minDate = $start->toDateString();
            $endDate = $start->addMonths(3)->subDay()->toDateString();
            $maxDate = min($endDate, $today);
            $ongoing = $endDate >= $today;
            return view('monitoring.harvest', $this->context($request, $record)
                + compact('record', 'production', 'entries', 'editing', 'productionRevision', 'activeCount',
                    'attainment', 'minDate', 'maxDate', 'ongoing') + ['section' => 'production']);
        });
    }

    public function initializeHarvest(Request $request, MonitoringRecord $monitoringRecord, DailyHarvestMonitoringService $service): RedirectResponse
    {
        $record = $this->accessibleRecord($request, $monitoringRecord, true);
        $production = $service->initialize($record, $request);
        return redirect()->route($this->context($request)['monitoringPrefix'].'.harvest.index', [$record, $production])
            ->with('success', 'Daily harvest initialized. No harvest records have been submitted.');
    }

    public function saveHarvest(Request $request, MonitoringRecord $monitoringRecord, string $productionId,
        DailyHarvestMonitoringService $service, ?string $entryId = null): RedirectResponse
    {
        $record = $this->accessibleRecord($request, $monitoringRecord, true);
        $service->saveHarvest($record, $productionId, $request, $entryId);
        return redirect()->route($this->context($request)['monitoringPrefix'].'.harvest.index', [$record, $productionId])
            ->with('success', 'Daily harvest saved and recorded totals recalculated.');
    }

    public function voidHarvest(Request $request, MonitoringRecord $monitoringRecord, string $productionId, string $entryId,
        DailyHarvestMonitoringService $service): RedirectResponse
    {
        $record = $this->accessibleRecord($request, $monitoringRecord, true);
        $service->voidHarvest($record, $productionId, $entryId, $request);
        return redirect()->route($this->context($request)['monitoringPrefix'].'.harvest.index', [$record, $productionId])
            ->with('success', 'Harvest voided. History retained and recorded totals recalculated.');
    }

    public function saveHarvestGoal(Request $request, MonitoringRecord $monitoringRecord, string $productionId,
        DailyHarvestMonitoringService $service): RedirectResponse
    {
        $record = $this->accessibleRecord($request, $monitoringRecord, true);
        $service->reviseGoal($record, $productionId, $request);
        return redirect()->route($this->context($request)['monitoringPrefix'].'.harvest.index', [$record, $productionId])
            ->with('success', 'Quarterly production goal saved with its revision reason.');
    }

    public function editOverview(Request $request, MonitoringRecord $monitoringRecord): View
    {
        $record = $this->accessibleRecord($request, $monitoringRecord, true);
        $users = UserProfile::query()->where('is_active', true)->whereHas('role', fn ($role) => $role
            ->where('is_active', true)->where('code', 'system_admin'))->get(['id', 'display_name']);
        $officer = UserProfile::query()->whereKey($record->project->association?->primary_field_officer_id)
            ->where('is_active', true)->whereHas('role', fn ($role) => $role->where('is_active', true)
                ->where('code', 'field_officer'))->first(['id', 'display_name']);
        if ($officer) $users->push($officer);
        return view('monitoring.edit-overview', $this->context($request, $record) + compact('record', 'users'));
    }

    public function saveOverview(Request $request, MonitoringRecord $monitoringRecord, MonitoringService $service): RedirectResponse
    {
        $record = $this->accessibleRecord($request, $monitoringRecord, true);
        $data = $request->validate(['monitored_by' => ['nullable', 'uuid'], 'site_validated' => ['required', 'boolean']]);
        $service->updateOverview($record, $data, $request->attributes->get('current_user'));
        return redirect()->route($this->context($request)['monitoringPrefix'].'.workspace', $record)
            ->with('success', 'Quarterly overview updated.');
    }

    public function createPage(Request $request): View
    {
        abort_unless($this->context($request)['canManage'], 403);
        return $this->editorPage($request);
    }

    public function editSection(Request $request, MonitoringRecord $monitoringRecord, string $section): View
    {
        abort_unless(in_array($section, ['production', 'income', 'materials'], true), 404);
        $record = $this->accessibleRecord($request, $monitoringRecord, true);
        return $this->editorPage($request, $record, $section);
    }

    private function editorPage(Request $request, ?MonitoringRecord $record = null, ?string $section = null): View
    {
        $projects = $this->projectsFor($request)->whereNull('archived_at')
            ->whereNotIn('status', ['completed', 'terminated'])
            ->when($record, fn ($query) => $query->whereKey($record->project_id))
            ->with(['association.primaryFieldOfficer.role', 'products', 'materials'])->orderBy('title')->get()
            ->filter(fn ($project) => $project->closed_at === null && $project->association
                && app(AssociationLifecycleService::class)->isOperational($project->association))->values();
        if ($record) {
            abort_unless($projects->contains('id', $record->project_id), 404);
            $record->setRelation('visitReports', collect());
            if ($section === 'materials') {
                $record->load('materials.projectMaterial');
                $record->setRelation('production', collect());
            } else {
                $record->setRelation('materials', collect());
                $record->load(['production.projectProduct', $section === 'income' ? 'production.income' : 'production.monthlyDetails']);
                foreach ($record->production as $production) {
                    $production->setRelation($section === 'income' ? 'monthlyDetails' : 'income', collect());
                }
            }
        }
        $mapped = $record ? $this->mapMonitoringRecord($record) : null;
        if ($record && $section === 'income') $mapped['income_revision'] = app(IncomeMonitoringService::class)->revision($record);
        if ($record && $section === 'materials') $mapped['materials_revision'] = app(MaterialsMonitoringService::class)->revision($record);
        $monitoringRows = $mapped ? collect([$mapped]) : collect();
        $projectRows = $projects->map(fn ($project) => $this->mapProject($project));
        $occupiedPeriods = $record ? collect() : MonitoringRecord::query()
            ->whereIn('project_id', $projects->modelKeys())->get(['project_id', 'year', 'quarter_id']);
        $quarters = Quarter::orderBy('quarter_number')->get();
        return view('monitoring.editor', $this->context($request, $record) + compact('projects', 'projectRows', 'quarters', 'monitoringRows', 'occupiedPeriods', 'section', 'record'));
    }

    public function saveSection(UpdateMonitoringSectionRequest $request, MonitoringRecord $monitoringRecord, string $section, MonitoringService $service): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $record = $this->accessibleRecord($request, $monitoringRecord, true);
        $service->updateSection($record, $section, $request->validated(), $request->attributes->get('current_user'));
        $destination = route($this->context($request)['monitoringPrefix'].'.workspace', [$record, $section]);
        if (in_array($section, ['income', 'materials'], true) && $request->expectsJson()) {
            $request->session()->flash('success', ucfirst($section).' updated.');
            return response()->json(['redirect' => $destination]);
        }
        return redirect()->to($destination)
            ->with('success', ucfirst($section).' updated.');
    }

    public function visits(MonitoringIndexRequest $request, MonitoringRecord $monitoringRecord): View
    {
        $record = $this->accessibleRecord($request, $monitoringRecord);
        $filters = $request->filters();
        $reports = app(MonitoringDirectoryQueryService::class)->visits($record, $filters);

        return view('monitoring.visits', $this->context($request, $record) + compact('record', 'reports', 'filters') + [
            'sortOptions' => $request->sortOptions(),
        ]);
    }

    public function visitPage(Request $request, MonitoringRecord $monitoringRecord, ?string $reportId = null): View|RedirectResponse
    {
        $routeName = $request->route()->getName();
        $mode = str_ends_with($routeName, '.create') ? 'create' : (str_ends_with($routeName, '.edit') ? 'edit' : 'view');
        $record = $this->accessibleRecord($request, $monitoringRecord, $mode !== 'view');
        $report = $reportId ? $record->visitReports()->with('conductedByUser')->whereKey($reportId)->firstOrFail() : null;
        abort_if($mode === 'view' && ! $report, 404);
        if ($mode === 'view') {
            return redirect()->route($this->context($request)['monitoringPrefix'].'.visits', $record);
        }
        $quarterStart = \Carbon\CarbonImmutable::create((int) $record->year, (((int) $record->quarter->quarter_number - 1) * 3) + 1, 1)->startOfDay();
        $minVisitDate = $quarterStart->format('Y-m-d');
        $maxVisitDate = $quarterStart->addMonths(3)->subDay()->format('Y-m-d');
        $historicalDate = $report?->monitoring_date?->format('Y-m-d');
        if ($historicalDate && $historicalDate >= $minVisitDate && $historicalDate <= $maxVisitDate) $historicalDate = null;
        $officer = $record->project->association?->primaryFieldOfficer;
        if (! $officer?->is_active || ! $officer->role?->is_active || $officer->role?->code !== 'field_officer') $officer = null;
        return view('monitoring.visit', $this->context($request, $record) + compact('record', 'report', 'mode', 'officer', 'minVisitDate', 'maxVisitDate', 'historicalDate'));
    }

    public function saveVisit(StoreMonitoringVisitReportRequest $request, MonitoringRecord $monitoringRecord, MonitoringService $service, ?string $reportId = null): RedirectResponse
    {
        try {
            $record = $this->accessibleRecord($request, $monitoringRecord, true);
            if ($reportId) {
                $record->visitReports()->whereKey($reportId)->firstOrFail();
                $service->updateVisitReport($record, $reportId, $request->validated(), $request->attributes->get('current_user'));
            } else {
                $service->createVisitReport($record, $request->validated(), $request->attributes->get('current_user'));
            }
        } catch (ValidationException $exception) {
            throw $exception->errorBag('monitoringVisit');
        }
        return redirect()->route($this->context($request)['monitoringPrefix'].'.visits', $record)
            ->with('success', 'Visit Report saved.');
    }
}
