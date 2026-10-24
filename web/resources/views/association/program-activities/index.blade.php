@extends('layouts.association')

@section('content')
<section class="program-management" data-workspace-module="association-program-activities" data-workspace-title="Program Activities" data-association-program-activities>
    <header class="card program-heading"><div><p class="eyebrow">Association Account</p><h1 tabindex="-1">Program Activities</h1><p>View saved Program Activities belonging to your association.</p></div></header>
    @if($unavailable ?? false)
        <section class="card program-information" role="alert"><h2>Program Activity information is temporarily unavailable</h2><p>Please try again later.</p><a class="button button-primary" href="{{ route('association.program-activities.index') }}">Retry Program Activities</a></section>
    @else
        @if($errors->any())<div class="notice notice-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        <section class="card program-register" aria-labelledby="association-activities-title">
            <div class="program-section-heading"><div><h2 id="association-activities-title">{{ $association->name }}</h2><p>{{ number_format($records->total()) }} matching activity record(s), including archived records. Read only.</p></div></div>
            <form method="GET" action="{{ route('association.program-activities.index') }}" class="program-filter-panel" aria-label="Filter association Program Activities">
                <div class="program-filter-grid">
                    <div class="field"><label for="activity-search">Activity title</label><input id="activity-search" name="search" type="search" value="{{ $filters['search'] }}" maxlength="120" placeholder="Search activity titles"></div>
                    <div class="field"><label for="activity-year">Program year</label><input id="activity-year" name="year" type="number" value="{{ $filters['year'] }}" min="2000" max="2100" step="1" placeholder="All years"></div>
                </div>
                <div class="program-filter-actions"><button type="submit" class="button button-primary">Apply filters</button><a class="button" href="{{ route('association.program-activities.index') }}">Reset filters</a></div>
            </form>
            <div class="table-scroll" role="region" aria-label="Association Program Activities directory" tabindex="0">
                <table class="data-table program-register-table"><caption class="sr-only">Program Activities belonging to your association</caption>
                    <thead><tr><th scope="col">Activity</th><th scope="col">Date</th><th scope="col">Venue</th><th scope="col">Status</th><th scope="col">Related project</th><th scope="col">Details</th></tr></thead>
                    <tbody>
                        @forelse($records as $activity)
                            <tr><th scope="row"><a href="{{ route('association.program-activities.show', $activity->id) }}">{{ $activity->title }}</a><small>Program year: {{ $activity->program_year }}</small></th>
                                <td>{{ $activity->activity_date?->format('M j, Y') ?? 'Not recorded' }}</td>
                                <td>{{ filled($activity->venue) ? $activity->venue : 'Not recorded' }}</td>
                                <td><x-status-badge :value="ucfirst($activity->status)" />@if($activity->archived_at)<span class="badge badge-neutral">Archived</span>@endif</td>
                                <td>@if($activity->relatedProject)<a href="{{ route('association.projects.show', $activity->relatedProject->id) }}">{{ $activity->relatedProject->title }}</a>@else{{ $activity->related_project_id ? 'Not available within your association' : 'Not recorded' }}@endif</td>
                                <td><a class="button" href="{{ route('association.program-activities.show', $activity->id) }}" aria-label="View details for {{ $activity->title }}"><x-icon name="eye" />View Details</a></td>
                            </tr>
                        @empty<tr><td colspan="6"><x-empty-state title="No Program Activities on this page" message="No activities match this page and its filters. Reset the filters to view your association's saved activities." /></td></tr>@endforelse
                    </tbody>
                </table>
            </div>
            @include('program-management.partials.pagination', ['paginator' => $records, 'label' => 'activities'])
        </section>
    @endif
</section>
@endsection
