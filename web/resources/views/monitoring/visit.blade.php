@extends($monitoringLayout)
@section('content')
@include('monitoring.partials.styles')
@include('monitoring.partials.context')
<section class="card" style="padding:20px"><h2>{{ ucfirst($mode) }} Visit Report</h2>
    @if($errors->any())<div class="notice notice-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <form method="POST" action="{{ $report ? route($monitoringPrefix.'.visit.update', [$record, $report->id]) : route($monitoringPrefix.'.visit.store', $record) }}">@csrf @if($report) @method('PATCH') @endif
        <p>New or changed visit dates must fall within Q{{ $record->quarter?->quarter_number }} {{ $record->year }}. Historical reports may retain their unchanged date and conductor.</p>
        <div class="form-grid">
            <div class="field">
                <label for="visit-date">Monitoring Visit Date</label>
                <input id="visit-date" type="date" name="monitoring_date" min="{{ $minVisitDate }}" max="{{ $maxVisitDate }}" value="{{ old('monitoring_date', $report?->monitoring_date?->format('Y-m-d')) }}" @readonly($historicalDate && old('monitoring_date', $historicalDate) === $historicalDate) @if($historicalDate) aria-describedby="visit-date-history" @endif required>
                @if($historicalDate)
                    <p id="visit-date-history" class="field-hint">The saved historical date is outside this quarter. Keep it unchanged to edit other fields, or choose a replacement within {{ $minVisitDate }} to {{ $maxVisitDate }}.</p>
                    <label><input id="visit-change-historical-date" type="checkbox" @checked(old('monitoring_date', $historicalDate) !== $historicalDate)> Change historical visit date</label>
                    <noscript><p class="field-hint">Enable JavaScript to change the historical date. Other fields can still be edited.</p></noscript>
                @endif
            </div>
            <div class="field"><label for="visit-validated">Site Validated</label><select id="visit-validated" name="site_validated" required><option value="1" @selected((string)old('site_validated', (int)($report?->site_validated ?? false)) === '1')>Yes</option><option value="0" @selected((string)old('site_validated', (int)($report?->site_validated ?? false)) === '0')>No</option></select></div>
            <div class="field"><label for="visit-officer">Conducted By — Assigned Field Officer</label><select id="visit-officer" name="conducted_by_user_id"><option value="">Select assigned officer or use another person's name</option>
                @if($report?->conducted_by_user_id && $report->conducted_by_user_id !== $officer?->id)<option value="{{ $report->conducted_by_user_id }}" @selected(old('conducted_by_user_id', $report->conducted_by_user_id) === $report->conducted_by_user_id)>{{ $report->conductedByUser?->display_name ?? 'Historical officer' }} (retain existing)</option>@endif
                @if($officer)<option value="{{ $officer->id }}" @selected(old('conducted_by_user_id', $report?->conducted_by_user_id) === $officer->id)>{{ $officer->display_name }}</option>@endif
            </select></div>
            <div class="field"><label for="visit-conductor">Or Another Person's Name</label><input id="visit-conductor" name="conducted_by" maxlength="255" value="{{ old('conducted_by', $report?->conducted_by) }}"></div>
            @foreach(['issues_encountered' => 'Issues Encountered', 'actions_taken' => 'Actions Taken', 'overall_remarks' => 'Overall Remarks'] as $field => $label)
                <div class="field field-wide"><label for="visit-{{ $field }}">{{ $label }}</label><textarea id="visit-{{ $field }}" name="{{ $field }}" rows="3">{{ old($field, $report?->$field) }}</textarea></div>
            @endforeach
        </div>
        <div class="monitoring-actions"><a class="button" href="{{ route($monitoringPrefix.'.visits', $record) }}">Cancel</a><button class="button button-primary" type="submit">Save Visit Report</button></div>
    </form>
<div class="monitoring-actions"><a class="button" href="{{ route($monitoringPrefix.'.visits', $record) }}">Back to Visit Reports</a></div>
</section>
@if($historicalDate)
<script type="module">
    const dateInput = document.getElementById('visit-date');
    document.getElementById('visit-change-historical-date').addEventListener('change', event => {
        dateInput.readOnly = !event.target.checked;
        if (dateInput.readOnly) dateInput.value = {{ Illuminate\Support\Js::from($historicalDate) }};
        else dateInput.focus();
    });
</script>
@endif
@endsection
