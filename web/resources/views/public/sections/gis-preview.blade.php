<section id="public-gis" class="public-section public-gis-section" aria-labelledby="public-gis-title">
    <div class="public-container">
        <div class="public-section-label" data-public-reveal><span>03 / The geographic view</span><span>Put the work in perspective</span></div>
        <div class="public-gis-grid">
            <div class="public-section-heading" data-public-reveal>
                <p class="public-eyebrow">A place. A project. A bigger picture.</p>
                <h2 id="public-gis-title">Every location<br>adds <span>context.</span></h2>
                <p>See where livelihood projects take place. Explore eligible published project locations and boundaries on the public Street Map, with no account required.</p>
                <a href="{{ route('public.gis') }}" class="public-button public-button-primary">Open the public map <x-icon name="arrow" /></a>
                <p class="public-gis-privacy"><x-icon name="shield" /> Only eligible published information is visible. Internal records stay within authorized workspaces.</p>
            </div>
            <figure class="public-map-preview" aria-labelledby="public-map-caption" data-public-reveal>
                <div class="public-map-heading"><span><x-icon name="map" /> AssocMap / Geographic view</span><span class="public-preview-label">Illustration</span></div>
                <div class="public-map-canvas" aria-hidden="true">
                    <svg class="public-map-art" viewBox="0 0 640 440" fill="none" preserveAspectRatio="xMidYMid slice">
                        <defs><pattern id="public-map-grid" width="48" height="48" patternUnits="userSpaceOnUse"><path d="M48 0H0V48" stroke="#adc7da" stroke-opacity=".32" /></pattern></defs>
                        <path fill="#dfeaf2" d="M0 0h640v440H0z" />
                        <path d="M0 0h405l-23 44 26 46-40 49 15 49-56 66 15 54-63 56-19 76H0z" fill="#edf2ec" stroke="#bfd1d0" stroke-width="2" />
                        <path d="M0 92l138 38 94-48 160 2M0 305l115-29 113 28 112-35M94 0l43 129 28 140-30 171M279 0l-48 137 31 176-19 127" stroke="#fff" stroke-width="15" />
                        <path d="M0 92l138 38 94-48 160 2M0 305l115-29 113 28 112-35M94 0l43 129 28 140-30 171M279 0l-48 137 31 176-19 127" stroke="#cedbdb" stroke-width="2" />
                        <path d="m185 174 91-17 45 62-40 49-95-20z" fill="#35679d" fill-opacity=".08" stroke="#35679d" stroke-width="2" stroke-dasharray="7 6" />
                        <path fill="url(#public-map-grid)" d="M0 0h640v440H0z" />
                    </svg>
                    <span class="public-map-north">N<x-icon name="arrow" /></span>
                    <span class="public-map-marker"><x-icon name="pin" /></span>
                    <span class="public-map-watermark">Illustrative geography<br>No actual project data</span>
                    <div class="public-map-caption-panel"><x-icon name="layers" /><div><strong>From records to geographic context.</strong><span>Discover the public view of program locations.</span></div></div>
                </div>
                <figcaption id="public-map-caption">Illustration only. Open the public map for published locations and boundaries.</figcaption>
            </figure>
        </div>
    </div>
</section>
