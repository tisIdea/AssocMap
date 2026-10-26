<?php

namespace App\Services\Associations;

use App\Http\Requests\AssociationIndexRequest;
use App\Models\Area;
use App\Models\Association;
use App\Models\AssociationRegistration;
use App\Models\Barangay;
use App\Models\Member;
use App\Models\Municipality;
use App\Models\ProgramActivityRecord;
use App\Models\Project;
use App\Models\RegistrationAgency;
use App\Models\Training;
use App\Models\UserProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class AssociationDirectoryQueryService
{
    private const EDITOR_CHOICE_LIMIT = 5000;

    public function __construct(private AssociationOfficerService $officers, private AssociationLifecycleService $lifecycle,
        private AssociationGeographyService $geography, private FieldOfficerAssignmentService $assignments) {}

    private function barangayRelation(): string
    {
        return $this->geography->barangayRelation();
    }

    /** @return Builder<Association> */
    public function scoped(UserProfile $actor): Builder
    {
        $this->officers->authorize($actor);

        return Association::query()->when($actor->role->code === 'field_officer',
            fn ($query) => $query->where('associations.primary_field_officer_id', $actor->id)
                ->whereNull('associations.archived_at'));
    }

    /** @return list<string> */
    private function locationRelations(): array
    {
        $barangay = $this->barangayRelation();

        return ['area:id,barangay_id,address,is_active', $barangay.':id,municipality_id,name,is_active',
            $barangay.'.municipality:id,name,province,region,is_active', 'currentPresident'];
    }

    /**
     * @param  array{q: string, status: string, municipality: string, barangay: string, field_officer: string, sort: string}  $filters
     * @return Builder<Association>
     */
    private function filtered(UserProfile $actor, array $filters, bool $geography = true): Builder
    {
        $query = $this->scoped($actor);
        $status = $filters['status'];
        if ($status === 'archived') {
            $query->whereNotNull('associations.archived_at');
        } elseif ($status !== 'all') {
            $query->whereNull('associations.archived_at');
            if ($status !== '') {
                $query->where('associations.status', $status);
            }
        }
        foreach (['municipality' => $this->barangayRelation().'.municipality', 'barangay' => $this->barangayRelation(),
            'field_officer' => 'primaryFieldOfficer'] as $key => $relation) {
            if ($filters[$key] !== '') {
                $query->whereHas($relation,
                    fn ($related) => $related->where($key === 'field_officer' ? 'display_name' : 'name', $filters[$key]));
            }
        }
        if ($filters['q'] !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($filters['q'])).'%';
            $query->where(function ($search) use ($pattern, $geography) {
                $search->whereRaw("LOWER(associations.name) LIKE ? ESCAPE '!'", [$pattern]);
                if ($geography) {
                    $search->orWhereRaw("LOWER(associations.acronym) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("LOWER(associations.status) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("CAST(associations.saad_phase2_join_date AS TEXT) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereHas('primaryFieldOfficer', fn ($officer) => $officer->whereRaw("LOWER(display_name) LIKE ? ESCAPE '!'", [$pattern]))
                        ->orWhereHas('area', fn ($area) => $area->whereRaw("LOWER(address) LIKE ? ESCAPE '!'", [$pattern]))
                        ->orWhereHas($this->barangayRelation(), fn ($barangay) => $barangay->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]))
                        ->orWhereHas($this->barangayRelation().'.municipality', fn ($municipality) => $municipality->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]));
                }
            });
        }
        $sort = $filters['sort'];
        if (in_array($sort, ['newest', 'oldest'], true)) {
            $query->orderBy($geography ? 'saad_phase2_join_date' : 'created_at', $sort === 'newest' ? 'desc' : 'asc');
        } elseif ($sort === 'status') {
            $query->orderBy('associations.status');
        } elseif ($sort === 'field_officer') {
            $query->orderBy(UserProfile::query()->select('display_name')
                ->whereColumn('user_profiles.id', 'associations.primary_field_officer_id'));
        } elseif (in_array($sort, ['municipality', 'barangay'], true)) {
            $location = $this->geography->usesDirectGeography()
                ? Barangay::query()->whereColumn('barangays.id', 'associations.barangay_id')
                : Area::query()->join('barangays', 'barangays.id', '=', 'areas.barangay_id')
                    ->whereColumn('areas.id', 'associations.area_id');
            $query->orderBy($location
                ->join('municipalities', 'municipalities.id', '=', 'barangays.municipality_id')
                ->select($sort === 'municipality' ? 'municipalities.name' : 'barangays.name'));
        }

        return $query->orderByRaw('LOWER(associations.name) '.($sort === 'za' ? 'DESC' : 'ASC'))->orderBy('associations.id');
    }

    /**
     * @param  array{q: string, status: string, municipality: string, barangay: string, field_officer: string, sort: string}  $filters
     * @return LengthAwarePaginator<int, Association>
     */
    public function records(UserProfile $actor, array $filters): LengthAwarePaginator
    {
        return $this->filtered($actor, $filters)->select(['associations.id', 'name', 'acronym', 'area_id', 'status',
            'archived_at', 'primary_field_officer_id', 'saad_phase2_join_date', 'created_at'])
            ->when($this->geography->usesDirectGeography(), fn ($query) => $query->addSelect('associations.barangay_id'))
            ->with($this->locationRelations())
            ->when($actor->role->code === 'system_admin', fn ($query) => $query->with('primaryFieldOfficer:id,display_name'))
            ->addSelect(['members_count' => Member::query()->selectRaw('COUNT(*)')
                ->whereColumn('members.association_id', 'associations.id')->where('member_status', 'active')->whereNull('archived_at')])
            ->paginate(25)->appends($filters);
    }

    /**
     * @param  array{q: string, status: string, municipality: string, barangay: string, field_officer: string, sort: string}  $filters
     * @return LengthAwarePaginator<int, Association>
     */
    public function officerRecords(UserProfile $actor, array $filters): LengthAwarePaginator
    {
        return $this->filtered($actor, $filters, false)->select(['associations.id', 'name', 'status',
            'archived_at', 'primary_field_officer_id', 'created_at'])->with('currentPresident')
            ->addSelect(['officers_count' => Member::query()->selectRaw('COUNT(*)')
                ->whereColumn('members.association_id', 'associations.id')->where('member_status', 'active')->whereNull('archived_at')
                ->whereHas('position', fn ($position) => $position->where('is_officer', true))])
            ->paginate(25)->appends($filters);
    }

    /** @return array{total: int, active: int, inactive: int, archived: int} */
    public function summary(UserProfile $actor): array
    {
        $counts = $this->scoped($actor)->selectRaw("COUNT(*) AS total,
            COUNT(CASE WHEN archived_at IS NULL AND status = 'active' THEN 1 END) AS active,
            COUNT(CASE WHEN archived_at IS NULL AND status = 'inactive' THEN 1 END) AS inactive,
            COUNT(CASE WHEN archived_at IS NOT NULL THEN 1 END) AS archived")->toBase()->first();

        return array_map('intval', (array) $counts);
    }

    /** @return array{municipality: list<string>, barangay: list<string>, field_officer: list<string>} */
    public function filterChoices(UserProfile $actor): array
    {
        $query = $this->scoped($actor);
        if ($this->geography->usesDirectGeography()) {
            $query->join('barangays', 'barangays.id', '=', 'associations.barangay_id');
        } else {
            $query->join('areas', 'areas.id', '=', 'associations.area_id')->join('barangays', 'barangays.id', '=', 'areas.barangay_id');
        }
        $locations = $query
            ->join('municipalities', 'municipalities.id', '=', 'barangays.municipality_id')
            ->leftJoin('user_profiles as assigned_officer', 'assigned_officer.id', '=', 'associations.primary_field_officer_id')
            ->distinct()->get(['municipalities.name as municipality', 'barangays.name as barangay', 'assigned_officer.display_name as field_officer']);

        return collect(['municipality', 'barangay', 'field_officer'])->mapWithKeys(fn ($key) => [
            $key => $locations->pluck($key)->filter()->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
        ])->all();
    }

    /**
     * @param  'members'|'projects'|'trainings'|'activities'|'registrations'  $section
     * @param  array<string, mixed>  $parameters
     * @return array{association: Association, section: string,
     *     related: LengthAwarePaginator<int, Member|Project|Training|ProgramActivityRecord|AssociationRegistration>, canViewActivities: bool}
     */
    public function details(UserProfile $actor, Association $association, string $section, array $parameters): array
    {
        $this->officers->authorize($actor, $association);
        abort_if($actor->role->code === 'field_officer' && $association->archived_at !== null, 403);
        $association->load([...$this->locationRelations(), 'primaryFieldOfficer:id,display_name']);
        $canViewActivities = $actor->role->code === 'system_admin' || $this->lifecycle->isOperational($association);
        if (in_array($section, ['trainings', 'activities'], true)) {
            abort_unless($canViewActivities, 403);
        }
        $query = match ($section) {
            'members' => Member::query()->where('association_id', $association->id)->with('position:id,name')
                ->orderBy('last_name')->orderBy('first_name')->orderBy('id')
                ->select(['id', 'association_id', 'first_name', 'middle_name', 'last_name', 'suffix', 'position_id', 'member_status', 'archived_at']),
            'projects' => Project::query()->where('association_id', $association->id)->orderBy('title')->orderBy('id')
                ->select(['id', 'association_id', 'title', 'status', 'archived_at']),
            'trainings' => Training::query()->where('association_id', $association->id)->orderByDesc('start_date')->orderBy('id')
                ->select(['id', 'association_id', 'title', 'start_date', 'status', 'archived_at']),
            'activities' => ProgramActivityRecord::query()->where('association_id', $association->id)->orderByDesc('activity_date')->orderBy('id')
                ->select(['id', 'association_id', 'title', 'activity_date', 'status', 'archived_at']),
            'registrations' => AssociationRegistration::query()->where('association_id', $association->id)->with('agency:id,name,registration_kind,is_active')
                ->orderByDesc('is_primary')->orderByDesc('registration_date')->orderBy('id')
                ->select(['id', 'association_id', 'agency_id', 'registration_number', 'registration_date', 'expiration_date', 'is_primary',
                    ...($actor->role->code === 'system_admin' ? ['remarks'] : [])]),
        };
        $related = $query->paginate(25, ['*'], 'related_page')->appends(array_replace($parameters, ['section' => $section]));

        return compact('association', 'section', 'related', 'canViewActivities');
    }

    /**
     * @return array{}|array{catalog: array<string, mixed>, module: array<string, mixed>, associationOpenEditor: bool,
     *     associationEditorRecord: null|array{id: string, name: string, acronym: ?string, area_id: string,
     *         primary_field_officer_id: string, joined_at: ?string, status_value: string, update_url: string}}
     */
    public function editorData(AssociationIndexRequest $request, ?Association $association = null): array
    {
        $recovering = $request->old('association_form') === 'editor';
        $editor = $request->validated('editor') ?? ($recovering ? $request->old('association_record_id', 'create') : null);
        if (! $editor) {
            return [];
        }
        $actor = $request->attributes->get('current_user');
        abort_unless($actor->role->code === 'system_admin', 403);
        $associationEditorRecord = null;
        if ($editor !== 'create' && Str::isUuid($editor)) {
            if ($association) {
                abort_unless(strtolower($association->id) === strtolower($editor), 404);
            }
            $record = $this->scoped($actor)->whereKey($editor)->firstOrFail();
            abort_if($record->archived_at !== null, 403);
            $associationEditorRecord = [
                'id' => $record->id, 'name' => $record->name, 'acronym' => $record->acronym, 'area_id' => $record->area_id,
                'primary_field_officer_id' => $record->primary_field_officer_id, 'joined_at' => $record->saad_phase2_join_date?->format('Y-m-d'),
                'expected_primary_field_officer_id' => $record->primary_field_officer_id,
                'status_value' => $record->status, 'update_url' => route('admin.associations.update', $record),
            ];
            if ($this->geography->usesDirectGeography()) {
                $record->load('barangay:id,municipality_id');
                $associationEditorRecord += ['barangay_id' => $record->barangay_id,
                    'municipality_id' => $record->barangay?->municipality_id, 'specific_address' => $record->specific_address,
                    'expected_updated_at' => $record->updated_at?->format('Y-m-d\TH:i:s.uP')];
            }
            $accreditations = AssociationRegistration::query()->where('association_id', $record->id)
                ->whereHas('agency', fn ($query) => $query->where('registration_kind', 'accreditation'))
                ->with('agency:id,name,is_active')->orderByDesc('registration_date')->orderBy('id')->get();
            $associationEditorRecord['accreditations'] = $accreditations->map(fn ($item) => [
                'id' => $item->id, 'agency' => $item->agency->name, 'agency_active' => $item->agency->is_active,
                'registration_number' => $item->registration_number, 'registration_date' => $item->registration_date->format('Y-m-d'),
                'expiration_date' => $item->expiration_date?->format('Y-m-d'), 'is_primary' => $item->is_primary,
                'remarks' => $item->remarks, 'expected_updated_at' => $item->updated_at?->format('Y-m-d\TH:i:s.uP'),
                'update_url' => route('admin.associations.index', ['editor' => $record->id, 'accreditation' => $item->id]),
            ])->all();
            $selectedId = $request->validated('accreditation') ?? ($recovering ? $request->old('registration.id') : null);
            $selected = is_string($selectedId) ? collect($associationEditorRecord['accreditations'])->firstWhere('id', $selectedId) : null;
            if ($request->validated('accreditation')) {
                abort_unless($selected && $selected['agency_active'] && $this->lifecycle->isOperational($record), 404);
            }
            $associationEditorRecord['selected_accreditation'] = $selected && $selected['agency_active'] && $this->lifecycle->isOperational($record) ? $selected : null;
        }
        if ($editor === 'create' && $request->validated('accreditation')) {
            abort(404);
        }
        $catalog = require resource_path('ui/admin.php');
        $areaQuery = Area::query()->where('is_active', true)
            ->with(['barangay:id,name,municipality_id', 'barangay.municipality:id,name'])
            ->orderBy('address')->orderBy('id');
        if ($this->geography->enabled()) {
            $areaQuery->whereHas('barangay', fn ($query) => $query->where('is_active', true)
                ->whereHas('municipality', fn ($parent) => $parent->where('is_active', true)));
        }
        $areas = $this->geography->enabled() ? $this->editorChoices($areaQuery, ['id', 'barangay_id', 'address'])
            : $areaQuery->get(['id', 'barangay_id', 'address']);
        $catalog['options']['area_records'] = $areas->map(fn ($area) => [
            'value' => $area->id, 'label' => $area->address, 'barangay' => $area->barangay?->name,
            'barangay_id' => $area->barangay_id, 'municipality' => $area->barangay?->municipality?->name,
            'municipality_id' => $area->barangay?->municipality_id,
        ])->all();
        if ($this->geography->enabled()) {
            $catalog['options']['municipality_records'] = $this->editorChoices(Municipality::where('is_active', true)->orderBy('name')->orderBy('id'), ['id', 'name'])
                ->map(fn ($record) => ['value' => $record->id, 'label' => $record->name])->all();
            $catalog['options']['barangay_records'] = $this->editorChoices(Barangay::where('is_active', true)
                ->whereHas('municipality', fn ($query) => $query->where('is_active', true))->orderBy('name')->orderBy('id'), ['id', 'name', 'municipality_id'])
                ->map(fn ($record) => ['value' => $record->id, 'label' => $record->name, 'municipality_id' => $record->municipality_id])->all();
        }
        $officers = UserProfile::query()->where('is_active', true)->whereNotNull('activated_at')
            ->whereHas('role', fn ($role) => $role->where('code', 'field_officer')->where('is_active', true))
            ->with('role')->orderBy('display_name')->orderBy('id')->get()
            ->filter(fn ($officer) => $this->assignments->eligibleOfficer($officer));
        $catalog['options']['field_officer_records'] = $officers
            ->map(fn ($user) => ['value' => $user->id, 'label' => $user->display_name])->values()->all();
        if ($associationEditorRecord && ! $officers->contains('id', $associationEditorRecord['primary_field_officer_id'])) {
            $recordedOfficer = UserProfile::find($associationEditorRecord['primary_field_officer_id']);
            $catalog['options']['field_officer_records'][] = [
                'value' => $associationEditorRecord['primary_field_officer_id'],
                'label' => ($recordedOfficer?->display_name ?? 'Recorded officer').' (retained current assignment)',
            ];
            $associationEditorRecord['retained_officer'] = true;
        }
        $catalog['options']['registration_agencies'] = RegistrationAgency::where('is_active', true)->orderBy('name')
            ->get(['id', 'name', 'registration_kind'])->map(fn ($agency) => [
                'value' => $agency->id, 'label' => $agency->name.' ('.$agency->registration_kind.')',
            ])->all();
        $associationOpenEditor = ! $recovering;
        $module = $catalog['modules']['associations'];
        $module['rows'] = $associationEditorRecord ? [$associationEditorRecord] : [];

        return compact('catalog', 'module', 'associationEditorRecord', 'associationOpenEditor');
    }

    private function editorChoices(Builder $query, array $columns): Collection
    {
        $records = $query->limit(self::EDITOR_CHOICE_LIMIT + 1)->get($columns);
        abort_if($records->count() > self::EDITOR_CHOICE_LIMIT, 503, 'The geographic catalog exceeds editor capacity. Contact the administrator.');

        return $records;
    }
}
