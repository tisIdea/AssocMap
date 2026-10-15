<aside class="sidebar" id="admin-sidebar" aria-label="Administration sidebar" data-dashboard-url="{{ route('admin.dashboard') }}">
    <a class="brand" href="#dashboard" aria-label="AssocMap dashboard"><span class="brand-symbol"><x-icon name="map" /></span><span class="brand-copy"><strong>Assoc<span>Map</span></strong><small>SAAD PHASE II · REGION VII</small></span></a>
    <div class="sidebar-section-label">WORKSPACE</div>
    <nav class="sidebar-nav" aria-label="Main navigation">
        <a class="nav-link active" href="#dashboard" data-nav="dashboard" aria-current="page" title="Dashboard"><x-icon name="grid" /><span>Dashboard</span></a>
        @foreach(['users', 'areas', 'associations', 'members'] as $key)
            <a class="nav-link" href="{{ $key === 'users' ? route('admin.users.index') : ($key === 'areas' ? route('admin.areas.index') : '#'.$key) }}" data-nav="{{ $key }}" title="{{ $catalog['modules'][$key]['title'] }}"><x-icon :name="$catalog['modules'][$key]['icon']" /><span>{{ $catalog['modules'][$key]['title'] }}</span></a>
        @endforeach
        <button type="button" class="nav-link program-toggle" data-disclosure="program-navigation" aria-expanded="false" aria-controls="program-navigation" title="Program Operations"><x-icon name="briefcase" /><span>Program Operations</span><x-icon name="chevron" class="disclosure-chevron" /></button>
        <div class="subnav" id="program-navigation" hidden>
            @foreach(['projects', 'trainings', 'monitoring', 'program-activities'] as $key)
                <a class="nav-link" href="{{ route('admin.'.$key.'.index') }}" data-nav="{{ $key }}" title="{{ $catalog['modules'][$key]['title'] }}"><x-icon :name="$catalog['modules'][$key]['icon']" /><span>{{ $catalog['modules'][$key]['title'] }}</span></a>
            @endforeach
        </div>
        <a class="nav-link" href="{{ route('admin.gis.index') }}" data-nav="gis" title="GIS Mapping"><x-icon name="map" /><span>GIS Mapping</span></a>
        <a class="nav-link" href="#reports" data-nav="reports" title="Reports & Analytics"><x-icon name="chart" /><span>Reports &amp; Analytics</span></a>
        <a class="nav-link" href="{{ route('admin.audit-logs.index') }}" data-nav="audit-logs" title="Audit Log"><x-icon name="receipt" /><span>Audit Log</span></a>
    </nav>
    <div class="sidebar-footer"><div class="sidebar-program"><x-icon name="shield" /><div><strong>BFAR Region VII</strong><small>Community livelihood monitoring</small></div></div><button type="button" class="sidebar-collapse" data-collapse-sidebar aria-label="Collapse sidebar" aria-expanded="true"><x-icon name="back" /><span>Collapse sidebar</span></button></div>
</aside>
<button class="sidebar-backdrop" data-close-sidebar aria-label="Close navigation" hidden></button>
