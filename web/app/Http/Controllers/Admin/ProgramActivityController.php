<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProgramActivityRequest;
use App\Models\Association;
use App\Models\ProgramActivityRecord;
use App\Models\Project;
use App\Models\SaadActivity;
use App\Services\ProgramActivityService;
use App\Services\AssociationLifecycleService;
use App\Services\ProjectLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class ProgramActivityController extends Controller
{
    private const EXCLUDED_ACTIVITY_CODES = [
        'FPL_LPD',
        'SP_LT',
        'FPL_CST',
        'MAED_ERT',
        'PM_CB',
        'PM_CD',
    ];

    public function index(Request $request): View
    {
        $statusFilter = $request->query('status', '');

        $statusFilter = in_array(
            $statusFilter,
            [
                'planned',
                'ongoing',
                'completed',
                'cancelled',
                'archived',
            ],
            true
        )
            ? $statusFilter
            : '';

        $records = ProgramActivityRecord::query()
            ->with([
                'saadActivity',
                'association.area.barangay.municipality',
                'relatedProject',
                'conductedByUser',
            ])
            ->when(
                $statusFilter === 'archived',
                fn ($query) =>
                    $query->whereNotNull('archived_at'),
                fn ($query) =>
                    $query->whereNull('archived_at')
            )
            ->when(
                in_array(
                    $statusFilter,
                    [
                        'planned',
                        'ongoing',
                        'completed',
                        'cancelled',
                    ],
                    true
                ),
                fn ($query) =>
                    $query->where(
                        'status',
                        $statusFilter
                    )
            )
            ->orderByDesc('program_year')
            ->orderByDesc('activity_date')
            ->orderBy('title')
            ->get();

        $associations = Association::query()
            ->whereNull('archived_at')
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        $activities = SaadActivity::query()
            ->where('is_active', true)
            ->whereNotIn(
                'code',
                self::EXCLUDED_ACTIVITY_CODES
            )
            ->orderBy('id')
            ->get();

        $projects = Project::query()
            ->whereNotIn('status', ['completed', 'terminated'])
            ->whereNull('archived_at')
            ->orderByDesc('program_year')
            ->orderBy('title')
            ->get();

        $activityRows = $records
            ->map(
                fn (ProgramActivityRecord $record) =>
                    $this->mapActivityRow($record)
            )
            ->values()
            ->all();

        return view(
            'admin.program-activities.index',
            [
                'records' =>
                    $records,

                'activityRows' =>
                    $activityRows,

                'associations' =>
                    $associations,

                'activities' =>
                    $activities,

                'projects' =>
                    $projects,

                'statusFilter' =>
                    $statusFilter,
            ]
        );
    }

    public function show(Request $request, ProgramActivityRecord $programActivity): View
    {
        return view('program-activities.show', $this->recordData($request, $programActivity));
    }

    public function edit(Request $request, ProgramActivityRecord $programActivity): View
    {
        $data = $this->recordData($request, $programActivity);
        abort_unless($data['canEdit'], 403, 'This activity is read-only.');
        $actor = $request->attributes->get('current_user');
        $associations = Association::whereNull('archived_at')->where('status', 'active')
            ->when($data['prefix'] === 'field-officer', fn ($query) => $query->where('primary_field_officer_id', $actor->id))
            ->orderBy('name')->get();
        $activities = SaadActivity::where('is_active', true)->whereNotIn('code', self::EXCLUDED_ACTIVITY_CODES)->orderBy('id')->get();
        $projects = Project::whereIn('association_id', $associations->modelKeys())->whereNull('archived_at')
            ->whereNotIn('status', ['completed', 'terminated'])->orderByDesc('program_year')->orderBy('title')->get();

        return view('program-activities.edit', $data + compact('associations', 'activities', 'projects'));
    }

    private function recordData(Request $request, ProgramActivityRecord $programActivity): array
    {
        $actor = $request->attributes->get('current_user');
        abort_unless($actor->canAccessApplication() && in_array($actor->role?->code, ['system_admin', 'field_officer'], true), 403);
        $programActivity->load(['association.area.barangay.municipality', 'relatedProject.association', 'saadActivity', 'conductedByUser']);
        $prefix = $actor->role->code === 'system_admin' ? 'admin' : 'field-officer';
        $lifecycle = app(AssociationLifecycleService::class);
        if ($prefix === 'field-officer') {
            abort_unless($programActivity->association && $lifecycle->isOperational($programActivity->association), 403);
            app(ProjectLifecycleService::class)->authorize($actor, $programActivity->association);
        }
        $canEdit = $programActivity->archived_at === null
            && (!$programActivity->association_id || ($programActivity->association && $lifecycle->isOperational($programActivity->association)))
            && !($programActivity->relatedProject?->isFinalized() ?? false)
            && (!$programActivity->relatedProject || ($programActivity->relatedProject->association
                && $lifecycle->isOperational($programActivity->relatedProject->association)));
        $canViewProject = $programActivity->relatedProject?->association
            && ($prefix === 'admin' || $programActivity->relatedProject->association->primary_field_officer_id === $actor->id);
        $record = $programActivity;
        $row = $this->mapActivityRow($record);
        $row['update_url'] = route($prefix.'.program-activities.update', $record);

        return compact('record', 'prefix', 'row', 'canEdit', 'canViewProject');
    }

    public function store(
        StoreProgramActivityRequest $request,
        ProgramActivityService $programActivityService
    ): RedirectResponse {
        $currentUser =
            $request->attributes->get(
                'current_user'
            );

        $programActivityService->create(
            $request->validated(),
            $currentUser
        );

        return redirect()
            ->route(
                'admin.program-activities.index'
            )
            ->with(
                'success',
                'Program activity created successfully.'
            );
    }

    public function update(
        StoreProgramActivityRequest $request,
        ProgramActivityRecord $programActivity,
        ProgramActivityService $programActivityService
    ): RedirectResponse {
        $currentUser =
            $request->attributes->get(
                'current_user'
            );

        $programActivityService->update(
            $programActivity,
            $request->validated(),
            $currentUser
        );

        return redirect()
            ->route(
                'admin.program-activities.index'
            )
            ->with(
                'success',
                'Program activity updated successfully.'
            );
    }

    public function archive(
        Request $request,
        ProgramActivityRecord $programActivity,
        ProgramActivityService $programActivityService
    ): RedirectResponse {
        $currentUser =
            $request->attributes->get(
                'current_user'
            );

        $programActivityService->archive(
            $programActivity,
            $currentUser
        );

        return redirect()
            ->route(
                'admin.program-activities.index'
            )
            ->with(
                'success',
                'Program activity archived successfully.'
            );
    }

    public function unarchive(
        Request $request,
        ProgramActivityRecord $programActivity,
        ProgramActivityService $programActivityService
    ): RedirectResponse {
        $currentUser =
            $request->attributes->get(
                'current_user'
            );

        $programActivityService->unarchive(
            $programActivity,
            $currentUser
        );

        return redirect()
            ->route(
                'admin.program-activities.index'
            )
            ->with(
                'success',
                'Program activity restored successfully.'
            );
    }

    private function mapActivityRow(
        ProgramActivityRecord $record
    ): array {
        $association =
            $record->association;

        $area =
            $association?->area;

        $barangay =
            $area?->barangay;

        $municipality =
            $barangay?->municipality;

        return [
            'id' =>
                $record->id,

            'project_finalized' => $record->relatedProject?->isFinalized() ?? false,

            'saad_activity_id' =>
                $record->saad_activity_id,

            'activity' =>
                $record->saadActivity?->name
                    ?? '—',

            'activity_code' =>
                $record->saadActivity?->code,

            'association_id' =>
                $record->association_id,

            'association' =>
                $association?->name
                    ?? 'Program-wide',

            'municipality' =>
                $municipality?->name
                    ?? '—',

            'barangay' =>
                $barangay?->name
                    ?? '—',

            'related_project_id' =>
                $record->related_project_id,

            'related_project' =>
                $record->relatedProject?->title
                    ?? '—',

            'title' =>
                $record->title,

            'description' =>
                $record->description,

            'program_year' =>
                $record->program_year,

            'activity_date' =>
                $record->activity_date
                    ? Carbon::parse(
                        $record->activity_date
                    )->format('Y-m-d')
                    : null,

            'venue' =>
                $record->venue,

            'status' =>
                ucfirst(
                    $record->status
                ),

            'status_value' =>
                $record->status,

            'conducted_by' =>
                $record->conducted_by,

            'remarks' =>
                $record->remarks,

            'archived' =>
                $record->archived_at
                    !== null,

            'update_url' =>
                route(
                    'admin.program-activities.update',
                    $record
                ),

            'archive_url' =>
                route(
                    'admin.program-activities.archive',
                    $record
                ),

            'unarchive_url' =>
                route(
                    'admin.program-activities.unarchive',
                    $record
                ),
        ];
    }
}
