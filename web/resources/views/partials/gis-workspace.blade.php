<section class="gis-module" data-gis-workspace data-workspace-module="gis" data-workspace-title="GIS Mapping"
    data-gis-base="{{ $gisBaseUrl }}" data-gis-public="{{ $gisPublic ? 'true' : 'false' }}" data-gis-manager="{{ $gisManager ? 'true' : 'false' }}">
    <header class="page-header gis-heading">
        <div><h1 tabindex="-1">{{ $gisTitle }}</h1><p>{{ $gisDescription }}</p></div>
        @if($gisManager)<a href="{{ $gisBaseUrl }}/create" class="button button-primary"><x-icon name="plus" />Add GIS location</a>@endif
    </header>
    <div class="gis-toolbar">
        <div class="area-list-switch" aria-label="GIS view">
            <button type="button" class="button" data-gis-view="map" aria-pressed="true">Map</button>
            <button type="button" class="button" data-gis-view="list" aria-pressed="false">{{ $gisManager ? 'Registered locations' : 'Location list' }}</button>
        </div>
        <div class="gis-filter-actions" role="group" aria-label="GIS record controls">
            <button type="button" class="button" data-gis-reload>Refresh Records</button>
            <button type="button" class="button" data-gis-retry hidden>Retry GIS records</button>
        </div>
    </div>
    <details class="card gis-filters" open>
        <summary>Search and filters</summary>
        <form data-gis-filters class="gis-filter-grid">
            <div class="field"><label for="gis-search">Search {{ $gisPublic ? 'approved titles and addresses' : 'locations, associations and geography' }}</label><input id="gis-search" name="q" type="search" maxlength="100"></div>
            <div class="field"><label for="gis-type">Location type</label><select id="gis-type" name="location_type"><option value="">All project location types</option><option value="project_point">Project point</option><option value="project_boundary">Project boundary</option></select></div>
            @if($gisPublic)
                <div class="field"><label for="gis-association">Association via published project location</label><select id="gis-association" name="association_location_id" data-gis-parent="association"><option value="">All eligible associations</option></select><small>Choose an approved project-location title to filter its association's published locations.</small></div>
            @else
                @foreach(['municipality' => 'Municipality', 'barangay' => 'Barangay', 'area' => 'Specific area', 'association' => 'Association', 'project' => 'Project'] as $kind => $label)
                    <div class="field"><label for="gis-filter-{{ $kind }}">{{ $label }}</label><select id="gis-filter-{{ $kind }}" name="{{ $kind }}_id" data-gis-parent="{{ $kind }}"><option value="">All {{ strtolower($label) }} records in scope</option></select></div>
                @endforeach
            @endif
            <div class="field"><label for="gis-parent-search">Find {{ $gisPublic ? 'approved project-location titles' : 'parent and geographic options' }}</label><input id="gis-parent-search" type="search" maxlength="100" data-gis-option-search><small data-gis-option-note>Options are bounded. Search to find additional records.</small></div>
            @if($gisManager)
                <div class="field"><label for="gis-publication">Publication</label><select id="gis-publication" name="publication"><option value="">All publication states</option><option value="published">Published</option><option value="unpublished">Unpublished</option></select></div>
                <div class="field"><label for="gis-lifecycle">Lifecycle</label><select id="gis-lifecycle" name="lifecycle"><option value="active">Active records</option><option value="archived">Archived records</option></select></div>
            @endif
            <div class="gis-filter-actions"><button type="submit" class="button button-primary">Apply filters</button><button type="reset" class="button">Reset</button></div>
        </form>
    </details>
    <p class="notice gis-status" data-gis-status role="status" aria-live="polite">Loading GIS records…</p>
    <p class="notice" data-gis-tile-notice hidden>Basemap tiles are unavailable. Saved locations and the location list remain accessible.</p>
    <div class="gis-spatial-grid">
        <div class="card gis-map-panel" data-gis-map-panel>
            <div class="gis-map" data-gis-map role="region" aria-label="Interactive GIS map"></div>
            <ul class="gis-legend" aria-label="Map legend"><li><i class="gis-key project" aria-hidden="true"></i>Project point</li><li><i class="gis-key boundary" aria-hidden="true"></i>Project boundary</li></ul>
            <p class="gis-help">The map shows bounded records in the current view. Zoom or filter to narrow results; use the list to browse all records in scope.</p>
        </div>
        <div class="card gis-register-panel" data-gis-list-panel hidden>
            <h2>{{ $gisManager ? 'GIS register' : 'Location list' }}</h2>
            <div data-gis-list></div>
            <div class="gis-pagination"><button type="button" class="button" data-gis-page="previous">Previous</button><span data-gis-page-label></span><button type="button" class="button" data-gis-page="next">Next</button></div>
        </div>
        <aside class="card gis-location-panel" aria-labelledby="gis-details-title">
            <h2 id="gis-details-title">Location information</h2>
            <p data-gis-selection-note>Select a map feature or a location from the list.</p>
            <dl data-gis-details></dl>
            <div class="gis-record-actions" data-gis-record-actions></div>
        </aside>
    </div>
    @if($gisManager)
        <input type="hidden" data-gis-csrf value="{{ csrf_token() }}">
        <dialog class="modal gis-confirm" data-gis-confirm aria-labelledby="gis-confirm-title">
            <h2 id="gis-confirm-title" data-gis-confirm-title>Review GIS action</h2><p data-gis-confirm-message></p><dl data-gis-confirm-preview></dl>
            <div class="modal-actions"><button type="button" class="button" data-gis-confirm-cancel>Cancel</button><button type="button" class="button button-primary" data-gis-confirm-submit>Confirm</button></div>
        </dialog>
    @endif
    <noscript><p class="notice">Enable JavaScript to use the GIS map, record list, and controls.</p></noscript>
</section>
@if($gisManager)
    <div id="gis-action-notification" class="gis-notification" role="status" aria-live="polite" aria-atomic="true">
        <div class="gis-notification-content" data-gis-notification-content hidden>
            <p data-gis-notification-message></p>
            <button type="button" class="button" data-gis-notification-dismiss aria-label="Dismiss publication notification">Dismiss</button>
        </div>
    </div>
@endif
