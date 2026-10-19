@extends($prefix === 'admin' ? 'layouts.admin' : 'layouts.field-officer')
@section('content')
<section class="program-management activity-management" data-workspace-module="program-activities" data-workspace-title="Edit Program Activity">
    <a class="back-link" href="{{ route($prefix.'.program-activities.show', $row['id']) }}"><x-icon name="back" />Back to Details</a>
    <header class="card program-heading"><div><p class="eyebrow">Program Operations</p><h1 tabindex="-1">Edit Program Activity</h1><p>{{ $row['title'] }}</p></div></header>
    <section class="card program-information program-editor" aria-label="Program activity editor">
        @include('program-activities.partials.form', ['activityPrefix' => $prefix.'.program-activities', 'editRow' => $row])
    </section>
</section>
@endsection
