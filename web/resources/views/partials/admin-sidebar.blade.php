<x-workspace-sidebar :dashboard-url="route('admin.dashboard')" workspace="ADMIN WORKSPACE" id="admin-sidebar" aria-label="Administration sidebar" data-dashboard-url="{{ route('admin.dashboard') }}">
    <x-workspace-nav-link :href="route('admin.dashboard')" label="Dashboard" icon="grid" :active="request()->routeIs('admin.dashboard')" data-nav="dashboard" />
    @foreach(['users', 'areas', 'associations', 'members'] as $key)
        <x-workspace-nav-link :href="route('admin.'.$key.'.index')" :label="$catalog['modules'][$key]['title']" :icon="$catalog['modules'][$key]['icon']" :active="request()->routeIs('admin.'.$key.'.*')" data-nav="{{ $key }}" />
    @endforeach
    <x-workspace-nav-group id="program-navigation" :active="request()->routeIs('admin.projects.*', 'admin.trainings.*', 'admin.monitoring.*', 'admin.program-activities.*')">
        @foreach(['projects', 'trainings', 'monitoring', 'program-activities'] as $key)
            <x-workspace-nav-link :href="route('admin.'.$key.'.index')" :label="$catalog['modules'][$key]['title']" :icon="$catalog['modules'][$key]['icon']" :active="request()->routeIs('admin.'.$key.'.*')" data-nav="{{ $key }}" />
        @endforeach
    </x-workspace-nav-group>
    <x-workspace-nav-link :href="route('admin.gis.index')" label="GIS Mapping" icon="map-pinned" :active="request()->routeIs('admin.gis.*')" data-nav="gis" />
    <x-workspace-nav-link :href="route('admin.reports.index')" label="Reports & Analytics" icon="chart" :active="request()->routeIs('admin.reports.*')" data-nav="reports" />
    <x-workspace-nav-link :href="route('admin.audit-logs.index')" label="Audit Log" icon="receipt" :active="request()->routeIs('admin.audit-logs.*')" data-nav="audit-logs" />
</x-workspace-sidebar>
<button class="sidebar-backdrop" data-close-sidebar aria-label="Close navigation" hidden></button>
