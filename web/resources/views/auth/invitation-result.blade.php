@extends('auth.invitation-layout')
@section('title', $succeeded ? 'Account Activated' : 'Activation Unavailable')
@section('content')
    <h1 id="activation-title">{{ $succeeded ? 'Account activated' : 'Activation unavailable' }}</h1>
    @if ($succeeded)
        <p class="staff-login-intro" role="status">Your account activation is complete. Sign in with your email and new password to access your authorized workspace.</p>
    @else
        <p class="staff-login-intro" role="alert">{{ session('activation_message', 'This activation could not be completed. The invitation or verification session may be unavailable. Contact the administrator for reconciliation before trying again.') }}</p>
        <p class="staff-login-note">If you verified the invitation but never submitted a password, you can <a href="{{ route('invitation.restart') }}">resume account activation</a>. If a password was submitted, contact an Administrator before retrying.</p>
    @endif
@endsection
