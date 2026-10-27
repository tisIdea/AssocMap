@forelse($record->materials as $material)
    <article class="monitoring-card">
        <div class="monitoring-card-heading"><h3>{{ $material->projectMaterial?->item_name ?? 'Material unavailable' }}</h3><span class="badge badge-neutral">{{ $material->condition }}</span></div>
        @include('monitoring.partials.details', ['details' => [
            'Condition' => $material->condition,
            'Scheduled Maintenance' => $material->scheduled_maintenance?->format('M d, Y'),
            'Actual Maintenance' => $material->actual_maintenance?->format('M d, Y'),
            'Remarks' => $material->remarks,
        ]])
        <details class="monitoring-resource-details"><summary>Registered project material</summary><p class="field-hint">Current project inventory values are shown for reference.</p>
            @include('monitoring.partials.details', ['details' => ['Planned Quantity' => $material->projectMaterial?->quantity_planned, 'Delivered Quantity' => $material->projectMaterial?->quantity_delivered, 'Unit' => $material->projectMaterial?->unit]])
        </details>
        @include('monitoring.partials.photos', ['photoKind' => 'materials', 'photoParent' => $material])
    </article>
@empty<p class="monitoring-empty">No Materials observations.</p>@endforelse
