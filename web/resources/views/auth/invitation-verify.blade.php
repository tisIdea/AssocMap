@extends('auth.invitation-layout')
@section('title', 'Verify Invitation')
@section('content')
    <h1 id="activation-title">Verify your invitation</h1>
    <p class="staff-login-intro">Continue to securely verify your invitation before creating your password.</p>
    @if ($errors->any())
        <div class="staff-login-errors" role="alert">
            @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif
    <p id="invitation-link-note" class="staff-login-note" role="status">Open the activation link from your invitation email. JavaScript must be enabled. If the link is unavailable, contact the administrator.</p>
    <form id="invitation-verification" method="POST" action="{{ route('invitation.verify.store') }}">
        @csrf
        <input type="hidden" name="token_hash" value="">
        <button type="submit" disabled>Verify invitation</button>
    </form>
@endsection
