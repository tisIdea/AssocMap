@extends($prefix === 'admin' ? 'layouts.admin' : 'layouts.field-officer')
@section('content')
<section data-workspace-module="trainings" data-workspace-title="Edit Training">
    <a class="back-link" href="{{ route($prefix.'.trainings.show', $row['id']) }}"><x-icon name="back" />Back to Details</a>
    @include('partials.page-header', ['title' => 'Edit Training', 'description' => $row['title'], 'eyebrow' => 'Program Operations'])
    <section class="card form-section">
        @include('trainings.partials.form', ['trainingPrefix' => $prefix.'.trainings', 'editRow' => $row])
    </section>
</section>
@endsection
