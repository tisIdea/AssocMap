@props([
    'workspace',
    'context',
    'displayName',
    'accountLabel',
    'contextId' => null,
    'avatar' => null,
])

<header class="topbar workspace-header">
    <div class="topbar-context">
        {{ $navigationToggle }}
        <div class="context-product">{{ $workspace }}</div>
        <x-icon name="chevron" />
        <span class="workspace-header-context" @if($contextId) id="{{ $contextId }}" @endif>{{ $context }}</span>
    </div>
    <div class="topbar-account">
        <span class="account-divider" aria-hidden="true"></span>
        @if($avatar)
            <span class="avatar">{{ $avatar }}</span>
        @endif
        <div class="account-copy">
            <strong>{{ $displayName }}</strong>
            <small>{{ $accountLabel }}</small>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="text-button"><x-icon name="log-out" />Logout</button>
        </form>
    </div>
</header>
