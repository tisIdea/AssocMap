<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMobileMemberApplicationRequest;
use App\Models\AssociationPosition;
use App\Models\MemberApplication;
use App\Models\MemberClassification;
use App\Models\SexCategory;
use App\Services\Members\MemberApplicationService;
use App\Services\Members\MemberExecutivePositionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MemberApplicationController extends Controller
{
    public function options(Request $request, MemberExecutivePositionService $positions): JsonResponse
    {
        $associationId = $request->attributes->get('mobile_association_id');
        $occupied = $positions->occupiedPositionIds($associationId);

        return response()->json(['data' => [
            'association' => $request->attributes->get('mobile_context')['association'],
            'sex_categories' => SexCategory::query()->useWritePdo()->where('is_active', true)->orderBy('id')->get(['id', 'name']),
            'positions' => AssociationPosition::query()->useWritePdo()->where('is_active', true)->orderBy('sort_order')->orderBy('id')
                ->get(['id', 'code', 'name'])->map(fn ($position) => [
                    'id' => $position->id, 'code' => $position->code, 'name' => $position->name,
                    'occupied' => in_array((string) $position->id, $occupied, true),
                ]),
            'classifications' => MemberClassification::query()->useWritePdo()->where('is_active', true)->orderBy('id')->get(['id', 'name']),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])],
            'association_id' => ['prohibited'], 'submitted_by_user_id' => ['prohibited'],
        ]);
        $page = $this->scope($request)->when($filters['status'] ?? null,
            fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('created_at')->orderBy('id')->paginate(25);

        return response()->json(['data' => [
            'association_id' => $request->attributes->get('mobile_association_id'),
            'applications' => $page->getCollection()->map($this->summary(...)),
            'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
        ]]);
    }

    public function store(StoreMobileMemberApplicationRequest $request, MemberApplicationService $applications): JsonResponse
    {
        $application = $applications->submit($request->validated(), $request->attributes->get('current_user'));

        return response()->json(['data' => [
            'association_id' => $application->association_id,
            'application' => $this->summary($application),
        ]], 201);
    }

    private function scope(Request $request): Builder
    {
        return MemberApplication::query()->useWritePdo()
            ->where('association_id', $request->attributes->get('mobile_association_id'))
            ->where('submitted_by_user_id', $request->attributes->get('current_user')->id);
    }

    private function summary(MemberApplication $application): array
    {
        return [
            'id' => $application->id,
            'name' => trim(implode(' ', array_filter([$application->first_name, $application->middle_name,
                $application->last_name, $application->suffix], fn ($value) => $value !== null && $value !== ''))),
            'status' => $application->status,
            'submitted_at' => $application->created_at?->toIso8601String(),
            'reviewed_at' => $application->reviewed_at?->toIso8601String(),
            'rejection_reason' => $application->rejection_reason,
            'has_photo' => $application->applicant_photo_path !== null,
        ];
    }
}
