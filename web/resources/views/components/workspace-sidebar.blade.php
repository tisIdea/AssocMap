@props(['dashboardUrl', 'workspace'])

<aside {{ $attributes->class(['sidebar']) }} data-workspace-sidebar>
    <a class="brand" href="{{ $dashboardUrl }}" aria-label="AssocMap dashboard">
        <span class="brand-symbol"><x-assocmap-mark /></span>
        <span class="brand-copy"><strong>Assoc<span>Map</span></strong><small>{{ $workspace }}</small></span>
    </a>
    <nav class="sidebar-nav" aria-label="Main navigation">
        {{ $slot }}
    </nav>
    <div class="sidebar-footer">
        <div class="sidebar-program"><x-icon name="info" /><div><strong>AssocMap Information System</strong><small>Association and project monitoring</small></div></div>
        <button type="button" class="sidebar-collapse sidebar-dismiss" data-close-workspace-sidebar aria-label="Close navigation" aria-controls="{{ $attributes->get('id') }}" hidden><x-icon name="close" /><span>Close navigation</span></button>
        <button type="button" class="sidebar-collapse" data-collapse-sidebar aria-label="Collapse sidebar" aria-controls="{{ $attributes->get('id') }}" aria-expanded="true" title="Collapse sidebar">
            <x-icon name="back" /><span data-sidebar-collapse-label>Collapse sidebar</span>
        </button>
    </div>
</aside>
