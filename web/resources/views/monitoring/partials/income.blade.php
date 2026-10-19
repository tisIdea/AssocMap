@forelse($record->production as $production)
    <article class="monitoring-card">
        <h3>{{ $production->projectProduct?->product_name ?? 'Product unavailable' }}</h3>
        @if($production->income->isNotEmpty())
            <div class="table-scroll" role="region" aria-label="Income for {{ $production->projectProduct?->product_name }}" tabindex="0">
                <table class="data-table monitoring-detail-table"><caption class="sr-only">Recorded gross income in Philippine pesos</caption><thead><tr><th scope="col">Period</th><th scope="col">Reporting period</th><th scope="col">Gross Income (PHP)</th><th scope="col">Remarks</th></tr></thead><tbody>
                @foreach($production->income->sortBy('month') as $income)<tr><th scope="row">{{ ucfirst($income->period_type) }}</th><td>{{ $income->month ? DateTime::createFromFormat('!m', (string) $income->month)->format('F').' '.$record->year : 'Q'.$record->quarter?->quarter_number.' '.$record->year }}</td><td class="monitoring-money">{{ number_format((float) $income->gross_income, 2) }}</td><td class="monitoring-text">{{ filled($income->remarks) ? $income->remarks : 'Not recorded' }}</td></tr>@endforeach
                </tbody></table>
            </div>
        @else<p class="monitoring-empty">No Income entries for this product.</p>@endif
    </article>
@empty<p class="monitoring-empty">Save Production before recording Income.</p>@endforelse
