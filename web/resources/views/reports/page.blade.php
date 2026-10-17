@extends($layout)
@php($globalFeedbackNotification = $unavailable ? ['type' => 'error', 'title' => 'Report Unavailable', 'message' => 'Reporting is temporarily unavailable. Please try again later.'] : null)

@section('content')
    <div data-workspace-module="reports" data-workspace-title="Reports &amp; Analytics">
        @include('admin.reports.index')
    </div>
@endsection
