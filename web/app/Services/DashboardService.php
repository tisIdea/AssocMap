<?php

namespace App\Services;

use App\Services\Reporting\ReportScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DashboardService
{
    public function __construct(private ReportScope $scope) {}

    public function load(Request $request): array
    {
        // Reuse authorization only; dashboards never invoke report generation.
        $actor = $this->scope->actor($request);
        $role = $actor->role->code;
        $associations = $this->scope->associations($actor);
        $associationIds = (clone $associations)->orderBy('a.id')->pluck('a.id')->all();
        $associations->whereIn('a.id', $associationIds);
        $ids = (clone $associations)->select('a.id');
        $associationCounts = (clone $associations)->selectRaw("COUNT(*) AS total,
            COUNT(CASE WHEN a.status = 'active' AND a.archived_at IS NULL THEN 1 END) AS active,
            COUNT(CASE WHEN a.status = 'inactive' AND a.archived_at IS NULL THEN 1 END) AS inactive,
            COUNT(CASE WHEN a.archived_at IS NOT NULL THEN 1 END) AS archived");
        $members = $this->records('members', 'm', $ids)->selectRaw("COUNT(*) AS total,
            COUNT(CASE WHEN m.member_status = 'active' AND m.archived_at IS NULL THEN 1 END) AS active,
            COUNT(CASE WHEN m.archived_at IS NOT NULL THEN 1 END) AS archived");
        $projects = $this->records('projects', 'p', $ids);
        $projectCounts = (clone $projects)->selectRaw('COUNT(*) AS total,
            COUNT(CASE WHEN p.archived_at IS NULL THEN 1 END) AS unarchived');
        $currentProjects = (clone $projects)->whereNull('p.archived_at');

        // Aggregate each table separately so one-to-many relationships cannot inflate counts.
        $summaryQuery = DB::query()->useWritePdo()->fromSub($associationCounts, 'association_counts')
            ->crossJoinSub($members, 'member_counts')->crossJoinSub($projectCounts, 'project_counts')
            ->select(['association_counts.total as associations_total', 'association_counts.active as associations_active',
                'association_counts.inactive as associations_inactive', 'association_counts.archived as associations_archived',
                'member_counts.total as members_total', 'member_counts.active as members_active',
                'project_counts.total as projects_total', 'project_counts.unarchived as projects_unarchived']);
        if ($role === 'system_admin') {
            $summaryQuery->selectSub(DB::table('user_profiles as u')->useWritePdo()->join('roles as r', 'r.id', '=', 'u.role_id')
                ->where('r.code', 'field_officer')->where('r.is_active', true)->where('u.is_active', true)
                ->selectRaw('COUNT(*)'), 'field_officers');
        }
        $summaryCounts = $summaryQuery->first();

        $metrics = [];
        if ($role === 'system_admin') {
            $metrics[] = $this->metric('associations_total', 'Registered associations', $summaryCounts->associations_total, 'All retained associations, including archives.', 'building');
            $metrics[] = $this->metric('associations_inactive', 'Inactive associations', $summaryCounts->associations_inactive, 'Inactive and unarchived.', 'building');
            $metrics[] = $this->metric('associations_archived', 'Archived associations', $summaryCounts->associations_archived, 'Archive takes precedence over status.', 'building');
        }
        if ($role !== 'association_account') {
            $metrics[] = $this->metric('associations_active', $role === 'field_officer' ? 'Assigned active associations' : 'Active associations', $summaryCounts->associations_active, 'Active and unarchived; current assignments only for Field Officers.', 'building');
        }
        $metrics[] = $this->metric('members_total', 'Official members', $summaryCounts->members_total, 'All retained official records, including archived members.', 'users');
        $metrics[] = $this->metric('members_active', 'Active official members', $summaryCounts->members_active, 'Active member status and no archive date.', 'users');
        $metrics[] = $this->metric('projects_total', 'Registered projects', $summaryCounts->projects_total, 'All retained projects in this scope, including archives.', 'briefcase');
        $metrics[] = $this->metric('projects_unarchived', 'Unarchived projects', $summaryCounts->projects_unarchived, 'All current project statuses; not just ongoing projects.', 'briefcase');
        if ($role === 'system_admin') {
            $metrics[] = $this->metric('field_officers', 'Active Field Officer profiles', $summaryCounts->field_officers, 'Active profile and role flags; not an invitation activation count.', 'user');
        }

        $training = $this->optional('training', function () use ($ids) {
            $present = DB::table('training_participants as tp')->useWritePdo()
                ->join('trainings as t', 't.id', '=', 'tp.training_id')->join('members as m', 'm.id', '=', 'tp.member_id')
                ->whereColumn('m.association_id', 't.association_id')->whereIn('t.association_id', clone $ids)
                ->where('tp.attendance_status', 'present')->selectRaw('COUNT(DISTINCT tp.member_id)');
            $counts = $this->records('trainings', 't', $ids)->selectRaw("COUNT(*) AS total,
                COUNT(CASE WHEN t.status = 'completed' THEN 1 END) AS completed")
                ->selectSub($present, 'present_members')->first();

            return ['records' => (int) $counts->total, 'completed' => (int) $counts->completed, 'distinct_present_members' => (int) $counts->present_members];
        });
        $metrics[] = $this->metric('training_records', 'Recorded training activities', $training['records'] ?? null, 'All start dates and statuses; historical archives retained.', 'graduation');

        $monitoring = $this->optional('monitoring', function () use ($currentProjects) {
            $records = DB::table('monitoring_records as mr')->useWritePdo()->whereIn('mr.project_id', (clone $currentProjects)->select('p.id'));
            $periods = DB::query()->useWritePdo()->fromSub((clone $records)->select('mr.year', 'mr.quarter_id')->distinct(), 'periods')
                ->selectRaw('COUNT(*)');
            $counts = (clone $records)->selectRaw('COUNT(*) AS total')->selectSub($periods, 'periods')->first();
            $recent = (clone $records)->join('projects as p', 'p.id', '=', 'mr.project_id')->join('quarters as q', 'q.id', '=', 'mr.quarter_id')
                ->orderByDesc('mr.monitoring_date')->orderBy('mr.id')->limit(6)
                ->get(['mr.id', 'p.title', 'mr.year', 'q.quarter_number', 'mr.monitoring_date', 'mr.project_status'])
                ->map(fn ($row) => (array) $row)->all();
            $visits = $this->optional('visit_reports', function () use ($records) {
                $counts = DB::table('monitoring_visit_reports as v')->useWritePdo()
                    ->whereIn('v.monitoring_record_id', (clone $records)->select('mr.id'))
                    ->selectRaw('COUNT(*) AS total, COUNT(CASE WHEN v.is_legacy_import = TRUE THEN 1 END) AS legacy')->first();

                return ['total' => (int) $counts->total, 'legacy_imports' => (int) $counts->legacy];
            });

            return ['records' => (int) $counts->total, 'periods' => (int) $counts->periods, 'visits' => $visits, 'recent' => $recent];
        });
        $metrics[] = $this->metric('monitoring_records', 'Quarterly monitoring records', $monitoring['records'] ?? null, 'All recorded years; unarchived projects only.', 'chart');

        $applications = null;
        if ($role !== 'system_admin') {
            $applications = $this->optional('applications', function () use ($ids) {
                $counts = $this->records('member_applications', 'ma', $ids)->selectRaw("COUNT(CASE WHEN ma.status = 'pending' THEN 1 END) AS pending,
                    COUNT(CASE WHEN ma.status = 'approved' THEN 1 END) AS approved,
                    COUNT(CASE WHEN ma.status = 'rejected' THEN 1 END) AS rejected")->first();

                return ['pending' => (int) $counts->pending, 'approved' => (int) $counts->approved, 'rejected' => (int) $counts->rejected];
            });
            $metrics[] = $this->metric('applications_pending', 'Pending member applications', $applications['pending'] ?? null,
                $role === 'field_officer' ? 'Awaiting review within your current eligible assignments.' : 'Awaiting Field Officer review; this account cannot approve applications.', 'receipt');
        }

        $charts = [];
        if ($role === 'system_admin') {
            $charts[] = $this->chart('association_status', 'Association lifecycle', 'All registered associations; categories do not overlap.', [
                ['label' => 'Active / unarchived', 'value' => (int) $summaryCounts->associations_active],
                ['label' => 'Inactive / unarchived', 'value' => (int) $summaryCounts->associations_inactive],
                ['label' => 'Archived', 'value' => (int) $summaryCounts->associations_archived],
            ]);
            $charts[] = $this->optional('municipalities', fn () => $this->chart('municipalities', 'Associations by municipality / city',
                'Registered associations, including inactive and archived. Up to 12 largest groups.',
                (clone $associations)->selectRaw('mu.name AS label, COUNT(*) AS value')->groupBy('mu.id', 'mu.name')
                    ->orderByDesc('value')->orderBy('mu.name')->limit(12)->get()->map(fn ($row) => (array) $row)->all()));
        }
        $charts[] = $this->optional('project_status', fn () => $this->chart('project_status', 'Project portfolio status',
            'Unarchived projects; current project status, not the historical monitoring status.',
            (clone $currentProjects)->selectRaw('p.status AS label, COUNT(*) AS value')->groupBy('p.status')->orderBy('p.status')
                ->get()->map(fn ($row) => ['label' => ucfirst($row->label), 'value' => (int) $row->value])->all()));
        $charts[] = $this->optional('components', fn () => $this->chart('components', 'Projects by program component', 'Unarchived projects; one component per project. Up to 12 components.',
            (clone $currentProjects)->join('saad_activities as sa', 'sa.id', '=', 'p.saad_activity_id')
                ->join('saad_subcomponents as sc', 'sc.id', '=', 'sa.subcomponent_id')->join('saad_components as c', 'c.id', '=', 'sc.component_id')
                ->selectRaw('c.name AS label, COUNT(*) AS value')->groupBy('c.id', 'c.name')->orderBy('c.name')
                ->limit(12)->get()->map(fn ($row) => (array) $row)->all()));

        $overview = $role === 'association_account' ? null : $this->optional('associations', fn () => (clone $associations)
            ->select(['a.id', 'a.name', 'a.status', 'a.archived_at', 'mu.name as municipality'])
            ->selectSub(fn ($query) => $query->from('members as m')->selectRaw('COUNT(*)')->whereColumn('m.association_id', 'a.id')
                ->where('m.member_status', 'active')->whereNull('m.archived_at'), 'active_members')
            ->selectSub(fn ($query) => $query->from('projects as p')->selectRaw('COUNT(*)')->whereColumn('p.association_id', 'a.id')
                ->whereNull('p.archived_at'), 'unarchived_projects')
            ->orderBy('a.name')->orderBy('a.id')->limit(10)->get()->map(fn ($row) => [
                'id' => $row->id, 'name' => $row->name, 'municipality' => $row->municipality,
                'status' => $row->archived_at !== null ? 'archived' : $row->status,
                'active_members' => (int) $row->active_members, 'unarchived_projects' => (int) $row->unarchived_projects,
            ])->all());
        $association = null;
        if ($role === 'association_account') {
            $linked = (clone $associations)->first(['a.id', 'a.name', 'a.status', 'mu.name as municipality', 'a.saad_phase2_join_date as joined_on']);
            abort_unless($linked, 403);
            $association = (array) $linked;
        }
        $attention = $role === 'system_admin' ? $this->optional('officer_assignments', fn () => (clone $associations)
            ->where('a.status', 'active')->whereNull('a.archived_at')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('user_profiles as u')->join('roles as r', 'r.id', '=', 'u.role_id')
                ->whereColumn('u.id', 'a.primary_field_officer_id')->where('u.is_active', true)->where('r.is_active', true)->where('r.code', 'field_officer'))
            ->count()) : null;
        $recentProjects = $this->optional('recent_projects', fn () => (clone $currentProjects)->join('associations as a', 'a.id', '=', 'p.association_id')
            ->orderByDesc('p.created_at')->orderBy('p.id')->limit(6)
            ->get(['p.id', 'p.title', 'p.status', 'p.program_year', 'p.created_at', 'a.name as association'])
            ->map(fn ($row) => (array) $row)->all());
        $activities = $this->optional('activities', function () use ($ids, $role) {
            return DB::table('program_activity_records as act')->useWritePdo()->whereNull('act.archived_at')
                ->where(function ($query) use ($ids, $role) {
                    $query->whereIn('act.association_id', clone $ids);
                    if ($role === 'system_admin') $query->orWhereNull('act.association_id');
                })->orderByDesc('act.activity_date')->orderBy('act.id')->limit(6)
                ->get(['act.id', 'act.title', 'act.activity_date', 'act.status'])->map(fn ($row) => (array) $row)->all();
        });

        // Recheck account/link eligibility before releasing the assembled payload.
        $freshActor = $this->scope->actor($request);
        $freshAssociations = $this->scope->associations($freshActor);
        abort_unless((clone $freshAssociations)->orderBy('a.id')->pluck('a.id')->all() === $associationIds, 403);
        if ($association !== null) abort_unless((clone $freshAssociations)->where('a.id', $association['id'])->exists(), 403);

        return [
            'version' => 'assocmap.dashboard.v1', 'role' => $role,
            'generated_at' => now('Asia/Manila')->toIso8601String(), 'timezone' => 'Asia/Manila',
            'scope_count' => (int) $summaryCounts->associations_total, 'association' => $association,
            'metrics' => $metrics, 'charts' => $charts, 'associations' => $overview,
            'training' => $training, 'monitoring' => $monitoring, 'applications' => $applications,
            'officer_attention' => $attention, 'recent_projects' => $recentProjects, 'activities' => $activities,
        ];
    }

    private function records(string $table, string $alias, Builder $ids): Builder
    {
        return DB::table($table.' as '.$alias)->useWritePdo()->whereIn($alias.'.association_id', clone $ids);
    }

    private function metric(string $key, string $label, mixed $value, string $note, string $icon): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value === null ? null : (int) $value, 'note' => $note, 'icon' => $icon];
    }

    private function chart(string $key, string $title, string $note, array $rows): array
    {
        return ['key' => $key, 'title' => $title, 'note' => $note,
            'rows' => array_map(fn ($row) => ['label' => $row['label'], 'value' => (int) $row['value']], $rows)];
    }

    private function optional(string $widget, callable $read): mixed
    {
        try {
            return $read();
        } catch (QueryException $exception) {
            Log::warning('Dashboard widget unavailable.', ['code' => 'dashboard_widget_unavailable', 'widget' => $widget]);

            return null;
        }
    }
}
