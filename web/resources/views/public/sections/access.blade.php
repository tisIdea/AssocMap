<section
    id="access"
    class="public-section public-access-section"
    aria-labelledby="public-access-title"
>
    <div class="public-container">
        <div class="public-access-panel">

            <div class="public-section-heading">
                <p class="public-eyebrow">Get started</p>

                <h2 id="public-access-title">
                    Access AssocMap
                </h2>

                <p>
                    One staff sign-in for System Administrators and Field Officers.
                    A separate public map is available for published program locations.
                </p>
            </div>

            <div>
                <div class="public-actions">

                    <a
                        href="{{ route('login') }}"
                        class="public-button public-button-primary"
                    >
                        Staff Sign In
                        <x-icon name="arrow" />
                    </a>

                    <a
                        href="{{ route('public.gis') }}"
                        class="public-button public-button-outline"
                    >
                        <x-icon name="map" />
                        View Public Map
                    </a>

                </div>

                <p
                    id="public-access-note"
                    class="public-template-note"
                >
                    Staff access requires an authorized AssocMap account.
                    The public map displays only published information.
                </p>
            </div>

        </div>
    </div>
</section>