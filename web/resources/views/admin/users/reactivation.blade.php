@extends('layouts.admin')
@section('content')
<section class="user-management" data-workspace-module="users" data-workspace-title="Reactivate Account">
    <a class="back-link" href="{{ route('admin.users.show', $review['target']->id) }}">Back to account details</a>
    <section class="card user-details-card">
        <h1>Reactivate Account</h1>
        <p>{{ $review['target']->display_name }} · {{ $review['target']->role->name }}</p>
        <dl class="user-details-grid"><div><dt>Registered email</dt><dd>{{ $review['email'] }}</dd></div><div><dt>Current access</dt><dd>Revoked</dd></div><div><dt>Original activation</dt><dd>{{ $review['target']->activated_at->timezone('Asia/Manila')->format('M d, Y g:i A') }}</dd></div></dl>
        <p>This restores the same account holder and role. The holder must verify their existing email and replace their password. The account stays inactive until completion. All existing sessions must be replaced with a fresh password sign-in.</p>
        @if($review['target']->role->code === 'field_officer')
            <h2>Review retained assignments</h2>
            <p>{{ $review['assignments']->count() }} primary designation(s). Active, unarchived associations become accessible again under the existing access rules. No associations will be transferred.</p>
            <div class="table-scroll"><table class="data-table"><caption>All retained primary Field Officer assignments</caption><thead><tr><th>Association</th><th>Status</th><th>History</th></tr></thead><tbody>
            @forelse($review['assignments'] as $association)<tr><th scope="row">{{ $association->name }}</th><td>{{ ucfirst($association->status) }}</td><td>{{ $association->archived_at ? 'Archived, read-only' : 'Not archived' }}</td></tr>
            @empty<tr><td colspan="3">No current assignments.</td></tr>@endforelse
            </tbody></table></div>
        @elseif($review['target']->role->code === 'association_account')
            <p>The existing active Association Account relationship will be retained. Changing the representative requires a separate handover workflow.</p>
        @endif
        <form method="POST" action="{{ route('admin.users.reactivation.store', $review['target']->id) }}">
            @csrf
            <input type="hidden" name="review_hash" value="{{ $review['review_hash'] }}">
            <p><label><input type="checkbox" name="confirm_relationships" value="1" required> I reviewed this account's role and all retained organizational relationships, including access that could resume.</label></p>
            <p><label><input type="checkbox" name="confirm_reactivation" value="1" required> I authorize reactivation for the same account holder.</label></p>
            @if($errors->any())<div class="notice notice-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
            <button type="submit" class="button button-primary">Send Reactivation Email</button>
        </form>
    </section>
</section>
@endsection
