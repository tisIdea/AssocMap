@extends($gisLayout)
@section('content')
    @php $editing = $gisFeature !== null; $record = $gisFeature['properties'] ?? []; @endphp
    <section class="gis-module" data-gis-editor data-workspace-module="gis" data-workspace-title="GIS Mapping" data-gis-base="{{ $gisBaseUrl }}">
        <header class="page-header"><div><h1>{{ $editing ? 'Edit GIS location' : 'Add GIS location' }}</h1><p>Select the correct parent and provide actual geographic coordinates or a project boundary.</p></div><a class="button" href="{{ $gisBaseUrl }}">Back to GIS</a></header>
        <p class="notice" data-gis-editor-status role="status">Loading the geometry editor…</p>
        @if($record['is_published'] ?? false)<p class="notice">This location is published. Saving updates its geometry and approved public details in the public viewer.</p>@endif
        @if($record['project_finalized'] ?? false)<p class="notice">This project is finalized. GIS details and publication may be maintained. Its project and association ownership cannot change, and this location cannot be deleted.</p>@endif
        <form data-gis-editor-form method="POST" action="{{ $editing ? $gisBaseUrl.'/'.$gisFeature['id'] : $gisBaseUrl }}">
            @csrf
            @if($editing)<input type="hidden" name="_method" value="PATCH"><input type="hidden" name="version" value="{{ $record['version'] }}">
            @else<input type="hidden" name="idempotency_key" value="{{ $gisRequestId }}">@endif
            <div class="gis-editor-grid">
                <div class="card gis-editor-fields">
                    <div class="field"><label for="gis-location-type">Location type</label><select id="gis-location-type" name="location_type" required><option value="project_point">Project point</option><option value="project_boundary">Project boundary</option></select></div>
                    <div class="field"><label for="gis-editor-parent-search">Find associations or projects</label><input id="gis-editor-parent-search" type="search" maxlength="100" data-gis-option-search><small id="gis-parent-option-note" data-gis-option-note role="status">Search to find additional parent records in your scope.</small><button type="button" class="button" data-gis-association-retry hidden>Retry associations</button></div>
                    <div class="field"><label for="gis-editor-association">Association</label><select id="gis-editor-association" data-gis-editor-association aria-describedby="gis-parent-option-note" disabled required><option value="">Select an association</option></select></div>
                    <input type="hidden" name="parent_association_id" value="">
                    <div class="field" data-gis-project-field><label for="gis-editor-project">Project</label><select id="gis-editor-project" name="project_id" aria-describedby="gis-project-status" disabled required><option value="">Select an association first</option></select><small id="gis-project-status" data-gis-project-status role="status">Select an association to load its eligible projects.</small><button type="button" class="button" data-gis-project-retry hidden>Retry projects</button></div>
                    <div class="field"><label for="gis-location-name">Internal location name</label><input id="gis-location-name" name="location_name" maxlength="255" value="{{ $record['title'] ?? '' }}" required></div>
                    <div class="field">
                        <label for="gis-registered-area">Registered Area</label>
                        <textarea id="gis-registered-area" rows="3" readonly data-gis-registered-area aria-describedby="gis-registered-area-status"></textarea>
                        <small id="gis-registered-area-status" data-gis-registered-area-status role="status">Select an association to load its registered area. This is association-area context, not a verified project site.</small>
                    </div>
                    <div class="field">
                        <label for="gis-internal-address">Project Site Address (optional)</label>
                        <textarea id="gis-internal-address" name="address" maxlength="2000" rows="2" aria-describedby="gis-site-address-note">{{ $record['address'] ?? '' }}</textarea>
                        <small id="gis-site-address-note">Enter a verified physical site address. Registered Area is never copied here. {{ $editing ? 'The saved value is preserved; legacy values may be association-area fallbacks. Verify it before treating it as an exact site address.' : 'Leave blank if a verified site address is unavailable.' }} Public details and geometry must be supplied separately.</small>
                    </div>
                    <fieldset class="gis-public-fields"><legend>Approved public details</legend>
                        <p class="gis-help">Public details are explicitly supplied here. Publishing never copies the internal name or address automatically.</p>
                        <div class="field"><label for="gis-public-title">Public title</label><input id="gis-public-title" name="public_title" maxlength="255" value="{{ $record['public_title'] ?? '' }}" @if($record['is_published'] ?? false) required @endif></div>
                        <div class="field"><label for="gis-public-description">Public description</label><textarea id="gis-public-description" name="public_description" maxlength="4000" rows="3">{{ $record['public_description'] ?? '' }}</textarea></div>
                        <div class="field"><label for="gis-public-address">Public address</label><textarea id="gis-public-address" name="public_address" maxlength="2000" rows="2">{{ $record['public_address'] ?? '' }}</textarea></div>
                    </fieldset>
                </div>
                <div class="card gis-editor-spatial">
                    <h2>Geometry</h2><p class="gis-help" data-gis-geometry-help>Click the map to select a point, or draw and edit boundary polygons. Nothing is saved until you submit the form.</p>
                    <div class="gis-map gis-editor-map" data-gis-editor-map role="region" aria-label="GIS geometry selection and boundary editor"></div>
                    <p class="notice" data-gis-tile-notice hidden>Basemap tiles are unavailable. Coordinate fields and GeoJSON editing remain available.</p>
                    <div class="gis-coordinate-grid" data-gis-coordinate-fields>
                        <div class="field"><label for="gis-longitude">Longitude</label><input id="gis-longitude" type="number" min="-180" max="180" step="any" data-gis-longitude></div>
                        <div class="field"><label for="gis-latitude">Latitude</label><input id="gis-latitude" type="number" min="-90" max="90" step="any" data-gis-latitude></div>
                    </div>
                    <div class="gis-editor-tools"><button type="button" class="button" data-gis-fit>Fit saved geometry</button><button type="button" class="button" data-gis-clear>Clear geometry</button></div>
                    <div class="field"><label for="gis-geometry-json">GeoJSON geometry</label><textarea id="gis-geometry-json" name="geometry" rows="7" spellcheck="false" maxlength="131072" aria-describedby="gis-json-help" required></textarea><small id="gis-json-help">Coordinates use longitude, latitude. Supply a geometry object, with at most 2,000 positions. Multiple polygon parts and existing holes are preserved.</small></div>
                    <button type="button" class="button" data-gis-preview>Preview GeoJSON</button>
                </div>
            </div>
            <div class="gis-save-actions"><a class="button" href="{{ $gisBaseUrl }}">Cancel</a><button type="submit" class="button button-primary" data-gis-save disabled>{{ $editing ? 'Save changes' : 'Create unpublished location' }}</button></div>
        </form>
        <script type="application/json" data-gis-seed>{!! json_encode($gisFeature, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
        <noscript><p class="notice">Enable JavaScript to select parents, edit geometry, and save this location.</p></noscript>
    </section>
@endsection
