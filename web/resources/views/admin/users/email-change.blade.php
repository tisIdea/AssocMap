@extends('layouts.admin')
@section('content')
<section class="user-management" data-workspace-module="users" data-workspace-title="Change Email">
    <a class="back-link" href="{{ route('admin.users.show', $target->id) }}">Back to account details</a>
    <section class="card user-details-card">
        <h1>Change Email</h1>
        <p>{{ $target->display_name }} · {{ $target->role?->name }}</p>
        <p>Use this only for the same authorized account holder. A different representative requires revocation and a separate invitation.</p>
        <p>The holder needs access to both mailboxes and must privately choose a new strong password. Application access is held during the change. Identity, role and organizational history are retained.</p>
        @if(session('success'))<p role="status">{{ session('success') }}</p>@endif
        @if(session('error'))<p class="notice" role="alert">{{ session('error') }}</p>@endif
        @if($errors->any())<div class="notice" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        @if($pending)
            <dl class="user-details-grid">
                <div><dt>Request status</dt><dd>{{ $pending->status === 'pending' && $pending->expires_at && \Illuminate\Support\Carbon::parse($pending->expires_at)->isPast() ? 'Expired — review unresolved hold' : ucfirst($pending->status) }}</dd></div>
                <div><dt>Verification phase</dt><dd>{{ str_replace('_', ' ', $pending->phase) }}</dd></div>
                <div><dt>Approved destination</dt><dd>{{ $pending->new_email }}</dd></div>
                <div><dt>Expires</dt><dd>{{ $pending->expires_at ?? 'Legacy request: review required' }}</dd></div>
            </dl>
            @if($pending->status === 'pending')
                <p role="status">This request reserves the destination. Expiry does not release an unresolved access hold. A started, interrupted or uncertain operation requires reconciliation; do not resend it or clear its markers.</p>
                @if($pending->phase === 'requested')
                    <form method="POST" action="{{ route('admin.users.email-change.cancel', $target->id) }}">
                        @csrf
                        <label><input type="checkbox" name="confirm_change" value="1" required> Cancel this request before any verification or email update has started.</label>
                        <button class="button" type="submit">Cancel Unstarted Request</button>
                    </form>
                @endif
            @endif
        @endif
        @if(!$pending || $pending->status !== 'pending')
            <form method="POST" action="{{ route($review ? 'admin.users.email-change.store' : 'admin.users.email-change.review', $target->id) }}">
                @csrf
                @if($review)
                    <dl class="user-details-grid"><div><dt>Current email</dt><dd>{{ $review['email'] }}</dd></div><div><dt>Verified destination required</dt><dd>{{ $review['destination'] }}</dd></div></dl>
                    <p>{{ $review['links']->where('is_active', true)->count() }} active association link(s); {{ $review['associations']->count() }} retained association record(s). No appointment will be transferred or restored.</p>
                    <input type="hidden" name="new_email" value="{{ $review['destination'] }}">
                    <input type="hidden" name="review_hash" value="{{ $review['review_hash'] }}">
                    <input type="hidden" name="same_holder" value="1">
                    <label><input type="checkbox" name="confirm_change" value="1" required> I approve the reviewed destination, same holder and retained relationships.</label>
                    <button class="button button-primary" type="submit">Hold Access and Request Verification</button>
                @else
                    <label for="new-email">New email</label>
                    <input id="new-email" name="new_email" type="email" autocomplete="off" maxlength="254" required>
                    <p><label><input type="checkbox" name="same_holder" value="1" required> I verified this is the same authorized holder and both mailboxes are accessible.</label></p>
                    <button class="button button-primary" type="submit">Review Email Change</button>
                @endif
            </form>
        @endif
    </section>
</section>
@endsection
