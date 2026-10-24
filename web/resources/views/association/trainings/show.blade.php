@extends('layouts.association')
@php $nameOf = fn ($participant) => collect([$participant->first_name, $participant->middle_name, $participant->last_name, $participant->suffix])->filter(fn ($part) => filled($part))->implode(' '); @endphp

@section('content')
<section class="program-management" data-workspace-module="association-trainings" data-workspace-title="Training Details" data-association-trainings>
    <a class="back-link" href="{{ route('association.trainings.index') }}"><x-icon name="back" />Back to Trainings</a>
    <header class="card program-heading"><div><p class="eyebrow">{{ $association->name }}</p><h1 tabindex="-1">Training Details</h1><p>{{ $training->title }}</p></div></header>
    @if($errors->any())<div class="notice notice-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <section class="card program-information" aria-labelledby="training-information-title">
        <h2 id="training-information-title">Training information</h2>
        <p>Read-only information for your association.</p>
        @if($training->archived_at)<p class="notice">This training is archived. Saved attendance remains available.</p>@endif
        <dl class="detail-grid">
            <div><dt>Title</dt><dd>{{ $training->title }}</dd></div>
            <div><dt>Status</dt><dd><x-status-badge :value="ucfirst($training->status)" /></dd></div>
            <div><dt>Program year</dt><dd>{{ $training->program_year }}</dd></div>
            <div><dt>Start date</dt><dd>{{ $training->start_date?->format('M j, Y') ?? 'Not recorded' }}</dd></div>
            <div><dt>End date</dt><dd>{{ $training->end_date?->format('M j, Y') ?? 'Not recorded' }}</dd></div>
            <div><dt>Venue</dt><dd>{{ filled($training->venue) ? $training->venue : 'Not recorded' }}</dd></div>
            <div><dt>Conducted by</dt><dd>{{ filled($training->conducted_by) ? $training->conducted_by : 'Not recorded' }}</dd></div>
            <div><dt>Related project</dt><dd>@if($training->relatedProject)<a href="{{ route('association.projects.show', $training->relatedProject->id) }}">{{ $training->relatedProject->title }}</a>@else{{ $training->related_project_id ? 'Not available within your association' : 'Not recorded' }}@endif</dd></div>
        </dl>
        <h3>Description</h3><p class="program-preserve-text">{{ filled($training->description) ? $training->description : 'Not recorded' }}</p>
    </section>
    <section class="card program-register" id="training-attendance" aria-labelledby="training-attendance-title">
        <div class="program-section-heading"><div><h2 id="training-attendance-title">Recorded attendance</h2><p>{{ number_format($participants->total()) }} recorded participant(s) from your association. Present: {{ number_format($counts['present'] ?? 0) }}. Absent: {{ number_format($counts['absent'] ?? 0) }}.</p></div><p>The Association Account represents the association, not an individual attendee. Counts cover all authorized attendance records, across every page.</p></div>
        <div class="table-scroll" role="region" aria-label="Association training attendance" tabindex="0">
            <table class="data-table program-register-table"><caption class="sr-only">Recorded member names and attendance for this association and training</caption><thead><tr><th scope="col">Member</th><th scope="col">Attendance</th></tr></thead><tbody>
                @forelse($participants as $participant)<tr><th scope="row">{{ $nameOf($participant) }}</th><td><x-status-badge :value="ucfirst($participant->attendance_status)" /></td></tr>
                @empty<tr><td colspan="2"><x-empty-state :title="$participants->total() === 0 ? 'No attendance recorded for your association' : 'No attendance on this page'" message="Only saved attendance linked to this training and members of your association appears here." /></td></tr>@endforelse
            </tbody></table>
        </div>
        @include('program-management.partials.pagination', ['paginator' => $participants, 'label' => 'participants'])
    </section>
</section>
@endsection
