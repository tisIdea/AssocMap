<?php

namespace App\Http\Controllers\FieldOfficer;

use App\Http\Controllers\Controller;
use App\Http\Requests\MemberLifecycleRequest;
use App\Http\Requests\MemberRegisterRequest;
use App\Http\Requests\UpdateMemberProfileRequest;
use App\Models\Member;
use App\Models\SexCategory;
use App\Services\MemberService;
use App\Services\MemberDirectoryQueryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use PDOException;

class MemberController extends Controller
{
    public function index(MemberRegisterRequest $request, MemberDirectoryQueryService $directory): View
    {
        $actor = $request->attributes->get('current_user');
        $filters = $request->filters();
        $status = $filters['status'];
        $associations = $directory->associations($actor);
        if ($filters['association'] !== '') abort_unless($associations->contains('id', $filters['association']), 404);
        $summary = $directory->memberSummary($actor);
        $members = $directory->members($actor, $filters, $summary);
        $sexCategories = SexCategory::where('is_active', true)->orderBy('id')->get();

        return view('field-officer.members.index', compact('members', 'associations', 'sexCategories', 'status', 'filters', 'summary'));
    }

    public function update(UpdateMemberProfileRequest $request, Member $member, MemberService $service): RedirectResponse
    {
        return $this->mutate($request, $member, $service, 'profile');
    }

    public function archive(MemberLifecycleRequest $request, Member $member, MemberService $service): RedirectResponse
    {
        return $this->mutate($request, $member, $service, 'archive');
    }

    public function reactivate(MemberLifecycleRequest $request, Member $member, MemberService $service): RedirectResponse
    {
        return $this->mutate($request, $member, $service, 'reactivate');
    }

    private function mutate(FormRequest $request, Member $member, MemberService $service, string $action): RedirectResponse
    {
        $actor = $request->attributes->get('current_user');
        try {
            match ($action) {
                'profile' => $service->update($member, $request->validated(), $actor),
                'archive' => $service->archive($member, $request->validated(), $actor),
                'reactivate' => $service->unarchive($member, $request->validated(), $actor),
            };
        } catch (PDOException $exception) {
            if (($exception->errorInfo[0] ?? null) === '23505'
                && str_contains($exception->errorInfo[2] ?? '', '"uq_members_fishr_registration_number"')) {
                throw ValidationException::withMessages(['fishr_registration_number' => 'This FishR registration number is already assigned to another member.']);
            }
            Log::warning('Member change could not be confirmed.', ['code' => 'member_mutation_database_failure']);

            return back()->with('error', 'The member change could not be confirmed. Refresh the list before trying again.');
        }

        return redirect()->route('field-officer.members.index', ['status' => $action === 'archive' ? 'archived' : 'active'])
            ->with('success', match ($action) {
                'profile' => 'Member profile updated successfully.',
                'archive' => 'Member archived successfully.',
                'reactivate' => 'Member reactivated successfully.',
            });
    }
}
