@extends('auth.invitation-layout')
@section('title', 'Resume Account Activation')
@section('content')
    <h1 id="activation-title">Resume account activation</h1>
    <p class="staff-login-intro">Use this when you verified an invitation but lost the activation page before submitting a password.</p>
    @if (!config('services.supabase.invitation_restart_enabled'))
        <p role="status">Activation recovery is not currently available. Contact an Administrator for assistance.</p>
    @else
        @if ($restartThrottled ?? false)<p class="staff-login-errors" role="alert">Too many recovery requests. Wait before requesting another link.</p>@endif
        @if (session('invitation_restart_requested'))
            <p role="status">If this invitation is eligible, instructions will be sent to its registered email. Check your inbox and spam folder. Wait at least one minute before requesting another link.</p>
        @endif
        @if ($errors->any())
            <div class="staff-login-errors" role="alert">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
        @endif
        <form method="POST" action="{{ route('invitation.restart.store') }}">
            @csrf
            <div class="staff-login-field">
                <label for="restart-email">Original invitation email</label>
                <input id="restart-email" name="email" type="email" autocomplete="email" autocapitalize="none" spellcheck="false" maxlength="255" required>
            </div>
            <button type="submit">Request activation recovery</button>
        </form>
    @endif
    <p class="staff-login-note">If you already submitted a password and its outcome is uncertain, contact an Administrator before trying again. This process does not restore revoked access or change your registered email.</p>
@endsection
