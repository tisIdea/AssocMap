<section id="features" class="public-section public-features" aria-labelledby="public-features-title">
    <div class="public-container">
        <div class="public-section-label" data-public-reveal><span>02 / The capabilities</span><span>Connected by design</span></div>
        <div class="public-features-layout">
            <div class="public-feature-intro public-section-heading" data-public-reveal>
                <span class="public-feature-intro-icon"><x-icon name="layers" /></span>
                <h2 id="public-features-title">Less scattered.<br>More connected.</h2>
                <p>One system for the different parts of program management, from community records to a view of progress.</p>
                <div class="public-feature-access"><x-icon name="shield" /><p>Management tools require an authorized account. Public visitors can explore eligible published locations.</p></div>
                <a href="{{ route('login') }}" class="public-text-link">Sign in to your workspace <x-icon name="arrow" /></a>
            </div>
            <div class="public-feature-list">
                @foreach ([
                    ['01', 'users', 'Associations & members', 'Keep association profiles and member records organized within authorized workspaces.'],
                    ['02', 'briefcase', 'Livelihood projects', 'Manage project information and follow the livelihood initiatives connected to each association.'],
                    ['03', 'graduation', 'Training & activities', 'Record trainings and program activities alongside the communities they support.'],
                    ['04', 'clipboard-check', 'Monitoring & accomplishments', 'Review monitoring records, production, income, and program accomplishments.'],
                    ['05', 'map', 'GIS mapping', 'Explore project locations and boundaries. Public access is limited to eligible published information.'],
                    ['06', 'chart', 'Reports & analytics', 'Turn recorded program information into summaries, reports, and analytical views.'],
                ] as [$number, $icon, $title, $description])
                    <article class="public-feature-row" data-public-reveal>
                        <span class="public-feature-number">{{ $number }}</span>
                        <div><h3>{{ $title }}</h3><p>{{ $description }}</p></div>
                        <x-icon :name="$icon" />
                    </article>
                @endforeach
            </div>
        </div>
    </div>
</section>
