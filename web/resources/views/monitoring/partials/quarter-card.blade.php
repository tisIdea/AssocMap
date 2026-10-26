<article class="monitoring-quarter-slot" aria-label="Quarter {{ $quarterNumber }} {{ $selectedYear }} for {{ $projectLabel }}" data-monitoring-quarter="{{ $quarterNumber }}">
    @php $workflow = app(\App\View\WorkflowNavigation::class); @endphp
    <h4><span>Q{{ $quarterNumber }}</span><small>{{ $selectedYear }}</small></h4>
    @if($quarterRecords->count() > 1)<p class="notice">{{ $quarterRecords->count() }} records found. Each saved record is shown.</p>@endif
    @forelse($quarterRecords as $record)
        <div class="monitoring-quarter-record" data-monitoring-record="{{ $record->id }}">
            <span class="badge badge-blue">Recorded</span>
            <p class="monitoring-quarter-validation">Site validated: <strong>{{ $record->site_validated ? 'Yes' : 'No' }}</strong></p>
            <dl><div><dt>Date created</dt><dd>{{ $record->created_at?->format('M d, Y') ?? 'Not recorded' }}</dd></div><div><dt>Monitored by</dt><dd>{{ $record->monitoredBy?->display_name ?? 'Not recorded' }}</dd></div><div><dt>Status at monitoring</dt><dd>{{ ucfirst($record->project_status) }}</dd></div></dl>
            <div class="monitoring-quarter-actions"><a class="button button-primary" href="{{ $workflow->to($monitoringPrefix.'.workspace', [$record]) }}" aria-label="Open Q{{ $quarterNumber }} {{ $selectedYear }} for {{ $projectLabel }}"><x-icon name="eye" />View Overview</a><a class="button" href="{{ $workflow->to($monitoringPrefix.'.visits', [$record]) }}" aria-label="View Reports for Q{{ $quarterNumber }} {{ $selectedYear }} · {{ $projectLabel }}">View Reports</a></div>
        </div>
    @empty<div class="monitoring-quarter-empty"><x-icon name="calendar" /><p>No record</p><small>No Monitoring record saved for this quarter.</small></div>@endforelse
</article>
