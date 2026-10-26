<?php

namespace App\Services\Programs;

use App\Models\Association;
use App\Models\Commodity;
use App\Models\FisheriesProjectCategory;
use App\Models\Member;
use App\Models\Project;
use App\Models\ProgramActivityRecord;
use App\Models\SaadActivity;
use App\Models\Training;
use App\Models\TrainingParticipant;
use App\Models\UserProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Str;

/**
 * @phpstan-type LookupPage array{
 *     options: SupportCollection<int, array{value: string, label: string, association_id?: string}>,
 *     page: int,
 *     has_more: bool
 * }
 */
class ProgramEditorQueryService
{
    public function __construct(private ProgramDirectoryQueryService $directory) {}

    /** @return Builder<Association> */
    public function associationScope(UserProfile $actor): Builder
    {
        return Association::query()->whereNull('archived_at')->where('status', 'active')
            ->when($actor->role->code === 'field_officer', fn ($query) => $query->where('primary_field_officer_id', $actor->id));
    }

    /** @return LookupPage|array{projects: LookupPage, members: LookupPage} */
    public function lookup(Request $request): array
    {
        $actor = $request->attributes->get('current_user');
        $kind = $request->input('lookup', 'associations');
        $associationId = $request->input('association_id');
        $trainingId = $request->input('training_id');
        if ($kind === 'training_choices') return ['projects' => $this->choicePage($actor, 'projects', $associationId),
            'members' => $this->choicePage($actor, 'members', $associationId, trainingId: $trainingId)];

        return $this->choicePage($actor, $kind, $associationId, $request->string('term')->toString(), (int) $request->input('lookup_page', 1), $trainingId);
    }

