@php
    $defaultStatus = $officerDirectory ? 'all' : '';
    $advancedCount = (int) ($filters['status'] !== $defaultStatus) + (int) ($filters['sort'] !== 'az')
        + (int) filled($filters['municipality']) + (int) filled($filters['barangay']) + (int) filled($filters['field_officer']);
    $filterOpen = $advancedCount > 0 || $errors->any();
@endphp
<form method="GET" action="{{ route($prefix.'.'.($officerDirectory ? 'association-officers.index' : 'associations.index')) }}" class="association-filter-form" data-feedback-error-bag="default">
    <div class="association-search-bar">
        <div class="field association-search"><label for="association-search">Search associations</label><div><x-icon name="search" /><input id="association-search" type="search" name="q" maxlength="120" value="{{ $filters['q'] }}" placeholder="{{ $officerDirectory ? 'Search association name...' : 'Search name, acronym, or recorded location...' }}"></div></div>
        <button class="button button-primary" type="submit">Search</button>
        <button class="button" type="button" data-disclosure="association-filter-panel" aria-controls="association-filter-panel" aria-expanded="{{ $filterOpen ? 'true' : 'false' }}"><x-icon name="filter" />Filters @if($advancedCount)<span class="association-filter-count">{{ $advancedCount }} active</span>@endif</button>
    </div>
    <div id="association-filter-panel" class="association-filter-panel" @if(!$filterOpen) hidden @endif>
        @if($errors->any() && old('association_form') !== 'editor')<div class="notice notice-error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <div class="association-filter-grid">
            <div class="field"><label for="association-status">Status</label><select id="association-status" name="status">@foreach(['' => 'Current (not archived)', 'all' => 'All, including archived', 'active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived'] as $value => $label)<option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>@endforeach</select></div>
            @if(!$officerDirectory)
                @foreach(['municipality' => 'City / Municipality', 'barangay' => 'Barangay', 'field_officer' => 'Primary Field Officer'] as $field => $label)
                    @if($field !== 'field_officer' || $prefix === 'admin')
                        <div class="field"><label for="association-{{ $field }}">{{ $label }}</label><select id="association-{{ $field }}" name="{{ $field }}"><option value="">All permitted {{ strtolower($label) }} records</option>@if($filters[$field] && !in_array($filters[$field], $choices[$field] ?? [], true))<option value="{{ $filters[$field] }}" selected>{{ $filters[$field] }} (no matching records)</option>@endif @foreach($choices[$field] ?? [] as $choice)<option value="{{ $choice }}" @selected($filters[$field] === $choice)>{{ $choice }}</option>@endforeach</select></div>
                    @endif
                @endforeach
            @endif
            <div class="field"><label for="association-sort">Sort by</label><select id="association-sort" name="sort">@foreach(['az' => 'Name A to Z', 'za' => 'Name Z to A', 'newest' => $officerDirectory ? 'Newest created' : 'Newest SAAD Phase II joining', 'oldest' => $officerDirectory ? 'Oldest created' : 'Oldest SAAD Phase II joining', 'municipality' => 'City / Municipality', 'barangay' => 'Barangay', 'field_officer' => 'Primary Field Officer', 'status' => 'Status'] as $value => $label)@if(!$officerDirectory || !in_array($value, ['municipality', 'barangay', 'field_officer']))<option value="{{ $value }}" @selected($filters['sort'] === $value)>{{ $label }}</option>@endif @endforeach</select></div>
        </div>
        <div class="association-filter-actions"><button class="button button-primary" type="submit">Apply Filters</button><a class="button" href="{{ route($prefix.'.'.($officerDirectory ? 'association-officers.index' : 'associations.index')) }}">Reset</a></div>
    </div>
</form>
