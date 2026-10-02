<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMemberRequest;
use App\Models\Association;
use App\Models\AssociationPosition;
use App\Models\Member;
use App\Models\SexCategory;
use App\Services\MemberService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MemberController extends Controller
{
    public function index(Request $request): View
    {
        $catalog = require resource_path('ui/admin.php');

        $statusFilter = $request->query(
            'status',
            ''
        );

        $statusFilter = in_array(
            $statusFilter,
            [
                'active',
                'inactive',
                'archived',
            ],
            true
        )
            ? $statusFilter
            : '';

        $members = Member::query()
            ->with([
                'association.area.barangay.municipality',
                'sex',
                'position',
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
                    ['active', 'inactive'],
                    true
                ),
                fn ($query) =>
                    $query->where(
                        'member_status',
                        $statusFilter
                    )
            )
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $associations = Association::query()
            ->whereNull('archived_at')
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        $sexCategories = SexCategory::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $positions = AssociationPosition::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $catalog['options']['association_records'] =
            $associations
                ->map(fn (Association $association) => [
                    'value' => $association->id,
                    'label' => $association->name,
                ])
                ->values()
                ->all();

        $catalog['options']['sex_records'] =
            $sexCategories
                ->map(fn (SexCategory $sex) => [
                    'value' => $sex->id,
                    'label' => $sex->name,
                ])
                ->values()
                ->all();

        $catalog['options']['position_records'] =
            $positions
                ->map(
                    fn (AssociationPosition $position) => [
                        'value' => $position->id,
                        'label' => $position->name,
                        'code' => $position->code,
                    ]
                )
                ->values()
                ->all();

        $module = $catalog['modules']['members'];
        $module['status_filter'] = $statusFilter;

        $module['rows'] = $members
            ->map(function (Member $member) {
                $association =
                    $member->association;

                $area =
                    $association?->area;

                $barangay =
                    $area?->barangay;

                $municipality =
                    $barangay?->municipality;

                return [
                    'id' => $member->id,

                    'association_id' =>
                        $member->association_id,

                    'association' =>
                        $association?->name ?? '—',

                    'first_name' =>
                        $member->first_name,

                    'middle_name' =>
                        $member->middle_name,

                    'last_name' =>
                        $member->last_name,

                    'suffix' =>
                        $member->suffix,

                    'full_name' =>
                        trim(implode(' ', array_filter([
                            $member->first_name,
                            $member->middle_name,
                            $member->last_name,
                            $member->suffix,
                        ]))),

                    'birth_date' =>
                        $member->birth_date
                            ? \Illuminate\Support\Carbon::parse(
                                $member->birth_date
                            )->format('Y-m-d')
                            : null,

                    'sex_id' =>
                        $member->sex_id,

                    'sex' =>
                        $member->sex?->name ?? '—',

                    'contact_number' =>
                        $member->contact_number ?? '—',

                    'fishr_registration_number' =>
                        $member->fishr_registration_number
                            ?? '—',

                    'position_id' =>
                        $member->position_id,

                    'position' =>
                        $member->position?->name ?? '—',

                    'position_other_text' =>
                        $member->position_other_text,

                    'date_registered' =>
                        $member->date_registered
                            ? \Illuminate\Support\Carbon::parse(
                                $member->date_registered
                            )->format('Y-m-d')
                            : null,

                    'municipality' =>
                        $municipality?->name ?? '—',

                    'barangay' =>
                        $barangay?->name ?? '—',

                    'area' =>
                        $area?->address ?? '—',

                    'member_status' =>
                        $member->member_status,

                    'status' =>
                        $member->member_status === 'active'
                            ? 'Active'
                            : 'Inactive',

                    'archived' =>
                        $member->archived_at !== null
                            ? 'Yes'
                            : 'No',

                    'archived_at' =>
                        $member->archived_at
                            ? \Illuminate\Support\Carbon::parse(
                                $member->archived_at
                            )->toIso8601String()
                            : null,

                    'update_url' =>
                        route(
                            'admin.members.update',
                            $member
                        ),

                    'archive_url' =>
                        route(
                            'admin.members.archive',
                            $member
                        ),

                    'unarchive_url' =>
                        route(
                            'admin.members.unarchive',
                            $member
                        ),
                ];
            })
            ->values()
            ->all();

        return view(
            'admin.members.index',
            [
                'catalog' => $catalog,
                'module' => $module,
            ]
        );
    }

    public function store(
        StoreMemberRequest $request,
        MemberService $memberService
    ): RedirectResponse {
        $currentUser =
            $request->attributes->get(
                'current_user'
            );

        $memberService->create(
            $request->validated(),
            $currentUser
        );

        return redirect()
            ->route('admin.members.index')
            ->with(
                'success',
                'Member added successfully.'
            );
    }

    public function update(
        StoreMemberRequest $request,
        Member $member,
        MemberService $memberService
    ): RedirectResponse {
        $currentUser =
            $request->attributes->get(
                'current_user'
            );

        $memberService->update(
            $member,
            $request->validated(),
            $currentUser
        );

        return redirect()
            ->route('admin.members.index')
            ->with(
                'success',
                'Member updated successfully.'
            );
    }

    public function archive(
        Request $request,
        Member $member,
        MemberService $memberService
    ): RedirectResponse {
        $currentUser =
            $request->attributes->get(
                'current_user'
            );

        $memberService->archive(
            $member,
            $currentUser
        );

        return redirect()
            ->route('admin.members.index')
            ->with(
                'success',
                'Member archived successfully.'
            );
    }

    public function unarchive(
        Request $request,
        Member $member,
        MemberService $memberService
    ): RedirectResponse {
        $currentUser =
            $request->attributes->get(
                'current_user'
            );

        $memberService->unarchive(
            $member,
            $currentUser
        );

        return redirect()
            ->route('admin.members.index')
            ->with(
                'success',
                'Member restored successfully.'
            );
    }
}