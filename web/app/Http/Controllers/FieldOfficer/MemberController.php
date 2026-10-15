<?php

namespace App\Http\Controllers\FieldOfficer;

use App\Http\Controllers\Controller;
use App\Http\Requests\MemberLifecycleRequest;
use App\Http\Requests\UpdateMemberProfileRequest;
use App\Models\Association;
use App\Models\Member;
use App\Models\SexCategory;
use App\Services\MemberService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use PDOException;

class MemberController extends Controller
{
    public function index(Request $request): View
    {
        $actor = $request->attributes->get('current_user');
        $filters = $request->validate(['status' => ['nullable', Rule::in(['active', 'archived', 'inactive'])],
            'association' => ['nullable', 'uuid'], 'q' => ['nullable', 'string', 'max:120']]);
        $status = $filters['status'] ?? 'active';
        $associations = Association::query()->useWritePdo()->where('primary_field_officer_id', $actor->id)->orderBy('name')->get();
        if (! empty($filters['association'])) abort_unless($associations->contains('id', $filters['association']), 404);

        $members = Member::query()->useWritePdo()->with(['association.area.barangay.municipality', 'sex', 'position', 'classifications'])
            ->whereHas('association', fn ($query) => $query->where('primary_field_officer_id', $actor->id))
            ->when($status === 'archived', fn ($query) => $query->whereNotNull('archived_at'),
                fn ($query) => $query->whereNull('archived_at')->where('member_status', $status))
            ->when(! empty($filters['association']), fn ($query) => $query->where('association_id', $filters['association']))
            ->when(! empty($filters['q']), fn ($query) => $query->where(fn ($names) =>
                $names->where('first_name', 'ilike', '%'.$filters['q'].'%')
                    ->orWhere('last_name', 'ilike', '%'.$filters['q'].'%')
                    ->orWhere('fishr_registration_number', 'ilike', '%'.$filters['q'].'%')))
            ->orderBy('last_name')->orderBy('first_name')->orderBy('id')->paginate(25)->withQueryString();
        $sexCategories = SexCategory::where('is_active', true)->orderBy('id')->get();

        return view('field-officer.members.index', compact('members', 'associations', 'sexCategories', 'status', 'filters'));
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
