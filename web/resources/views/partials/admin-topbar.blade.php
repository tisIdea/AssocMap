<header class="topbar"><div class="topbar-context">
    <button class="icon-button mobile-menu" type="button" data-open-sidebar aria-label="Open navigation" aria-controls="admin-sidebar" aria-expanded="false"><x-icon name="menu" />
</button><span class="context-product">Admin workspace</span>
<x-icon name="chevron" /><span id="current-context">Dashboard</span>
</div>
<div class="topbar-account">
    <button class="icon-button notification-button" type="button" disabled aria-label="Notifications preview">
        <x-icon name="bell" />
    </button><span class="account-divider">

    </span>
    <span class="avatar">SA</span>
    <div class="account-copy">
        <strong>{{ $currentUser->display_name }}</strong>
    <small>{{ $currentUser->role->name }}</small>
    </div><form method="POST" action="{{ route('logout') }}">
        @csrf<button type="submit" class="text-button">
            Logout</button>
        </form>
    </div>
</header>
