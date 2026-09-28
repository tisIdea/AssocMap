@props(['id', 'fields'])
<form id="{{ $id }}" class="filter-panel" data-filter-form hidden>
    <div class="panel-heading"><h3>Filter records</h3><span class="muted">Refine the preview below</span></div>
    <div class="filter-grid">@foreach($fields as $field)<x-field :field="$field" :prefix="$id" :filter="true" />@endforeach</div>
    <div class="filter-actions"><button type="reset" class="button button-quiet">Clear Filters</button><button type="submit" class="button button-primary">Apply Filters</button></div>
</form>
