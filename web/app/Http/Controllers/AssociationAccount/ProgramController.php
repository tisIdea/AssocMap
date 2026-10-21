<?php

namespace App\Http\Controllers\AssociationAccount;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ApiRequestGuard;
use App\Http\Requests\AssociationProgramRequest;
use App\Models\ProgramActivityRecord;
use App\Models\Training;
use App\Services\Reporting\ReportScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ProgramController extends Controller
{
    public function __construct(private ReportScope $scope)
    {
    }

    public function trainings(AssociationProgramRequest $request): Response
    {
        return $this->directory($request, true);
    }

    public function activities(AssociationProgramRequest $request): Response
    {
        return $this->directory($request, false);
    }

    public function training(AssociationProgramRequest $request, string $training): Response
    {
        try {
            $association = $this->association($request);
            $training = $this->records(Training::class, $association->id)->whereKey($training)->firstOrFail([
                'id', 'association_id', 'related_project_id', 'title', 'description', 'program_year',
                'start_date', 'end_date', 'venue', 'conducted_by', 'status', 'archived_at',
            ]);
            $attendance = $training->participants()->useWritePdo()
                ->join('members as m', 'm.id', '=', 'training_participants.member_id')
                ->where('m.association_id', $association->id);
            $counts = (clone $attendance)->select('attendance_status')->selectRaw('COUNT(*) AS total')
                ->groupBy('attendance_status')->pluck('total', 'attendance_status');
            $participants = $attendance->orderBy('m.last_name')->orderBy('m.first_name')->orderBy('training_participants.id')
                ->paginate(25, ['training_participants.id', 'training_participants.attendance_status',
                    'm.first_name', 'm.middle_name', 'm.last_name', 'm.suffix'])->fragment('training-attendance');
            $this->scope->recheck($request, [], [$association->id]);
            abort_unless(Training::query()->useWritePdo()->whereKey($training->id)->where('association_id', $association->id)->exists(), 404);

            return ApiRequestGuard::secure(response()->view('association.trainings.show', compact('association', 'training', 'participants', 'counts')));
        } catch (QueryException $exception) {
            return $this->unavailable('trainings');
        }
    }

    public function activity(AssociationProgramRequest $request, string $activity): Response
    {
        try {
            $association = $this->association($request);
            $activity = $this->records(ProgramActivityRecord::class, $association->id)->whereKey($activity)->firstOrFail([
                'id', 'association_id', 'related_project_id', 'title', 'description', 'program_year',
                'activity_date', 'venue', 'conducted_by', 'status', 'archived_at',
            ]);
            $this->scope->recheck($request, [], [$association->id]);
            abort_unless(ProgramActivityRecord::query()->useWritePdo()->whereKey($activity->id)->where('association_id', $association->id)->exists(), 404);

            return ApiRequestGuard::secure(response()->view('association.program-activities.show', compact('association', 'activity')));
        } catch (QueryException $exception) {
            return $this->unavailable('program-activities');
        }
    }

    private function directory(AssociationProgramRequest $request, bool $training): Response
    {
        $module = $training ? 'trainings' : 'program-activities';
        try {
            $association = $this->association($request);
            $filters = $request->filters();
            $query = $this->records($training ? Training::class : ProgramActivityRecord::class, $association->id);
            if ($filters['search'] !== '') {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($filters['search'])).'%';
                $query->whereRaw("LOWER(title) LIKE ? ESCAPE '!'", [$pattern]);
            }
            if ($filters['year'] !== '') $query->where('program_year', $filters['year']);
            $dates = $training ? ['start_date', 'end_date'] : ['activity_date'];
            $records = $query->select(array_merge(['id', 'association_id', 'related_project_id', 'title',
                'program_year', 'venue', 'status', 'archived_at'], $dates))
                ->orderByDesc('program_year')->orderByDesc($dates[0])->orderBy('title')->orderBy('id')
                ->paginate(25)->appends($filters);
            $this->scope->recheck($request, [], [$association->id]);

            return ApiRequestGuard::secure(response()->view('association.'.$module.'.index', compact('association', 'filters', 'records')));
        } catch (QueryException $exception) {
            return $this->unavailable($module);
        }
    }

    private function records(string $model, string $associationId): Builder
    {
        return $model::query()->useWritePdo()->where('association_id', $associationId)
            ->with(['relatedProject' => fn ($query) => $query->useWritePdo()->where('association_id', $associationId)
                ->select(['id', 'association_id', 'title'])]);
    }

    private function association(AssociationProgramRequest $request): object
    {
        $actor = $this->scope->actor($request);
        abort_unless($actor->role->code === 'association_account', 403);
        $association = $this->scope->associations($actor)->first(['a.id', 'a.name']);
        abort_unless($association, 403);

        return $association;
    }

    private function unavailable(string $module): Response
    {
        Log::warning('Association program information unavailable.', ['code' => 'association_program_database_unavailable']);

        return ApiRequestGuard::secure(response()->view('association.'.$module.'.index', ['unavailable' => true], 503));
    }
}
