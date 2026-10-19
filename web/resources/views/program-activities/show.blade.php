@extends($prefix === 'admin' ? 'layouts.admin' : 'layouts.field-officer')
@php
    $activityPrefix = $prefix.'.program-activities';
@endphp
@section('content')
<section class="program-management activity-management" data-workspace-module="program-activities" data-workspace-title="Program Activity Details">
    <a class="back-link" href="{{ route($activityPrefix.'.index') }}"><x-icon name="back" />Back to Program Activities</a>
    <header class="card program-heading">
        <div><p class="eyebrow">Program Operations</p><h1 tabindex="-1">Program Activity Details</h1><p>{{ $row['title'] }}</p></div>
        @if($canEdit)
            <a class="button button-primary" href="{{ route($activityPrefix.'.edit', $row['id']) }}"><x-icon name="pencil" />Edit Program Activity</a>
        @endif
    </header>
    <section class="card program-information">
        <h2>General Information</h2>
        @if($row['association_archived'])
            <p class="notice">Association Archived - Read Only. Historical details remain available.</p>
        @elseif(!$canEdit)
            <p class="notice">This record is currently read-only.</p>
        @endif
        <dl class="detail-grid">
            <div><dt>Activity Title</dt><dd>{{ $row['title'] }}</dd></div>
            <div><dt>Status</dt><dd><x-status-badge :value="$row['status']" /></dd></div>
            <div><dt>Record State</dt><dd>{{ $row['archived'] ? 'Archived' : 'Current' }}</dd></div>
        </dl>
        <h3>Description</h3><p class="program-preserve-text">{{ $row['description'] ?: 'Not recorded' }}</p>
    </section>
    <section class="card program-information">
        <h2>Program Information and Relationships</h2>
        <dl class="detail-grid">
            <div><dt>SAAD Activity</dt><dd>{{ $row['activity'] }}</dd></div>
            <div><dt>Scope</dt><dd>{{ $row['association_id'] ? 'Association-specific' : 'Program-wide' }}</dd></div>
            <div><dt>Association</dt><dd>
                @if($row['association_id'])
                    <a href="{{ route($prefix.'.associations.show', $row['association_id']) }}">{{ $row['association'] }}</a>
                @else
                    Program-wide
                @endif
            </dd></div>
            <div><dt>Barangay</dt><dd>{{ $row['barangay'] }}</dd></div>
            <div><dt>Municipality</dt><dd>{{ $row['municipality'] }}</dd></div>
            <div><dt>Related Project</dt><dd>
                @if($canViewProject)
                    <a href="{{ route($prefix.'.projects.show', $row['related_project_id']) }}">{{ $row['related_project'] }}</a>
                @else
                    {{ $row['related_project'] }}
                @endif
            </dd></div>
        </dl>
        @if($row['project_finalized'])
            <p class="notice">The linked project is completed or terminated. This activity is permanently read-only.</p>
        @elseif(!$row['parents_operational'])
            <p class="notice">An association for this activity or its linked project is unavailable or inactive. Operational changes are restricted.</p>
        @endif
    </section>
    <section class="card program-information">
        <h2>Activity Schedule</h2>
        <dl class="detail-grid">
            <div><dt>Activity Date</dt><dd>{{ $record->activity_date?->format('M j, Y') ?? 'Not recorded' }}</dd></div>
            <div><dt>Program Year</dt><dd>{{ $row['program_year'] }}</dd></div>
            <div><dt>Venue</dt><dd>{{ $row['venue'] ?: 'Not recorded' }}</dd></div>
            <div><dt>Conducted By</dt><dd>{{ $row['conducted_by'] ?: 'Not recorded' }}</dd></div>
        </dl>
    </section>
    <section class="card program-information">
        <h2>Status and Historical Details</h2>
        <dl class="detail-grid">
            <div><dt>Created At</dt><dd>{{ $record->created_at?->format('M j, Y g:i A') ?? 'Not recorded' }}</dd></div>
            <div><dt>Updated At</dt><dd>{{ $record->updated_at?->format('M j, Y g:i A') ?? 'Not recorded' }}</dd></div>
            <div><dt>Archived At</dt><dd>{{ $record->archived_at?->format('M j, Y g:i A') ?? 'Not archived' }}</dd></div>
        </dl>
        <h3>Remarks</h3><p class="program-preserve-text">{{ $row['remarks'] ?: 'Not recorded' }}</p>
        @if($row['archived'] && !$row['project_finalized'] && $row['parents_operational'])
            <p class="notice">This activity is archived. Restore it from the archived register before editing.</p>
            <a class="button" href="{{ route($activityPrefix.'.index', ['status' => 'archived']) }}">View archived activities</a>
        @endif
    </section>
</section>
@endsection
