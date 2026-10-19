@extends('auth.invitation-layout')
@section('title', 'Finish Activation')
@section('content')
    <h1 id="activation-title">Finish account activation</h1>
    <p class="staff-login-intro">Your password was confirmed, but application activation did not finish. You can retry activation in this browser without changing your password again.</p>
    @if ($errors->any())
        <div class="staff-login-errors" role="alert">
            @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif
    <form method="POST" action="{{ route('invitation.recovery.store') }}">
        @csrf
        <button type="submit">Finish activation</button>
    </form>
    <p class="staff-login-note">This recovery expires with your original verification session. If it expires or cannot finish, contact the administrator for reconciliation.</p>
@endsection
