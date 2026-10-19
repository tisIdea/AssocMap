@extends($monitoringLayout)
@section('content')
<div class="monitoring-management" data-workspace-module="monitoring" data-workspace-title="Monitoring">
    @include('monitoring.partials.context')
    <section class="card monitoring-register" aria-labelledby="visit-history-title">
        <div class="monitoring-section-heading"><div><h2 id="visit-history-title">Visit Reports</h2><p>Visit history for Q{{ $record->quarter?->quarter_number }} {{ $record->year }}, including retained historical reports.</p></div>
            @if($canManage)<a class="button button-primary" href="{{ route($monitoringPrefix.'.visit.create', $record) }}"><x-icon name="plus" />Add Visit Report</a>@endif
        </div>
        @include('monitoring.partials.filters', ['filterAction' => route($monitoringPrefix.'.visits', $record), 'searchPlaceholder' => 'Conductor, visit date, issues, actions or remarks'])
        <div class="monitoring-section-body">
            @forelse($reports as $report)
                <article class="monitoring-card">
                    <div class="monitoring-card-heading"><div><p class="eyebrow">Monitoring Visit</p><h3>{{ $report->monitoring_date?->format('M d, Y') ?? 'Date unavailable' }}</h3></div>
                        @if($canManage)<a class="button" href="{{ route($monitoringPrefix.'.visit.edit', [$record, $report->id]) }}" aria-label="Edit Visit Report dated {{ $report->monitoring_date?->format('M d, Y') }}"><x-icon name="pencil" />Edit Visit Report</a>@endif
                    </div>
                    @include('monitoring.partials.details', ['details' => ['Conducted By' => $report->conductedByUser?->display_name ?? $report->conducted_by, 'Site Validated' => $report->site_validated ? 'Yes' : 'No']])
                    <dl class="monitoring-visit-notes">
                        @foreach(['issues_encountered' => 'Issues Encountered', 'actions_taken' => 'Actions Taken', 'overall_remarks' => 'Overall Remarks'] as $field => $label)<div><dt>{{ $label }}</dt><dd>{{ filled($report->$field) ? $report->$field : 'Not recorded' }}</dd></div>@endforeach
                    </dl>
                    @if($report->is_legacy_import)<p class="field-hint">Retained historical visit report.</p>@endif
                </article>
            @empty<p class="monitoring-empty">{{ $filters['search'] !== '' || $filters['validated'] !== '' ? 'No Visit Reports match these filters.' : 'No Visit Reports recorded.' }}</p>@endforelse
        </div>
        @include('monitoring.partials.pagination', ['paginator' => $reports, 'label' => 'Visit Reports'])
    </section>
</div>
@endsection
