@extends('auth.invitation-layout')
@section('title', 'Create Password')
@section('content')
    <h1 id="activation-title">Create your password</h1>
    <p id="password-policy" class="staff-login-intro">Use 12–128 characters with uppercase and lowercase letters, a number, and a symbol. Complete activation within 10 minutes of verification.</p>
    @if ($errors->any())
        <div class="staff-login-errors" role="alert">
            @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif
    <form method="POST" action="{{ route('invitation.password.store') }}" aria-describedby="password-policy">
        @csrf
        <div class="staff-login-field">
            <label for="activation-password">Password</label>
            <input id="activation-password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="128" required aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}">
        </div>
        <div class="staff-login-field">
            <label for="activation-confirmation">Confirm password</label>
            <input id="activation-confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="128" required aria-invalid="{{ $errors->has('password_confirmation') ? 'true' : 'false' }}">
        </div>
        <button type="submit">Activate account</button>
    </form>
@endsection
