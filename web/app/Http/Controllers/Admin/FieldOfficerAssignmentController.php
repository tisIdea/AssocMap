<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateFieldOfficerAssignmentsRequest;
use App\Models\Association;
use App\Models\UserProfile;
use App\Services\FieldOfficerAssignmentService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class FieldOfficerAssignmentController extends Controller
{
    public function edit(Request $request, UserProfile $userProfile, FieldOfficerAssignmentService $service): Response
    {
        $actor = $request->attributes->get('current_user');
        abort_unless($actor?->canAccessApplication() && $actor->role?->code === 'system_admin', 403);
        abort_unless($service->eligibleOfficer($userProfile), 422, 'Select an active, eligible Field Officer.');

        return response()->view('admin.users.assignments', [
            'officer' => $userProfile,
            'assigned' => $userProfile->assignedAssociations()->orderBy('name')
                ->paginate(25, ['id', 'name', 'status', 'archived_at'], 'assigned_page')->withQueryString(),
            'choices' => Association::whereNull('archived_at')->where('status', 'active')->with('primaryFieldOfficer:id,display_name')
                ->orderBy('name')->orderBy('id')->paginate(50)->withQueryString(),
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function update(UpdateFieldOfficerAssignmentsRequest $request, UserProfile $userProfile,
        FieldOfficerAssignmentService $service): JsonResponse|RedirectResponse
    {
        try {
            $changed = $service->assign($request, $userProfile, $request->validated());
        } catch (QueryException $exception) {
            Log::warning('Field Officer assignment outcome needs review.', ['code' => 'assignment_storage_unavailable']);
            $message = 'Assignments could not be confirmed. Reload the current assignments before retrying.';
            if ($request->expectsJson()) return response()->json(['message' => $message], 503)->header('Cache-Control', 'no-store');

            return redirect()->route('admin.users.assignments.edit', $userProfile->id)->withErrors(['association_ids' => $message]);
        }
        $message = $changed === 0 ? 'Selected associations are already assigned to this Field Officer.'
            : $changed.' association assignment(s) saved.';
        if ($request->expectsJson()) return response()->json(['message' => $message, 'changed' => $changed])->header('Cache-Control', 'no-store');

        return redirect()->route('admin.users.assignments.edit', $userProfile->id)->with('success', $message);
    }
}
