@extends($monitoringLayout)
@section('content')
@include('monitoring.partials.styles')
<div data-workspace-module="monitoring" data-workspace-title="Monitoring">
<header class="page-header"><div><div class="eyebrow">Program Operations</div><h1>Monitoring</h1><p>Quarterly records for your accessible associations and projects.</p></div></header>
@if($errors->any())<div class="notice notice-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<section class="card list-card" style="padding:20px">
    <div class="table-toolbar">
        <form method="GET" action="{{ route($monitoringPrefix.'.index') }}">
            <div class="field"><label for="monitoring-year">Reporting Year</label><select id="monitoring-year" name="year" @disabled($years->isEmpty())>
                @forelse($years as $year)<option value="{{ $year }}" @selected((int)$year === (int)$selectedYear)>{{ $year }}</option>@empty<option>No reporting years available</option>@endforelse
            </select></div>
            <div class="field"><label for="monitoring-search">Search Monitoring</label><input id="monitoring-search" type="search" name="search" maxlength="255" value="{{ $search }}" placeholder="Project, association, year, or quarter"></div>
            <button class="button" type="submit" @disabled($years->isEmpty())>Apply Filters</button>
        </form>
        @if($canManage)<a class="button button-primary" href="{{ route($monitoringPrefix.'.create') }}">Add Monitoring Record</a>@endif
    </div>
    @forelse($records->groupBy(fn ($record) => ($record->project?->association_id ?? '').':'.$record->project_id) as $group)
        @php($context = $group->first())
        <section class="monitoring-group"><h2>{{ $context->project?->association?->name ?? 'Association unavailable' }}</h2><p>@if($monitoringPrefix !== 'association.monitoring' && $context->project)<a href="{{ route($monitoringPrefix === 'admin.monitoring' ? 'admin.projects.show' : 'field-officer.projects.show', $context->project) }}">{{ $context->project->title }}</a>@else{{ $context->project?->title ?? 'Project unavailable' }}@endif · {{ $selectedYear }}</p>
        <div class="monitoring-scroll" tabindex="0" role="region" aria-label="Quarterly records for {{ $context->project?->association?->name }} · {{ $context->project?->title }}"><div class="monitoring-grid">
            @foreach([1,2,3,4] as $quarterNumber)
                <section><h3>Q{{ $quarterNumber }}</h3>
                @forelse($group->filter(fn ($record) => (int)$record->quarter?->quarter_number === $quarterNumber) as $record)
                    <article class="card monitoring-card monitoring-quarter-card"><h3><a class="monitoring-quarter-link" href="{{ route($monitoringPrefix.'.workspace', $record) }}" aria-label="Open Q{{ $quarterNumber }} {{ $record->year }} Monitoring for {{ $record->project?->association?->name }} · {{ $record->project?->title }}">Q{{ $quarterNumber }} {{ $record->year }}</a></h3><p>{{ $record->project?->association?->name }}</p><p>{{ $record->project?->title }}</p>
                        @include('monitoring.partials.details', ['details' => ['Date Created' => $record->created_at?->format('M d, Y'), 'Monitored By' => $record->monitoredBy?->display_name, 'Site Validated' => $record->site_validated ? 'Yes' : 'No']])
                        <div class="monitoring-actions">
                            <a class="button monitoring-reports-link" href="{{ route($monitoringPrefix.'.visits', $record) }}" aria-label="View Reports for Q{{ $quarterNumber }} {{ $record->year }} · {{ $record->project?->title }}">View Reports</a>
                        </div>
                    </article>
                @empty<p class="monitoring-empty">No record</p>@endforelse
                </section>
            @endforeach
        </div></div></section>
    @empty<p class="monitoring-empty">{{ $search ? 'No accessible Monitoring records match your search.' : 'No accessible Monitoring records are available.' }}</p>@endforelse
    <p role="status">{{ $records->count() }} Monitoring records</p>
</section></div>
@endsection
