@php
    $activeFilterCount = (int) ($filters['q'] !== '') + (int) ($filters['association'] !== '')
        + (int) ($filters['year'] !== '') + (int) ($filters['status'] !== '') + (int) ($filters['sort'] !== 'year');
@endphp
<form method="GET" action="{{ route($prefix.'.'.$module.'.index') }}" class="program-register-filters" aria-label="Search and filter {{ strtolower($moduleLabel) }}">
    <div class="program-search-bar">
        <div class="field program-search"><label for="program-search">Search {{ strtolower($moduleLabel) }}</label><div><x-icon name="search" /><input id="program-search" type="search" name="q" maxlength="120" value="{{ $filters['q'] }}" placeholder="{{ $searchPlaceholder ?? 'Search by title or association' }}"></div></div>
        <button class="button button-primary" type="submit">Search</button>
        <button class="button" type="button" data-disclosure="program-extra-filters" aria-controls="program-extra-filters" aria-expanded="false"><x-icon name="filter" />Filters<x-icon name="chevron-down" /></button>
        <a class="button" href="{{ route($prefix.'.'.$module.'.index') }}">Reset</a>
        <span class="program-filter-indicator" role="status">{{ $activeFilterCount ? $activeFilterCount.' active filter(s)' : 'Default view' }}</span>
    </div>
    <div id="program-extra-filters" class="program-filter-panel" hidden>
        <div class="program-filter-grid">
            <div class="field"><label for="program-status">Status</label><select id="program-status" name="status"><option value="">All current {{ strtolower($moduleLabel) }}</option>@foreach($statuses + ['archived' => 'Archived records'] as $value => $label)<option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>@endforeach</select></div>
            <div class="field"><label for="program-association">Association name</label><input id="program-association" name="association" value="{{ $filters['association'] }}" maxlength="150" placeholder="All authorized associations"></div>
            <div class="field"><label for="program-year">Program year</label><input id="program-year" type="number" name="year" min="2000" max="2100" value="{{ $filters['year'] }}" placeholder="All years"></div>
            <div class="field"><label for="program-sort">Sort by</label><select id="program-sort" name="sort">@foreach($sortLabels ?? ['year' => 'Program year: newest first', 'az' => 'Title: A–Z', 'za' => 'Title: Z–A', 'newest' => 'Start date: newest first', 'oldest' => 'Start date: oldest first'] as $value => $label)<option value="{{ $value }}" @selected($filters['sort'] === $value)>{{ $label }}</option>@endforeach</select></div>
        </div>
        <div class="program-filter-actions"><button class="button button-primary" type="submit">Apply Filters</button><a class="button" href="{{ route($prefix.'.'.$module.'.index') }}">Reset All</a></div>
    </div>
    <noscript><style>.program-management #program-extra-filters[hidden] { display: block; }</style></noscript>
</form>
