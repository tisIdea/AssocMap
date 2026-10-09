<?php

namespace App\Http\Controllers\FieldOfficer;

use App\Http\Controllers\Controller;
use App\Models\MemberApplication;
use App\Services\MemberApplicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MemberApplicationController extends Controller
{
    public function index(Request $request): View
    {
        $currentUser = $request->attributes->get('current_user');

        $statusFilter = $request->query('status', 'pending');

        $statusFilter = in_array(
            $statusFilter,
            [
                'pending',
                'approved',
                'rejected',
            ],
            true
        )
            ? $statusFilter
            : 'pending';

        $applications = MemberApplication::query()
            ->with([
                'association.area.barangay.municipality',
                'sex',
                'position',
                'classifications',
                'submittedBy',
                'reviewedBy',
                'member.classifications',
            ])
            ->whereHas(
                'association',
                function ($query) use ($currentUser) {
                    $query->where(
                        'primary_field_officer_id',
                        $currentUser->id
                    );
                }
            )
            ->where('status', $statusFilter)
            ->orderByDesc('created_at')
            ->get();

        return view(
            'field-officer.member-applications.index',
            [
                'applications' => $applications,
                'statusFilter' => $statusFilter,
            ]
        );
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