    /**
     * @param 'associations'|'activities'|'projects'|'members' $kind
     * @return Builder<Association>|Builder<SaadActivity>|Builder<Project>|Builder<Member>
     */
    private function choiceQuery(UserProfile $actor, string $kind, ?string $associationId = null, string $term = '', ?string $trainingId = null): Builder
    {
        if ($kind === 'activities') {
            return SaadActivity::where('is_active', true)
                ->whereNotIn('code', ['FPL_LPD', 'SP_LT', 'FPL_CST', 'MAED_ERT', 'PM_CB', 'PM_CD'])
                ->when($term !== '', fn ($query) => $query->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$this->directory->pattern($term)]))
                ->orderBy('id')->select(['id', 'name']);
        }
        if ($kind === 'associations') {
            return $this->associationScope($actor)->when($term !== '',
                fn ($query) => $query->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$this->directory->pattern($term)]))
                ->orderBy('name')->orderBy('id')->select(['id', 'name']);
        }
        $query = $kind === 'projects' ? Project::query()->whereNull('archived_at')->whereNull('closed_at')
            ->whereNotIn('status', ['completed', 'terminated']) : Member::query()->whereNull('archived_at')->where('member_status', 'active');
        $query->where('association_id', $associationId)->whereHas('association', fn ($owner) => $owner
            ->whereNull('archived_at')->where('status', 'active')
            ->when($actor->role->code === 'field_officer', fn ($owner) => $owner->where('primary_field_officer_id', $actor->id)));
        if ($kind === 'projects') return $query->when($term !== '', fn ($query) => $query
            ->whereRaw("LOWER(title) LIKE ? ESCAPE '!'", [$this->directory->pattern($term)]))
            ->orderByDesc('program_year')->orderBy('title')->orderBy('id')->select(['id', 'association_id', 'title']);
        if ($trainingId !== null) $query->whereNotIn('id', TrainingParticipant::query()->select('member_id')
            ->whereIn('training_id', $this->directory->scope($actor, 'trainings')->select('trainings.id')->whereKey($trainingId)));
        if ($term !== '') {
            foreach (preg_split('/\s+/', $term, -1, PREG_SPLIT_NO_EMPTY) as $word) $query->where(fn ($names) => $names
                ->whereRaw("LOWER(first_name) LIKE ? ESCAPE '!'", [$this->directory->pattern($word)])
                ->orWhereRaw("LOWER(middle_name) LIKE ? ESCAPE '!'", [$this->directory->pattern($word)])
                ->orWhereRaw("LOWER(suffix) LIKE ? ESCAPE '!'", [$this->directory->pattern($word)])
                ->orWhereRaw("LOWER(last_name) LIKE ? ESCAPE '!'", [$this->directory->pattern($word)]));
        }

        return $query->orderBy('last_name')->orderBy('first_name')->orderBy('id')
            ->select(['id', 'association_id', 'first_name', 'middle_name', 'last_name', 'suffix']);
    }

    /**
     * @param 'associations'|'activities'|'projects'|'members' $kind
     * @return LookupPage
     */
    private function choicePage(UserProfile $actor, string $kind, ?string $associationId = null, string $term = '', int $page = 1, ?string $trainingId = null): array
    {
        $records = $this->choiceQuery($actor, $kind, $associationId, $term, $trainingId)->offset(($page - 1) * 25)->limit(26)->get();

        return [
            'options' => $records->take(25)->map(fn ($record) => [
                'value' => (string) $record->id,
                'label' => match ($kind) {
                    'members' => collect([$record->first_name, $record->middle_name, $record->last_name, $record->suffix])
                        ->filter(fn ($part) => filled($part))->implode(' '),
                    'projects' => $record->title,
                    default => $record->name,
                },
            ] + (in_array($kind, ['associations', 'activities'], true) ? [] : ['association_id' => $record->association_id]))->values(),
            'page' => $page,
            'has_more' => $records->count() > 25,
        ];
    }

    /** @return SupportCollection<int, array{value: string, label: string}> */
    private function associationChoices(Request $request, ?string $selected): SupportCollection
    {
        $actor = $request->attributes->get('current_user');
        $associationOptions = collect($this->choicePage($actor, 'associations')['options']);
        if (is_string($selected) && Str::isUuid($selected) && !$associationOptions->contains('value', $selected)) {
            $record = $this->associationScope($actor)->whereKey($selected)->first(['id', 'name']);
            if ($record) $associationOptions->push(['value' => $record->id, 'label' => $record->name]);
        }

        return $associationOptions;
    }

    /**
     * @return array{
     *     associations: SupportCollection<int, object{id: string, name: string}>,
     *     activities: Collection<int, SaadActivity>, projects: Collection<int, Project>
     * }
     */
    public function activityData(Request $request, ?ProgramActivityRecord $record = null): array
    {
        $actor = $request->attributes->get('current_user');
        $restoring = $request->old('program_activity_form') === 'editor'
            && (string) $request->old('program_activity_record_id', '') === (string) $record?->id;
        $selected = $restoring ? $request->old('association_id') : $record?->association_id;
        $selected = is_string($selected) && Str::isUuid($selected) ? $selected : null;
        $associations = $this->associationChoices($request, $selected)
            ->map(fn ($choice) => (object) ['id' => $choice['value'], 'name' => $choice['label']]);
        $activities = $this->choiceQuery($actor, 'activities')->limit(25)->get();
        $selectedActivity = $restoring ? $request->old('saad_activity_id') : $record?->saad_activity_id;
        if (is_scalar($selectedActivity) && ctype_digit((string) $selectedActivity) && !$activities->contains('id', $selectedActivity)) {
            $activity = $this->choiceQuery($actor, 'activities')->whereKey($selectedActivity)->first();
            if ($activity) $activities->push($activity);
        }
        $projects = $selected ? $this->choiceQuery($actor, 'projects', $selected)->limit(25)->get() : new Collection;
        $selectedProject = $restoring ? $request->old('related_project_id') : $record?->related_project_id;
        if (is_string($selectedProject) && Str::isUuid($selectedProject) && !$projects->contains('id', $selectedProject)) {
            $project = $this->choiceQuery($actor, 'projects', $selected)->whereKey($selectedProject)->first();
            // Retain recorded history even when it is no longer eligible for a new link.
            if (!$project && $selectedProject === $record?->related_project_id) $project = $record->relatedProject;
            if ($project) $projects->push($project);
        }

        return compact('associations', 'activities', 'projects');
    }

    /**
     * @return array{
     *     associations: SupportCollection<int, object{id: string, name: string}>,
     *     activities: Collection<int, SaadActivity>, projects: Collection<int, Project>, members: Collection<int, Member>
     * }
     */
    public function trainingData(Request $request, ?Training $training = null): array
    {
        $actor = $request->attributes->get('current_user');
        $restoring = $request->old('training_form') === 'editor'
            && (string) $request->old('training_record_id', '') === (string) $training?->id;
        $selected = $restoring ? $request->old('association_id') : $training?->association_id;
        $selected = is_string($selected) && Str::isUuid($selected) ? $selected : null;
        $associations = $this->associationChoices($request, $selected)->map(fn ($choice) => (object) ['id' => $choice['value'], 'name' => $choice['label']]);
        $activities = SaadActivity::where('is_active', true)->whereIn('code', ['SP_LT', 'FPL_CST', 'MAED_ERT', 'PM_CB', 'PM_CD'])
            ->orderBy('id')->get(['id', 'name']);
        $projects = $selected ? $this->choiceQuery($actor, 'projects', $selected)->limit(25)->get() : new Collection;
        $members = $selected ? $this->choiceQuery($actor, 'members', $selected, trainingId: $training?->id)->limit(25)->get() : new Collection;
        $selectedProject = $restoring ? $request->old('related_project_id') : $training?->related_project_id;
        if (is_string($selectedProject) && Str::isUuid($selectedProject) && !$projects->contains('id', $selectedProject)) {
            $record = $this->choiceQuery($actor, 'projects', $selected)->whereKey($selectedProject)->first();
            if ($record) $projects->push($record);
        }
        if ($restoring) {
            $ids = collect((array) $request->old('participants', []))->pluck('member_id')
                ->filter(fn ($id) => is_string($id) && Str::isUuid($id))->diff($members->modelKeys())->values();
            if ($ids->isNotEmpty()) $members = $members->merge($this->choiceQuery($actor, 'members', $selected)->whereKey($ids->all())->get());
        }

        return compact('associations', 'activities', 'projects', 'members');
    }

    /** @return array{catalog: array<string, mixed>} */
    public function projectData(Request $request, ?Project $project = null): array
    {
        $selected = $request->old('project_form') === 'editor'
            ? $request->old('association_id') : $project?->association_id;
        $associationOptions = $this->associationChoices($request, is_string($selected) ? $selected : null);
        $catalog = require resource_path('ui/admin.php');
        $catalog['options'] = array_replace($catalog['options'], [
            'association_records' => $associationOptions->all(),
            'fisheries_category_records' => FisheriesProjectCategory::where('is_active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($category) => ['value' => $category->id, 'label' => $category->name])->all(),
            'commodity_records' => Commodity::where('is_active', true)->orderBy('name')->get(['id', 'name', 'category_id', 'source'])
                ->map(fn ($commodity) => ['value' => $commodity->id, 'label' => $commodity->name,
                    'category_id' => $commodity->category_id, 'source' => $commodity->source])->all(),
        ]);

        return compact('catalog');
    }
}
