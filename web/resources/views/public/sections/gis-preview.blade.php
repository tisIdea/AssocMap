<section id="public-gis" class="public-section public-section-tinted" aria-labelledby="public-gis-title">
    <div class="public-container public-gis-grid">
        <div class="public-section-heading">
            <p class="public-eyebrow">Public GIS</p>
            <h2 id="public-gis-title">See the geographic context of livelihood programs.</h2>
            <p>The future public map will show only published association locations and projects, without requiring a staff sign-in.</p>
            <p>Unpublished locations and internal records will not be part of the public view.</p>
            <button type="button" class="public-button public-button-outline" disabled aria-describedby="public-map-note"><x-icon name="map" /> View Public Map</button>
            <p id="public-map-note" class="public-template-note">Static preview only. The public map is not connected.</p>
        </div>
        <figure class="public-map-preview" aria-labelledby="public-map-caption">
            <div class="public-map-heading"><span><x-icon name="map" /> Public map preview</span><span class="public-preview-label">Placeholder</span></div>
            <div class="public-map-canvas" aria-hidden="true">
                <svg class="public-map-art" viewBox="0 0 600 340" preserveAspectRatio="xMidYMid slice" fill="none">
                    <rect width="600" height="340" fill="#e5eef5" />
                    <path d="M0 0H402L383 53 413 100 357 151 380 213 325 268 302 340H0Z" fill="#f3f5ee" stroke="#cbd9d8" stroke-width="2" />
                    <path d="M0 97 132 112 237 67 386 73 M0 251 153 215 258 248 366 205 M100 0 128 108 177 230 149 340 M269 0 241 124 281 252 252 340" stroke="white" stroke-width="13" />
                    <path d="M0 97 132 112 237 67 386 73 M0 251 153 215 258 248 366 205 M100 0 128 108 177 230 149 340 M269 0 241 124 281 252 252 340" stroke="#d2dddd" stroke-width="2" />
                    <path d="m188 147 92-19 38 54-27 61-112-28z" fill="#0a447d" fill-opacity=".1" stroke="#35679d" stroke-width="2" stroke-dasharray="6 5" />
                </svg>
                <span class="public-map-marker public-association-marker"><x-icon name="building" /></span>
                <span class="public-map-marker public-project-marker"><x-icon name="briefcase" /></span>
                <span class="public-map-watermark">Illustrative layout</span>
            </div>
            <ul class="public-map-legend" aria-label="Map legend placeholders">
                <li><span class="public-legend-point"></span>Association Location</li>
                <li><span class="public-legend-point public-legend-project"></span>Project Location</li>
                <li><span class="public-legend-boundary"></span>Project Boundary</li>
            </ul>
            <figcaption id="public-map-caption">Illustration only. No real locations, coordinates, or project boundaries are shown.</figcaption>
        </figure>
    </div>
</section>
