@php
    $defaultSort = $applicationRegister ? 'newest' : 'az';
    $defaultStatus = str_starts_with($indexRoute, 'field-officer.') ? ($applicationRegister ? 'pending' : 'active') : '';
    $activeFilters = (int) ($filters['q'] !== '') + (int) ($filters['association'] !== '')
        + (int) ($filters['sort'] !== $defaultSort) + (int) ($filters['status'] !== $defaultStatus);
@endphp
<form method="GET" action="{{ route($indexRoute) }}" class="member-register-filters" aria-label="{{ $applicationRegister ? 'Search and filter applications' : 'Search and filter members' }}">
    <div class="member-search-bar">
        <div class="field member-search">
            <label for="member-register-search">{{ $applicationRegister ? 'Search applicants' : 'Search members' }}</label>
            <div><x-icon name="search" /><input id="member-register-search" name="q" type="search" maxlength="120" value="{{ $filters['q'] }}" placeholder="Search by name or FishR registration number"></div>
        </div>
        <button class="button button-primary" type="submit">Search</button>
        <a class="button" href="{{ route($indexRoute) }}">Reset</a>
        <span class="member-filter-indicator" role="status">{{ $activeFilters ? $activeFilters.' active filter(s)' : 'Default view' }}</span>
    </div>
    <details class="member-filter-disclosure" @if($activeFilters) open @endif>
        <summary class="button"><x-icon name="filter" />Filters &amp; sorting<x-icon name="chevron-down" /></summary>
        <div class="member-filter-panel">
            <div class="member-filter-grid">
                <div class="field"><label for="member-register-status">Status</label><select id="member-register-status" name="status">
                    @foreach($cards as [$label, $value])<option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>@endforeach
                </select></div>
                @if(isset($associations))
                    <div class="field"><label for="member-register-association">Association</label><select id="member-register-association" name="association">
                        <option value="">{{ str_starts_with($indexRoute, 'field-officer.') ? 'All assigned associations' : 'All associations' }}</option>
                        @foreach($associations as $choice)<option value="{{ $choice->id }}" @selected($filters['association'] === $choice->id)>{{ $choice->name }}{{ $choice->archived_at ? ' (archived)' : ($choice->status !== 'active' ? ' (inactive)' : '') }}</option>@endforeach
                    </select></div>
                @endif
                <div class="field"><label for="member-register-sort">Sort by</label><select id="member-register-sort" name="sort">
                    @foreach(['az' => 'Name: A–Z', 'za' => 'Name: Z–A', 'newest' => ($applicationRegister ? 'Submitted: newest first' : 'Registered: newest first'), 'oldest' => ($applicationRegister ? 'Submitted: oldest first' : 'Registered: oldest first')] as $value => $label)<option value="{{ $value }}" @selected($filters['sort'] === $value)>{{ $label }}</option>@endforeach
                </select></div>
            </div>
            <div class="member-filter-actions"><button class="button button-primary" type="submit">Apply Filters</button><a class="button" href="{{ route($indexRoute) }}">Reset All</a></div>
        </div>
    </details>
</form>
