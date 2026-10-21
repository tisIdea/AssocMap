@extends('layouts.association')

@section('content')
<section class="program-management" data-workspace-module="association-projects" data-workspace-title="My Projects" data-association-projects>
    <header class="card program-heading">
        <div><p class="eyebrow">Association Account</p><h1 tabindex="-1">My Projects</h1><p>View your association's projects, registered assistance and existing Monitoring records.</p></div>
    </header>
    @if($unavailable ?? false)
        <section class="card program-information" role="alert"><h2>Project information is temporarily unavailable</h2><p>No project information could be loaded. Please try again later.</p><a class="button button-primary" href="{{ route('association.projects.index') }}">Retry My Projects</a></section>
    @else
        @if($errors->any())<div class="notice notice-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        <section class="card program-register" aria-labelledby="my-project-register-title">
            <div class="program-section-heading"><div><h2 id="my-project-register-title">{{ $association->name }}</h2><p>{{ number_format($projects->total()) }} matching project(s). All projects are included unless filtered, including archived projects and projects without Monitoring records.</p></div></div>
            <form method="GET" action="{{ route('association.projects.index') }}" class="program-filter-panel" aria-label="Filter my projects">
                <div class="program-filter-grid">
                    <div class="field"><label for="my-project-search">Project name</label><input id="my-project-search" name="search" type="search" value="{{ $filters['search'] }}" maxlength="120" placeholder="Search project names"></div>
                    <div class="field"><label for="my-project-year">Program year</label><input id="my-project-year" name="year" type="number" value="{{ $filters['year'] }}" min="2000" max="2100" step="1" placeholder="All years"></div>
                    <div class="field"><label for="my-project-status">Project status / archive</label><select id="my-project-status" name="status">
                        @foreach(['' => 'All projects', 'planned' => 'Planned', 'ongoing' => 'Ongoing', 'completed' => 'Completed', 'suspended' => 'Suspended', 'cancelled' => 'Cancelled', 'terminated' => 'Terminated', 'archived' => 'Archived records'] as $value => $label)
                            <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select></div>
                </div>
                <div class="program-filter-actions"><button type="submit" class="button button-primary">Apply filters</button><a class="button" href="{{ route('association.projects.index') }}">Reset filters</a></div>
            </form>
            <div class="table-scroll" role="region" aria-label="My Projects directory" tabindex="0">
                <table class="data-table program-register-table"><caption class="sr-only">Projects belonging to your linked association</caption>
                    <thead><tr><th scope="col">Project</th><th scope="col">Program year</th><th scope="col">Implementation</th><th scope="col">Current status</th><th scope="col">Details</th></tr></thead>
                    <tbody>
                        @forelse($projects as $project)
                            <tr><th scope="row"><a href="{{ route('association.projects.show', $project->id) }}">{{ $project->title }}</a><small>{{ $project->fisheriesCategory?->name ?? 'Category not recorded' }}@if($project->commodity) · {{ $project->commodity->name }}@endif</small></th>
                                <td>{{ $project->program_year }}</td>
                                <td>{{ $project->implementation_start_date?->format('M j, Y') ?? 'Not recorded' }}<small>End: {{ $project->implementation_end_date?->format('M j, Y') ?? 'Not recorded' }}</small></td>
                                <td><x-status-badge :value="ucfirst($project->status)" />@if($project->archived_at)<span class="badge badge-neutral">Archived</span>@endif</td>
                                <td><a class="button" href="{{ route('association.projects.show', $project->id) }}" aria-label="View details for {{ $project->title }}"><x-icon name="eye" />View Details</a></td>
                            </tr>
                        @empty<tr><td colspan="5"><x-empty-state title="No projects on this page" message="No projects match this page and its filters. Reset the filters to view all projects registered for your association." /></td></tr>@endforelse
                    </tbody>
                </table>
            </div>
            @include('program-management.partials.pagination', ['paginator' => $projects, 'label' => 'projects'])
        </section>
    @endif
</section>
@endsection
