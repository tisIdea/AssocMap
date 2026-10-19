@extends($projectLayout ?? 'layouts.admin')
@section('content')
@php
    $prefix = $projectRoutePrefix ?? 'admin.projects';
    $rows = collect($module['rows']);
    $restore = $errors->any() && old('project_form') === 'editor' && !old('project_record_id');
    $statuses = ['planned' => 'Planned', 'ongoing' => 'Ongoing', 'completed' => 'Completed', 'terminated' => 'Terminated', 'suspended' => 'Suspended', 'cancelled' => 'Cancelled'];
    $columns = ['title' => 'Project Title', 'association' => 'Association', 'municipality' => 'Municipality', 'component' => 'SAAD Classification', 'fisheries_category' => 'Fisheries Category', 'commodity' => 'Commodity', 'program_year' => 'Program Year', 'implementation_start_date' => 'Implementation Date'];
@endphp
<header class="page-header"><div><div class="eyebrow">{{ $prefix === 'admin.projects' ? 'Program Operations' : 'Field Officer' }}</div><h1 tabindex="-1">Projects</h1><p>{{ $prefix === 'admin.projects' ? 'Manage association projects and material deliveries.' : 'Manage projects for your assigned associations.' }}</p></div></header>
@if($prefix === 'admin.projects')
    @if($errors->any() && !$restore)<div class="notice notice-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@endif
<section class="card list-card" aria-label="Projects">
    <div class="table-toolbar">
        <form method="GET" action="{{ route($prefix.'.index') }}"><div class="field"><label for="project-status-filter">Project Status</label><select id="project-status-filter" name="status"><option value="">All current projects</option>@foreach($statuses + ['archived' => 'Archived'] as $value => $label)<option value="{{ $value }}" @selected(($module['status_filter'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div><button type="submit" class="button">Apply Filter</button></form>
        <button type="button" class="button button-primary" data-project-create data-dialog="project-editor" @disabled(empty($catalog['options']['association_records']) || empty($catalog['options']['fisheries_category_records']))><x-icon name="plus" />Add Project</button>
    </div>
    <div class="table-scroll" role="region" aria-label="Project list" tabindex="0"><table class="data-table"><caption class="sr-only">Association projects and implementation status</caption><thead><tr>@foreach($columns as $label)<th scope="col">{{ $label }}</th>@endforeach<th scope="col">Status</th><th scope="col">Actions</th></tr></thead><tbody>
    @foreach($rows as $row)
        <tr>@foreach($columns as $key => $label)<td @class(['primary-cell' => $key === 'title'])>@if($key === 'title')<a href="{{ route($prefix.'.show', $row['id']) }}">{{ $row['title'] }}</a>@else{{ filled($row[$key] ?? null) ? $row[$key] : '—' }}@endif</td>@endforeach
        <td><span class="badge badge-{{ $row['status_value'] === 'completed' ? 'success' : ($row['status_value'] === 'terminated' ? 'danger' : ($row['archived_at'] ? 'neutral' : 'warning')) }}">{{ in_array($row['status_value'], ['completed', 'terminated'], true) ? strtoupper($row['status_value']) : ($row['archived_at'] ? 'Archived' : $row['status']) }}</span>@if(in_array($row['status_value'], ['completed', 'terminated'], true) && $row['archived_at'])<span class="badge badge-neutral">Archived</span>@endif</td>
        <td><div class="row-actions"><a class="text-button" href="{{ route($prefix.'.show', $row['id']) }}" aria-label="View {{ $row['title'] }}">View</a>@if($row['can_mutate'] ?? false)@if(!$row['archived_at'])<a class="text-button" href="{{ route($prefix.'.edit', $row['id']) }}" aria-label="Edit {{ $row['title'] }}">Edit</a>@if($row['status_value'] === 'ongoing')<a class="text-button text-danger" href="{{ route($prefix.'.show', ['project' => $row['id'], 'finalize' => 'completed']) }}">Complete Project</a><a class="text-button text-danger" href="{{ route($prefix.'.show', ['project' => $row['id'], 'finalize' => 'terminated']) }}">Terminate Project</a>@endif @endif<button type="button" class="text-button" data-dialog="project-action-{{ $row['id'] }}">{{ $row['archived_at'] ? 'Unarchive' : 'Archive' }}</button>@else<span>Read-only</span>@endif</div></td></tr>
    @endforeach
    </tbody></table></div>
    @if($rows->isEmpty())<x-empty-state title="No projects found." message="Try another status filter or add a project for an available association." />@endif
    <footer class="table-footer"><span>{{ $rows->count() }} {{ $rows->count() === 1 ? 'project' : 'projects' }}</span></footer>
</section>
@if(empty($catalog['options']['association_records']))<p class="notice">No active associations are available for creating projects.</p>@endif
@if(empty($catalog['options']['fisheries_category_records']))<p class="notice">No active fisheries categories are available.</p>@endif
@foreach($rows as $row)
    @if($row['can_mutate'] ?? false)
    <x-modal :id="'project-action-'.$row['id']" :title="$row['archived_at'] ? 'Unarchive Project' : 'Archive Project'">
        <p>{{ $row['archived_at'] ? 'Restore' : 'Archive' }} <strong>{{ $row['title'] }}</strong>? {{ $row['archived_at'] ? 'The project will return to the current project list.' : 'Archived projects remain available for viewing and cannot be edited.' }}</p>
        <form method="POST" action="{{ $row['archived_at'] ? $row['unarchive_url'] : $row['archive_url'] }}">@csrf @method('PATCH')<div class="modal-actions"><button type="button" class="button" data-close-dialog>Cancel</button><button type="submit" class="button {{ $row['archived_at'] ? 'button-primary' : 'button-danger' }}">{{ $row['archived_at'] ? 'Unarchive Project' : 'Archive Project' }}</button></div></form>
    </x-modal>
    @endif
@endforeach
<x-modal id="project-editor" title="Add Project">
    @include('projects.partials.form')
</x-modal>
@endsection
