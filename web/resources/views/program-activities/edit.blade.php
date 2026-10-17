@extends($prefix === 'admin' ? 'layouts.admin' : 'layouts.field-officer')
@section('content')
<section data-workspace-module="program-activities" data-workspace-title="Edit Program Activity">
    <a class="back-link" href="{{ route($prefix.'.program-activities.show', $row['id']) }}"><x-icon name="back" />Back to Details</a>
    @include('partials.page-header', ['title' => 'Edit Program Activity', 'description' => $row['title'], 'eyebrow' => 'Program Operations'])
    <section class="card form-section">
        @include('program-activities.partials.form', ['activityPrefix' => $prefix.'.program-activities', 'editRow' => $row])
    </section>
</section>
@endsection
