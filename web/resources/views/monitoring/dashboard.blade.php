@extends($monitoringLayout)
@section('content')
<div class="monitoring-management" data-workspace-module="monitoring" data-workspace-title="Monitoring">
    <header class="card monitoring-heading">
        <div><p class="eyebrow">Program Operations</p><h1 tabindex="-1">Monitoring Management</h1><p>Review quarterly progress, resources and visit reports for your accessible projects.</p></div>
        @if($canManage)<a class="button button-primary" href="{{ route($monitoringPrefix.'.create') }}"><x-icon name="plus" />Add Monitoring Record</a>@endif
    </header>
    @if($errors->any())<div class="notice notice-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <div class="monitoring-summary" aria-label="Monitoring register summary">
        <section class="stat-card monitoring-summary-selected"><div class="stat-top"><span>Matching projects</span><span class="stat-icon"><x-icon name="clipboard-check" /></span></div><strong>{{ number_format($projects->total()) }}</strong><small>With a record matching the selected filters</small></section>
        <section class="stat-card"><div class="stat-top"><span>Reporting year</span><span class="stat-icon"><x-icon name="calendar" /></span></div><strong>{{ $selectedYear ?? '—' }}</strong><small>{{ $years->count() }} available reporting year(s)</small></section>
        <section class="stat-card"><div class="stat-top"><span>Projects on this page</span><span class="stat-icon"><x-icon name="layers" /></span></div><strong>{{ $projects->count() }}</strong><small>Up to 25 projects per page</small></section>
    </div>
    <section class="card monitoring-register" aria-labelledby="monitoring-register-title">
        <div class="monitoring-section-heading"><div><h2 id="monitoring-register-title">Projects by reporting quarter</h2><p>Projects qualify through matching records. Cards show every recorded quarter for the selected year.</p></div><p>Historical values remain available within your access.</p></div>
        @include('monitoring.partials.filters', ['filterAction' => route($monitoringPrefix.'.index'), 'searchPlaceholder' => 'Project, association, quarter, date or monitor'])
        <div class="monitoring-project-list">
            @forelse($projects as $project)
                @include('monitoring.partials.dashboard-project')
            @empty<x-empty-state title="No matching Monitoring records" message="No projects have records matching the selected year and filters. Try another search or reporting year, or reset the filters." />@endforelse
        </div>
        @include('monitoring.partials.pagination', ['paginator' => $projects, 'label' => 'projects'])
    </section>
</div>
@endsection
