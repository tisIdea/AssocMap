@extends('layouts.admin')
@php($globalFeedbackNotification = $users === null ? ['type' => 'error', 'title' => 'User Records Unavailable', 'message' => 'User records could not be loaded. No account data was changed. Reload to retry.'] : null)

@section('content')
<section data-workspace-module="users" data-workspace-title="User Management">
    @include('partials.page-header', ['title' => 'User Management', 'description' => 'Application profiles, invitation state and current organizational links.', 'eyebrow' => 'System Administrator'])
    <p><a class="button button-primary" href="{{ route('admin.user-invitations.create') }}">Invite User</a></p>
    <div class="notice"><p>Profiles and roles come from AssocMap. Auth-only identities are not application accounts and are not listed. Profile status does not prove that an Auth identity can currently sign in.</p></div>
    @if($users === null)
        <div class="notice notice-error" role="alert" data-feedback-fallback>User records are unavailable. No account data was changed. Reload to retry.</div>
    @else
        <section class="card list-card">
            <div class="table-scroll"><table class="data-table">
                <caption>Existing application profiles. Auth email is read only for the profiles on this page.</caption>
                <thead><tr><th scope="col">Display name</th><th scope="col">Email / Auth link</th><th scope="col">Role</th><th scope="col">Profile status</th><th scope="col">Invitation</th><th scope="col">Associations</th><th scope="col">Actions</th></tr></thead>
                <tbody>
                @forelse($users as $user)
                    <tr>
                        <td>{{ $user['display_name'] }}</td>
                        <td>{{ $user['email'] ?? 'Email unavailable' }}
                            @if($user['auth_link'] === 'missing')<p class="field-help">Matching Auth identity not found in server lookup; verify linkage and database visibility.</p>
                            @elseif($user['auth_link'] === 'unavailable')<p class="field-help">Auth metadata lookup unavailable; linkage is unverified.</p>@endif
                            @if($user['email_mismatch'])<p class="field-help">Current Auth email differs from invitation history. Review before changing the account.</p>@endif
                            @if($user['email'] === null && $user['invitation_email'])<p class="field-help">Historical invitation email: {{ $user['invitation_email'] }}</p>@endif
                        </td>
                        <td>{{ $user['role'] }} @if(!$user['role_active'])<p class="field-help">Role inactive or unavailable</p>@endif</td>
                        <td>{{ $user['is_active'] ? 'Active' : 'Inactive' }}</td>
                        <td>{{ $user['invitation_status'] ? ucfirst($user['invitation_status']) : 'No bound invitation' }}</td>
                        <td>
                            @if($user['role_code'] === 'field_officer')
                                {{ $user['assigned_count'] }} association(s); {{ $user['archived_assigned_count'] }} archived.
                            @elseif($user['role_code'] === 'association_account')
                                {{ $user['association'] ?? 'No association link' }}
                                @if($user['association'] && (!$user['link_active'] || !$user['association_available']))<p class="field-help">Link or association unavailable</p>@endif
                            @else — @endif
                        </td>
                        <td>@if($user['role_code'] === 'field_officer' && $user['is_active'] && $user['role_active'])<a class="text-button" href="{{ route('admin.users.assignments.edit', $user['id']) }}">Association assignments</a>@else — @endif</td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="empty-state">No application profiles are available on this page.</div></td></tr>
                @endforelse
                </tbody>
            </table></div>
            <div class="table-footer"><span>{{ $users->total() }} application profile(s). Page {{ $users->currentPage() }} of {{ $users->lastPage() }}.</span>
                <nav aria-label="User register pages">@if($users->previousPageUrl())<a class="button" href="{{ $users->previousPageUrl() }}" rel="prev">Previous</a>@endif @if($users->nextPageUrl())<a class="button" href="{{ $users->nextPageUrl() }}" rel="next">Next</a>@endif</nav>
            </div>
        </section>
    @endif
</section>
@endsection
