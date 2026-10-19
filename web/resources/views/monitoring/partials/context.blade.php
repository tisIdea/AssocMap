@php
    $visitsActive = request()->routeIs($monitoringPrefix.'.visits', $monitoringPrefix.'.visit.*');
    $activeSection = $visitsActive ? 'visits' : ($section ?? 'overview');
    $sections = ['overview' => ['Overview', 'clipboard-check'], 'production' => ['Production', 'chart'], 'income' => ['Income', 'trend'], 'materials' => ['Materials', 'layers'], 'visits' => ['Visit Reports', 'calendar']];
@endphp
<header class="card monitoring-heading">
    <div><p class="eyebrow">Quarterly Monitoring · Q{{ $record->quarter?->quarter_number }} {{ $record->year }}</p>
        <h1 tabindex="-1">{{ $sections[$activeSection][0] }}</h1>
        <p>{{ $record->project?->association?->name ?? 'Association unavailable' }}</p>
        <p>@if($monitoringPrefix !== 'association.monitoring' && $record->project)<a href="{{ route($monitoringPrefix === 'admin.monitoring' ? 'admin.projects.show' : 'field-officer.projects.show', $record->project) }}">{{ $record->project->title }}</a>@else{{ $record->project?->title ?? 'Project unavailable' }}@endif</p>
    </div>
    <a class="button" href="{{ route($monitoringPrefix.'.index', ['year' => $record->year]) }}"><x-icon name="back" />Back to Dashboard</a>
</header>
@if($record->project?->association?->archived_at)<p class="notice">Association Archived — Read Only. Monitoring values and history are preserved.</p>@endif
@if($record->project?->archived_at)<p class="notice">Project Archived — Read Only.</p>@elseif($record->project?->isFinalized())<p class="notice">Project finalized — Read Only.</p>@endif
<nav class="monitoring-nav" aria-label="Monitoring navigation">
    @foreach($sections as $tab => [$label, $icon])
        <a href="{{ $tab === 'visits' ? route($monitoringPrefix.'.visits', $record) : route($monitoringPrefix.'.workspace', [$record, $tab]) }}" @if($activeSection === $tab) aria-current="page" @endif><x-icon :name="$icon" /><span>{{ $label }}</span></a>
    @endforeach
</nav>
<section class="card monitoring-overview" aria-labelledby="quarterly-overview-heading">
    <div class="monitoring-section-heading"><div><h2 id="quarterly-overview-heading">Quarterly Overview</h2><p>Saved context for this reporting period.</p></div>
        @if($canManage && !request()->routeIs($monitoringPrefix.'.overview.edit'))<a class="button" href="{{ route($monitoringPrefix.'.overview.edit', $record) }}"><x-icon name="pencil" />Edit Overview</a>@endif
    </div>
    @include('monitoring.partials.details', ['details' => [
        'Reporting Period' => 'Q'.$record->quarter?->quarter_number.' '.$record->year,
        'Date Created' => $record->created_at?->format('M d, Y'),
        'Monitored By' => $record->monitoredBy?->display_name,
        'Site Validated' => $record->site_validated ? 'Yes' : 'No',
        'Current Project Status' => ucfirst($record->project?->status ?? 'Unavailable'),
        'Status During Monitoring' => ucfirst($record->project_status),
    ]])
</section>
