@extends($monitoringLayout)
@php
    $visitErrors = $errors->getBag('monitoringVisit');
    $restoreVisit = $visitErrors->any();
    $visitOld = function ($key, $default = null) use ($restoreVisit) {
        $value = $restoreVisit ? old($key, $default) : $default;
        return is_scalar($value) || $value === null ? $value : $default;
    };
    $selectedOfficer = $visitOld('conducted_by_user_id', $report?->conducted_by_user_id);
@endphp
@section('content')
@include('monitoring.partials.styles')
@include('monitoring.partials.context')
<section class="card" style="padding:20px"><h2>{{ ucfirst($mode) }} Visit Report</h2>
    <form method="POST" action="{{ $report ? route($monitoringPrefix.'.visit.update', [$record, $report->id]) : route($monitoringPrefix.'.visit.store', $record) }}" data-visit-report-form data-feedback-error-bag="monitoringVisit" data-saved-conductor="{{ json_encode($report ? ['id' => $report->conducted_by_user_id, 'name' => $report->conducted_by] : null) }}" data-historical-date="{{ $historicalDate }}">@csrf @if($report) @method('PATCH') @endif
        <input type="hidden" name="monitoring_form" value="visit">
        @if($visitErrors->any())<div class="notice notice-error" role="alert" data-visit-validation tabindex="-1">@foreach($visitErrors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        <p>New or changed visit dates must fall within Q{{ $record->quarter?->quarter_number }} {{ $record->year }}. Historical reports may retain their unchanged date and conductor.</p>
        <div class="form-grid">
            <div class="field">
                <label for="visit-date">Monitoring Visit Date</label>
                <input id="visit-date" type="date" name="monitoring_date" min="{{ $minVisitDate }}" max="{{ $maxVisitDate }}" value="{{ $visitOld('monitoring_date', $report?->monitoring_date?->format('Y-m-d')) }}" @readonly($historicalDate && $visitOld('monitoring_date', $historicalDate) === $historicalDate) @if($historicalDate) aria-describedby="visit-date-history" @endif required>
                @if($historicalDate)
                    <p id="visit-date-history" class="field-hint">The saved historical date is outside this quarter. Keep it unchanged to edit other fields, or choose a replacement within {{ $minVisitDate }} to {{ $maxVisitDate }}.</p>
                    <label><input id="visit-change-historical-date" type="checkbox" @checked($visitOld('monitoring_date', $historicalDate) !== $historicalDate)> Change historical visit date</label>
                    <noscript><p class="field-hint">Enable JavaScript to change the historical date. Other fields can still be edited.</p></noscript>
                @endif
            </div>
            <div class="field"><label for="visit-validated">Site Validated</label><select id="visit-validated" name="site_validated" required><option value="1" @selected((string)$visitOld('site_validated', (int)($report?->site_validated ?? false)) === '1')>Yes</option><option value="0" @selected((string)$visitOld('site_validated', (int)($report?->site_validated ?? false)) === '0')>No</option></select></div>
            <div class="field"><label for="visit-officer">Conducted By — Assigned Field Officer</label><select id="visit-officer" name="conducted_by_user_id" aria-describedby="visit-conductor-help visit-conductor-error"><option value="">Select assigned officer or use another person's name</option>
                @if($selectedOfficer && !in_array($selectedOfficer, [$report?->conducted_by_user_id, $officer?->id], true))<option value="{{ $selectedOfficer }}" selected>Unavailable submitted officer — choose again</option>@endif
                @if($report?->conducted_by_user_id && $report->conducted_by_user_id !== $officer?->id)<option value="{{ $report->conducted_by_user_id }}" @selected($selectedOfficer === $report->conducted_by_user_id)>{{ $report->conductedByUser?->display_name ?? 'Historical officer' }} (retain existing)</option>@endif
                @if($officer)<option value="{{ $officer->id }}" @selected($selectedOfficer === $officer->id)>{{ $officer->display_name }}</option>@endif
            </select></div>
            <div class="field"><label for="visit-conductor">Or Another Person's Name</label><input id="visit-conductor" name="conducted_by" maxlength="255" value="{{ $visitOld('conducted_by', $report?->conducted_by) }}" aria-describedby="visit-conductor-help visit-conductor-error"></div>
            <p class="field-hint field-wide" id="visit-conductor-help">Choose exactly one: the assigned Field Officer or another person's name. Clear the other option yourself to keep the intended attribution. Unchanged historical attribution may be retained.</p>
            <p class="field-error field-wide" id="visit-conductor-error" data-visit-conductor-error aria-live="polite" hidden></p>
            @foreach(['issues_encountered' => 'Issues Encountered', 'actions_taken' => 'Actions Taken', 'overall_remarks' => 'Overall Remarks'] as $field => $label)
                <div class="field field-wide"><label for="visit-{{ $field }}">{{ $label }}</label><textarea id="visit-{{ $field }}" name="{{ $field }}" rows="3">{{ $visitOld($field, $report?->$field) }}</textarea></div>
            @endforeach
        </div>
        <div class="monitoring-actions"><a class="button" href="{{ route($monitoringPrefix.'.visits', $record) }}">Cancel</a><button class="button button-primary" type="submit">Save Visit Report</button></div>
        <p role="status" data-visit-submit-status></p>
    </form>
<div class="monitoring-actions"><a class="button" href="{{ route($monitoringPrefix.'.visits', $record) }}">Back to Visit Reports</a></div>
</section>
@include('monitoring.partials.visit-script')
@endsection
