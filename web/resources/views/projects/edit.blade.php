@extends($prefix === 'admin' ? 'layouts.admin' : 'layouts.field-officer')
@section('content')
<section data-workspace-module="projects" data-workspace-title="Edit Project">
    <a class="back-link" href="{{ route($prefix.'.projects.show', $project) }}"><x-icon name="back" />Back to Details</a>
    @include('partials.page-header', ['title' => 'Edit Project', 'description' => $project->title, 'eyebrow' => 'Program Operations'])
    <section class="card form-section">
        @include('projects.partials.form', ['projectRoutePrefix' => $prefix.'.projects', 'editRow' => $editRow])
    </section>
</section>
@endsection
