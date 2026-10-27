@extends($prefix === 'admin' ? 'layouts.admin' : 'layouts.field-officer')
@section('content')
<section class="program-management" data-workspace-module="projects" data-workspace-title="Edit Project">
    <a class="back-link" href="{{ route($prefix.'.projects.show', $project) }}"><x-icon name="back" />Back to Details</a>
<header class="card program-heading"><div><p class="eyebrow">Project Management</p><h1 tabindex="-1">Edit Project</h1><p>{{ $project->title }}</p></div></header>
    @include('projects.partials.cover', ['canManage' => true])
    <section class="card program-information program-editor">
        @include('projects.partials.form', ['projectRoutePrefix' => $prefix.'.projects', 'editRow' => $editRow])
    </section>
</section>
@endsection
