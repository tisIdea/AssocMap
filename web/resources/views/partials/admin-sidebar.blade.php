<x-workspace-sidebar :dashboard-url="route('admin.dashboard')" workspace="ADMIN WORKSPACE" id="admin-sidebar" aria-label="Administration sidebar" data-dashboard-url="{{ route('admin.dashboard') }}">
    <x-workspace-nav-section id="admin-overview" label="OVERVIEW">
        <x-workspace-nav-link :href="route('admin.dashboard')" label="Dashboard" icon="grid" :active="request()->routeIs('admin.dashboard')" data-nav="dashboard" />
    </x-workspace-nav-section>
    <x-workspace-nav-section id="admin-associations-members" label="ASSOCIATIONS & MEMBERS">
        @foreach(['areas', 'associations', 'members'] as $key)
            <x-workspace-nav-link :href="route('admin.'.$key.'.index')" :label="$catalog['modules'][$key]['title']" :icon="$catalog['modules'][$key]['icon']" :active="request()->routeIs('admin.'.$key.'.*')" data-nav="{{ $key }}" />
        @endforeach
    </x-workspace-nav-section>
    <x-workspace-nav-section id="admin-projects-activities" label="PROJECTS & ACTIVITIES">
        @foreach(['projects', 'trainings', 'program-activities'] as $key)
            <x-workspace-nav-link :href="route('admin.'.$key.'.index')" :label="$catalog['modules'][$key]['title']" :icon="$catalog['modules'][$key]['icon']" :active="request()->routeIs('admin.'.$key.'.*')" data-nav="{{ $key }}" />
        @endforeach
    </x-workspace-nav-section>
    <x-workspace-nav-section id="admin-monitoring-insights" label="MONITORING & INSIGHTS">
        <x-workspace-nav-link :href="route('admin.monitoring.index')" :label="$catalog['modules']['monitoring']['title']" :icon="$catalog['modules']['monitoring']['icon']" :active="request()->routeIs('admin.monitoring.*')" data-nav="monitoring" />
        <x-workspace-nav-link :href="route('admin.gis.index')" label="GIS Mapping" icon="map-pinned" :active="request()->routeIs('admin.gis.*')" data-nav="gis" />
        <x-workspace-nav-link :href="route('admin.reports.index')" label="Reports & Analytics" icon="chart" :active="request()->routeIs('admin.reports.*')" data-nav="reports" />
    </x-workspace-nav-section>
    <x-workspace-nav-section id="admin-administration" label="ADMINISTRATION">
        <x-workspace-nav-link :href="route('admin.users.index')" :label="$catalog['modules']['users']['title']" :icon="$catalog['modules']['users']['icon']" :active="request()->routeIs('admin.users.*')" data-nav="users" />
        <x-workspace-nav-link :href="route('admin.audit-logs.index')" label="Audit Log" icon="receipt" :active="request()->routeIs('admin.audit-logs.*')" data-nav="audit-logs" />
    </x-workspace-nav-section>
</x-workspace-sidebar>
<button class="sidebar-backdrop" data-close-sidebar aria-label="Close navigation" hidden></button>
