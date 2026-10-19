@extends('layouts.admin')

@section('content')
@php
    $values = [];
    foreach (['display_name', 'email', 'role_id', 'association_id'] as $field) {
        $value = old($field);
        $values[$field] = is_string($value) || is_int($value) ? (string) $value : '';
    }
@endphp
<div data-workspace-module="users" data-workspace-title="Invite User">
    <a class="back-link" href="{{ route('admin.users.index') }}"><x-icon name="back" />Back to User Management</a>
    @include('partials.page-header', ['title' => 'Invite User', 'description' => 'The recipient sets a password and activates the invited account before signing in.', 'eyebrow' => 'User Management'])

    @if($configurationProblems !== [])
        <div class="notice notice-error" role="alert"><div>
            <strong>Invitation configuration checks failed.</strong>
            <ul>@foreach($configurationProblems as $problem)<li>{{ $problem }}</li>@endforeach</ul>
            <p>No configuration values are displayed. Correct the configuration before submitting an invitation.</p>
        </div></div>
    @endif
    <p class="field-help">Configuration cache: {{ $configurationCached ? 'present; it may need refreshing after an approved configuration change' : 'not present' }}. These local checks do not verify credentials with Supabase or confirm email delivery.</p>

    @if(session('invitation_result'))
        @php($result = session('invitation_result'))
        <div class="notice" role="status">
            <div>
                @if($result['invitation_id'])<p>Operation reference: {{ $result['invitation_id'] }}. Status: {{ $result['status'] }}.</p>@endif
            </div>
        </div>
    @endif
    @if($errors->any())
        <div class="notice notice-error" role="alert"><div>@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div></div>
    @endif

    <form class="record-form" method="POST" action="{{ route('admin.user-invitations.store', [], false) }}" data-user-invitation-form data-feedback-error-bag="default">
        @csrf
        <div class="form-main">
            <x-form-section title="Invitation details" number="1">
                <div class="form-grid">
                    <div class="field">
                        <label for="invitation-display-name">Display name *</label>
                        <input id="invitation-display-name" name="display_name" value="{{ $values['display_name'] }}" required maxlength="255" autocomplete="name" @if($errors->has('display_name')) aria-invalid="true" @endif>
                    </div>
                    <div class="field">
                        <label for="invitation-email">Recipient email *</label>
                        <input id="invitation-email" name="email" type="email" value="{{ $values['email'] }}" required maxlength="255" autocomplete="email" @if($errors->has('email')) aria-invalid="true" @endif>
                    </div>
                    <div class="field">
                        <label for="invitation-role">Role *</label>
                        <select id="invitation-role" name="role_id" required data-invitation-role @if($errors->has('role_id')) aria-invalid="true" @endif>
                            <option value="">Select a role</option>
                            @foreach($roles as $role)
                                <option value="{{ $role->id }}" data-role-code="{{ $role->code }}" @selected($values['role_id'] === (string) $role->id)>{{ $role->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="invitation-association">Association (required for Association Accounts)</label>
                        <select id="invitation-association" name="association_id" data-invitation-association aria-describedby="invitation-association-help" @if($errors->has('association_id')) aria-invalid="true" @endif>
                            <option value="">Select an association</option>
                            @foreach($associations as $association)
                                <option value="{{ $association->id }}" @selected($values['association_id'] === $association->id)>{{ $association->name }}</option>
                            @endforeach
                        </select>
                        <p class="field-help" id="invitation-association-help">Only active, unarchived associations without an account link are listed. Another unresolved invitation can also reserve an association. Field Officer invitations do not change assignments.</p>
                    </div>
                </div>
            </x-form-section>
            <div class="form-actions">
                <a class="button" href="{{ route('admin.users.index') }}">Cancel</a>
                <button type="submit" class="button button-primary" data-invitation-submit @disabled($roles->isEmpty() || $configurationProblems !== [])>Send invitation</button>
            </div>
            <p class="field-help" role="status" aria-live="polite" data-invitation-status></p>
        </div>
        <aside class="form-aside"><div class="card"><h3>Account activation</h3><p>Supabase Auth sends the invitation through its configured email provider. A successful request does not confirm delivery.</p><p>If the outcome is uncertain, reconcile the operation before sending again. Existing accounts are never overwritten.</p></div></aside>
    </form>
</div>
@endsection
