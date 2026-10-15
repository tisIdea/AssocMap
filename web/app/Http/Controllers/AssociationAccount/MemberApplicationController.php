<?php

namespace App\Http\Controllers\AssociationAccount;

use App\Exceptions\MemberApplicationPhotoUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMemberApplicationRequest;
use App\Models\AssociationAccount;
use App\Models\AssociationPosition;
use App\Models\MemberApplication;
use App\Models\MemberClassification;
use App\Models\SexCategory;
use App\Services\MemberApplicationService;
use App\Services\MemberExecutivePositionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use PDOException;
use Symfony\Component\HttpFoundation\Response;

class MemberApplicationController extends Controller
{
    public function index(Request $request, MemberExecutivePositionService $executivePositions): View
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

        $occupiedExecutivePositionIds = $executivePositions->occupiedPositionIds($associationAccount->association_id);

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

                'occupiedExecutivePositionIds' =>
                    $occupiedExecutivePositionIds,

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
    ): Response {
        $currentUser = $request->attributes->get('current_user');

        try {
            $memberApplicationService->submit(
                $request->validated(),
                $currentUser
            );
        } catch (MemberApplicationPhotoUnavailableException $exception) {
            $message = $exception->getMessage();
            if ($request->expectsJson()) return response()->json(['message' => $message], 503);
            $request->flashOnly(['first_name', 'middle_name', 'last_name', 'suffix', 'birth_date', 'sex_id',
                'contact_number', 'fishr_registration_number', 'position_id', 'position_other_text',
                'classification_ids', 'endorsement_letter']);

            return response()->view('association.member-applications.storage-unavailable', compact('message'), 503);
        } catch (PDOException $exception) {
            // Database exception messages can contain private evidence in SQL bindings.
            Log::warning('Member application submission could not be confirmed.', [
                'code' => 'application_submission_database_failure',
            ]);

            return redirect()->route('association.member-applications.index')->with(
                'error',
                'Submission could not be confirmed. Review your application list before submitting again. If it is unavailable, contact the administrator.'
            );
        }

        return back()
            ->with(
                'success',
                'Member application submitted successfully.'
            );
    }
}
