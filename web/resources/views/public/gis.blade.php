<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Public GIS Map · AssocMap</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="public-page public-gis-page">
    <header class="public-gis-header">
        <div class="gis-shell-container gis-shell-navbar">
            <a href="#" class="public-brand" aria-label="AssocMap home (placeholder)">
                <span class="public-brand-mark"><x-icon name="map" /></span>
                <span><strong>Assoc<span>Map</span></strong><small>SAAD Phase II · Region VII</small></span>
            </a>
            <a href="{{ route('home') }}" class="gis-shell-back"><x-icon name="back" /> Back to AssocMap</a>
        </div>
    </header>
    <main class="gis-shell-container gis-shell-main" aria-labelledby="public-gis-title">
        <header class="gis-shell-heading">
            <h1 id="public-gis-title">Public GIS Map</h1>
            <p>A public view of published program locations and project information. This page is a static shell; the map will be connected later.</p>
        </header>
        <div class="gis-shell-notice">
            <x-icon name="shield" />
            <p>Only information approved for public viewing is displayed on this map. Personal member information, internal notes, audit records, private account details, and unpublished locations are excluded from the intended public view.</p>
        </div>

        <form class="gis-shell-filters" onsubmit="return false;" aria-describedby="gis-filter-note">
            <fieldset>
                <legend>Filter published information</legend>
                <div class="gis-shell-filter-grid">
                    <div><label for="gis-municipality">Municipality</label><select id="gis-municipality" name="municipality"><option value="">All Municipalities</option></select></div>
                    <div><label for="gis-association">Association</label><select id="gis-association" name="association"><option value="">All Associations</option></select></div>
                    <div><label for="gis-project">Project</label><select id="gis-project" name="project"><option value="">All Projects</option></select></div>
                    <div><label for="gis-program-year">Program Year</label><select id="gis-program-year" name="program_year"><option value="">All Years</option></select></div>
                    <div>
                        <label for="gis-fisheries-category">Fisheries Category</label>
                        <select id="gis-fisheries-category" name="fisheries_category">
                            <option value="">All Categories</option>
                            <option value="aquaculture">Aquaculture</option>
                            <option value="capture-fisheries">Capture Fisheries</option>
                            <option value="post-harvest">Post-Harvest</option>
                        </select>
                    </div>
                </div>
            </fieldset>
            <div class="gis-shell-filter-footer">
                <p id="gis-filter-note">Filter controls are placeholders. No location or project records are loaded.</p>
                <button type="reset" class="public-button public-button-outline gis-shell-reset">Reset Filters</button>
            </div>
        </form>

        <div class="gis-shell-workspace">
            <figure class="gis-shell-map" aria-labelledby="gis-map-title gis-map-caption">
                <div class="gis-shell-map-title">
                    <h2 id="gis-map-title"><x-icon name="map" /> Map workspace</h2>
                    <span class="gis-shell-label">Static placeholder</span>
                </div>
                <div class="gis-shell-canvas">
                    <svg class="gis-shell-art" viewBox="0 0 800 470" preserveAspectRatio="xMidYMid slice" fill="none" aria-hidden="true">
                        <defs><pattern id="gis-placeholder-grid" width="40" height="40" patternUnits="userSpaceOnUse"><path d="M40 0H0V40" stroke="#ccd9e3" stroke-width="1" /></pattern></defs>
                        <rect width="800" height="470" fill="#e8eff4" />
                        <rect width="800" height="470" fill="url(#gis-placeholder-grid)" />
                        <path d="M0 0H500L461 65 491 128 434 187 469 265 396 331 374 410 320 470H0Z" fill="#f1f3ed" stroke="#c7d5d5" stroke-width="2" />
                        <path d="M0 120 148 145 268 88 474 93 M0 340 195 296 333 340 447 270 M143 0 169 149 208 300 175 470 M337 0 300 175 341 369 293 470" stroke="white" stroke-width="14" />
                        <path d="m274 184 123-28 51 75-42 69-144-35z" fill="#0a447d" fill-opacity=".1" stroke="#35679d" stroke-width="2" stroke-dasharray="7 5" />
                    </svg>
                    <span class="gis-shell-marker gis-shell-association" aria-hidden="true"><x-icon name="building" /></span>
                    <span class="gis-shell-marker gis-shell-project" aria-hidden="true"><x-icon name="briefcase" /></span>
                    <div class="gis-shell-controls" aria-label="Map zoom placeholders">
                        <button type="button" disabled aria-label="Zoom in (not connected)"><x-icon name="plus" /></button>
                        <button type="button" disabled aria-label="Zoom out (not connected)"><x-icon name="minus" /></button>
                    </div>
                    <button type="button" class="gis-shell-layers" disabled aria-label="Map layers (not connected)"><x-icon name="layers" /></button>
                    <span class="gis-shell-map-note">Illustrative shapes and markers only · no geographic data</span>
                </div>
                <ul class="gis-shell-legend" aria-label="Map legend">
                    <li><x-icon name="building" /> Association Location</li>
                    <li><x-icon name="briefcase" class="gis-shell-project-icon" /> Project Location</li>
                    <li><span class="gis-shell-boundary-key" aria-hidden="true"></span> Project Boundary</li>
                </ul>
                <figcaption id="gis-map-caption">Map placeholder for the public GIS. Map controls and feature selection are not connected.</figcaption>
            </figure>

            <aside class="gis-shell-details" aria-labelledby="gis-details-title">
                <h2 id="gis-details-title">Location information</h2>
                <div class="gis-shell-empty">
                    <x-icon name="pin" />
                    <h3>No location selected.</h3>
                    <p>Select a published map feature to view details once the map is available.</p>
                </div>
                <dl>
                    <div><dt>Location Name</dt><dd>—</dd></div>
                    <div><dt>Type</dt><dd>—</dd></div>
                    <div><dt>Municipality</dt><dd>—</dd></div>
                    <div><dt>Barangay</dt><dd>—</dd></div>
                    <div><dt>Project</dt><dd>—</dd></div>
                    <div><dt>Program Year</dt><dd>—</dd></div>
                    <div><dt>Description</dt><dd>No published feature selected.</dd></div>
                </dl>
            </aside>
        </div>
        <footer class="gis-shell-footer">AssocMap · BFAR Region VII · SAAD Phase II</footer>
    </main>
</body>
</html>
