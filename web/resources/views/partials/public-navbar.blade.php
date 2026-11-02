<header class="public-header" data-public-navbar>
    <div class="public-container public-navbar">
        <a href="{{ route('home') }}#home" class="public-brand" aria-label="AssocMap home">
            <span class="public-brand-mark"><x-assocmap-mark /></span>
            <span><strong>Assoc<span>Map</span></strong><small>Association and project monitoring</small></span>
        </a>
        <button type="button" class="public-menu-toggle" data-public-menu-toggle aria-controls="public-navigation" aria-expanded="false" hidden>
            <x-icon name="menu" /> <span>Menu</span>
        </button>
        <nav id="public-navigation" class="public-navigation" aria-label="Public navigation">
            <a href="{{ route('home') }}#home">Home</a>
            <a href="{{ route('home') }}#about">About</a>
            <a href="{{ route('home') }}#public-gis">Public GIS</a>
            <a href="{{ route('home') }}#access" class="public-nav-access">Staff Sign In <x-icon name="arrow" /></a>
        </nav>
    </div>
</header>
