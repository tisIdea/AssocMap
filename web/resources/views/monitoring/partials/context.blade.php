@php
    $visitsActive = request()->routeIs($monitoringPrefix.'.visits', $monitoringPrefix.'.visit.*');
    $activeSection = $visitsActive ? 'visits' : ($section ?? 'overview');
    $sections = ['overview' => ['Overview', 'clipboard-check'], 'production' => ['Production', 'chart'], 'income' => ['Income', 'trend'], 'materials' => ['Materials', 'layers'], 'visits' => ['Visit Reports', 'calendar']];
    $workflow = app(\App\View\WorkflowNavigation::class);
    $programPrefix = str_replace('.monitoring', '', $monitoringPrefix);
    $parentAssociation = $record->project?->association;
    $canViewParentProject = $record->project && $parentAssociation && ($programPrefix !== 'association'
        || ($parentAssociation->status === 'active' && $parentAssociation->archived_at === null));
    $parents = $canViewParentProject ? [['name' => $programPrefix.'.projects.show', 'parameters' => ['project' => $record->project_id], 'label' => $record->project->title]] : [];
    $workflowBack = $workflow->back(request(), $monitoringPrefix.'.index', ['year' => $record->year], $parents);
    $breadcrumbs = [];
    if ($parentAssociation) $breadcrumbs[] = ['label' => $parentAssociation->name, 'url' => $programPrefix === 'association' ? null : route($programPrefix.'.associations.show', ['association' => $parentAssociation->id, 'section' => 'projects'])];
    if ($record->project) $breadcrumbs[] = ['label' => $record->project->title, 'url' => $canViewParentProject ? $workflow->parent(request(), $programPrefix.'.projects.show', ['project' => $record->project_id]) : null];
@endphp
<x-workflow-navigation :back="$workflowBack" :parents="$breadcrumbs" :current="'Q'.$record->quarter?->quarter_number.' '.$record->year.' · '.$sections[$activeSection][0]" />
<header class="card monitoring-heading">
    <div><p class="eyebrow">Quarterly Monitoring · Q{{ $record->quarter?->quarter_number }} {{ $record->year }}</p>
        <h1 tabindex="-1">{{ $sections[$activeSection][0] }}</h1>
        <p>{{ $record->project?->association?->name ?? 'Association unavailable' }}</p>
        <p>{{ $record->project?->title ?? 'Project unavailable' }}</p>
    </div>
</header>
@if($record->project?->association?->archived_at)<p class="notice">Association Archived — Read Only. Monitoring values and history are preserved.</p>@endif
@if($record->project?->archived_at)<p class="notice">Project Archived — Read Only.</p>@elseif($record->project?->isFinalized())<p class="notice">Project finalized — Read Only.</p>@endif
<nav class="monitoring-nav" aria-label="Monitoring navigation">
    @foreach($sections as $tab => [$label, $icon])
        <a href="{{ $tab === 'visits' ? route($monitoringPrefix.'.visits', [$record] + $workflow->carry(request())) : route($monitoringPrefix.'.workspace', [$record, $tab] + $workflow->carry(request())) }}" @if($activeSection === $tab) aria-current="page" @endif><x-icon :name="$icon" /><span>{{ $label }}</span></a>
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
