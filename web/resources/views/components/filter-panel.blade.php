@props(['id', 'fields', 'interactive' => false])
<form id="{{ $id }}" class="filter-panel" data-filter-form data-form onsubmit="return false;" hidden>
    <div class="panel-heading"><h3>Filter records</h3>@unless($interactive)<span class="muted">Filtering is not connected yet</span>@endunless</div>
    <div class="filter-grid">@foreach($fields as $field)<x-field :field="$field" :prefix="$id" :filter="true" />@endforeach</div>
    <div class="filter-actions"><button type="reset" class="button button-quiet">Clear Filters</button><button type="button" @disabled(!$interactive) class="button button-primary" data-filter-apply>Apply Filters</button></div>
</form>
