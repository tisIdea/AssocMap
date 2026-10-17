@extends($prefix === 'admin' ? 'layouts.admin' : 'layouts.field-officer')
@section('content')
@php
    $trainingPrefix = $prefix.'.trainings';
    $isFieldOfficer = $prefix === 'field-officer';
    $columns = ['title' => 'Training Title', 'association' => 'Association', 'municipality' => 'Municipality', 'saad_activity' => 'SAAD Activity', 'related_project' => 'Related Project', 'program_year' => 'Program Year', 'start_date' => 'Start Date', 'end_date' => 'End Date', 'venue' => 'Venue'];
    $nameOf = fn ($member) => $member ? collect([$member->first_name, $member->middle_name, $member->last_name, $member->suffix])->filter(fn ($part) => filled($part))->implode(' ') : 'Member unavailable';
@endphp
<section data-workspace-module="trainings" data-workspace-title="Training Details">
    <a class="back-link" href="{{ route($trainingPrefix.'.index') }}"><x-icon name="back" />Back to Trainings</a>
    <header class="page-header" style="flex-wrap: wrap; align-items: flex-start;">
        <div style="flex: 1 1 240px; min-width: 0; overflow-wrap: anywhere;">
            <div class="eyebrow">Program Operations</div>
            <h1 tabindex="-1">Training Details</h1>
            <p>{{ $row['title'] }}</p>
        </div>
        @if($canEdit)<a class="button button-primary" style="flex-shrink: 0; margin-left: auto;" href="{{ route($trainingPrefix.'.edit', $row['id']) }}"><x-icon name="pencil" />Edit Training</a>@endif
    </header>
    <section class="card form-section">
        @if(!$canEdit)<p class="notice">This record is currently read-only.</p>@endif
        <dl class="detail-grid">@foreach($columns + ['description' => 'Description', 'conducted_by' => 'Conducted By', 'training_cost' => 'Training Cost', 'status' => 'Status', 'remarks' => 'Remarks'] as $key => $label)<div><dt>{{ $label }}</dt><dd>@if($key === 'related_project' && $canViewProject)<a href="{{ route($prefix.'.projects.show', $row['related_project_id']) }}">{{ $row[$key] }}</a>@else{{ filled($row[$key] ?? null) ? $row[$key] : '—' }}@endif</dd></div>@endforeach<div><dt>Archived</dt><dd>{{ $row['archived'] ? 'Yes' : 'No' }}</dd></div>@if($training?->archived_at)<div><dt>Archived At</dt><dd>{{ $training->archived_at->format('M j, Y g:i A') }}</dd></div>@endif</dl>
        @if($row['project_finalized'])<p class="notice">The linked project is completed or terminated. This training and its participants are permanently read-only.</p>@endif
        @if(!$row['project_finalized'] && $row['archived'])<p class="notice">This training is archived and read-only. Unarchive it before editing.</p>@endif
        <h3>Participants and Attendance</h3>
        @forelse($training?->participants ?? [] as $participant)<div class="repeat-row"><dl class="detail-grid"><div><dt>Member</dt><dd>{{ $nameOf($participant->member) }}</dd></div><div><dt>Attendance Status</dt><dd>{{ ucfirst($participant->attendance_status) }}</dd></div><div><dt>Remarks</dt><dd>{{ $participant->remarks ?: '—' }}</dd></div></dl></div>@empty<p>No participants recorded.</p>@endforelse
    </section>
</section>
@endsection
