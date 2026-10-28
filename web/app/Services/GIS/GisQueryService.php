<?php

namespace App\Services\GIS;

use App\Models\GisLocation;
use App\Models\UserProfile;
use App\Services\Associations\AssociationGeographyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GisQueryService
{
    public function __construct(private GisFeatureSerializer $serializer, private GisAddressResolver $addresses) {}

    public function role(UserProfile $user): string
    {
        abort_unless($user->canAccessApplication()
            && in_array($user->role?->code, ['system_admin', 'field_officer', 'association_account'], true), 403);

        return $user->role->code;
    }

    public function manager(UserProfile $user): bool
    {
        return in_array($this->role($user), ['system_admin', 'field_officer'], true);
    }

    public function siteTypes(): array
    {
        return DB::table('gis_site_types')->orderBy('name')->orderBy('id')->get(['id', 'code', 'name'])
            ->map(fn ($type) => ['id' => (int) $type->id, 'code' => $type->code, 'name' => $type->name])->all();
    }

    public function scoped(?UserProfile $user, ?bool $archived = false): Builder
    {
        $administrator = $user !== null && $this->role($user) === 'system_admin';
        $query = GisLocation::query()->from('gis_locations as g')
            ->leftJoin('projects as p', 'p.id', '=', 'g.project_id')
            ->join('associations as a', 'a.id', '=', DB::raw('COALESCE(g.association_id, p.association_id)'));
        app(AssociationGeographyService::class)->joinGeography($query);
        $query->where(function ($associations) use ($administrator) {
                $associations->where(fn ($active) => $active->where('a.status', 'active')->whereNull('a.archived_at'));
                if ($administrator) $associations->orWhereNotNull('a.archived_at');
            })
            ->whereIn('g.location_type', GisLocation::TYPES)
            ->whereNull('g.association_id')->whereNotNull('p.id')
            ->when(! $administrator, fn ($projects) => $projects->whereNull('p.archived_at'));
        if ($user !== null) $this->applyRoleScope($query, $user);
        if ($user !== null && $this->manager($user)) {
            if ($archived === true) $query->whereNotNull('g.archived_at');
            elseif ($archived === false) $query->whereNull('g.archived_at');
        } else $query->whereNull('g.archived_at');
        if ($user === null) {
            $query->where('g.is_published', true)->whereNotNull('g.published_by')->whereNotNull('g.published_at')
                ->whereNotNull('g.public_title')->whereRaw("BTRIM(g.public_title) <> ''")
                ->whereRaw($this->readableGeometry());
        }

        return $query;
    }

    public function features(?UserProfile $user, array $filters): array
    {
        if (($filters['mode'] ?? '') === 'overview') return $this->overview($user, $filters);
        $public = $user === null;
        $manager = $user !== null && $this->manager($user);
        $archived = ($filters['lifecycle'] ?? 'active') === 'archived';
        $query = $this->scoped($user, $archived);
        if ($public) $this->addPublicDisplayPoint($query);
        $this->filter($query, $filters, $public);
        $this->selectFeature($query, $public, $manager);
        $query->orderByDesc('g.updated_at')->orderBy('g.id');
        $map = ($filters['mode'] ?? 'list') === 'map';
        if ($map) {
            $rows = $query->limit(101)->get();
            $meta = ['limit' => 100, 'truncated' => $rows->count() > 100];
            $rows = $rows->take(100);
        } else {
            $page = $query->paginate(20, ['*'], 'page', (int) ($filters['page'] ?? 1));
            $rows = $page->getCollection();
            $meta = ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => 20];
        }
        $features = [];
        $bytes = 0;
        foreach ($rows as $row) {
            $feature = $this->serializer->make($row, $public, $manager, $manager && $user->role->code === 'system_admin');
            $bytes += strlen(json_encode($feature, JSON_THROW_ON_ERROR));
            if ($map && $bytes > 2097152) { $meta['truncated'] = true; break; }
            $features[] = $feature;
        }
        if ($features === []) {
            $meta['empty_reason'] = $map && $meta['truncated']
                ? 'display_limit' : $this->emptyReason($user, $filters, $map);
        }

        return ['type' => 'FeatureCollection', 'features' => $features, 'meta' => $meta];
    }

    private function overview(?UserProfile $user, array $filters): array
    {
        unset($filters['bbox']);
        $query = $this->scoped($user, ($filters['lifecycle'] ?? 'active') === 'archived');
        if ($user === null) $this->addPublicDisplayPoint($query);
        $this->filter($query, $filters, $user === null);
        $query->whereRaw($this->readableGeometry());
        $geometry = $user === null ? 'gis_display.display_geom' : 'g.geom';
        $extent = $query->toBase()->selectRaw('extensions.ST_XMin(extensions.ST_Extent('.$geometry.')::extensions.box3d) AS west,
            extensions.ST_YMin(extensions.ST_Extent('.$geometry.')::extensions.box3d) AS south,
            extensions.ST_XMax(extensions.ST_Extent('.$geometry.')::extensions.box3d) AS east,
            extensions.ST_YMax(extensions.ST_Extent('.$geometry.')::extensions.box3d) AS north')->first();
        $bounds = $extent?->west !== null
            ? [(float) $extent->west, (float) $extent->south, (float) $extent->east, (float) $extent->north] : null;

        return ['type' => 'FeatureCollection', 'features' => [], 'meta' => [
            'bounds' => $bounds, 'empty_reason' => $bounds === null ? $this->emptyReason($user, $filters, true) : null,
        ]];
    }

    private function emptyReason(?UserProfile $user, array $filters, bool $map): string
    {
        if (! $this->scoped($user, null)->exists()) return 'no_records';
        unset($filters['bbox']);
        $query = $this->scoped($user, ($filters['lifecycle'] ?? 'active') === 'archived');
        $this->filter($query, $filters, $user === null);
        if (! (clone $query)->exists()) return 'filtered';
        if ($map && ! (clone $query)->whereRaw($this->readableGeometry())->exists()) return 'geometry_unavailable';

        return $map ? 'outside_view' : 'empty_page';
    }

    public function detail(UserProfile $user, string $id): array
    {
        $manager = $this->manager($user);
        $query = $this->scoped($user)->where('g.id', $id);
        $this->selectFeature($query, false, $manager);
        $row = $query->first();
        if (! $row && $manager) {
            $query = $this->scoped($user, true)->where('g.id', $id);
            $this->selectFeature($query, false, $manager);
            $row = $query->first();
        }
        abort_unless($row, 404);

        return $this->serializer->make($row, false, $manager, $user->role->code === 'system_admin');
    }

    public function parents(UserProfile $user, array $filters): array
    {
        $query = app(AssociationGeographyService::class)->joinGeography(DB::table('associations as a'))
            ->where('a.status', 'active')->whereNull('a.archived_at');
        $this->applyRoleScope($query, $user);
        $kind = $filters['kind'];
        $columns = [
            'association' => ['a.id', 'a.name'], 'project' => ['p.id', 'p.title'],
            'municipality' => ['m.id', 'm.name'], 'barangay' => ['b.id', 'b.name'], 'area' => ['ar.id', 'ar.address'],
        ];
        [$id, $label] = $columns[$kind];
        if ($kind === 'area') $query->whereNotNull('ar.id');
        if ($kind === 'project') $query->join('projects as p', 'p.association_id', '=', 'a.id')->whereNull('p.archived_at');
        foreach (['association_id' => 'a.id', 'municipality_id' => 'm.id', 'barangay_id' => 'b.id', 'area_id' => 'ar.id'] as $field => $column) {
            if (! empty($filters[$field]) && $column !== $id) $query->where($column, $filters[$field]);
        }

        return $this->options($query, $id, $label, $filters,
            $kind === 'association' && $this->manager($user));
    }

    public function publicAssociations(array $filters): array
    {
        $query = $this->scoped(null)->toBase();

        return $this->options($query, 'g.id', 'g.public_title', $filters);
    }

    public function projectLocations(UserProfile $user, array $projectIds): ?array
    {
        abort_unless($this->manager($user), 403);
        if ($projectIds === []) return [];

        try {
            return $this->scoped($user)->whereIn('g.project_id', $projectIds)
                ->select(['g.id', 'g.project_id', 'g.location_name', 'g.location_type', 'g.address'])
                ->orderBy('g.location_name')->orderBy('g.id')->get()->groupBy('project_id')
                ->map(fn ($rows) => $rows->map(fn ($row) => [
                    'id' => $row->id, 'name' => $row->location_name, 'type' => $row->location_type, 'address' => $row->address,
                ])->values()->all())->all();
        } catch (QueryException $exception) {
            Log::warning('Project GIS location lookup failed.', ['sqlstate' => $exception->errorInfo[0] ?? null]);

            return null;
        }
    }

    private function options(QueryBuilder $query, string $id, string $label, array $filters, bool $withAddress = false): array
    {
        $query->select([$id.' as value', $label.' as label'])->distinct();
        if ($withAddress) $query->addSelect(['ar.address as area_address', 'b.name as barangay_name',
            'm.name as municipality_name', 'm.province', 'm.region']);
        $selected = ! empty($filters['selected']) ? (clone $query)->where($id, $filters['selected'])->first() : null;
        if (! empty($filters['q'])) $query->whereRaw($label.' ILIKE ?', [$this->searchPattern($filters['q'])]);
        $rows = $query->orderBy($label)->orderBy($id)->limit(51)->get();
        $truncated = $rows->count() > 50;
        $rows = $rows->take(50);
        if ($selected && ! $rows->contains(fn ($row) => $row->value === $selected->value)) $rows->prepend($selected);

        $options = $rows->map(function ($row) use ($withAddress) {
            $option = ['value' => $row->value, 'label' => $row->label];
            if ($withAddress) $option['address_info'] = $this->addresses->fromRow($row);

            return $option;
        });

        return ['options' => $options->values()->all(), 'truncated' => $truncated];
    }

    private function applyRoleScope(Builder|QueryBuilder $query, UserProfile $user): void
    {
        $role = $this->role($user);
        if ($role === 'field_officer') $query->where('a.primary_field_officer_id', $user->id);
        if ($role === 'association_account') {
            $query->whereExists(fn ($link) => $link->selectRaw('1')->from('association_accounts as aa')
                ->whereColumn('aa.association_id', 'a.id')->where('aa.user_id', $user->id)->where('aa.is_active', true));
        }
    }

    private function filter(Builder $query, array $filters, bool $public): void
    {
        if (! empty($filters['location_type'])) $query->where('g.location_type', $filters['location_type']);
        if (($filters['site_type_id'] ?? '') === 'unclassified') $query->whereNull('g.site_type_id');
        elseif (! empty($filters['site_type_id'])) $query->where('g.site_type_id', $filters['site_type_id']);
        if (! empty($filters['q'])) {
            $columns = $public ? ['g.public_title', 'g.public_description', 'g.public_address']
                : ['g.location_name', 'g.address', 'a.name', 'p.title', 'm.name', 'b.name', 'ar.address'];
            $pattern = $this->searchPattern($filters['q']);
            $query->where(function ($search) use ($columns, $pattern) {
                foreach ($columns as $column) $search->orWhereRaw($column.' ILIKE ?', [$pattern]);
            });
        }
        if ($public && ! empty($filters['association_location_id'])) {
            $location = $this->scoped(null)->where('g.id', $filters['association_location_id'])
                ->first(['a.id as parent_association_id']);
            $location ? $query->where('a.id', $location->parent_association_id) : $query->whereRaw('1 = 0');
        }
        if (! $public) {
            foreach (['association_id' => 'a.id', 'project_id' => 'p.id', 'municipality_id' => 'm.id', 'barangay_id' => 'b.id', 'area_id' => 'ar.id'] as $field => $column) {
                if (! empty($filters[$field])) $query->where($column, $filters[$field]);
            }
            if (! empty($filters['publication'])) $query->where('g.is_published', $filters['publication'] === 'published');
        }
        if (! empty($filters['bbox'])) {
            $bounds = array_values($filters['bbox']);
            $geometry = $public ? 'gis_display.display_geom' : 'g.geom';
            $query->whereRaw('g.geom OPERATOR(extensions.&&) extensions.ST_MakeEnvelope(?, ?, ?, ?, 4326)
                AND CASE WHEN '.$this->readableGeometry().' THEN extensions.ST_Intersects('.$geometry.',
                    extensions.ST_MakeEnvelope(?, ?, ?, ?, 4326)) ELSE FALSE END', array_merge($bounds, $bounds));
        }
    }

    private function selectFeature(Builder $query, bool $public = false, bool $manager = false): void
    {
        if ($public) {
            $query->select(['g.id', 'g.location_type', 'g.public_title', 'g.public_description', 'g.public_address'])
                ->selectRaw('extensions.ST_AsGeoJSON(gis_display.display_geom, 15, 0) AS display_point_json');
        } else {
            $query->select(['g.id', 'g.association_id', 'g.project_id', 'g.location_type', 'g.location_name', 'g.address',
                'g.public_title', 'g.public_description', 'g.public_address', 'g.is_published', 'g.published_by', 'g.published_at',
                'g.created_by', 'g.updated_by', 'g.created_at', 'g.updated_at', 'g.archived_at', 'g.archived_by',
                'a.id as parent_association_id', 'a.name as association_name', 'p.title as project_title',
                'p.program_year', 'm.name as municipality_name', 'b.name as barangay_name', 'ar.address as area_address'])
                ->selectRaw('g.updated_at::text AS version');
        }
        $query->leftJoin('gis_site_types as st', 'st.id', '=', 'g.site_type_id')
            ->addSelect(['g.site_type_id', 'st.code as site_type_code', 'st.name as site_type_name']);
        if (! $public && $manager) {
            $query->addSelect(['p.status as project_status', 'p.closed_at as project_closed_at',
                'p.archived_at as project_archived_at', 'a.status as association_status', 'a.archived_at as association_archived_at']);
            foreach (['created', 'updated', 'published', 'archived'] as $action) {
                $alias = $action.'_actor';
                $query->leftJoin('user_profiles as '.$alias, $alias.'.id', '=', 'g.'.$action.'_by')
                    ->addSelect($alias.'.display_name as '.$action.'_by_name');
            }
        }
        $query->selectRaw('CASE WHEN '.$this->readableGeometry().' THEN extensions.ST_AsGeoJSON(g.geom, 15, 0) ELSE NULL END AS geometry_json');
    }

    private function addPublicDisplayPoint(Builder $query): void
    {
        // OFFSET 0 keeps this lateral projection from being inlined and recalculated for the viewport and output.
        $query->crossJoin(DB::raw('LATERAL (SELECT CASE WHEN '.$this->readableGeometry().'
            THEN CASE WHEN g.location_type = \'project_boundary\' THEN extensions.ST_PointOnSurface(g.geom)
                ELSE g.geom END ELSE NULL END AS display_geom OFFSET 0) AS gis_display'));
    }

    private function readableGeometry(): string
    {
        return "CASE WHEN g.geom IS NOT NULL AND extensions.ST_SRID(g.geom) = 4326
            AND extensions.ST_NDims(g.geom) = 2 AND NOT extensions.ST_IsEmpty(g.geom)
            AND extensions.ST_IsValid(g.geom, 0) AND extensions.ST_NPoints(g.geom) <= ".GisGeometry::MAX_VERTICES."
            AND ((g.location_type = 'project_point' AND extensions.ST_GeometryType(g.geom) = 'ST_Point')
                OR (g.location_type = 'project_boundary' AND extensions.ST_GeometryType(g.geom) IN ('ST_Polygon', 'ST_MultiPolygon')))
            THEN extensions.ST_CoveredBy(g.geom, extensions.ST_MakeEnvelope(-180, -90, 180, 90, 4326)) ELSE FALSE END";
    }

    private function searchPattern(string $value): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value).'%';
    }
}
