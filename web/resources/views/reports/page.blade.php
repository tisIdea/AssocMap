@extends($layout)
@php
    $reportFailureMessage = $sizeLimitMessage ?? ($unavailable ? 'Reporting is temporarily unavailable. Please try again later.' : null);
    $globalFeedbackNotification = $reportFailureMessage
        ? ['type' => 'error', 'title' => 'Report Unavailable', 'message' => $reportFailureMessage] : null;
@endphp

@section('content')
    <div data-workspace-module="reports" data-workspace-title="Reports &amp; Analytics">
        @include('admin.reports.index')
    </div>
@endsection
