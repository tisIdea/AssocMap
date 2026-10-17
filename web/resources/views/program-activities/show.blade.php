@extends($prefix === 'admin' ? 'layouts.admin' : 'layouts.field-officer')
@section('content')
@php
    $activityPrefix = $prefix.'.program-activities';
    $isFieldOfficer = $prefix === 'field-officer';
    $columns = ['title' => 'Title', 'activity' => 'SAAD Activity', 'association' => 'Association', 'municipality' => 'Municipality', 'related_project' => 'Related Project', 'program_year' => 'Program Year', 'activity_date' => 'Activity Date', 'venue' => 'Venue', 'conducted_by' => 'Conducted By'];
@endphp
<section data-workspace-module="program-activities" data-workspace-title="Program Activity Details">
    <a class="back-link" href="{{ route($activityPrefix.'.index') }}"><x-icon name="back" />Back to Program Activities</a>
    <header class="page-header" style="flex-wrap: wrap; align-items: flex-start;">
        <div style="flex: 1 1 240px; min-width: 0; overflow-wrap: anywhere;">
            <div class="eyebrow">Program Operations</div>
            <h1 tabindex="-1">Program Activity Details</h1>
            <p>{{ $row['title'] }}</p>
        </div>
        @if($canEdit)<a class="button button-primary" style="flex-shrink: 0; margin-left: auto;" href="{{ route($activityPrefix.'.edit', $row['id']) }}"><x-icon name="pencil" />Edit Program Activity</a>@endif
    </header>
    <section class="card form-section">
        @if(!$canEdit)<p class="notice">This record is currently read-only.</p>@endif
        <dl class="detail-grid">
            @foreach($columns + ['description' => 'Description', 'status' => 'Status', 'remarks' => 'Remarks'] as $key => $label)
                <div><dt>{{ $label }}</dt><dd>@if($key === 'related_project' && $canViewProject)<a href="{{ route($prefix.'.projects.show', $row['related_project_id']) }}">{{ $row[$key] }}</a>@else{{ $key === 'association' && !$isFieldOfficer && !$row['association_id'] ? 'Program-wide' : (filled($row[$key] ?? null) ? $row[$key] : '—') }}@endif</dd></div>
            @endforeach
            <div><dt>Archived</dt><dd>{{ $row['archived'] ? 'Yes' : 'No' }}</dd></div>
            @if($record?->archived_at)<div><dt>Archived At</dt><dd>{{ $record->archived_at->format('M j, Y g:i A') }}</dd></div>@endif
        </dl>
        @if($row['project_finalized'])<p class="notice">The linked project is completed or terminated. This activity is permanently read-only.</p>@endif
        @if(!$row['project_finalized'] && $row['archived'])<p class="notice">This activity is archived and read-only. Unarchive it before editing.</p>@endif
    </section>
</section>
@endsection
