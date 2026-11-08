@if($geography)
    @php $total = collect(collect($result['sections'])->firstWhere('key', 'associations')['measures'] ?? [])->firstWhere('label', 'Distinct associations')['value'] ?? null; @endphp
    <details class="report-geography" id="report-geography" tabindex="-1">
        <summary>{{ $geography['title'] }}</summary>
        <p class="field-help">Distinct authorized associations · current snapshot · archived associations retained</p>
        @if($geography['rows'])
            <div class="report-table-scroll" role="region" tabindex="0" aria-label="{{ $geography['title'] }}">
                <table><caption>Geographic IDs determine groups. Distribution bars use the authorized association total.</caption>
                    <thead><tr><th scope="col">Municipality</th><th scope="col">Barangay</th><th scope="col">Association count</th><th scope="col">Visual distribution</th></tr></thead>
                    @foreach(collect($geography['rows'])->groupBy('municipality_id') as $municipalityId => $rows)
                        <tbody data-municipality-id="{{ $municipalityId }}">
                            <tr class="report-geography-subtotal"><th scope="rowgroup" colspan="2">{{ $rows->first()['municipality'] }}</th><td class="report-numeric">{{ number_format($rows->first()['municipality_total']) }}</td><td>Municipality subtotal</td></tr>
                            @foreach($rows as $row)
                                <tr data-barangay-id="{{ $row['barangay_id'] }}"><td class="report-geography-parent">{{ $row['municipality'] }}</td><th scope="row">{{ $row['barangay'] }}</th><td class="report-numeric">{{ number_format($row['count']) }}</td><td><span class="report-geography-bar" aria-hidden="true" style="width: {{ $total ? $row['count'] / $total * 100 : 0 }}%"></span></td></tr>
                            @endforeach
                        </tbody>
                    @endforeach
                    <tfoot><tr><th scope="row" colspan="2">Authorized association total</th><td class="report-numeric">{{ $total === null ? 'Unobserved' : number_format($total) }}</td><td>All recorded barangays</td></tr></tfoot>
                </table>
            </div>
        @else<p class="report-empty">No recorded associations in this scope.</p>@endif
    </details>
@endif
