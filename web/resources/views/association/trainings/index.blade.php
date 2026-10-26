@extends('layouts.association')
@php $workflow = app(\App\View\WorkflowNavigation::class); @endphp

@section('content')
<section class="program-management" data-workspace-module="association-trainings" data-workspace-title="Trainings" data-association-trainings>
    <header class="card program-heading"><div><p class="eyebrow">Association Account</p><h1 tabindex="-1">Trainings</h1><p>View your association's saved trainings and recorded attendance.</p></div></header>
    @if($unavailable ?? false)
        <section class="card program-information" role="alert"><h2>Training information is temporarily unavailable</h2><p>Please try again later.</p><a class="button button-primary" href="{{ route('association.trainings.index') }}">Retry Trainings</a></section>
    @else
        @if($errors->any())<div class="notice notice-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        <section class="card program-register" aria-labelledby="association-trainings-title">
            <div class="program-section-heading"><div><h2 id="association-trainings-title">{{ $association->name }}</h2><p>{{ number_format($records->total()) }} matching training(s), including archived records. Read only.</p></div></div>
            <form method="GET" action="{{ route('association.trainings.index') }}" class="program-filter-panel" aria-label="Filter association trainings">
                <div class="program-filter-grid">
                    <div class="field"><label for="training-search">Training title</label><input id="training-search" name="search" type="search" value="{{ $filters['search'] }}" maxlength="120" placeholder="Search training titles"></div>
                    <div class="field"><label for="training-year">Program year</label><input id="training-year" name="year" type="number" value="{{ $filters['year'] }}" min="2000" max="2100" step="1" placeholder="All years"></div>
                </div>
                <div class="program-filter-actions"><button type="submit" class="button button-primary">Apply filters</button><a class="button" href="{{ route('association.trainings.index') }}">Reset filters</a></div>
            </form>
            <div class="table-scroll" role="region" aria-label="Association trainings directory" tabindex="0">
                <table class="data-table program-register-table"><caption class="sr-only">Trainings belonging to your association</caption>
                    <thead><tr><th scope="col">Training</th><th scope="col">Dates</th><th scope="col">Venue</th><th scope="col">Status</th><th scope="col">Related project</th><th scope="col">Details</th></tr></thead>
                    <tbody>
                        @forelse($records as $training)
                            <tr><th scope="row"><a href="{{ $workflow->to('association.trainings.show', [$training->id]) }}">{{ $training->title }}</a><small>Program year: {{ $training->program_year }}</small></th>
                                <td>{{ $training->start_date?->format('M j, Y') ?? 'Not recorded' }}<small>End: {{ $training->end_date?->format('M j, Y') ?? 'Not recorded' }}</small></td>
                                <td>{{ filled($training->venue) ? $training->venue : 'Not recorded' }}</td>
                                <td><x-status-badge :value="ucfirst($training->status)" />@if($training->archived_at)<span class="badge badge-neutral">Archived</span>@endif</td>
                                <td>@if($training->relatedProject)<a href="{{ route('association.projects.show', $training->relatedProject->id) }}">{{ $training->relatedProject->title }}</a>@else{{ $training->related_project_id ? 'Not available within your association' : 'Not recorded' }}@endif</td>
                                <td><a class="button" href="{{ $workflow->to('association.trainings.show', [$training->id]) }}" aria-label="View details for {{ $training->title }}"><x-icon name="eye" />View Details</a></td>
                            </tr>
                        @empty<tr><td colspan="6"><x-empty-state title="No trainings on this page" message="No trainings match this page and its filters. Reset the filters to view your association's saved trainings." /></td></tr>@endforelse
                    </tbody>
                </table>
            </div>
            @include('program-management.partials.pagination', ['paginator' => $records, 'label' => 'trainings'])
        </section>
    @endif
</section>
@endsection
