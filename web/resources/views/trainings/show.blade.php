@extends($prefix === 'admin' ? 'layouts.admin' : 'layouts.field-officer')
@php
    $trainingPrefix = $prefix.'.trainings';
    $nameOf = fn ($member) => $member ? collect([$member->first_name, $member->middle_name, $member->last_name, $member->suffix])->filter(fn ($part) => filled($part))->implode(' ') : 'Member unavailable';
@endphp
@section('content')
<section class="program-management" data-workspace-module="trainings" data-workspace-title="Training Details">
    <a class="back-link" href="{{ route($trainingPrefix.'.index') }}"><x-icon name="back" />Back to Trainings</a>
    <header class="card program-heading"><div><p class="eyebrow">Training Management</p><h1 tabindex="-1">Training Details</h1><p>{{ $training->title }}</p></div><div class="program-heading-actions"><x-status-badge :value="ucfirst($training->status)" />@if($training->archived_at)<span class="badge badge-neutral">Archived</span>@endif @if($canEdit)<a class="button button-primary" href="{{ route($trainingPrefix.'.edit', $training) }}"><x-icon name="pencil" />Edit Training</a>@endif</div></header>
    @if($row['association_archived'])<p class="notice">Association Archived - Read Only. Historical details remain available.</p>@elseif(!$canEdit)<p class="notice">This record is currently read-only.</p>@endif
    @if($row['project_finalized'])<p class="notice">The linked project is completed or terminated. This training and its participants are permanently read-only.</p>@endif
    @if(!$row['project_finalized'] && $row['archived'])<p class="notice">This training is archived and read-only. @if($row['parents_operational'])Unarchive it before editing.@endif</p>@endif
    <section class="card program-information"><h2>General Information</h2><dl class="detail-grid">
        <div><dt>Training Title</dt><dd>{{ $training->title }}</dd></div>
        <div><dt>Association</dt><dd><a href="{{ route($prefix.'.associations.show', $training->association_id) }}">{{ $training->association?->name ?? 'Not available' }}</a></dd></div>
        <div><dt>SAAD Activity</dt><dd>{{ $training->saadActivity?->name ?? 'Not recorded' }}</dd></div>
        <div><dt>Related Project</dt><dd>@if($canViewProject)<a href="{{ route($prefix.'.projects.show', $training->related_project_id) }}">{{ $row['related_project'] }}</a>@else{{ $row['related_project'] }}@endif</dd></div>
        <div><dt>Description</dt><dd>{{ $training->description ?: 'Not recorded' }}</dd></div>
        <div><dt>Remarks</dt><dd>{{ $training->remarks ?: 'Not recorded' }}</dd></div>
    </dl></section>
    <section class="card program-information"><h2>Schedule and Location</h2><dl class="detail-grid">
        @foreach(['Program Year' => $training->program_year, 'Start Date' => $training->start_date?->format('M j, Y'), 'End Date' => $training->end_date?->format('M j, Y'), 'Venue' => $training->venue, 'Conducted By' => $training->conducted_by, 'Training Cost' => $training->training_cost, 'Municipality' => $training->association?->area?->barangay?->municipality?->name, 'Barangay' => $training->association?->area?->barangay?->name, 'Archived At' => $training->archived_at?->format('M j, Y g:i A')] as $label => $value)<div><dt>{{ $label }}</dt><dd>{{ filled($value) ? $value : 'Not recorded' }}</dd></div>@endforeach
    </dl></section>
    <section class="card program-register" aria-labelledby="training-participation-title"><div class="program-section-heading"><div><h2 id="training-participation-title">Participants and Attendance</h2><p>{{ number_format($participants->total()) }} recorded participant(s)</p></div><p>Historical membership and recorded attendance are retained.</p></div>
        <div class="table-scroll" role="region" aria-label="Training participants" tabindex="0"><table class="data-table program-register-table"><thead><tr><th scope="col">Member</th><th scope="col">Attendance</th><th scope="col">Remarks</th></tr></thead><tbody>
            @forelse($participants as $participant)<tr><th scope="row">{{ $nameOf($participant->member) }}@if($participant->member && ($participant->member->archived_at || $participant->member->member_status !== 'active'))<small>Historical participation</small>@endif</th><td><span class="badge badge-{{ $participant->attendance_status === 'present' ? 'success' : 'neutral' }}">{{ ucfirst($participant->attendance_status) }}</span></td><td>{{ $participant->remarks ?: 'Not recorded' }}</td></tr>@empty<tr><td colspan="3"><x-empty-state title="No participants recorded" message="Participation will appear here after it is saved through the authorized training form." /></td></tr>@endforelse
        </tbody></table></div>
        @include('program-management.partials.pagination', ['paginator' => $participants, 'label' => 'participants'])
    </section>
</section>
@endsection
