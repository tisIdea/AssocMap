@php
    $heroImage = collect(['webp', 'jpg', 'avif'])
        ->map(fn ($extension) => 'images/public/landing-hero.'.$extension)
        ->first(fn ($path) => is_file(public_path($path)));
@endphp
<section id="home" class="public-hero" aria-labelledby="public-hero-title">
    @if ($heroImage)
        <img class="public-hero-photo" src="{{ asset($heroImage) }}"
            @if ($heroImage === 'images/public/landing-hero.webp')
                srcset="{{ asset('images/public/landing-hero-mobile.webp') }} 960w, {{ asset($heroImage) }} 2400w"
                sizes="100vw"
            @endif
            alt="" width="2400" height="1600" fetchpriority="high">
    @endif
    <div class="public-hero-overlay" aria-hidden="true"></div>
    <div class="public-container public-hero-content">
        <div class="public-hero-topline public-hero-enter"><span class="public-status-dot" aria-hidden="true"></span> Community livelihood. A connected perspective.</div>
        <div class="public-hero-copy">
            <p class="public-eyebrow public-hero-enter">Welcome to AssocMap</p>
            <h1 id="public-hero-title" class="public-hero-enter">Mapping communities.<br>Monitoring <em>progress.</em></h1>
            <p class="public-hero-description public-hero-enter">Connecting livelihood associations, projects, and geographic information for a clearer view of program progress.</p>
            <div class="public-actions public-hero-enter">
                <a href="{{ route('public.gis') }}" class="public-button public-button-light">Explore GIS Map <x-icon name="arrow" /></a>
                <a href="#about" class="public-button public-button-ghost">Discover AssocMap <x-icon name="chevron-down" /></a>
            </div>
        </div>
        <div class="public-hero-bottom">
            <a href="#about" class="public-scroll-link"><span class="public-scroll-icon"><x-icon name="chevron-down" /></span> Explore the bigger picture</a>
            <div class="public-hero-meta">
                <p class="public-hero-credit">Photo by <a href="https://unsplash.com/@hectorjp" target="_blank" rel="noopener noreferrer">Hector John Periquin<span class="sr-only"> (opens in a new tab)</span></a> on <a href="https://unsplash.com/photos/man-riding-row-boat-on-sea-HbDEhWpouM8" target="_blank" rel="noopener noreferrer">Unsplash<span class="sr-only"> (opens in a new tab)</span></a></p>
                <p>Community livelihood information <span>&middot; SAAD Phase II program context</span></p>
            </div>
        </div>
    </div>
</section>
<div class="public-intro-band">
    <div class="public-container public-intro-content">
        <span>People at the center.<br><strong>Information that connects.</strong></span>
        <p>From association records to geographic context, bring the pieces of community livelihood monitoring together.</p>
        <a href="#features" class="public-text-link">Explore the system <x-icon name="arrow" /></a>
    </div>
</div>
