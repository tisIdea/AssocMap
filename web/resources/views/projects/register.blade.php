@extends($prefix === 'admin' ? 'layouts.admin' : 'layouts.field-officer')
@php
    $workflow = app(\App\View\WorkflowNavigation::class);
    $module = 'projects';
    $moduleLabel = 'Projects';
    $projectRoutePrefix = $prefix.'.projects';
    $statuses = ['planned' => 'Planned', 'ongoing' => 'Ongoing', 'suspended' => 'Suspended', 'cancelled' => 'Cancelled', 'completed' => 'Completed', 'terminated' => 'Terminated'];
    $cards = ['current' => ['Current Projects', '', 'folder-kanban'], 'ongoing' => ['Ongoing', 'ongoing', 'trend'], 'completed' => ['Completed', 'completed', 'check'], 'terminated' => ['Terminated', 'terminated', 'close'], 'archived' => ['Archived Records', 'archived', 'archive']];
@endphp
@section('content')
<section class="program-management" data-workspace-module="projects" data-workspace-title="Project Management" data-project-actions>
    <header class="card program-heading"><div><p class="eyebrow">{{ $prefix === 'admin' ? 'System Administrator' : 'Field Officer' }}</p><h1 tabindex="-1">Project Management</h1><p>{{ $prefix === 'admin' ? 'Manage association projects, implementation progress, and project resources.' : 'Manage projects and resources within your assigned associations.' }}</p></div><a class="button button-primary" href="{{ route($projectRoutePrefix.'.index', $filters + ['editor' => 'create']) }}"><x-icon name="plus" />Add Project</a>
        <x-contextual-report :prefix="$prefix" type="projects" :related="true"
            :filters="$filters['year'] !== '' ? ['program_year' => (int) $filters['year']] : []"
            :scope="($filters['year'] !== '' ? 'Program year '.$filters['year'].'. ' : 'All program years. ').($prefix === 'admin' ? 'All authorized associations, including archived associations. ' : 'Your currently assigned active, unarchived associations. ').'Current project statuses, including archived projects; no event reporting period. Search, association-name, status, sorting and pagination selections from this register are not applied. Open a project for a single-project report.'" />
    </header>
    @include('program-management.partials.summary')
    <section class="card program-register" aria-labelledby="project-register-title">
        <div class="program-section-heading"><div><h2 id="project-register-title">Project register</h2><p>{{ number_format($projects->total()) }} matching project(s)</p></div><p>Completed and terminated projects remain permanently read-only.</p></div>
        @include('program-management.partials.filters')
        <div class="table-scroll" role="region" aria-label="Project register" tabindex="0"><table class="data-table program-register-table"><caption class="sr-only">Authorized association projects and their implementation status</caption><thead><tr><th scope="col">Project</th><th scope="col">Association</th><th scope="col">Implementation</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead><tbody>
            @forelse($projects as $project)
                @php
                    $canManage = $project->association && $project->association->status === 'active' && !$project->association->archived_at && !$project->isFinalized();
                @endphp
                <tr><th scope="row"><a href="{{ $workflow->to($projectRoutePrefix.'.show', [$project]) }}">{{ $project->title }}</a><small>{{ $project->fisheriesCategory?->name ?? 'Category unavailable' }}@if($project->commodity) · {{ $project->commodity->name }}@endif</small></th>
                    <td><a href="{{ $workflow->to($prefix.'.associations.show', ['association' => $project->association_id, 'section' => 'projects']) }}">{{ $project->association?->name ?? 'Association unavailable' }}</a><small>{{ $project->association?->locationBarangay()?->name ?? '—' }} · {{ $project->association?->locationBarangay()?->municipality?->name ?? '—' }}</small>@if($project->association?->archived_at)<small>Association archived</small>@elseif($project->association?->status !== 'active')<small>Association inactive</small>@endif</td>
                    <td>{{ $project->implementation_start_date?->format('M j, Y') ?? 'Not recorded' }}<small>Program year {{ $project->program_year }}@if($project->implementation_end_date) · Ends {{ $project->implementation_end_date->format('M j, Y') }}@endif</small></td>
                    <td><x-status-badge :value="ucfirst($project->status)" />@if($project->archived_at)<span class="badge badge-neutral">Archived</span>@endif</td>
                    <td class="project-actions-cell">
                        <div class="row-actions program-row-actions project-row-actions">
                            <a class="button button-quiet" href="{{ $workflow->to($projectRoutePrefix.'.show', [$project]) }}" aria-label="View {{ $project->title }}"><x-icon name="eye" />View</a>
                            @if($canManage)
                                <details class="project-actions" data-user-actions>
                                    <summary aria-label="Actions for {{ $project->title }}"><x-icon name="ellipsis" /></summary>
                                    <div class="project-actions-panel" data-user-actions-panel>
                                        @if(!$project->archived_at)
                                            <a href="{{ route($projectRoutePrefix.'.edit', $project) }}"><x-icon name="pencil" />Edit Project</a>
                                        @endif
                                        <button type="button" class="{{ $project->archived_at ? '' : 'project-action-danger' }}" data-dialog="project-action-{{ $project->id }}"><x-icon :name="$project->archived_at ? 'archive-restore' : 'archive'" />{{ $project->archived_at ? 'Restore Project' : 'Archive Project' }}</button>
                                        @if(!$project->archived_at && $project->status === 'ongoing')
                                            <a class="project-action-finalize" href="{{ route($projectRoutePrefix.'.show', ['project' => $project->id, 'finalize' => 'completed']) }}"><x-icon name="check" />Complete Project</a>
                                            <a class="project-action-danger" href="{{ route($projectRoutePrefix.'.show', ['project' => $project->id, 'finalize' => 'terminated']) }}"><x-icon name="close" />Terminate Project</a>
                                        @endif
                                    </div>
                                </details>
                            @else
                                <small class="project-read-only">Read-only</small>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty<tr><td colspan="5"><x-empty-state title="No matching projects" message="Try another search or filter, or reset to the current project register." /></td></tr>@endforelse
        </tbody></table></div>
        @include('program-management.partials.pagination', ['paginator' => $projects, 'label' => 'projects'])
    </section>
</section>
@foreach($projects as $project)
    @if($project->association && $project->association->status === 'active' && !$project->association->archived_at && !$project->isFinalized())
        <x-modal :id="'project-action-'.$project->id" :title="$project->archived_at ? 'Unarchive Project' : 'Archive Project'">
            <p>{{ $project->archived_at ? 'Restore' : 'Archive' }} <strong>{{ $project->title }}</strong>? {{ $project->archived_at ? 'The project will return to the current project list.' : 'Archived projects remain available for viewing and cannot be edited.' }}</p>
            <form method="POST" action="{{ route($projectRoutePrefix.($project->archived_at ? '.unarchive' : '.archive'), $project) }}">@csrf @method('PATCH')<div class="modal-actions"><button type="button" class="button" data-close-dialog>Cancel</button><button type="submit" class="button {{ $project->archived_at ? 'button-primary' : 'button-danger' }}">{{ $project->archived_at ? 'Unarchive Project' : 'Archive Project' }}</button></div></form>
        </x-modal>
    @endif
@endforeach
@if($showEditor)
    <x-modal id="project-editor" title="Add Project"><div class="program-editor">
        @include('projects.partials.form')
    </div></x-modal>
@endif
@endsection
