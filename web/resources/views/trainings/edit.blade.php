@extends($prefix === 'admin' ? 'layouts.admin' : 'layouts.field-officer')
@section('content')
<section class="program-management" data-workspace-module="trainings" data-workspace-title="Edit Training">
    <a class="back-link" href="{{ route($prefix.'.trainings.show', $row['id']) }}"><x-icon name="back" />Back to Details</a>
<header class="card program-heading"><div><p class="eyebrow">Training Management</p><h1 tabindex="-1">Edit Training</h1><p>{{ $training->title }}</p></div></header>
    <section class="card program-information program-editor">
        @include('trainings.partials.form', ['trainingPrefix' => $prefix.'.trainings', 'editRow' => $row])
    </section>
</section>
@endsection
