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
            <label for="activation-password">New password</label>
            <div class="activation-password-control">
                <input id="activation-password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="128" required aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}">
                <button type="button" class="activation-password-toggle" data-invitation-password-toggle data-password-label="new password" aria-controls="activation-password" aria-label="Show new password" hidden>
                    <x-icon name="eye">
                        <path data-password-eye-off d="m3 3 18 18" hidden />
                    </x-icon>
                    <span data-password-toggle-text>Show</span>
                </button>
            </div>
        </div>
        <div class="staff-login-field">
            <label for="activation-confirmation">Confirm password</label>
            <div class="activation-password-control">
                <input id="activation-confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="128" required aria-invalid="{{ $errors->has('password_confirmation') ? 'true' : 'false' }}">
                <button type="button" class="activation-password-toggle" data-invitation-password-toggle data-password-label="confirm password" aria-controls="activation-confirmation" aria-label="Show confirm password" hidden>
                    <x-icon name="eye">
                        <path data-password-eye-off d="m3 3 18 18" hidden />
                    </x-icon>
                    <span data-password-toggle-text>Show</span>
                </button>
            </div>
        </div>
        <button type="submit">Activate account</button>
    </form>
@endsection
