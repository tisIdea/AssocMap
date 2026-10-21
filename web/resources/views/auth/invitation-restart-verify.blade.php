@extends('auth.invitation-layout')
@section('title', 'Verify Activation Recovery')
@section('content')
    <h1 id="activation-title">Verify activation recovery</h1>
    <p class="staff-login-intro">Verify the fresh email link to continue setting up your original invited account. This will not sign you in automatically.</p>
    @if ($signedIn)
        <p id="invitation-account-note" role="status">This browser is already signed in. Confirm below to end that local session after successful verification.</p>
    @endif
    <p id="invitation-link-note" class="staff-login-note" role="status" data-invalid-message="This page has no usable activation recovery token. Reopen the latest activation recovery email or contact an Administrator.">Reopen your activation recovery email if verification is unavailable. JavaScript is required to submit the link securely.</p>
    <noscript><p role="alert">Enable JavaScript and reopen your activation recovery email, or contact an Administrator.</p></noscript>
    <form id="invitation-verification" method="POST" action="{{ route('invitation.restart.verify', $reference) }}">
        @csrf
        <input type="hidden" name="token_hash" value="">
        @if ($signedIn)
            <label class="staff-login-note"><input class="activation-confirmation" type="checkbox" name="sign_out_current_account" value="1" required aria-describedby="invitation-account-note"> I agree to sign out of the current account after verification.</label>
        @endif
        <button type="submit" disabled>Verify and continue activation</button>
    </form>
@endsection
