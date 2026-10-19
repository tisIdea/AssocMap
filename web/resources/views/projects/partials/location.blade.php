<section aria-labelledby="project-location-{{ $row['id'] }}">
    <h3 id="project-location-{{ $row['id'] }}">Project Location</h3>
    <dl class="detail-grid">
        <div><dt>Registered Area reference</dt><dd>{{ filled($row['area'] ?? null) ? $row['area'] : 'Registered area information unavailable.' }}</dd></div>
        <div><dt>Barangay</dt><dd>{{ $row['barangay'] ?? 'Not available' }}</dd></div>
        <div><dt>Municipality</dt><dd>{{ $row['municipality'] ?? 'Not available' }}</dd></div>
    </dl>
    <p>The Registered Area belongs to the association. It is not a confirmed project site.</p>
    @if(!($row['gis_locations_available'] ?? true))
        <p class="notice notice-error" role="alert">Project GIS location information could not be loaded. Refresh this page to retry.</p>
    @else
    @forelse($row['gis_locations'] ?? [] as $location)
        <div class="repeat-row">
            <dl class="detail-grid">
                <div><dt>GIS location</dt><dd>{{ $location['name'] }}</dd></div>
                <div><dt>Type</dt><dd>{{ $location['type'] === 'project_point' ? 'Project Point' : 'Project Boundary' }}</dd></div>
                <div><dt>Project Site Address</dt><dd>{{ filled($location['address']) ? $location['address'] : 'Project site address not available.' }}</dd></div>
                <div><dt>Source / verification</dt><dd>Stored in the project's GIS location. Address source and exact-site verification are not recorded; legacy values may be association-area fallbacks and require review.</dd></div>
            </dl>
        </div>
    @empty
        <p>Project site address not available.</p>
        <p>No eligible active GIS project locations are available in your current scope.</p>
    @endforelse
    @endif
</section>
