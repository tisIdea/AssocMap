@extends('layouts.admin')
@php($globalFeedbackNotification = $account === null ? ['type' => 'error', 'title' => 'Account Details Unavailable', 'message' => 'Account details could not be loaded. Reload to retry.'] : null)

@section('content')
<section class="user-management" data-workspace-module="users" data-workspace-title="Account Details">
    <a class="back-link" href="{{ $backUrl }}"><x-icon name="back" />Back to User Management</a>
    <div class="card user-page-heading">
        @include('partials.page-header', ['title' => 'Account Details', 'description' => $account['display_name'] ?? 'Account information is temporarily unavailable.', 'eyebrow' => 'User Management'])
    </div>
    @if($account === null)
        <div class="notice notice-error" role="alert" data-feedback-fallback>Account details are unavailable. No account data was changed. Reload to retry.</div>
    @else
        <section class="card user-details-card" aria-labelledby="user-profile-title">
            <div class="user-details-heading"><div class="user-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($account['display_name'], 0, 1)) }}</div><div><h2 id="user-profile-title">{{ $account['display_name'] }}</h2><p>{{ $account['role'] }}</p></div><x-status-badge :value="$account['is_active'] ? 'Active' : 'Inactive'" /></div>
            <dl class="user-details-grid">
                <div><dt>Current email</dt><dd>{{ $account['email'] ?? 'Email unavailable' }}</dd></div>
                <div><dt>Role status</dt><dd>{{ $account['role_active'] ? 'Active' : 'Inactive or unavailable' }}</dd></div>
                <div><dt>Created</dt><dd>{{ $account['created_at']?->timezone('Asia/Manila')->format('M d, Y g:i A') ?? 'Not recorded' }}</dd></div>
                <div><dt>Activated</dt><dd>{{ $account['activated_at']?->timezone('Asia/Manila')->format('M d, Y g:i A') ?? 'Not recorded' }}</dd></div>
                <div><dt>Invitation state</dt><dd>{{ $account['invitation_status'] ? ucfirst($account['invitation_status']) : 'No bound invitation' }}</dd></div>
                @if($account['role_code'] === 'association_account')
                    <div><dt>Linked association</dt><dd>{{ $account['association'] ?? 'No association link' }}</dd></div>
                    <div><dt>Association link</dt><dd>{{ !$account['association'] ? 'No association link' : ($account['link_active'] && $account['association_available'] ? 'Available' : 'Link or association unavailable') }}</dd></div>
                @endif
            </dl>
            @if($account['auth_link'] === 'missing')<p class="notice">Matching Auth identity not found in server lookup; verify linkage and database visibility.</p>
            @elseif($account['auth_link'] === 'unavailable')<p class="notice">Auth metadata lookup unavailable; linkage is unverified.</p>@endif
            @if($account['email_mismatch'])<p class="notice">Current Auth email differs from invitation history. Review before changing the account.</p>@endif
            @if($account['email'] === null && $account['invitation_email'])<p class="field-help">Historical invitation email: {{ $account['invitation_email'] }}</p>@endif
            <p class="field-help">Profile status does not confirm sign-in availability. Password reset is coming soon; account deactivation is unavailable in this release.</p>
        </section>

        @if($account['role_code'] === 'field_officer')
            <section class="card user-register" aria-labelledby="user-assignments-title">
                <header class="user-register-heading"><div><h2 id="user-assignments-title">Assigned associations</h2><p>{{ $assigned->total() }} current primary assignment(s), including inactive and archived associations.</p></div>@if($account['is_active'] && $account['role_active'])<a class="button button-primary" href="{{ route('admin.users.assignments.edit', $account['id']) }}">Manage assignments</a>@endif</header>
                <div class="table-scroll" tabindex="0" role="region" aria-label="Assigned associations">
                    <table class="data-table"><caption class="sr-only">Associations currently assigned to this Field Officer.</caption><thead><tr><th scope="col">Association</th><th scope="col">Status</th><th scope="col">History</th></tr></thead><tbody>
                    @forelse($assigned as $association)
                        <tr><th scope="row">{{ $association->name }}</th><td><x-status-badge :value="ucfirst($association->status)" /></td><td>{{ $association->archived_at ? 'Archived · read-only' : 'Not archived' }}</td></tr>
                    @empty<tr><td colspan="3"><x-empty-state title="No assigned associations" message="This Field Officer has no current primary assignments." /></td></tr>@endforelse
                    </tbody></table>
                </div>
                <div class="table-footer user-table-footer"><span>Page {{ $assigned->currentPage() }} of {{ $assigned->lastPage() }}</span><nav aria-label="Assigned association pages">@if($assigned->previousPageUrl())<a class="button" href="{{ $assigned->previousPageUrl() }}" rel="prev">Previous</a>@endif @if($assigned->nextPageUrl())<a class="button" href="{{ $assigned->nextPageUrl() }}" rel="next">Next</a>@endif</nav></div>
            </section>
        @endif
    @endif
</section>
@endsection
