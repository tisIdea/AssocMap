@extends('auth.invitation-layout')
@section('title', 'Verify Invitation')
@section('content')
    <h1 id="activation-title">Verify your invitation</h1>
    <p class="staff-login-intro">Continue to securely verify your invitation before creating your password.</p>
    @if ($signedIn)
        <p id="invitation-account-note" class="staff-login-note" role="status">This browser is currently signed in. Confirm below to sign out of that account and verify this invitation. Activation will not sign you in automatically.</p>
    @endif
    @if ($errors->any())
        <div class="staff-login-errors" role="alert">
            @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif
    <p id="invitation-link-note" class="staff-login-note" role="status">Open the activation link from your invitation email. If verification does not become available, JavaScript may be disabled or blocked. Reopen the original email link or contact the administrator.</p>
    <noscript><p class="staff-login-errors" role="alert">JavaScript is required to verify this invitation securely. Enable it and reopen the original activation link from your email, or contact the administrator.</p></noscript>
    <form id="invitation-verification" method="POST" action="{{ route('invitation.verify.store') }}">
        @csrf
        <input type="hidden" name="token_hash" value="">
        @if ($signedIn)
            <label class="staff-login-note">
                <input class="activation-confirmation" type="checkbox" name="sign_out_current_account" value="1" required aria-describedby="invitation-account-note">
                I agree to sign out of the current account before verifying this invitation.
            </label>
        @endif
        <button type="submit" disabled>{{ $signedIn ? 'Sign out and verify invitation' : 'Verify invitation' }}</button>
    </form>
@endsection
