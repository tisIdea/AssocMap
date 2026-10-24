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
            <div class="user-details-heading"><div class="user-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($account['display_name'], 0, 1)) }}</div><div><h2 id="user-profile-title">{{ $account['display_name'] }}</h2><p>{{ $account['role'] }}</p></div><x-status-badge :value="$account['is_active'] ? 'Active' : ($account['revoked_at'] ? 'Revoked' : 'Inactive')" /></div>
            <dl class="user-details-grid">
                <div><dt>Current email</dt><dd>{{ $account['email'] ?? 'Email unavailable' }}</dd></div>
                <div><dt>Role status</dt><dd>{{ $account['role_active'] ? 'Active' : 'Inactive or unavailable' }}</dd></div>
                <div><dt>Created</dt><dd>{{ $account['created_at']?->timezone('Asia/Manila')->format('M d, Y g:i A') ?? 'Not recorded' }}</dd></div>
                <div><dt>Activated</dt><dd>{{ $account['activated_at']?->timezone('Asia/Manila')->format('M d, Y g:i A') ?? 'Not recorded' }}</dd></div>
                <div><dt>Invitation state</dt><dd>{{ $account['invitation_status'] ? ucfirst($account['invitation_status']) : 'No bound invitation' }}</dd></div>
                @if($account['role_code'] === 'association_account' || $account['association_integrity_issue'])
                    <div><dt>Linked association</dt><dd>{{ $account['association'] ?? $account['association_relationship_status'] }}</dd></div>
                    <div><dt>Association relationship</dt><dd>{{ $account['association_relationship_status'] }}</dd></div>
                    @if($account['association_status'])<div><dt>Association status</dt><dd>{{ $account['association_status'] }}</dd></div>@endif
                @endif
            </dl>
            @if($account['auth_link'] === 'missing')<p class="notice">Matching Auth identity not found in server lookup; verify linkage and database visibility.</p>
            @elseif($account['auth_link'] === 'unavailable')<p class="notice">Auth metadata lookup unavailable; linkage is unverified.</p>@endif
            @if($account['email_mismatch'])<p class="notice">Current Auth email differs from invitation history. Review before changing the account.</p>@endif
            @if($account['email'] === null && $account['invitation_email'])<p class="field-help">Historical invitation email: {{ $account['invitation_email'] }}</p>@endif
            <p class="field-help">Profile status does not confirm sign-in availability. Eligible users can request password recovery from the sign-in page.</p>
            @if(config('services.supabase.account_email_change_enabled') === true)
                <a class="button" href="{{ route('admin.users.email-change.edit', $account['id']) }}">Change Email / Review Request</a>
                <p class="field-help">Same holder only. Eligibility is checked again before approval. A different representative requires revocation and a separate invitation.</p>
            @endif
            @if($account['role_code'] === 'association_account')<p class="field-help">Revoking access ends the active association appointment and preserves its history. A replacement representative receives a separate invitation and identity.</p>@endif
            @if($account['association_history'])
                <h3>Ended association appointments</h3>
                <ul>@foreach($account['association_history'] as $historical)<li>{{ $historical['association'] }} — ended {{ $historical['ended_at']?->timezone('Asia/Manila')->format('M d, Y g:i A') ?? '(time not recorded)' }}</li>@endforeach</ul>
            @endif
        </section>

        @if(!$account['is_active'] && !$account['activated_at'] && !$account['revoked_at'] && $account['role_active']
            && in_array($account['role_code'], ['field_officer', 'association_account'], true)
            && $account['auth_link'] === 'linked' && !$account['email_mismatch'] && $account['invitation_status'] === 'sent')
            <section class="card user-details-card" aria-labelledby="invitation-resend-title">
                <h2 id="invitation-resend-title">Resend invitation</h2>
                <p>Request a replacement invitation for the existing, unconfirmed Auth identity. Email delivery remains unverified. If the recipient already verified their email, review interrupted activation instead.</p>
                <form method="POST" action="{{ route('admin.users.invitation.resend', $account['id']) }}" data-feedback-error-bag="default">
                    @csrf
                    <label><input type="checkbox" name="confirm_resend" value="1" required> I confirm this replacement invitation request.</label>
                    @error('confirm_resend')<p class="field-error">{{ $message }}</p>@enderror
                    <button type="submit" class="button button-primary">Resend Invitation</button>
                </form>
            </section>
        @endif

        <section id="user-access" class="card user-details-card" aria-labelledby="user-access-title">
            <h2 id="user-access-title">Account access</h2>
            @if($account['revoked_at'])
                <p role="status">Access revoked on {{ $account['revoked_at']->timezone('Asia/Manila')->format('M d, Y g:i A') }}. Organizational records were preserved.</p>
                @if($account['reactivation_blocked_reason'])<p class="field-help" role="status">{{ $account['reactivation_blocked_reason'] }}</p>@endif
                @if(config('services.supabase.account_reactivation_enabled') === true && $account['activated_at'] && $account['role_active'])
                    @if(!$account['reactivation_blocked_reason'])
                        <a class="button button-primary" href="{{ route('admin.users.reactivation.edit', $account['id']) }}">Reactivate Account</a>
                        <p class="field-help">Eligibility is checked again during review and submission. Completion requires mailbox verification, a new password and a fresh sign-in.</p>
                    @endif
                @endif
                @if($account['role_code'] === 'association_account' && $account['link_active'] === true)
                    <p class="field-help">This revoked account still holds an active appointment. Ending it releases the association for a separately authorized replacement and preserves its history.</p>
                    <form method="POST" action="{{ route('admin.users.revoke', $account['id']) }}" data-feedback-error-bag="default">
                        @csrf @method('PATCH')
                        <p><label><input type="checkbox" name="confirm_revocation" value="1" required> Keep access revoked, end this retained appointment and cancel any pending reactivation approval.</label></p>
                        <button type="submit" class="button button-danger-outline">End Retained Appointment</button>
                    </form>
                @elseif(config('services.supabase.account_reactivation_enabled') === true && $account['activated_at'] && $account['role_active'])
                    <form method="POST" action="{{ route('admin.users.revoke', $account['id']) }}">
                        @csrf @method('PATCH')
                        <p><label><input type="checkbox" name="confirm_revocation" value="1" required> Keep access revoked and cancel any pending reactivation approval.</label></p>
                        <button type="submit" class="button button-danger-outline">Cancel Pending Reactivation</button>
                    </form>
                @endif
                <a class="button" href="{{ route('admin.audit-logs.index', ['module' => 'User Management', 'entity_type' => 'user_profile']) }}">View access audit history</a>
            @elseif($account['id'] === $currentUser->id)
                <p>You cannot revoke your own access.</p>
            @else
                <p>Revoking access blocks subsequent protected requests and cancels outstanding invitations. Organizational records and account history are preserved.</p>
                <form method="POST" action="{{ route('admin.users.revoke', $account['id']) }}" data-feedback-error-bag="default">
                    @csrf
                    @method('PATCH')
                    <label><input type="checkbox" name="confirm_revocation" value="1" required> I confirm that access for {{ $account['display_name'] }} should be revoked.</label>
                    @error('confirm_revocation')<p class="field-error">{{ $message }}</p>@enderror
                    <button type="submit" class="button button-danger-outline">Revoke Access</button>
                </form>
            @endif
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
