<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Admin\MonitoringController;
use App\Http\Requests\StoreMonitoringVisitReportRequest;
use App\Http\Requests\UpdateMonitoringSectionRequest;
use App\Models\AssociationAccount;
use App\Models\MonitoringRecord;
use App\Models\Project;
use App\Models\Quarter;
use App\Models\UserProfile;
use App\Services\AssociationLifecycleService;
use App\Services\MonitoringService;
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
            abort_unless($account, 403, 'No active association relationship is available.');
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
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:255']]);
        $search = trim($filters['search'] ?? '');
        $query = MonitoringRecord::query()->whereIn('project_id', $this->projectsFor($request)->select('id'));
        $years = (clone $query)->select('year')->distinct()->orderByDesc('year')->pluck('year');
        $selectedYear = $years->contains((int) $request->query('year')) ? (int) $request->query('year') : $years->first();
        $records = $selectedYear === null ? collect() : $query->where('year', $selectedYear)
            ->with(['project.association', 'quarter', 'monitoredBy'])->orderByDesc('created_at')->get();
        if ($search !== '') {
            $records = $records->filter(fn ($record) => str_contains(mb_strtolower(implode(' ', [
                $record->project?->title, $record->project?->association?->name, $record->year,
                'Q'.$record->quarter?->quarter_number, $record->monitoredBy?->display_name,
                $record->created_at?->format('M d, Y'), $record->project_status,
            ])), mb_strtolower($search)));
        }
        return view('monitoring.dashboard', $this->context($request) + compact('records', 'years', 'selectedYear', 'search'));
    }

    public function workspace(Request $request, MonitoringRecord $monitoringRecord, string $section = 'overview'): View
    {
        abort_unless(in_array($section, ['overview', 'production', 'income', 'materials'], true), 404);
        $record = $this->accessibleRecord($request, $monitoringRecord);
        if ($section === 'production') $record->load(['production.projectProduct', 'production.monthlyDetails']);
        if ($section === 'income') $record->load(['production.projectProduct', 'production.income']);
        if ($section === 'materials') $record->load('materials.projectMaterial');
        return view('monitoring.workspace', $this->context($request, $record) + compact('record', 'section'));
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
        $monitoringRows = $record ? collect([$this->mapMonitoringRecord($record)]) : collect();
        $projectRows = $projects->map(fn ($project) => $this->mapProject($project));
        $occupiedPeriods = $record ? collect() : MonitoringRecord::query()
            ->whereIn('project_id', $projects->modelKeys())->get(['project_id', 'year', 'quarter_id']);
        $quarters = Quarter::orderBy('quarter_number')->get();
        return view('monitoring.editor', $this->context($request, $record) + compact('projects', 'projectRows', 'quarters', 'monitoringRows', 'occupiedPeriods', 'section', 'record'));
    }

    public function saveSection(UpdateMonitoringSectionRequest $request, MonitoringRecord $monitoringRecord, string $section, MonitoringService $service): RedirectResponse
    {
        $record = $this->accessibleRecord($request, $monitoringRecord, true);
        $service->updateSection($record, $section, $request->validated(), $request->attributes->get('current_user'));
        return redirect()->route($this->context($request)['monitoringPrefix'].'.workspace', [$record, $section])
            ->with('success', ucfirst($section).' updated.');
    }

    public function visits(Request $request, MonitoringRecord $monitoringRecord): View
    {
        $record = $this->accessibleRecord($request, $monitoringRecord);
        $reports = $record->visitReports()->with('conductedByUser:id,display_name')->get();
        return view('monitoring.visits', $this->context($request, $record) + compact('record', 'reports'));
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
        $record = $this->accessibleRecord($request, $monitoringRecord, true);
        if ($reportId) {
            $record->visitReports()->whereKey($reportId)->firstOrFail();
            $service->updateVisitReport($record, $reportId, $request->validated(), $request->attributes->get('current_user'));
        } else {
            $service->createVisitReport($record, $request->validated(), $request->attributes->get('current_user'));
        }
        return redirect()->route($this->context($request)['monitoringPrefix'].'.visits', $record)
            ->with('success', 'Visit Report saved.');
    }
}
