<?php

namespace App\Services;

use App\Models\MonitoringRecord;
use App\Models\Project;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MonitoringDirectoryQueryService
{
    public function records(Builder $authorizedProjects, array $filters): array
    {
        $query = MonitoringRecord::query()->whereIn('project_id', $authorizedProjects->select('id'));
        $years = (clone $query)->select('year')->distinct()->orderByDesc('year')->pluck('year');
        $selectedYear = $years->contains((int) $filters['year']) ? (int) $filters['year'] : $years->first();
        $query->where('monitoring_records.year', $selectedYear ?? 0)
            ->leftJoin('quarters as search_quarter', 'search_quarter.id', '=', 'monitoring_records.quarter_id');

        if ($filters['quarter'] !== '') {
            $query->whereHas('quarter', fn (Builder $quarter) => $quarter->where('quarter_number', $filters['quarter']));
        }
        if ($filters['validated'] !== '') {
            $query->where('monitoring_records.site_validated', (bool) $filters['validated']);
        }
        if ($filters['search'] !== '') {
            $this->searchRecords($query, $filters['search']);
        }

        $groups = $query->select('monitoring_records.project_id')
            ->selectRaw('MIN(monitoring_records.created_at) as earliest_record_at, MAX(monitoring_records.created_at) as latest_record_at')
            ->selectRaw('MIN(search_quarter.quarter_number) as first_matching_quarter, COUNT(*) as matching_record_count')
            ->groupBy('monitoring_records.project_id');
        $projects = Project::query()->joinSub($groups, 'matching', 'matching.project_id', '=', 'projects.id')
            ->select('projects.*', 'matching.first_matching_quarter', 'matching.matching_record_count')
            ->with([
                'association',
                'monitoringRecords' => fn (HasMany $records) => $records->where('year', $selectedYear ?? 0)
                    ->with(['quarter', 'monitoredBy'])->orderBy('created_at')->orderBy('id'),
            ]);
        $this->orderProjects($projects, $filters['sort']);
        $projects = $projects->paginate(25)->withQueryString();

        return compact('projects', 'years', 'selectedYear');
    }

    private function orderProjects(Builder $projects, string $sort): void
    {
        if ($sort === 'quarter') {
            $projects->orderBy('matching.first_matching_quarter');
        }
        $projects->orderBy($sort === 'oldest' ? 'matching.earliest_record_at' : 'matching.latest_record_at',
            $sort === 'oldest' ? 'asc' : 'desc')->orderBy('projects.id');
    }

    public function visits(MonitoringRecord $record, array $filters): LengthAwarePaginator
    {
        $query = $record->visitReports()->with('conductedByUser:id,display_name');
        if ($filters['validated'] !== '') {
            $query->where('site_validated', (bool) $filters['validated']);
        }
        if ($filters['search'] !== '') {
            $date = $this->displayDate($query->getQuery(), 'monitoring_visit_reports.monitoring_date');
            $query->where(function (Builder $query) use ($filters, $date) {
                $query->whereRaw("LOWER(COALESCE(conducted_by, '') || ' ' || COALESCE(issues_encountered, '') || ' ' ||
                    COALESCE(actions_taken, '') || ' ' || COALESCE(overall_remarks, '') || ' ' || COALESCE(($date), '')) LIKE ? ESCAPE '!'",
                    [$this->pattern($filters['search'])])
                    ->orWhereHas('conductedByUser', fn (Builder $user) => $user
                        ->whereRaw("LOWER(display_name) LIKE ? ESCAPE '!'", [$this->pattern($filters['search'])]));
            });
        }

        $direction = $filters['sort'] === 'oldest' ? 'asc' : 'desc';

        return $query->reorder()->orderBy('monitoring_date', $direction)->orderBy('created_at', $direction)
            ->orderBy('id', $direction)->paginate(25)->withQueryString();
    }

    private function searchRecords(Builder $query, string $search): void
    {
        $date = $this->displayDate($query, 'monitoring_records.created_at');
        $query->leftJoin('projects as search_project', 'search_project.id', '=', 'monitoring_records.project_id')
            ->leftJoin('associations as search_association', 'search_association.id', '=', 'search_project.association_id')
            ->leftJoin('user_profiles as search_monitor', 'search_monitor.id', '=', 'monitoring_records.monitored_by')
            ->whereRaw("LOWER(COALESCE(search_project.title, '') || ' ' || COALESCE(search_association.name, '') || ' ' ||
                CAST(monitoring_records.year AS TEXT) || ' Q' || COALESCE(CAST(search_quarter.quarter_number AS TEXT), '') || ' ' ||
                COALESCE(search_monitor.display_name, '') || ' ' || COALESCE(($date), '') || ' ' ||
                COALESCE(monitoring_records.project_status, '')) LIKE ? ESCAPE '!'", [$this->pattern($search)]);
    }

    private function displayDate(Builder $query, string $column): string
    {
        return $query->getConnection()->getDriverName() === 'sqlite'
            ? "substr('JanFebMarAprMayJunJulAugSepOctNovDec', (CAST(strftime('%m', $column) AS INTEGER) - 1) * 3 + 1, 3) || strftime(' %d, %Y', $column)"
            : "TO_CHAR($column, 'Mon DD, YYYY')";
    }

    private function pattern(string $search): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
    }
}
