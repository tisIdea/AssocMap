@php
    $activityPrefix = $activityPrefix ?? 'admin.program-activities';
    $rows = collect($activityRows);
    $restore = $errors->any() && old('program_activity_form') === 'editor' && !old('program_activity_record_id');
    $isFieldOfficer = $activityPrefix === 'field-officer.program-activities';
    $statuses = ['planned' => 'Planned', 'ongoing' => 'Ongoing', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
    $columns = ['title' => 'Title', 'activity' => 'SAAD Activity', 'association' => 'Association', 'municipality' => 'Municipality', 'related_project' => 'Related Project', 'program_year' => 'Program Year', 'activity_date' => 'Activity Date', 'venue' => 'Venue', 'conducted_by' => 'Conducted By'];
@endphp
@extends($activityLayout ?? 'layouts.admin')
@section('content')
<div data-workspace-module="program-activities" data-workspace-title="Program Activities">
    <header class="page-header">
        <div><div class="eyebrow">{{ $isFieldOfficer ? 'Field Officer' : 'Program Operations' }}</div><h1 tabindex="-1">Program Activities</h1><p>{{ $isFieldOfficer ? 'Manage program activities for your assigned associations.' : 'Manage association-specific and program-wide activities.' }}</p></div>
    </header>
    @if(!$isFieldOfficer)
        @if($errors->any() && !$restore)<div class="notice notice-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    @endif
    <section class="card list-card" aria-label="Program activities">
        <div class="table-toolbar">
            <form method="GET" action="{{ route($activityPrefix.'.index') }}">
                <div class="field"><label for="program-activity-status-filter">Activity Status</label><select id="program-activity-status-filter" name="status"><option value="">All current activities</option>@foreach($statuses + ['archived' => 'Archived'] as $value => $label)<option value="{{ $value }}" @selected($statusFilter === $value)>{{ $label }}</option>@endforeach</select></div>
                <button type="submit" class="button">Apply Filter</button>
            </form>
            <button type="button" class="button button-primary" data-program-activity-create data-dialog="program-activity-editor" @disabled($activities->isEmpty() || ($isFieldOfficer && $associations->isEmpty()))><x-icon name="plus" />Add Program Activity</button>
        </div>
        <div class="table-scroll" role="region" aria-label="Program activity list" tabindex="0">
            <table class="data-table"><caption class="sr-only">Program activities and implementation status</caption>
                <thead><tr>@foreach($columns as $label)<th scope="col">{{ $label }}</th>@endforeach<th scope="col">Status</th><th scope="col">Actions</th></tr></thead>
                <tbody>
                @foreach($rows as $row)
                    <tr>
                        @foreach($columns as $key => $label)
                            <td @class(['primary-cell' => $key === 'title'])>@if($key === 'title')<a href="{{ route($activityPrefix.'.show', $row['id']) }}">{{ $row['title'] }}</a>@else{{ $key === 'association' && !$isFieldOfficer && !$row['association_id'] ? 'Program-wide' : (filled($row[$key] ?? null) ? $row[$key] : '—') }}@endif</td>
                        @endforeach
                        <td><span class="badge badge-{{ $row['archived'] ? 'neutral' : ($row['status_value'] === 'completed' ? 'success' : ($row['status_value'] === 'cancelled' ? 'danger' : 'warning')) }}">{{ $row['archived'] ? 'Archived' : $row['status'] }}</span></td>
                        <td><div class="row-actions">
                            <a class="text-button" href="{{ route($activityPrefix.'.show', $row['id']) }}" aria-label="View {{ $row['title'] }}">View</a>
                            @if(!$row['project_finalized'])
                            @if(!$row['archived'])<a class="text-button" href="{{ route($activityPrefix.'.edit', $row['id']) }}" aria-label="Edit {{ $row['title'] }}">Edit</a>@endif
                            <button type="button" class="text-button {{ !$row['archived'] ? 'text-danger' : '' }}" data-dialog="program-activity-action-{{ $row['id'] }}" aria-label="{{ $row['archived'] ? 'Unarchive' : 'Archive' }} {{ $row['title'] }}">{{ $row['archived'] ? 'Unarchive' : 'Archive' }}</button>
                            @else<span>Read-only: project finalized</span>@endif
                        </div></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @if($rows->isEmpty())<x-empty-state title="No program activities found." message="Try another status filter or add a program activity." />@endif
        <footer class="table-footer"><span>{{ $rows->count() }} {{ $rows->count() === 1 ? 'activity' : 'activities' }}</span></footer>
    </section>
    @if($activities->isEmpty())<p class="notice">No active SAAD activities are available for this module.</p>@endif
    @if($associations->isEmpty())<p class="notice">{{ $isFieldOfficer ? 'No assigned active associations are available for creating activities.' : 'No active associations are available. You can still create program-wide activities.' }}</p>@endif
</div>
@foreach($rows as $row)
    @if(!$row['project_finalized'])
    <x-modal :id="'program-activity-action-'.$row['id']" :title="$row['archived'] ? 'Unarchive Program Activity' : 'Archive Program Activity'">
        <p>{{ $row['archived'] ? 'Restore' : 'Archive' }} <strong>{{ $row['title'] }}</strong>? {{ $row['archived'] ? 'The activity will return to the current list.' : 'Its details will remain available for viewing.' }}</p>
        <form method="POST" action="{{ $row['archived'] ? $row['unarchive_url'] : $row['archive_url'] }}">
            @csrf @method('PATCH')
            <div class="modal-actions"><button type="button" class="button" data-close-dialog>Cancel</button><button type="submit" class="button {{ $row['archived'] ? 'button-primary' : 'button-danger' }}">{{ $row['archived'] ? 'Unarchive Activity' : 'Archive Activity' }}</button></div>
        </form>
    </x-modal>
    @endif
@endforeach
<x-modal id="program-activity-editor" title="Add Program Activity">
    @include('program-activities.partials.form')
</x-modal>
@endsection
