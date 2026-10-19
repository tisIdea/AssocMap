<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\MemberRegisterRequest;
use App\Services\MemberDirectoryQueryService;
use Illuminate\View\View;

class MemberController extends Controller
{
    public function index(MemberRegisterRequest $request, MemberDirectoryQueryService $directory): View
    {
        $actor = $request->attributes->get('current_user');
        $filters = $request->filters();
        $associations = $directory->associations($actor);
        if ($filters['association'] !== '') abort_unless($associations->contains('id', $filters['association']), 404);
        $summary = $directory->memberSummary($actor);
        $members = $directory->members($actor, $filters, $summary);

        return view('admin.members.register', compact('filters', 'associations', 'members', 'summary'));
    }
}
