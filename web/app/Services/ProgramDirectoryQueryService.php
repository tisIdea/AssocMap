<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProgramActivityRecord;
use App\Models\Training;
use App\Models\UserProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class ProgramDirectoryQueryService
{
    /**
     * @param 'projects'|'trainings'|'program-activities' $module
     * @return Builder<Project>|Builder<Training>|Builder<ProgramActivityRecord>
     */
    public function scope(UserProfile $actor, string $module): Builder
    {
        abort_unless(in_array($actor->role->code, ['system_admin', 'field_officer'], true), 403);
        $query = match ($module) {
            'projects' => Project::query(),
            'trainings' => Training::query(),
            'program-activities' => ProgramActivityRecord::query(),
        };

        return $query->useWritePdo()->when($actor->role->code === 'field_officer',
            fn ($query) => $query->whereHas('association', fn ($owner) => $owner
                ->where('primary_field_officer_id', $actor->id)->whereNull('archived_at')
                ->when($module !== 'projects', fn ($owner) => $owner->where('status', 'active'))));
    }

    /**
     * @param 'projects'|'trainings'|'program-activities' $module
     * @return array{current: int, archived: int, completed: int, cancelled: int,
     *     planned?: int, ongoing?: int, suspended?: int, terminated?: int, scheduled?: int}
     */
    public function summary(UserProfile $actor, string $module): array
    {
        $statuses = match ($module) {
            'projects' => ['planned', 'ongoing', 'suspended', 'cancelled', 'completed', 'terminated'],
            'trainings' => ['scheduled', 'completed', 'cancelled'],
            'program-activities' => ['planned', 'ongoing', 'completed', 'cancelled'],
        };
        $columns = ["COUNT(CASE WHEN archived_at IS NULL THEN 1 END) AS current",
            'COUNT(CASE WHEN archived_at IS NOT NULL THEN 1 END) AS archived'];
        foreach ($statuses as $status) $columns[] = "COUNT(CASE WHEN archived_at IS NULL AND status = '{$status}' THEN 1 END) AS {$status}";

        return array_map('intval', (array) $this->scope($actor, $module)->selectRaw(implode(', ', $columns))->toBase()->first());
    }

    /**
     * @param 'projects'|'trainings'|'program-activities' $module
     * @param array{q: string, association: string, year: int|string, status: string, sort: string} $filters
     * @param array<string, int> $summary
     * @return LengthAwarePaginator<int, Project|Training|ProgramActivityRecord>
     */
    public function records(UserProfile $actor, string $module, array $filters, array $summary): LengthAwarePaginator
    {
        $query = $this->scope($actor, $module)
            ->when($filters['status'] === 'archived', fn ($query) => $query->whereNotNull('archived_at'),
                fn ($query) => $query->whereNull('archived_at')
                    ->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status'])));
        if ($filters['q'] !== '') $query->where(function ($search) use ($filters) {
            $search->whereRaw("LOWER(title) LIKE ? ESCAPE '!'", [$this->pattern($filters['q'])])
                ->orWhereHas('association', fn ($owner) => $owner->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$this->pattern($filters['q'])]));
            if ($search->getModel() instanceof ProgramActivityRecord) {
                $search->orWhereHas('saadActivity', fn ($activity) => $activity
                    ->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$this->pattern($filters['q'])]));
            }
        });
        if ($filters['association'] !== '') $query->whereHas('association',
            fn ($owner) => $owner->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$this->pattern($filters['association'])]));
        if ($filters['year'] !== '') $query->where('program_year', $filters['year']);
        $date = match ($module) {
            'projects' => 'implementation_start_date',
            'trainings' => 'start_date',
            'program-activities' => 'activity_date',
        };
        if ($filters['sort'] === 'year') {
            $query->orderByDesc('program_year');
            if ($module !== 'projects') $query->orderByDesc($date);
        } elseif (in_array($filters['sort'], ['newest', 'oldest'], true)) {
            $query->orderBy($date, $filters['sort'] === 'newest' ? 'desc' : 'asc');
        }
        $query->orderByRaw('LOWER(title) '.($filters['sort'] === 'za' ? 'DESC' : 'ASC'))->orderBy('id');
        $relations = ['association:id,name,area_id,status,archived_at,primary_field_officer_id',
            'association.area:id,barangay_id,address', 'association.area.barangay:id,municipality_id,name',
            'association.area.barangay.municipality:id,name'];
        if ($module === 'projects') {
            $query->select(['id', 'association_id', 'title', 'program_year', 'implementation_start_date', 'implementation_end_date',
                'fisheries_category_id', 'commodity_id', 'status', 'archived_at', 'closed_at'])
                ->with([...$relations, 'fisheriesCategory:id,name', 'commodity:id,name']);
        } elseif ($module === 'trainings') {
            $query->select(['id', 'association_id', 'related_project_id', 'saad_activity_id', 'title', 'program_year',
                'start_date', 'end_date', 'venue', 'status', 'archived_at'])
                ->with([...$relations, 'saadActivity:id,name', 'relatedProject:id,association_id,title,status,archived_at,closed_at',
                    'relatedProject.association:id,status,archived_at'])->withCount('participants');
        } else {
            $query->select(['id', 'association_id', 'related_project_id', 'saad_activity_id', 'title', 'program_year',
                'activity_date', 'venue', 'status', 'archived_at'])
                ->with([...$relations, 'saadActivity:id,code,name',
                    'relatedProject:id,association_id,title,status,archived_at,closed_at',
                    'relatedProject.association:id,status,archived_at,primary_field_officer_id']);
        }
        $total = $filters['q'] === '' && $filters['association'] === '' && $filters['year'] === ''
            ? $summary[$filters['status'] ?: 'current'] : null;

        return $query->paginate(25, total: $total)->appends($filters);
    }

    public function pattern(string $value): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($value)).'%';
    }
}
