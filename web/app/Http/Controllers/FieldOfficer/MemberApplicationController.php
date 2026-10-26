<?php

namespace App\Http\Controllers\FieldOfficer;

use App\Http\Controllers\Controller;
use App\Http\Requests\MemberRegisterRequest;
use App\Services\Members\MemberApplicationService;
use App\Models\MemberApplication;
use App\Services\Members\MemberDirectoryQueryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MemberApplicationController extends Controller
{
    public function index(MemberRegisterRequest $request, MemberDirectoryQueryService $directory): View
    {
        $currentUser = $request->attributes->get('current_user');
        $filters = $request->filters();
        $statusFilter = $filters['status'];
        $associations = $directory->associations($currentUser, true);
        if ($filters['association'] !== '') abort_unless($associations->contains('id', $filters['association']), 404);
        $scope = MemberApplication::query()->useWritePdo()->whereHas('association',
            fn ($query) => $query->where('primary_field_officer_id', $currentUser->id));
        $summary = $directory->applicationSummary($scope);
        $applications = $directory->applications($scope, $filters, true, $summary);

        return view('field-officer.member-applications.index', compact('applications', 'statusFilter', 'filters', 'associations', 'summary'));
    }

    public function approve(
        Request $request,
        MemberApplication $memberApplication,
        MemberApplicationService $memberApplicationService
    ): RedirectResponse {
        $currentUser = $request->attributes->get('current_user');

        $memberApplicationService->approve(
            $memberApplication,
            $currentUser
        );

        return back()
            ->with(
                'success',
                'Member application approved successfully.'
            );
    }

    public function reject(
        Request $request,
        MemberApplication $memberApplication,
        MemberApplicationService $memberApplicationService
    ): RedirectResponse {
        $request->validate([
            'rejection_reason' => [
                'required',
                'string',
                'max:1000',
            ],
        ]);

        $currentUser = $request->attributes->get('current_user');

        $memberApplicationService->reject(
            $memberApplication,
            $currentUser,
            $request->string('rejection_reason')->toString()
        );

        return back()
            ->with(
                'success',
                'Member application rejected.'
            );
    }
}
