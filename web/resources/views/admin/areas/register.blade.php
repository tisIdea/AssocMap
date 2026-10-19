@extends('layouts.admin')

@section('content')
<section class="area-management" data-workspace-module="areas" data-workspace-title="Area Management">
    <header class="card area-page-heading"><div><p class="eyebrow">Geographic records</p><h1 tabindex="-1">Area Management</h1><p>Manage cities, municipalities, and barangays using their existing geographic references.</p></div><button class="button button-primary" type="button" data-area-add><x-icon name="plus" />Add Area</button></header>
    @php
        $cards = [
            ['key' => 'municipalities', 'label' => 'Total Cities / Municipalities', 'icon' => 'pin', 'tab' => 'municipalities', 'status' => ''],
            ['key' => 'associations', 'label' => 'Current Associations', 'icon' => 'building'],
            ['key' => 'current_barangays', 'label' => 'Current Barangays', 'icon' => 'map-pinned', 'tab' => 'barangays', 'status' => 'active'],
            ['key' => 'inactive_barangays', 'label' => 'Inactive Barangays', 'icon' => 'archive', 'tab' => 'barangays', 'status' => 'inactive'],
        ];
    @endphp
    <nav class="area-summary-grid" aria-label="Geographic record groups">
        @foreach($cards as $card)
            @php
                $navigation = $card['key'] === 'associations';
                $selected = !$navigation && $filters['tab'] === $card['tab'] && $filters['status'] === $card['status']
                    && !$filters['municipality_id'] && !$filters['source'] && !$filters['type'];
                $href = $navigation ? route('admin.associations.index').'#associations'
                    : route('admin.areas.index', ['tab' => $card['tab'], 'status' => $card['status'], 'q' => $filters['q'], 'sort' => $filters['sort']]);
            @endphp
            <a class="area-summary-card" href="{{ $href }}" data-area-summary="{{ $card['key'] }}" @if($selected) aria-current="page" @endif>
                <x-stat-card :label="$card['label']" :value="number_format($summary[$card['key']])" :icon="$card['icon']" :animate="false" :note="$navigation ? 'Open Association Management' : 'View matching records'" />
            </a>
        @endforeach
    </nav>
    <p class="area-help">Totals include all cities and municipalities. Current barangays are active; inactive records remain readable. Current associations include active and inactive associations that have not been archived.</p>
    <section class="card area-directory" aria-labelledby="area-directory-title">
        <div class="area-section-heading"><div><h2 id="area-directory-title">Geographic directory</h2><p>{{ number_format($records->total()) }} matching record(s)</p></div><p>Record specific addresses from a barangay's detail page.</p></div>
        <nav class="area-tabs" aria-label="Geographic record type">
            @foreach(['municipalities' => 'Cities / Municipalities', 'barangays' => 'Barangays'] as $tab => $label)
                <a href="{{ route('admin.areas.index', ['tab' => $tab, 'q' => $filters['q'], 'sort' => $filters['sort']]) }}" @if($filters['tab'] === $tab) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
        <form class="area-filter-form" method="GET" action="{{ route('admin.areas.index') }}" data-feedback-error-bag="default">
            <input type="hidden" name="tab" value="{{ $filters['tab'] }}">
            @if($errors->any() && old('area_form') !== 'editor')<div class="notice notice-error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            <div class="area-filter-grid">
                <div class="field"><label for="geography-search">Search</label><input id="geography-search" type="search" name="q" value="{{ $filters['q'] }}" maxlength="120" placeholder="Name, PSGC code, or location"></div>
                <div class="field"><label for="geography-parent">City / Municipality</label><select id="geography-parent" name="municipality_id"><option value="">All cities / municipalities</option>@foreach($municipalityChoices as $choice)<option value="{{ $choice->id }}" @selected($filters['municipality_id'] === $choice->id)>{{ $choice->name }}{{ $choice->is_active ? '' : ' (Inactive)' }}</option>@endforeach</select></div>
                <div class="field"><label for="geography-status">Status</label><select id="geography-status" name="status"><option value="">All statuses</option><option value="active" @selected($filters['status'] === 'active')>Current (Active)</option><option value="inactive" @selected($filters['status'] === 'inactive')>Inactive</option></select></div>
                <div class="field"><label for="geography-source">Source</label><select id="geography-source" name="source"><option value="">All sources</option>@foreach(['psgc' => 'PSGC', 'manual' => 'Manual'] as $value => $label)<option value="{{ $value }}" @selected($filters['source'] === $value)>{{ $label }}</option>@endforeach</select></div>
                @if($filters['tab'] === 'municipalities')<div class="field"><label for="geography-type">Locality type</label><select id="geography-type" name="type"><option value="">Cities and municipalities</option><option value="city" @selected($filters['type'] === 'city')>City</option><option value="municipality" @selected($filters['type'] === 'municipality')>Municipality</option></select></div>@endif
                <div class="field"><label for="geography-sort">Sort by</label><select id="geography-sort" name="sort">@foreach(['az' => 'Name A to Z', 'za' => 'Name Z to A', 'newest' => 'Newest first', 'oldest' => 'Oldest first', 'municipality' => 'City / Municipality', 'status' => 'Status'] as $value => $label)<option value="{{ $value }}" @selected($filters['sort'] === $value)>{{ $label }}</option>@endforeach</select></div>
            </div>
            <div class="area-filter-actions"><button class="button button-primary" type="submit"><x-icon name="filter" />Apply filters</button><a class="button" href="{{ route('admin.areas.index', ['tab' => $filters['tab']]) }}">Reset</a></div>
        </form>
        <div class="table-scroll" role="region" aria-label="Geographic records" tabindex="0">
            <table class="data-table area-record-table"><caption class="sr-only">{{ $filters['tab'] === 'barangays' ? 'Barangays' : 'Cities and municipalities' }}. Connected association counts include all statuses and archived history.</caption>
                <thead><tr><th scope="col">{{ $filters['tab'] === 'barangays' ? 'Barangay' : 'City / Municipality' }}</th><th scope="col">{{ $filters['tab'] === 'barangays' ? 'City / Municipality' : 'Barangays' }}</th><th scope="col">Associations</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead>
                <tbody>
                @forelse($records as $record)
                    @php($showUrl = route($filters['tab'] === 'barangays' ? 'admin.barangays.show' : 'admin.municipalities.show', ['municipality' => $filters['tab'] === 'municipalities' ? $record->id : null, 'barangay' => $filters['tab'] === 'barangays' ? $record->id : null] + $filters + ['page' => $records->currentPage()]))
                    <tr>
                        <th scope="row"><a href="{{ $showUrl }}">{{ $record->name }}</a><small>{{ $filters['tab'] === 'barangays' ? ($record->psgc_code ?? 'No PSGC code recorded') : ucfirst($record->locality_type) }}</small></th>
                        <td>@if($filters['tab'] === 'barangays')<a href="{{ route('admin.municipalities.show', $record->municipality_id) }}">{{ $record->municipality?->name ?? 'Parent unavailable' }}</a>@else{{ number_format($record->barangays_count) }}@endif</td>
                        <td>{{ number_format($record->associations_count) }}</td><td><x-status-badge :value="$record->is_active ? 'Active' : 'Inactive'" /></td>
                        <td>@include('admin.areas.partials.actions', ['editorRecord' => $record->editor_record, 'viewUrl' => $showUrl])</td>
                    </tr>
                @empty<tr><td colspan="5"><x-empty-state title="No geographic records found" message="Try another search or reset the filters." /></td></tr>@endforelse
                </tbody>
            </table>
        </div>
        @include('admin.areas.partials.pagination', ['paginator' => $records, 'label' => 'Geographic record pages'])
        <p class="area-table-note">Connected counts include inactive geographic records and active, inactive, and archived associations. Deactivation preserves existing relationships.</p>
    </section>
</section>
@endsection
