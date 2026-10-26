<?php

namespace App\Services\Areas;

use App\Models\Area;
use App\Models\Association;
use App\Models\Barangay;
use App\Models\Municipality;
use App\Services\Associations\AssociationGeographyService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AreaManagementQueryService
{
    public function __construct(private AssociationGeographyService $geography) {}

    private function associationGeography(): Builder
    {
        $query = Association::query()->leftJoin('areas', 'areas.id', '=', 'associations.area_id');

        return $query->join('barangays', 'barangays.id', '=',
            $this->geography->usesDirectGeography() ? 'associations.barangay_id' : 'areas.barangay_id');
    }

    public function summary(): array
    {
        $counts = DB::query()
            ->selectSub(Municipality::query()->selectRaw('COUNT(*)'), 'municipalities')
            ->selectSub(Association::query()->whereNull('archived_at')->selectRaw('COUNT(*)'), 'associations')
            ->selectSub(Barangay::query()->where('is_active', true)->selectRaw('COUNT(*)'), 'current_barangays')
            ->selectSub(Barangay::query()->where('is_active', false)->selectRaw('COUNT(*)'), 'inactive_barangays')
            ->first();

        return array_map('intval', (array) $counts);
    }

    public function municipalities(): Builder
    {
        return Municipality::query()->select(['municipalities.id', 'name', 'psgc_code', 'province', 'region',
            'locality_type', 'source', 'is_active', 'created_at'])->withCount('barangays')
            ->addSelect(['associations_count' => $this->associationGeography()
                ->whereColumn('barangays.municipality_id', 'municipalities.id')
                ->selectRaw('COUNT(DISTINCT associations.id)')]);
    }

    public function barangays(): Builder
    {
        return Barangay::query()->select(['barangays.id', 'municipality_id', 'name', 'psgc_code', 'source', 'is_active', 'created_at'])
            ->with('municipality:id,name,province,region,is_active')
            ->addSelect(['associations_count' => $this->geography->usesDirectGeography()
                ? Association::query()->whereColumn('associations.barangay_id', 'barangays.id')->selectRaw('COUNT(*)')
                : Association::query()->join('areas', 'areas.id', '=', 'associations.area_id')
                    ->whereColumn('areas.barangay_id', 'barangays.id')->selectRaw('COUNT(DISTINCT associations.id)')]);
    }

    public function records(array $filters): LengthAwarePaginator
    {
        $barangays = $filters['tab'] === 'barangays';
        $query = $barangays ? $this->barangays() : $this->municipalities();
        $table = $barangays ? 'barangays' : 'municipalities';
        if ($filters['status'] !== '') $query->where($table.'.is_active', $filters['status'] === 'active');
        if ($filters['source'] !== '') $query->where($table.'.source', $filters['source']);
        if ($filters['municipality_id'] !== '') {
            $query->where($barangays ? 'municipality_id' : 'municipalities.id', $filters['municipality_id']);
        }
        if (!$barangays && $filters['type'] !== '') $query->where('locality_type', $filters['type']);
        if ($filters['q'] !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($filters['q'])).'%';
            $query->where(function ($search) use ($table, $barangays, $pattern) {
                $search->whereRaw("LOWER({$table}.name) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER({$table}.psgc_code) LIKE ? ESCAPE '!'", [$pattern]);
                if ($barangays) $search->orWhereHas('municipality', fn ($parent) => $parent->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]));
                else $search->orWhereRaw("LOWER(province) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(region) LIKE ? ESCAPE '!'", [$pattern]);
            });
        }
        $sort = $filters['sort'];
        if (in_array($sort, ['newest', 'oldest'], true)) $query->orderBy($table.'.created_at', $sort === 'newest' ? 'desc' : 'asc');
        elseif ($sort === 'status') $query->orderByDesc($table.'.is_active');
        elseif ($sort === 'municipality' && $barangays) $query->orderBy(
            Municipality::query()->select('name')->whereColumn('municipalities.id', 'barangays.municipality_id')
        );
        $query->orderByRaw("LOWER({$table}.name) ".($sort === 'za' ? 'DESC' : 'ASC'))->orderBy($table.'.id');

        return $query->paginate(25)->appends($filters)
            ->through(fn ($record) => $record->setAttribute('editor_record', $this->editorRecord($record)));
    }

    public function associations(): Builder
    {
        return $this->associationGeography()
            ->select(['associations.id', 'associations.name', 'associations.status', 'associations.archived_at',
                'areas.id as area_id', 'areas.address', 'areas.is_active as area_active',
                'barangays.id as barangay_id', 'barangays.name as barangay_name', 'barangays.municipality_id'])
            ->orderBy('associations.name')->orderBy('associations.id');
    }

    public function municipalityDetails(Municipality $municipality, array $parameters): array
    {
        $record = $this->municipalities()->findOrFail($municipality->id);

        return [
            'record' => $record,
            'barangays' => $this->barangays()->where('municipality_id', $municipality->id)->orderBy('name')->orderBy('id')
                ->paginate(25, ['*'], 'barangays_page', total: (int) $record->barangays_count)->appends($parameters),
            'associations' => $this->associations()->where('barangays.municipality_id', $municipality->id)
                ->paginate(25, ['*'], 'associations_page', total: (int) $record->associations_count)->appends($parameters),
        ];
    }

    public function barangayDetails(Barangay $barangay, array $parameters): array
    {
        $record = $this->barangays()->findOrFail($barangay->id);

        return [
            'record' => $record,
            'associations' => $this->associations()->where('barangays.id', $barangay->id)
                ->paginate(25, ['*'], 'associations_page', total: (int) $record->associations_count)->appends($parameters),
            'areas' => Area::query()->select(['id', 'barangay_id', 'address', 'source', 'is_active', 'created_at'])
                ->where('barangay_id', $barangay->id)->withCount('associations')->orderBy('address')->orderBy('id')
                ->paginate(25, ['*'], 'areas_page')->appends($parameters),
        ];
    }

    public function editorRecord(Municipality|Barangay $record): array
    {
        $barangay = $record instanceof Barangay;
        $parent = $barangay ? $record->municipality : $record;

        return [
            'id' => $record->id, 'name' => $record->name, 'psgc_code' => $record->psgc_code,
            'area_type' => $barangay ? 'Barangay' : ($record->locality_type === 'city' ? 'City' : 'Municipality'),
            'municipality_id' => $barangay ? $record->municipality_id : null,
            'municipality' => $parent?->name, 'province' => $parent?->province, 'region' => $parent?->region,
            'source' => $record->source === 'psgc' ? 'PSGC' : 'Manual',
            'status' => $record->is_active ? 'Active' : 'Inactive',
            'update_url' => route($barangay ? 'admin.barangays.update' : 'admin.municipalities.update', $record),
            'deactivate_url' => route($barangay ? 'admin.barangays.deactivate' : 'admin.municipalities.deactivate', $record),
        ];
    }

    public function editorCatalog(?Barangay $barangay = null, ?Collection $municipalities = null): array
    {
        $catalog = require resource_path('ui/admin.php');
        $municipalities ??= Municipality::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'is_active']);
        $catalog['options']['municipality_records'] = $municipalities->where('is_active', true)
            ->map(fn ($record) => ['value' => $record->id, 'label' => $record->name])->values()->all();
        $catalog['options']['barangay_records'] = $barangay?->is_active && $barangay->municipality?->is_active ? [
            ['value' => $barangay->id, 'label' => $barangay->name, 'municipality_id' => $barangay->municipality_id],
        ] : [];
        if ($barangay) {
            $catalog['options']['specific_municipality_records'] = $barangay->municipality?->is_active ? [
                ['value' => $barangay->municipality_id, 'label' => $barangay->municipality->name],
            ] : [];
        }
        $catalog['modules']['areas']['rows'] = [];
        if (session()->getOldInput('area_form') === 'editor' && Str::isUuid(session()->getOldInput('area_record_id'))) {
            $model = session()->getOldInput('area_type') === 'Barangay' ? Barangay::with('municipality') : Municipality::query();
            if ($record = $model->find(session()->getOldInput('area_record_id'))) {
                $catalog['modules']['areas']['rows'][] = $this->editorRecord($record);
            }
        }

        return $catalog;
    }
}
