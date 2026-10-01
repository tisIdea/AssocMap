@include('partials.page-header', ['title' => $module['title'], 'description' => $module['description'], 'eyebrow' => in_array($module['key'], ['projects', 'trainings', 'monitoring', 'program-activities']) ? 'Program Operations' : 'Administration'])
@if($module['readonly'] ?? false)<div class="notice"><x-icon name="shield" /><span>{{ $module['key'] === 'members' ? 'Read-only access. Official membership and application decisions are managed by Field Officers.' : 'Read-only activity history. Audit events cannot be edited or deleted.' }}</span></div>@endif
@if($module['key'] === 'monitoring')<div class="notice"><x-icon name="info" /><span>Monitoring tracks project performance. Production, gross income, and materials are recorded in one monitoring record.</span></div>@endif
<section class="card list-card" data-list="{{ $module['key'] }}">
    @if($module['key'] === 'areas')
        <div class="area-intro"><div><h2>Geographic reference directory</h2><p>Municipalities and barangays · official geographic data will be pre-seeded.</p></div><div class="area-selector"><label for="area-municipality-search">Search or select municipality</label><input type="search" id="area-municipality-search" list="area-municipalities" placeholder="All municipalities" data-area-municipality><datalist id="area-municipalities">@foreach($catalog['options']['municipality'] ?? [] as $municipality)<option value="{{ $municipality }}">@endforeach</datalist></div></div>
    @endif
    <div class="table-toolbar">
        <x-search-box :id="$module['key'].'-search'" :placeholder="$module['key'] === 'areas' ? 'Search municipality or barangay…' : 'Search '.strtolower($module['title']).'…'" />
        <button class="button" type="button" data-disclosure="{{ $module['key'] }}-filters" aria-controls="{{ $module['key'] }}-filters" aria-expanded="false"><x-icon name="filter" /> Filters <span class="filter-count" data-filter-count hidden></span></button>
        <x-sort-dropdown :id="$module['key'].'-sort'" :audit="$module['key'] === 'audit-logs'" :project="$module['key'] === 'projects'" />
        @if($module['key'] === 'areas')
            <button class="button button-primary" type="button" data-area-add><x-icon name="plus" />Add Area</button>
        @elseif(isset($module['action']))
            <a class="button button-primary" href="#{{ $module['key'] }}/create"><x-icon name="plus" />{{ $module['action'] }}</a>
        @endif
    </div>
    @php
        $filterFields = $module['filters'];
        if ($module['key'] === 'areas') {
            foreach ($filterFields as $index => $field) {
                if ($field['name'] === 'municipality') {
                    $filterFields[$index]['choices'] = $catalog['options']['municipality'] ?? ($field['choices'] ?? []);
                }
            }
        }
    @endphp
    <x-filter-panel :id="$module['key'].'-filters'" :fields="$filterFields" />
    <x-data-table :module="$module" :rows="$module['rows'] ?? []" />
    <x-pagination :count="count($module['rows'] ?? [])" />
</section>
