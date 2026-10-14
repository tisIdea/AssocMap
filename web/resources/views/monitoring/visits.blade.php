@extends($monitoringLayout)
@section('content')
@include('monitoring.partials.styles')
<div data-workspace-module="monitoring" data-workspace-title="Monitoring">
@include('monitoring.partials.context')
@if(session('success'))<p class="notice notice-success" role="status">{{ session('success') }}</p>@endif
<section class="card" style="padding:20px"><h2>Visit Reports</h2>
@if($canManage)<a class="button button-primary" href="{{ route($monitoringPrefix.'.visit.create', $record) }}">Add Visit Report</a>@endif
@forelse($reports as $report)
    <article class="monitoring-card"><h3>{{ $report->monitoring_date?->format('M d, Y') ?? 'Date unavailable' }}</h3>
        @include('monitoring.partials.details', ['details' => ['Monitoring Date' => $report->monitoring_date?->format('M d, Y'), 'Conducted By' => $report->conductedByUser?->display_name ?? $report->conducted_by, 'Site Validated' => $report->site_validated ? 'Yes' : 'No']])
        @foreach(['issues_encountered' => 'Issues Encountered', 'actions_taken' => 'Actions Taken', 'overall_remarks' => 'Overall Remarks'] as $field => $label)
            @include('monitoring.partials.details', ['details' => [$label => $report->$field]])
        @endforeach
        @if($canManage)<div class="monitoring-actions"><a class="button" href="{{ route($monitoringPrefix.'.visit.edit', [$record, $report->id]) }}">Edit Visit Report</a></div>@endif
    </article>
@empty<p class="monitoring-empty">No Visit Reports recorded.</p>@endforelse
</section></div>
@endsection
