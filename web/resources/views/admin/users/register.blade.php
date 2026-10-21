@extends('layouts.admin')
@php
    $globalFeedbackNotification = $users === null ? ['type' => 'error', 'title' => 'User Records Unavailable', 'message' => 'User records could not be loaded. No account data was changed. Reload to retry.'] : null;
    $cards = [
        ['key' => 'total', 'label' => 'Total Users', 'icon' => 'users', 'role' => '', 'status' => ''],
        ['key' => 'system_admin', 'label' => 'System Admins', 'icon' => 'shield', 'role' => 'system_admin', 'status' => ''],
        ['key' => 'field_officer', 'label' => 'Field Officers', 'icon' => 'user', 'role' => 'field_officer', 'status' => ''],
        ['key' => 'association_account', 'label' => 'Association Accounts', 'icon' => 'building', 'role' => 'association_account', 'status' => ''],
        ['key' => 'inactive', 'label' => 'Inactive Users', 'icon' => 'archive', 'role' => '', 'status' => 'inactive'],
    ];
@endphp

@section('content')
<section class="user-management" data-user-management data-workspace-module="users" data-workspace-title="User Management">
    <div class="card user-page-heading">
        @include('partials.page-header', ['title' => 'User Management', 'description' => 'Manage AssocMap application profiles, invitations, and organizational assignments.', 'eyebrow' => 'System Administrator'])
    </div>

    <nav class="user-summary-grid" aria-label="Filter users by account group">
        @foreach($cards as $card)
            @php
                $selected = $filters['role'] === $card['role'] && $filters['status'] === $card['status'];
                $cardUrl = route('admin.users.index', ['q' => $filters['q'], 'sort' => $filters['sort'], 'role' => $card['role'], 'status' => $card['status']]);
            @endphp
            <a class="user-summary-card" href="{{ $cardUrl }}" @if($selected) aria-current="page" @endif data-user-summary="{{ $card['key'] }}">
                <x-stat-card :label="$card['label']" :value="$summary === null ? '?' : number_format($summary[$card['key']])" :icon="$card['icon']" note="View matching accounts" :animate="false" />
            </a>
        @endforeach
    </nav>

    <section class="card user-filter-card" aria-labelledby="user-filters-title">
        <div class="user-section-heading"><h2 id="user-filters-title"><x-icon name="filter" />Filter Users</h2><p>Card totals include all application profiles. Cards keep your search and sort.</p></div>
        <form method="GET" action="{{ route('admin.users.index') }}" class="user-filter-form" data-feedback-error-bag="default">
            @if($errors->any())<div id="user-filter-errors" class="notice notice-error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            <div class="user-filter-grid">
                <div class="field"><label for="user-search">Search</label><input id="user-search" name="q" type="search" value="{{ $filters['q'] }}" maxlength="120" placeholder="Search name or current email..." @if($errors->has('q')) aria-invalid="true" aria-describedby="user-filter-errors" @endif></div>
                <div class="field"><label for="user-role">Role</label><select id="user-role" name="role" @if($errors->has('role')) aria-invalid="true" aria-describedby="user-filter-errors" @endif><option value="">All roles</option>@foreach(['system_admin' => 'System Admin', 'field_officer' => 'Field Officer', 'association_account' => 'Association Account'] as $value => $label)<option value="{{ $value }}" @selected($filters['role'] === $value)>{{ $label }}</option>@endforeach</select></div>
                <div class="field"><label for="user-status">Profile status</label><select id="user-status" name="status" @if($errors->has('status')) aria-invalid="true" aria-describedby="user-filter-errors" @endif><option value="">All statuses</option><option value="active" @selected($filters['status'] === 'active')>Active</option><option value="inactive" @selected($filters['status'] === 'inactive')>Inactive</option></select></div>
                <div class="field"><label for="user-sort">Sort by</label><select id="user-sort" name="sort" @if($errors->has('sort')) aria-invalid="true" aria-describedby="user-filter-errors" @endif>@foreach(['name' => 'Name A to Z', 'name_desc' => 'Name Z to A', 'newest' => 'Newest first', 'oldest' => 'Oldest first'] as $value => $label)<option value="{{ $value }}" @selected($filters['sort'] === $value)>{{ $label }}</option>@endforeach</select></div>
            </div>
            <div class="user-filter-actions"><button type="submit" class="button button-primary">Apply filters</button><a class="button" href="{{ route('admin.users.index') }}">Reset</a></div>
        </form>
    </section>

    <section class="card user-register" aria-labelledby="user-register-title">
        <header class="user-register-heading">
            <div><h2 id="user-register-title">User Accounts</h2><p>{{ $users === null ? 'Records unavailable' : number_format($users->total()).' matching application profile(s)' }}</p></div>
            <a class="button button-primary" href="{{ route('admin.user-invitations.create') }}"><x-icon name="plus" />Invite User</a>
        </header>
        <p class="user-register-note">Profile status does not confirm sign-in availability. Auth-only identities are not application accounts and are not listed.</p>
        @if($users === null)
            <div class="notice notice-error" role="alert" data-feedback-fallback>User records are unavailable. No account data was changed. Reload to retry.</div>
        @else
            <div class="table-scroll" tabindex="0" role="region" aria-label="User accounts">
                <table class="data-table user-table">
                    <caption class="sr-only">Application profiles, current Auth emails, invitation state, and organizational links.</caption>
                    <thead><tr><th scope="col">Account</th><th scope="col">Role</th><th scope="col">Account status</th><th scope="col">Linked association</th><th scope="col">Invitation</th><th scope="col">Created</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                    @forelse($users as $user)
                        @php($detailUrl = route('admin.users.show', ['userProfile' => $user['id']] + $filters + ['page' => $users->currentPage()]))
                        <tr>
                            <td><div class="user-account-cell">
                                <span class="user-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($user['display_name'], 0, 1)) }}</span>
                                <div class="user-account-copy">
                                    <a class="user-account-name" href="{{ $detailUrl }}">{{ $user['display_name'] }}</a>
                                    <p>{{ $user['email'] ?? 'Email unavailable' }}</p>
                                    @if($user['auth_link'] === 'missing')<p class="field-help">Matching Auth identity not found in server lookup; verify linkage and database visibility.</p>
                                    @elseif($user['auth_link'] === 'unavailable')<p class="field-help">Auth metadata lookup unavailable; linkage is unverified.</p>@endif
                                    @if($user['email_mismatch'])<p class="field-help">Current Auth email differs from invitation history. Review before changing the account.</p>@endif
                                    @if($user['email'] === null && $user['invitation_email'])<p class="field-help">Historical invitation email: {{ $user['invitation_email'] }}</p>@endif
                                    @if($user['role_code'] === 'field_officer')
                                        <p class="user-account-meta">{{ $user['assigned_count'] }} association(s); {{ $user['archived_assigned_count'] }} archived.</p>
                                    @endif
                                </div>
                            </div></td>
                            <td><span @class(['badge', 'user-role-admin' => $user['role_code'] === 'system_admin', 'user-role-officer' => $user['role_code'] === 'field_officer', 'user-role-association' => $user['role_code'] === 'association_account', 'badge-neutral' => !$user['role_code']])>{{ $user['role'] }}</span>@if(!$user['role_active'])<p class="field-help">Role inactive or unavailable</p>@endif</td>
                            <td><x-status-badge :value="$user['is_active'] ? 'Active' : ($user['revoked_at'] ? 'Revoked' : 'Inactive')" /></td>
                            <td>
                                @if($user['role_code'] === 'association_account' || $user['association_integrity_issue'])
                                    <span>{{ $user['association'] ?? $user['association_relationship_status'] }}</span>
                                    @if($user['association'])<p class="field-help">{{ $user['association_relationship_status'] }} · Association: {{ $user['association_status'] }}</p>@endif
                                @else<span class="field-help">Not applicable</span>@endif
                            </td>
                            <td><x-status-badge :value="$user['invitation_status'] ? ucfirst($user['invitation_status']) : 'No bound invitation'" /></td>
                            <td class="user-created">{{ $user['created_at']?->timezone('Asia/Manila')->format('M d, Y') ?? 'Not recorded' }}</td>
                            <td class="user-actions-cell">
                                <details class="user-actions" data-user-actions>
                                    <summary aria-label="Actions for {{ $user['display_name'] }}" title="Actions for {{ $user['display_name'] }}"><x-icon name="ellipsis" /></summary>
                                    <div class="user-actions-panel" data-user-actions-panel>
                                        <a href="{{ $detailUrl }}"><x-icon name="eye" /><span>View Account Details</span></a>
                                        @if($user['role_code'] === 'field_officer' && $user['is_active'] && $user['role_active'])<a href="{{ route('admin.users.assignments.edit', $user['id']) }}"><x-icon name="building" /><span>Association assignments</span></a>@endif
                                        <button type="button" disabled><x-icon name="settings" /><span>Reset Password<small>Coming soon</small></span></button>
                                        @if($user['id'] !== $currentUser->id)<a href="{{ $detailUrl }}#user-access"><x-icon name="archive" /><span>Manage access</span></a>@else<button type="button" disabled title="You cannot revoke your own access."><x-icon name="archive" /><span>Revoke Access<small>Your account</small></span></button>@endif
                                    </div>
                                </details>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><x-empty-state title="No accounts on this page" message="Try another search or reset the filters to view all application profiles." /></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="table-footer user-table-footer">
                <span>{{ $users->firstItem() ?? 0 }} to {{ $users->lastItem() ?? 0 }} of {{ number_format($users->total()) }} matching profiles / Page {{ $users->currentPage() }} of {{ $users->lastPage() }}</span>
                <nav aria-label="User register pages">@if($users->previousPageUrl())<a class="button" href="{{ $users->previousPageUrl() }}" rel="prev">Previous</a>@endif @if($users->nextPageUrl())<a class="button" href="{{ $users->nextPageUrl() }}" rel="next">Next</a>@endif</nav>
            </div>
        @endif
    </section>
</section>
@endsection
