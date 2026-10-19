@include('partials.page-header', ['title' => $module['title'], 'description' => $module['description'], 'eyebrow' => in_array($module['key'], ['projects', 'trainings', 'monitoring', 'program-activities']) ? 'Program Operations' : 'Administration'])
@if($module['readonly'] ?? false)<div class="notice"><x-icon name="shield" /><span>{{ $module['key'] === 'members' ? 'Member records are view-only. Profile changes and membership lifecycle actions are managed by assigned Field Officers.' : 'Read-only activity history. Audit events cannot be edited or deleted.' }}</span></div>@endif
@if($module['key'] === 'monitoring')<div class="notice"><x-icon name="info" /><span>Monitoring tracks project performance. Production, gross income, and materials are recorded in one monitoring record.</span></div>@endif
<section class="card list-card" data-list="{{ $module['key'] }}" @if($module['key'] === 'associations') data-association-index-url="{{ route('admin.associations.index') }}" data-association-status-filter="{{ $module['status_filter'] ?? '' }}" @elseif($module['key'] === 'members') data-member-index-url="{{ route('admin.members.index') }}" data-member-status-filter="{{ $module['status_filter'] ?? '' }}" @endif>
    @if($module['key'] === 'areas')
        <div class="area-intro"><div><h2>Geographic reference directory</h2><p>Municipalities, cities, and barangays.</p></div><div class="area-selector"><label for="area-municipality-search">Search or select municipality</label><input type="search" id="area-municipality-search" list="area-municipalities" placeholder="All municipalities" data-area-municipality><datalist id="area-municipalities">@foreach($catalog['options']['municipality'] ?? [] as $municipality)<option value="{{ $municipality }}">@endforeach</datalist></div></div>
    @endif
    <div class="table-toolbar">
        @if($module['key'] === 'areas')
            <div class="area-list-switch" role="group" aria-label="Area list type">
                <button type="button" class="button" data-area-list-type="Municipality" aria-pressed="true">Municipality</button>
                <button type="button" class="button" data-area-list-type="Barangay" aria-pressed="false">Barangay</button>
                <button type="button" class="button" data-area-list-type="Specific Area" aria-pressed="false">Specific Area</button>
            </div>
        @endif
        <x-search-box :id="$module['key'].'-search'" :placeholder="$module['key'] === 'areas' ? 'Search municipality or barangay…' : 'Search '.strtolower($module['title']).'…'" />
        <button class="button" type="button" data-disclosure="{{ $module['key'] }}-filters" aria-controls="{{ $module['key'] }}-filters" aria-expanded="false"><x-icon name="filter" /> Filters <span class="filter-count" data-filter-count hidden></span></button>
        <x-sort-dropdown :id="$module['key'].'-sort'" :audit="$module['key'] === 'audit-logs'" :project="$module['key'] === 'projects'" :area="$module['key'] === 'areas'" :association="$module['key'] === 'associations'" :member="$module['key'] === 'members'" />
        @if($module['key'] === 'areas')
            <button class="button button-primary" type="button" data-area-add><x-icon name="plus" />Add Area</button>
        @elseif($module['key'] === 'associations')
            @if(isset($catalog['options']['area_records']))
                <button class="button button-primary" type="button" data-association-add data-dialog="association-editor"><x-icon name="plus" />Add Association</button>
            @else
                <a class="button button-primary" href="{{ route('admin.associations.index') }}"><x-icon name="plus" />Add Association</a>
            @endif
        @elseif(isset($module['action']) && $module['key'] !== 'members')
            <a class="button button-primary" href="#{{ $module['key'] }}/create"><x-icon name="plus" />{{ $module['action'] }}</a>
        @endif
    </div>
    @php
        $filterFields = $module['filters'];
        if (in_array($module['key'], ['associations', 'members'])) {
            foreach ($filterFields as $index => $field) {
                if ($field['name'] === 'status') {
                    $filterFields[$index]['choices'] = [
                        ['value' => 'active', 'label' => 'Active'],
                        ['value' => 'inactive', 'label' => 'Inactive'],
                        ['value' => 'archived', 'label' => 'Archived'],
                    ];
                    continue;
                }
                $filterFields[$index]['choices'] = collect($module['rows'] ?? [])
                    ->pluck($field['name'])->filter()->unique()->sort()->values()->all();
            }
        }
        if ($module['key'] === 'areas') {
            $filterFields[] = ['name' => 'barangay', 'label' => 'Barangay', 'type' => 'select', 'choices' => $catalog['options']['barangay_filter_records'] ?? [], 'required' => false];
            foreach ($filterFields as $index => $field) {
                if ($field['name'] === 'municipality') {
                    $filterFields[$index]['choices'] = $catalog['options']['municipality'] ?? ($field['choices'] ?? []);
                }
            }
        }
    @endphp
    <x-filter-panel :id="$module['key'].'-filters'" :fields="$filterFields" :interactive="in_array($module['key'], ['areas', 'associations', 'members'])" />
    <x-data-table :module="$module" :rows="$module['rows'] ?? []" />
    <x-pagination :count="count($module['rows'] ?? [])" />
</section>
