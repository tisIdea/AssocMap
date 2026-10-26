@extends($prefix === 'admin' ? 'layouts.admin' : 'layouts.field-officer')
@php
    $workflow = app(\App\View\WorkflowNavigation::class);
    $module = 'program-activities';
    $moduleLabel = 'Program Activities';
    $activityPrefix = $prefix.'.'.$module;
    $statuses = ['planned' => 'Planned', 'ongoing' => 'Ongoing', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
    $cards = ['current' => ['Current Activities', '', 'calendar'], 'planned' => ['Planned', 'planned', 'calendar'],
        'ongoing' => ['Ongoing', 'ongoing', 'trend'], 'completed' => ['Completed', 'completed', 'check'],
        'cancelled' => ['Cancelled', 'cancelled', 'close'], 'archived' => ['Archived Records', 'archived', 'archive']];
    $sortLabels = ['year' => 'Program year: newest first', 'az' => 'Title: A–Z', 'za' => 'Title: Z–A',
        'newest' => 'Activity date: newest first', 'oldest' => 'Activity date: oldest first'];
@endphp
@section('content')
<section class="program-management activity-management" data-workspace-module="program-activities" data-workspace-title="Program Activity Management" data-program-activities>
    <header class="card program-heading">
        <div><p class="eyebrow">{{ $prefix === 'admin' ? 'System Administrator' : 'Field Officer' }}</p><h1 tabindex="-1">Program Activity Management</h1><p>{{ $prefix === 'admin' ? 'Manage association-specific and program-wide SAAD activities.' : 'Manage program activities for your assigned active associations.' }}</p></div>
        <a class="button button-primary" href="{{ route($activityPrefix.'.index', $filters + ['editor' => 'create']) }}"><x-icon name="plus" />Add Program Activity</a>
    </header>
    @include('program-management.partials.summary')
    <section class="card program-register" aria-labelledby="activity-register-title">
        <div class="program-section-heading"><div><h2 id="activity-register-title">Activity register</h2><p>{{ number_format($records->total()) }} matching activity record(s)</p></div><p>Program-wide and association-specific records retain their existing scope.</p></div>
        @include('program-management.partials.filters', ['searchPlaceholder' => 'Search title, association, or SAAD activity'])
        <div class="table-scroll" role="region" aria-label="Program activity register" tabindex="0">
            <table class="data-table program-register-table activity-register-table">
                <caption class="sr-only">Authorized program activities, relationships, and status</caption>
                <thead><tr><th scope="col">Activity / SAAD activity</th><th scope="col">Scope / Project</th><th scope="col">Schedule</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead>
                <tbody>
                    @forelse($rows as $row)
                        <tr>
                            <th scope="row"><a href="{{ $workflow->to($activityPrefix.'.show', [$row['id']]) }}">{{ $row['title'] }}</a><small>{{ $row['activity'] }}</small></th>
                            <td>
                                @if($row['association_id'])
                                    <a href="{{ $workflow->to($prefix.'.associations.show', ['association' => $row['association_id'], 'section' => 'activities']) }}">{{ $row['association'] }}</a>
                                    <small>{{ $row['barangay'] }} · {{ $row['municipality'] }}</small>
                                @else
                                    <span class="badge badge-neutral">Program-wide</span>
                                @endif
                                @if($row['can_view_project'])
                                    <small>Project: <a href="{{ route($prefix.'.projects.show', $row['related_project_id']) }}">{{ $row['related_project'] }}</a></small>
                                @else
                                    <small>{{ $row['related_project'] }}</small>
                                @endif
                            </td>
                            <td>{{ $row['activity_date'] }}<small>Program year {{ $row['program_year'] }}</small><small>{{ $row['venue'] ?: 'Venue not recorded' }}</small></td>
                            <td><x-status-badge :value="$row['status']" />
                                @if($row['archived'])
                                    <span class="badge badge-neutral">Archived</span>
                                @endif
                            </td>
                            <td class="activity-actions-cell">
                                <div class="row-actions activity-row-actions">
                                    <a class="button button-quiet" href="{{ $workflow->to($activityPrefix.'.show', [$row['id']]) }}" aria-label="View {{ $row['title'] }}"><x-icon name="eye" />View</a>
                                    @if($row['can_manage'])
                                        <details class="activity-actions" data-user-actions>
                                            <summary aria-label="Actions for {{ $row['title'] }}"><x-icon name="ellipsis" /></summary>
                                            <div class="activity-actions-panel" data-user-actions-panel>
                                                @if(!$row['archived'])
                                                    <a href="{{ route($activityPrefix.'.edit', $row['id']) }}"><x-icon name="pencil" />Edit Activity</a>
                                                @endif
                                                <button type="button" @class(['activity-action-danger' => !$row['archived']]) data-dialog="program-activity-action-{{ $row['id'] }}"><x-icon :name="$row['archived'] ? 'archive-restore' : 'archive'" />{{ $row['archived'] ? 'Restore Activity' : 'Archive Activity' }}</button>
                                            </div>
                                        </details>
                                    @else
                                        <small class="activity-read-only">Read-only</small>
                                    @endif
                                </div>
                                @if($row['project_finalized'])
                                    <small>Project finalized</small>
                                @elseif($row['association_archived'])
                                    <small>Association archived</small>
                                @elseif(!$row['parents_operational'])
                                    <small>Association unavailable or inactive</small>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-empty-state title="No matching program activities" message="Try another search or filter, or reset to the current activity register." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('program-management.partials.pagination', ['paginator' => $records, 'label' => 'activities'])
    </section>
</section>
@foreach($rows as $row)
    @if($row['can_manage'])
        <x-modal :id="'program-activity-action-'.$row['id']" :title="$row['archived'] ? 'Restore Program Activity' : 'Archive Program Activity'">
            <p>{{ $row['archived'] ? 'Restore' : 'Archive' }} <strong>{{ $row['title'] }}</strong>? {{ $row['archived'] ? 'The activity will return to the current list.' : 'Its details and relationships will remain available for viewing.' }}</p>
            <form method="POST" action="{{ $row['archived'] ? $row['unarchive_url'] : $row['archive_url'] }}">
                @csrf
                @method('PATCH')
                <div class="modal-actions"><button type="button" class="button" data-close-dialog>Cancel</button><button type="submit" class="button {{ $row['archived'] ? 'button-primary' : 'button-danger' }}">{{ $row['archived'] ? 'Restore Activity' : 'Archive Activity' }}</button></div>
            </form>
        </x-modal>
    @endif
@endforeach
@if($showEditor)
    <x-modal id="program-activity-editor" title="Add Program Activity"><div class="program-editor">
        @include('program-activities.partials.form')
    </div></x-modal>
@endif
@endsection
