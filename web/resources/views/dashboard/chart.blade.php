<section class="card dashboard-panel" aria-labelledby="dashboard-chart-{{ $chart['key'] }}">
    <h2 id="dashboard-chart-{{ $chart['key'] }}">{{ $chart['title'] }}</h2>
    <p class="dashboard-help">{{ $chart['note'] }}</p>
    @if(array_sum(array_column($chart['rows'], 'value')) > 0)
        @php $maximum = max(1, ...array_column($chart['rows'], 'value')); @endphp
        <dl class="dashboard-bars">
            @foreach($chart['rows'] as $row)
                <div>
                    <dt>{{ $row['label'] }}</dt>
                    <dd><span class="dashboard-bar-track" aria-hidden="true"><span style="width: {{ min(100, $row['value'] / $maximum * 100) }}%"></span></span><span>{{ number_format($row['value']) }}</span></dd>
                </div>
            @endforeach
        </dl>
    @else
        <p class="dashboard-empty">No matching records in this scope.</p>
    @endif
</section>
