<!DOCTYPE html>
@php $workspaceHeading = request()->routeIs('association.gis.*') ? 'GIS Mapping' : (request()->routeIs('association.monitoring.*') ? 'Monitoring' : 'Member Applications'); @endphp
<html lang="en">
@php if (request()->routeIs('association.reports.*')) $workspaceHeading = 'Reports & Analytics'; @endphp
@php if (request()->routeIs('association.dashboard')) $workspaceHeading = 'Dashboard'; @endphp
@php if (request()->routeIs('association.projects.*')) $workspaceHeading = 'My Projects'; @endphp
@php if (request()->routeIs('association.trainings.*')) $workspaceHeading = 'Trainings'; @endphp
@php if (request()->routeIs('association.program-activities.*')) $workspaceHeading = 'Program Activities'; @endphp
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $workspaceHeading }} · AssocMap</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <a class="skip-link" href="#main-content">Skip to content</a>
    <x-workspace-sidebar :dashboard-url="route('association.dashboard')" workspace="ASSOCIATION WORKSPACE" id="association-sidebar" aria-label="Association Account navigation">
        <x-workspace-nav-section id="association-overview" label="OVERVIEW">
            <x-workspace-nav-link :href="route('association.dashboard')" label="Dashboard" icon="layout-dashboard" :active="request()->routeIs('association.dashboard')" />
        </x-workspace-nav-section>
        <x-workspace-nav-section id="association-membership" label="MEMBERSHIP">
            <x-workspace-nav-link :href="route('association.member-applications.index')" label="Member Applications" icon="users" :active="request()->routeIs('association.member-applications.*')" />
        </x-workspace-nav-section>
        <x-workspace-nav-section id="association-projects-activities" label="PROJECTS & ACTIVITIES">
            <x-workspace-nav-link :href="route('association.projects.index')" label="Projects" icon="folder-kanban" :active="request()->routeIs('association.projects.*')" />
            <x-workspace-nav-link :href="route('association.trainings.index')" label="Trainings" icon="graduation" :active="request()->routeIs('association.trainings.*')" />
            <x-workspace-nav-link :href="route('association.program-activities.index')" label="Program Activities" icon="calendar" :active="request()->routeIs('association.program-activities.*')" />
        </x-workspace-nav-section>
        <x-workspace-nav-section id="association-monitoring-insights" label="MONITORING & INSIGHTS">
            <x-workspace-nav-link :href="route('association.monitoring.index')" label="Monitoring" icon="clipboard-check" :active="request()->routeIs('association.monitoring.*')" />
            <x-workspace-nav-link :href="route('association.gis.index')" label="GIS Mapping" icon="map-pinned" :active="request()->routeIs('association.gis.*')" />
            <x-workspace-nav-link :href="route('association.reports.index')" label="Reports & Analytics" icon="chart-no-axes-column" :active="request()->routeIs('association.reports.*')" />
        </x-workspace-nav-section>
    </x-workspace-sidebar>
    <button type="button" class="sidebar-backdrop" data-aa-close hidden aria-label="Close navigation"></button>
    <div class="main-shell">
        <x-workspace-header
            workspace="Association Account workspace"
            :context="$workspaceHeading"
            :display-name="$currentUser->display_name ?? 'Association Account'"
            account-label="Association Account workspace"
        >
            <x-slot:navigationToggle>
                <button type="button" class="icon-button mobile-menu" data-aa-open aria-label="Open navigation" aria-controls="association-sidebar" aria-expanded="false"><x-icon name="menu" /></button>
            </x-slot:navigationToggle>
        </x-workspace-header>
        <main id="main-content" tabindex="-1">
            @yield('content')
        </main>
        <footer class="app-footer"><span>AssocMap · GIS-Based Program Monitoring Information System</span><span>Capstone Project Prototype</span></footer>
    </div>
    @stack('dialogs')
    <x-global-feedback :notification="$globalFeedbackNotification ?? null" />
    <noscript><div class="notice">Enable JavaScript to open application details and the submission form.</div></noscript>
    <script type="module">
        @stack('scripts')
    </script>
</body>
</html>
