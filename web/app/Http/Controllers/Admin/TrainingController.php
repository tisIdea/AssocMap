<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTrainingRequest;
use App\Models\Association;
use App\Models\Member;
use App\Models\Project;
use App\Models\SaadActivity;
use App\Models\Training;
use App\Services\TrainingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class TrainingController extends Controller
{
    private const TRAINING_ACTIVITY_CODES = [
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
                'scheduled',
                'completed',
                'cancelled',
                'archived',
            ],
            true
        )
            ? $statusFilter
            : '';

        $trainings = Training::query()
            ->with([
                'association.area.barangay.municipality',
                'relatedProject',
                'saadActivity',
                'participants.member',
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
                        'scheduled',
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
            ->orderByDesc('start_date')
            ->orderBy('title')
            ->get();

        $associations = Association::query()
            ->whereNull('archived_at')
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        $activities = SaadActivity::query()
            ->where('is_active', true)
            ->whereIn(
                'code',
                self::TRAINING_ACTIVITY_CODES
            )
            ->orderBy('id')
            ->get();

        $projects = Project::query()
            ->whereNotIn('status', ['completed', 'terminated'])
            ->whereNull('archived_at')
            ->orderByDesc('program_year')
            ->orderBy('title')
            ->get();

        $members = Member::query()
            ->where('member_status', 'active')
            ->whereNull('archived_at')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $trainingRows = $trainings
            ->map(
                fn (Training $training) =>
                    $this->mapTrainingRow(
                        $training
                    )
            )
            ->values()
            ->all();

        return view(
            'admin.trainings.index',
            [
                'trainings' =>
                    $trainings,

                'trainingRows' =>
                    $trainingRows,

                'associations' =>
                    $associations,

                'activities' =>
                    $activities,

                'projects' =>
                    $projects,

                'members' =>
                    $members,

                'statusFilter' =>
                    $statusFilter,
            ]
        );
    }

    public function store(
        StoreTrainingRequest $request,
        TrainingService $trainingService
    ): RedirectResponse {
        $currentUser =
            $request->attributes->get(
                'current_user'
            );

        $trainingService->create(
            $request->validated(),
            $currentUser
        );

        return redirect()
            ->route(
                'admin.trainings.index'
            )
            ->with(
                'success',
                'Training created successfully.'
            );
    }

    public function update(
        StoreTrainingRequest $request,
        Training $training,
        TrainingService $trainingService
    ): RedirectResponse {
        $currentUser =
            $request->attributes->get(
                'current_user'
            );

        $trainingService->update(
            $training,
            $request->validated(),
            $currentUser
        );

        return redirect()
            ->route(
                'admin.trainings.index'
            )
            ->with(
                'success',
                'Training updated successfully.'
            );
    }

    public function archive(
        Request $request,
        Training $training,
        TrainingService $trainingService
    ): RedirectResponse {
        $currentUser =
            $request->attributes->get(
                'current_user'
            );

        $trainingService->archive(
            $training,
            $currentUser
        );

        return redirect()
            ->route(
                'admin.trainings.index'
            )
            ->with(
                'success',
                'Training archived successfully.'
            );
    }

    public function unarchive(
        Request $request,
        Training $training,
        TrainingService $trainingService
    ): RedirectResponse {
        $currentUser =
            $request->attributes->get(
                'current_user'
            );

        $trainingService->unarchive(
            $training,
            $currentUser
        );

        return redirect()
            ->route(
                'admin.trainings.index'
            )
            ->with(
                'success',
                'Training restored successfully.'
            );
    }

    private function mapTrainingRow(
        Training $training
    ): array {
        $association =
            $training->association;

        $area =
            $association?->area;

        $barangay =
            $area?->barangay;

        $municipality =
            $barangay?->municipality;

        return [
            'id' =>
                $training->id,

            'project_finalized' => $training->relatedProject?->isFinalized() ?? false,
            'expected_updated_at' => $training->updated_at?->format('Y-m-d\TH:i:s.uP'),

            'association_id' =>
                $training->association_id,

            'association' =>
                $association?->name ?? '—',

            'municipality' =>
                $municipality?->name ?? '—',

            'barangay' =>
                $barangay?->name ?? '—',

            'related_project_id' =>
                $training->related_project_id,

            'related_project' =>
                $training->relatedProject?->title
                    ?? '—',

            'saad_activity_id' =>
                $training->saad_activity_id,

            'saad_activity' =>
                $training->saadActivity?->name
                    ?? '—',

            'title' =>
                $training->title,

            'description' =>
                $training->description,

            'program_year' =>
                $training->program_year,

            'start_date' =>
                $training->start_date
                    ? Carbon::parse(
                        $training->start_date
                    )->format('Y-m-d')
                    : null,

            'end_date' =>
                $training->end_date
                    ? Carbon::parse(
                        $training->end_date
                    )->format('Y-m-d')
                    : null,

            'venue' =>
                $training->venue,

            'conducted_by' =>
                $training->conducted_by,

            'training_cost' =>
                $training->training_cost,

            'status' =>
                ucfirst(
                    $training->status
                ),

            'status_value' =>
                $training->status,

            'remarks' =>
                $training->remarks,

            'participants' =>
                $training->participants
                    ->map(
                        fn ($participant) => [
                            'member_name' => collect([$participant->member?->first_name, $participant->member?->middle_name,
                                $participant->member?->last_name, $participant->member?->suffix])->filter(fn ($part) => filled($part))->implode(' '),
                            'id' =>
                                $participant->id,

                            'member_id' =>
                                $participant->member_id,

                            'attendance_status' =>
                                $participant->attendance_status,

                            'remarks' =>
                                $participant->remarks,
                        ]
                    )
                    ->values()
                    ->all(),

            'participant_count' =>
                $training
                    ->participants
                    ->count(),

            'archived' =>
                $training->archived_at
                    !== null,

            'update_url' =>
                route(
                    'admin.trainings.update',
                    $training
                ),

            'archive_url' =>
                route(
                    'admin.trainings.archive',
                    $training
                ),

            'unarchive_url' =>
                route(
                    'admin.trainings.unarchive',
                    $training
                ),
        ];
    }
}
