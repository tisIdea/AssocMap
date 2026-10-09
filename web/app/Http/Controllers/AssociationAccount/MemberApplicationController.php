<?php

namespace App\Http\Controllers\AssociationAccount;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMemberApplicationRequest;
use App\Models\AssociationAccount;
use App\Models\AssociationPosition;
use App\Models\MemberApplication;
use App\Models\MemberClassification;
use App\Models\SexCategory;
use App\Services\MemberApplicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MemberApplicationController extends Controller
{
    public function index(Request $request): View
    {
        $currentUser = $request->attributes->get('current_user');

        $associationAccount = AssociationAccount::query()
            ->with('association.area.barangay.municipality')
            ->where('user_id', $currentUser->id)
            ->where('is_active', true)
            ->first();

        if (! $associationAccount) {
            throw ValidationException::withMessages([
                'account' =>
                    'No active Association Account is linked to this user.',
            ]);
        }

        $statusFilter = $request->query('status', '');

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
            : '';

        $applications = MemberApplication::query()
            ->with([
                'sex',
                'position',
                'classifications',
                'reviewedBy',
                'member.classifications',
            ])
            ->where(
                'association_id',
                $associationAccount->association_id
            )
            ->where(
                'submitted_by_user_id',
                $currentUser->id
            )
            ->when(
                $statusFilter !== '',
                fn ($query) =>
                    $query->where('status', $statusFilter)
            )
            ->orderByDesc('created_at')
            ->get();

        $sexCategories = SexCategory::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $positions = AssociationPosition::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $classifications = MemberClassification::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        return view(
            'association.member-applications.index',
            [
                'associationAccount' =>
                    $associationAccount,

                'applications' =>
                    $applications,

                'sexCategories' =>
                    $sexCategories,

                'positions' =>
                    $positions,

                'classifications' =>
                    $classifications,

                'statusFilter' =>
                    $statusFilter,
            ]
        );
    }

    public function store(
        StoreMemberApplicationRequest $request,
        MemberApplicationService $memberApplicationService
    ): RedirectResponse {
        $currentUser = $request->attributes->get('current_user');

        $memberApplicationService->submit(
            $request->validated(),
            $currentUser
        );

        return back()
            ->with(
                'success',
                'Member application submitted successfully.'
            );
    }
}