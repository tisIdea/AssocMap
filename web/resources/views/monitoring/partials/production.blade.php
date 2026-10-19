@forelse($record->production as $production)
    <article class="monitoring-card">
        <div class="monitoring-card-heading"><h3>{{ $production->projectProduct?->product_name ?? 'Product unavailable' }}</h3><span class="badge badge-neutral">{{ ucfirst($production->projectProduct?->product_type ?? 'Unavailable') }}</span></div>
        @include('monitoring.partials.details', ['details' => ['Target Output' => $production->target_output, 'Actual Output' => $production->actual_output, 'Unit' => $production->unit, 'Remarks' => $production->remarks]])
        <h4>Monthly Production</h4>
        @if($monthlyReconciled[$production->id] ?? false)<p class="field-hint">Quarterly Actual Output equals the three recorded monthly quantities. These values represent the same output and must be counted once.</p>
        @else<p class="notice">{{ $production->monthlyDetails->isEmpty() ? 'Legacy quarterly-only record.' : 'Legacy or unreconciled monthly details.' }} The recorded Actual Output is preserved. Monthly conversion requires a separate decision.</p>@endif
        @if($production->monthlyDetails->isNotEmpty())
            <div class="table-scroll" role="region" aria-label="Monthly production for {{ $production->projectProduct?->product_name }}" tabindex="0">
                <table class="data-table monitoring-detail-table"><caption class="sr-only">Monthly quantities in {{ $production->unit }}; quarterly actual output is shown above</caption><thead><tr><th scope="col">Month</th><th scope="col">Production activities</th><th scope="col">Total quantity ({{ $production->unit }})</th><th scope="col">Remarks</th></tr></thead><tbody>
                @foreach($production->monthlyDetails->sortBy('month') as $monthly)<tr><th scope="row">{{ DateTime::createFromFormat('!m', (string) $monthly->month)->format('F') }} {{ $record->year }}</th><td>{{ $monthly->activity_count }}</td><td>{{ $monthly->total_quantity }}</td><td class="monitoring-text">{{ filled($monthly->remarks) ? $monthly->remarks : 'Not recorded' }}</td></tr>@endforeach
                </tbody></table>
            </div>
        @else<p class="monitoring-empty">Monthly quantities were not recorded; no monthly values are inferred.</p>@endif
    </article>
@empty<p class="monitoring-empty">No Production entries.</p>@endforelse
