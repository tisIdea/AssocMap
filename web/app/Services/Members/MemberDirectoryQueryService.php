<?php

namespace App\Services\Members;

use App\Models\Association;
use App\Models\Member;
use App\Models\UserProfile;
use App\Services\Associations\AssociationGeographyService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class MemberDirectoryQueryService
{
    public function associations(UserProfile $actor, bool $applications = false): Collection
    {
        return Association::query()->useWritePdo()
            ->when($actor->role->code === 'field_officer', fn ($query) => $query
                ->where('primary_field_officer_id', $actor->id)
                ->when(!$applications, fn ($query) => $query->whereNull('archived_at')))
            ->orderBy('name')->orderBy('id')->get(['id', 'name', 'status', 'archived_at']);
    }

    public function memberScope(UserProfile $actor): Builder
    {
        abort_unless(in_array($actor->role->code, ['system_admin', 'field_officer'], true), 403);

        return Member::query()->useWritePdo()
            ->when($actor->role->code === 'field_officer', fn ($query) => $query->whereHas('association',
                fn ($association) => $association->where('primary_field_officer_id', $actor->id)->whereNull('archived_at')));
    }

    public function members(UserProfile $actor, array $filters, ?array $summary = null): LengthAwarePaginator
    {
        $query = $this->memberScope($actor)
            ->when($filters['status'] === 'archived', fn ($query) => $query->whereNotNull('archived_at'),
                fn ($query) => $query->whereNull('archived_at')
                    ->when($filters['status'] !== '', fn ($query) => $query->where('member_status', $filters['status'])));
        $this->filterAndOrder($query, $filters, 'date_registered');

        $geography = app(AssociationGeographyService::class);

        return $query->with(['association:'.implode(',', $geography->associationColumns(['id', 'name', 'area_id', 'status', 'archived_at'])),
            ...$geography->relations('association'), 'sex:id,name', 'position:id,name', 'classifications'])
            ->paginate(25, total: $this->paginationTotal($filters, $summary, 'current'))->appends($filters);
    }

    public function memberSummary(UserProfile $actor): array
    {
        $counts = $this->memberScope($actor)->selectRaw("COUNT(CASE WHEN archived_at IS NULL THEN 1 END) AS current,
            COUNT(CASE WHEN archived_at IS NULL AND member_status = 'active' THEN 1 END) AS active,
            COUNT(CASE WHEN archived_at IS NULL AND member_status = 'inactive' THEN 1 END) AS inactive,
            COUNT(CASE WHEN archived_at IS NOT NULL THEN 1 END) AS archived")->toBase()->first();

        return array_map('intval', (array) $counts);
    }

    public function applications(Builder $scope, array $filters, bool $fieldOfficer, ?array $summary = null): LengthAwarePaginator
    {
        $query = (clone $scope)->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']));
        $this->filterAndOrder($query, $filters, 'created_at');
        $relations = ['sex:id,name', 'position:id,name', 'classifications', 'reviewedBy:id,display_name'];
        if ($fieldOfficer) {
            $geography = app(AssociationGeographyService::class);
            $relations = [...$relations, 'association:'.implode(',', $geography->associationColumns(['id', 'name', 'area_id', 'status', 'archived_at'])),
                ...$geography->relations('association')];
        }

        return $query->with($relations)->paginate(25, total: $this->paginationTotal($filters, $summary, 'total'))->appends($filters);
    }

    public function applicationSummary(Builder $scope): array
    {
        $counts = (clone $scope)->selectRaw("COUNT(*) AS total,
            COUNT(CASE WHEN status = 'pending' THEN 1 END) AS pending,
            COUNT(CASE WHEN status = 'approved' THEN 1 END) AS approved,
            COUNT(CASE WHEN status = 'rejected' THEN 1 END) AS rejected")->toBase()->first();

        return array_map('intval', (array) $counts);
    }

    private function filterAndOrder(Builder $query, array $filters, string $dateColumn): void
    {
        if ($filters['association'] !== '') $query->where('association_id', $filters['association']);
        if ($filters['q'] !== '') {
            foreach (preg_split('/\s+/u', $filters['q'], -1, PREG_SPLIT_NO_EMPTY) as $word) {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($word)).'%';
                $query->where(fn ($names) => $names
                    ->whereRaw("LOWER(first_name) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(middle_name) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(last_name) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(suffix) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(fishr_registration_number) LIKE ? ESCAPE '!'", [$pattern]));
            }
        }
        if (in_array($filters['sort'], ['newest', 'oldest'], true)) {
            $query->orderBy($dateColumn, $filters['sort'] === 'newest' ? 'desc' : 'asc');
        }
        $direction = $filters['sort'] === 'za' ? 'DESC' : 'ASC';
        $query->orderByRaw('LOWER(last_name) '.$direction)->orderByRaw('LOWER(first_name) '.$direction)->orderBy('id');
    }

    private function paginationTotal(array $filters, ?array $summary, string $default): ?int
    {
        // Filtered totals must come from the filtered query, never the overall cards.
        return $summary !== null && $filters['q'] === '' && $filters['association'] === ''
            ? $summary[$filters['status'] ?: $default] : null;
    }
}
