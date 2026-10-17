@extends($monitoringLayout)
@section('content')
@include('monitoring.partials.styles')
@include('monitoring.partials.context', ['section' => 'overview'])
<section class="card" style="padding:20px"><h2>Edit Quarterly Overview</h2>
@if($errors->any())<div class="notice notice-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<form method="POST" action="{{ route($monitoringPrefix.'.overview.update', $record) }}" data-feedback-error-bag="default">@csrf @method('PATCH')
    <div class="form-grid">
        <div class="field"><label for="monitored-by">Monitored By</label><select id="monitored-by" name="monitored_by">
            @if(!$record->monitored_by)<option value="" @selected(!old('monitored_by', $record->monitored_by))>Not recorded (keep historical attribution)</option>
            @elseif(!$users->contains('id', $record->monitored_by))<option value="{{ $record->monitored_by }}" @selected(old('monitored_by', $record->monitored_by) === $record->monitored_by)>{{ $record->monitoredBy?->display_name ?? 'Historical attribution' }} (retain existing)</option>@endif
            @foreach($users->sortBy('display_name') as $user)<option value="{{ $user->id }}" @selected(old('monitored_by', $record->monitored_by) === $user->id)>{{ $user->display_name }}</option>@endforeach
        </select></div>
        <div class="field"><label for="site-validated">Site Validated</label><select id="site-validated" name="site_validated" required><option value="1" @selected((string)old('site_validated', (int)$record->site_validated) === '1')>Yes</option><option value="0" @selected((string)old('site_validated', (int)$record->site_validated) === '0')>No</option></select></div>
    </div>
    <div class="monitoring-actions"><a class="button" href="{{ route($monitoringPrefix.'.workspace', $record) }}">Cancel</a><button class="button button-primary" type="submit">Save Overview</button></div>
</form></section>
@endsection
