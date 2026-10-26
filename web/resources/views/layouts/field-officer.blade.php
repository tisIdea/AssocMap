<!DOCTYPE html>
@php
    $workspaceHeading = request()->routeIs('field-officer.members.*') ? 'Member Management' : (request()->routeIs('field-officer.gis.*') ? 'GIS Mapping' : (request()->routeIs('field-officer.monitoring.*') ? 'Monitoring' : (request()->routeIs('field-officer.program-activities.*') ? 'Program Activities' : (request()->routeIs('field-officer.trainings.*') ? 'Trainings' : (request()->routeIs('field-officer.projects.*') ? 'Projects' : 'Member Applications')))));
@endphp
@php if (request()->routeIs('field-officer.reports.*')) $workspaceHeading = 'Reports & Analytics'; @endphp
@php if (request()->routeIs('field-officer.dashboard')) $workspaceHeading = 'Dashboard'; @endphp
@php if (request()->routeIs('field-officer.associations.*')) $workspaceHeading = 'Assigned Associations'; @endphp
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
    <x-workspace-sidebar :dashboard-url="route('field-officer.dashboard')" workspace="FIELD OFFICER WORKSPACE" id="field-officer-sidebar" aria-label="Field Officer navigation">
        <x-workspace-nav-section id="field-officer-overview" label="OVERVIEW">
            <x-workspace-nav-link :href="route('field-officer.dashboard')" label="Dashboard" icon="layout-dashboard" :active="request()->routeIs('field-officer.dashboard')" />
        </x-workspace-nav-section>
        <x-workspace-nav-section id="field-officer-associations-members" label="ASSOCIATIONS & MEMBERS">
            <x-workspace-nav-link :href="route('field-officer.associations.index')" label="Assigned Associations" icon="building" :active="request()->routeIs('field-officer.associations.*')" />
            <x-workspace-nav-link :href="route('field-officer.association-officers.index')" label="Association Officers" icon="users" :active="request()->routeIs('field-officer.association-officers.*')" />
            <x-workspace-nav-link :href="route('field-officer.members.index')" label="Member Management" icon="users-round" :active="request()->routeIs('field-officer.members.*')" />
            <x-workspace-nav-link :href="route('field-officer.member-applications.index')" label="Member Applications" icon="users" :active="request()->routeIs('field-officer.member-applications.*')" />
        </x-workspace-nav-section>
        <x-workspace-nav-section id="field-officer-projects-activities" label="PROJECTS & ACTIVITIES">
            <x-workspace-nav-link :href="route('field-officer.projects.index')" label="Projects" icon="folder-kanban" :active="request()->routeIs('field-officer.projects.*')" />
            <x-workspace-nav-link :href="route('field-officer.trainings.index')" label="Trainings" icon="graduation-cap" :active="request()->routeIs('field-officer.trainings.*')" />
            <x-workspace-nav-link :href="route('field-officer.program-activities.index')" label="Program Activities" icon="calendar" :active="request()->routeIs('field-officer.program-activities.*')" />
        </x-workspace-nav-section>
        <x-workspace-nav-section id="field-officer-monitoring-insights" label="MONITORING & INSIGHTS">
            <x-workspace-nav-link :href="route('field-officer.monitoring.index')" label="Monitoring" icon="clipboard-check" :active="request()->routeIs('field-officer.monitoring.*')" />
            <x-workspace-nav-link :href="route('field-officer.gis.index')" label="GIS Mapping" icon="map-pinned" :active="request()->routeIs('field-officer.gis.*')" />
            <x-workspace-nav-link :href="route('field-officer.reports.index')" label="Reports & Analytics" icon="chart-no-axes-column" :active="request()->routeIs('field-officer.reports.*')" />
        </x-workspace-nav-section>
    </x-workspace-sidebar>
    <button type="button" class="sidebar-backdrop" data-fo-close hidden aria-label="Close navigation"></button>
    <div class="main-shell">
        <x-workspace-header
            workspace="Field Officer workspace"
            :context="request()->routeIs('field-officer.association-officers.*') ? 'Association Officers' : $workspaceHeading"
            :display-name="$currentUser->display_name ?? 'Field Officer'"
            account-label="Field Officer workspace"
        >
            <x-slot:navigationToggle>
                <button type="button" class="icon-button mobile-menu" data-fo-open aria-label="Open navigation" aria-controls="field-officer-sidebar" aria-expanded="false"><x-icon name="menu" /></button>
            </x-slot:navigationToggle>
        </x-workspace-header>
        <main id="main-content" tabindex="-1">
            @yield('content')
        </main>
        <footer class="app-footer"><span>AssocMap · GIS-Based Program Monitoring Information System</span><span>Capstone Project Prototype</span></footer>
    </div>
    @stack('dialogs')
    <x-global-feedback :notification="$globalFeedbackNotification ?? null" />
    <noscript><div class="notice">Enable JavaScript to open application details and review dialogs.</div></noscript>
    <script type="module">
        @stack('scripts')
    </script>
</body>
</html>
