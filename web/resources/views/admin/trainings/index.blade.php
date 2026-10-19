@php
    $trainingPrefix = $trainingPrefix ?? 'admin.trainings';
    $rows = collect($trainingRows);
    $restore = $errors->any() && old('training_form') === 'editor' && !old('training_record_id');
    $statuses = ['scheduled' => 'Scheduled', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
    $columns = ['title' => 'Training Title', 'association' => 'Association', 'municipality' => 'Municipality', 'saad_activity' => 'SAAD Activity', 'related_project' => 'Related Project', 'program_year' => 'Program Year', 'start_date' => 'Start Date', 'end_date' => 'End Date', 'venue' => 'Venue'];
@endphp
@extends($trainingLayout ?? 'layouts.admin')
@section('content')
<div data-workspace-module="trainings" data-workspace-title="Trainings">
    <header class="page-header"><div><div class="eyebrow">{{ $trainingPrefix === 'admin.trainings' ? 'Program Operations' : 'Field Officer' }}</div><h1 tabindex="-1">Trainings</h1><p>{{ $trainingPrefix === 'admin.trainings' ? 'Manage training activities, participants, and attendance.' : 'Manage training activities and attendance for your assigned associations.' }}</p></div></header>
    @if($trainingPrefix === 'admin.trainings')
        @if($errors->any() && !$restore)<div class="notice notice-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    @endif
    <section class="card list-card" aria-label="Trainings">
        <div class="table-toolbar">
            <form method="GET" action="{{ route($trainingPrefix.'.index') }}"><div class="field"><label for="training-status-filter">Training Status</label><select id="training-status-filter" name="status"><option value="">All current trainings</option>@foreach($statuses + ['archived' => 'Archived'] as $value => $label)<option value="{{ $value }}" @selected($statusFilter === $value)>{{ $label }}</option>@endforeach</select></div><button type="submit" class="button">Apply Filter</button></form>
            <button type="button" class="button button-primary" data-training-create data-dialog="training-editor" @disabled($associations->isEmpty() || $activities->isEmpty())><x-icon name="plus" />Add Training</button>
        </div>
        <div class="table-scroll" role="region" aria-label="Training list" tabindex="0"><table class="data-table"><caption class="sr-only">Training activities and participant counts</caption><thead><tr>@foreach($columns as $label)<th scope="col">{{ $label }}</th>@endforeach<th scope="col">Status</th><th scope="col">Participant Count</th><th scope="col">Actions</th></tr></thead><tbody>
        @foreach($rows as $row)
            <tr>@foreach($columns as $key => $label)<td @class(['primary-cell' => $key === 'title'])>@if($key === 'title')<a href="{{ route($trainingPrefix.'.show', $row['id']) }}">{{ $row['title'] }}</a>@else{{ filled($row[$key] ?? null) ? $row[$key] : '—' }}@endif</td>@endforeach
                <td><span class="badge badge-{{ $row['archived'] ? 'neutral' : ($row['status_value'] === 'completed' ? 'success' : ($row['status_value'] === 'cancelled' ? 'danger' : 'warning')) }}">{{ $row['archived'] ? 'Archived' : $row['status'] }}</span></td><td>{{ $row['participant_count'] }}</td>
                <td><div class="row-actions"><a class="text-button" href="{{ route($trainingPrefix.'.show', $row['id']) }}" aria-label="View {{ $row['title'] }}">View</a>@if($row['parents_operational'] && !$row['project_finalized'])@if(!$row['archived'])<a class="text-button" href="{{ route($trainingPrefix.'.edit', $row['id']) }}" aria-label="Edit {{ $row['title'] }}">Edit</a>@endif<button type="button" class="text-button {{ !$row['archived'] ? 'text-danger' : '' }}" data-dialog="training-action-{{ $row['id'] }}">{{ $row['archived'] ? 'Unarchive' : 'Archive' }}</button>@else<span>{{ $row['association_archived'] ? 'Association Archived — Read Only' : ($row['project_finalized'] ? 'Read-only: project finalized' : 'Read-only: association unavailable or inactive') }}</span>@endif</div></td>
            </tr>
        @endforeach
        </tbody></table></div>
        @if($rows->isEmpty())<x-empty-state title="No trainings found." message="Try another status filter or add a training for an available association." />@endif
        <footer class="table-footer"><span>{{ $rows->count() }} {{ $rows->count() === 1 ? 'training' : 'trainings' }}</span></footer>
    </section>
    @if($associations->isEmpty())<p class="notice">No active associations are available for creating trainings.</p>@endif
    @if($activities->isEmpty())<p class="notice">No active SAAD training activities are available.</p>@endif
</div>
@foreach($rows as $row)
    @if($row['parents_operational'] && !$row['project_finalized'])
    <x-modal :id="'training-action-'.$row['id']" :title="$row['archived'] ? 'Unarchive Training' : 'Archive Training'">
        <p>{{ $row['archived'] ? 'Restore' : 'Archive' }} <strong>{{ $row['title'] }}</strong>? {{ $row['archived'] ? 'The training will return to the current list.' : 'Its details and attendance will remain available for viewing.' }}</p>
        <form method="POST" action="{{ $row['archived'] ? $row['unarchive_url'] : $row['archive_url'] }}">@csrf @method('PATCH')<div class="modal-actions"><button type="button" class="button" data-close-dialog>Cancel</button><button type="submit" class="button {{ $row['archived'] ? 'button-primary' : 'button-danger' }}">{{ $row['archived'] ? 'Unarchive Training' : 'Archive Training' }}</button></div></form>
    </x-modal>
    @endif
@endforeach
<x-modal id="training-editor" title="Add Training">
    @include('trainings.partials.form')
</x-modal>
@endsection
