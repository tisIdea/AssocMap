<header class="page-header" data-workspace-module="monitoring" data-workspace-title="Monitoring"><div><div class="eyebrow">Quarterly Monitoring</div>
    <h1 tabindex="-1">{{ $record->project?->association?->name ?? 'Association unavailable' }}</h1>
    <p>@if($monitoringPrefix !== 'association.monitoring' && $record->project)<a href="{{ route($monitoringPrefix === 'admin.monitoring' ? 'admin.projects.show' : 'field-officer.projects.show', $record->project) }}">{{ $record->project->title }}</a>@else{{ $record->project?->title ?? 'Project unavailable' }}@endif · Q{{ $record->quarter?->quarter_number }} {{ $record->year }}</p>
</div></header>
<section class="card monitoring-card monitoring-overview" aria-labelledby="quarterly-overview-heading">
    <div class="monitoring-overview-heading"><h2 id="quarterly-overview-heading">Quarterly Overview</h2>
        @if($canManage && !request()->routeIs($monitoringPrefix.'.overview.edit'))<a class="button" href="{{ route($monitoringPrefix.'.overview.edit', $record) }}">Edit Overview</a>@endif
    </div>
    @include('monitoring.partials.details', ['details' => [
        'Association' => $record->project?->association?->name,
        'Project' => $record->project?->title,
        'Reporting Period' => 'Q'.$record->quarter?->quarter_number.' '.$record->year,
        'Date Created' => $record->created_at?->format('M d, Y'),
        'Monitored By' => $record->monitoredBy?->display_name,
        'Site Validated' => $record->site_validated ? 'Yes' : 'No',
        'Current Project Status' => ucfirst($record->project?->status ?? 'Unavailable'),
        'Status During Monitoring' => ucfirst($record->project_status),
    ]])
</section>
<nav class="monitoring-nav" aria-label="Monitoring navigation">
    <a class="button" href="{{ route($monitoringPrefix.'.index', ['year' => $record->year]) }}">Back to Dashboard</a>
    @php($visitsActive = request()->routeIs($monitoringPrefix.'.visits', $monitoringPrefix.'.visit.*'))
    <a class="button {{ $visitsActive ? 'button-primary' : '' }}" href="{{ route($monitoringPrefix.'.visits', $record) }}" @if($visitsActive) aria-current="page" @endif>Visit Reports</a>
    @foreach(['production' => 'Production', 'income' => 'Income', 'materials' => 'Materials'] as $tab => $label)
        <a class="button {{ ($section ?? '') === $tab ? 'button-primary' : '' }}" href="{{ route($monitoringPrefix.'.workspace', [$record, $tab]) }}" @if(($section ?? '') === $tab) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
