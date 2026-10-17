<!DOCTYPE html>
@php
    $workspaceHeading = request()->routeIs('field-officer.members.*') ? 'Member Management' : (request()->routeIs('field-officer.gis.*') ? 'GIS Mapping' : (request()->routeIs('field-officer.monitoring.*') ? 'Monitoring' : (request()->routeIs('field-officer.program-activities.*') ? 'Program Activities' : (request()->routeIs('field-officer.trainings.*') ? 'Trainings' : (request()->routeIs('field-officer.projects.*') ? 'Projects' : 'Member Applications')))));
@endphp
@php if (request()->routeIs('field-officer.reports.*')) $workspaceHeading = 'Reports & Analytics'; @endphp
@php if (request()->routeIs('field-officer.dashboard')) $workspaceHeading = 'Dashboard'; @endphp
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ request()->routeIs('field-officer.association-officers.*') ? 'Association Officers' : $workspaceHeading }} · AssocMap</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <a class="skip-link" href="#main-content">Skip to content</a>
    <aside class="sidebar" id="field-officer-sidebar" aria-label="Field Officer navigation">
        <a class="brand" href="{{ route('field-officer.dashboard') }}" aria-label="AssocMap dashboard">
            <span class="brand-symbol"><x-icon name="map" /></span>
            <span class="brand-copy"><strong>Assoc<span>Map</span></strong><small>FIELD OFFICER WORKSPACE</small></span>
        </a>
        <div class="sidebar-section-label">WORKSPACE</div>
        <nav class="sidebar-nav" aria-label="Main navigation">
            <a class="nav-link {{ request()->routeIs('field-officer.dashboard') ? 'active' : '' }}" href="{{ route('field-officer.dashboard') }}" @if(request()->routeIs('field-officer.dashboard')) aria-current="page" @endif><x-icon name="grid" /><span>Dashboard</span></a>
            <a class="nav-link {{ request()->routeIs('field-officer.reports.*') ? 'active' : '' }}" href="{{ route('field-officer.reports.index') }}" @if(request()->routeIs('field-officer.reports.*')) aria-current="page" @endif><x-icon name="chart" /><span>Reports &amp; Analytics</span></a>
            <a class="nav-link {{ request()->routeIs('field-officer.members.*') ? 'active' : '' }}" href="{{ route('field-officer.members.index') }}" @if(request()->routeIs('field-officer.members.*')) aria-current="page" @endif><x-icon name="users" /><span>Member Management</span></a>
            <a class="nav-link {{ request()->routeIs('field-officer.association-officers.*') ? 'active' : '' }}" href="{{ route('field-officer.association-officers.index') }}" @if(request()->routeIs('field-officer.association-officers.*')) aria-current="page" @endif><x-icon name="users" /><span>Association Officers</span></a>
            <a class="nav-link {{ request()->routeIs('field-officer.gis.*') ? 'active' : '' }}" href="{{ route('field-officer.gis.index') }}" @if(request()->routeIs('field-officer.gis.*')) aria-current="page" @endif><x-icon name="map" /><span>GIS Mapping</span></a>
            <a class="nav-link {{ request()->routeIs('field-officer.program-activities.*') ? 'active' : '' }}" href="{{ route('field-officer.program-activities.index') }}" @if(request()->routeIs('field-officer.program-activities.*')) aria-current="page" @endif><x-icon name="briefcase" /><span>Program Activities</span></a>
            <a class="nav-link {{ request()->routeIs('field-officer.trainings.*') ? 'active' : '' }}" href="{{ route('field-officer.trainings.index') }}" @if(request()->routeIs('field-officer.trainings.*')) aria-current="page" @endif><x-icon name="users" /><span>Trainings</span></a>
            <a class="nav-link {{ request()->routeIs('field-officer.member-applications.*') ? 'active' : '' }}" href="{{ route('field-officer.member-applications.index') }}" @if(request()->routeIs('field-officer.member-applications.*')) aria-current="page" @endif><x-icon name="users" /><span>Member Applications</span></a>
            <a class="nav-link {{ request()->routeIs('field-officer.projects.*') ? 'active' : '' }}" href="{{ route('field-officer.projects.index') }}" @if(request()->routeIs('field-officer.projects.*')) aria-current="page" @endif><x-icon name="briefcase" /><span>Projects</span></a>
            <a class="nav-link {{ request()->routeIs('field-officer.monitoring.*') ? 'active' : '' }}" href="{{ route('field-officer.monitoring.index') }}" @if(request()->routeIs('field-officer.monitoring.*')) aria-current="page" @endif><x-icon name="chart" /><span>Monitoring</span></a>
        </nav>
    </aside>
    <button type="button" class="sidebar-backdrop" data-fo-close hidden aria-label="Close navigation"></button>
    <div class="main-shell">
        <header class="topbar">
            <div class="topbar-context">
                <button type="button" class="icon-button mobile-menu" data-fo-open aria-label="Open navigation" aria-controls="field-officer-sidebar" aria-expanded="false"><x-icon name="menu" /></button>
                <span>{{ request()->routeIs('field-officer.association-officers.*') ? 'Association Officers' : $workspaceHeading }}</span>
            </div>
            <div class="topbar-account">
                <div class="account-copy"><strong>{{ $currentUser->display_name ?? 'Field Officer' }}</strong><small>Field Officer workspace</small></div>
                <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="text-button">Logout</button></form>
            </div>
        </header>
        <main id="main-content" tabindex="-1">
            @yield('content')
        </main>
        <footer class="app-footer"><span>AssocMap · GIS-Based Program Monitoring Information System</span><span>BFAR Region VII / SAAD Phase II</span></footer>
    </div>
    @stack('dialogs')
    <x-global-feedback :notification="$globalFeedbackNotification ?? null" />
    <noscript><div class="notice">Enable JavaScript to open application details and review dialogs.</div></noscript>
    <script type="module">
        const sidebar = document.getElementById('field-officer-sidebar');
        const shell = document.querySelector('.main-shell');
        const opener = document.querySelector('[data-fo-open]');
        const backdrop = document.querySelector('[data-fo-close]');
        const mobile = window.matchMedia('(max-width: 1020px)');
        function setDrawer(open, restore = true) {
            document.body.classList.toggle('drawer-open', open);
            backdrop.hidden = !open;
            opener.setAttribute('aria-expanded', String(open));
            sidebar.inert = mobile.matches && !open;
            shell.inert = open;
            if (open) sidebar.querySelector('a').focus();
            else if (restore && mobile.matches) opener.focus();
        }
        opener.addEventListener('click', () => setDrawer(true));
        backdrop.addEventListener('click', () => setDrawer(false));
        document.addEventListener('keydown', event => {
            if (!document.body.classList.contains('drawer-open')) return;
            if (event.key === 'Escape') setDrawer(false);
            if (event.key === 'Tab') {
                const links = Array.from(sidebar.querySelectorAll('a'));
                const first = links[0];
                const last = links.at(-1);
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
                else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
            }
        });
        mobile.addEventListener('change', () => setDrawer(false, false));
        setDrawer(false, false);
        @stack('scripts')
    </script>
</body>
</html>
