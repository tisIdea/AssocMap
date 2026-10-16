@extends($monitoringLayout)
@section('content')
@include('monitoring.partials.styles')
<div data-workspace-module="monitoring" data-workspace-title="Monitoring">
@include('monitoring.partials.context')
@if(session('success'))<p class="notice notice-success" role="status">{{ session('success') }}</p>@endif
<section class="card" style="padding:20px"><h2>{{ $section === 'overview' ? 'Quarterly Notes' : ucfirst($section) }}</h2>
@include('monitoring.partials.project-shortcuts')
@if($canManage && $section !== 'overview')<div class="monitoring-actions"><a class="button" href="{{ route($monitoringPrefix.'.section.edit', [$record, $section]) }}">Manage {{ ucfirst($section) }}</a></div>@endif
@if($section === 'overview')
    @include('monitoring.partials.details', ['details' => ['Issues Encountered (quarterly legacy)' => $record->issues_encountered, 'Actions Taken (quarterly legacy)' => $record->actions_taken, 'Overall Remarks (quarterly legacy)' => $record->overall_remarks]])
@elseif($section === 'production')
    @forelse($record->production as $production)
        <article class="monitoring-card"><h3>{{ $production->projectProduct?->product_name ?? 'Product unavailable' }}</h3>
        @include('monitoring.partials.details', ['details' => ['Product Type' => $production->projectProduct?->product_type, 'Target Output' => $production->target_output, 'Actual Output' => $production->actual_output, 'Unit' => $production->unit, 'Remarks' => $production->remarks]])
        @php($production->setRelation('monitoringRecord', $record))
        @php($monthlyReconciled = app(\App\Services\ProductionMonitoringService::class)->canEditMonthly($production))
        <h4>Monthly Production</h4>
        @if($monthlyReconciled)<p>Quarterly Actual Output equals the three recorded monthly quantities. These values represent the same output and must be counted once.</p>
        @else<p class="notice">{{ $production->monthlyDetails->isEmpty() ? 'Legacy quarterly-only record.' : 'Legacy or unreconciled monthly details.' }} The recorded Actual Output is preserved. Monthly conversion requires a separate decision.</p>@endif
        @forelse($production->monthlyDetails as $monthly)
            <article class="monitoring-card"><h4>{{ DateTime::createFromFormat('!m', (string)$monthly->month)->format('F') }} {{ $record->year }}</h4>
            @include('monitoring.partials.details', ['details' => ['Production Activities' => $monthly->activity_count, 'Total Quantity' => $monthly->total_quantity, 'Remarks' => $monthly->remarks]])</article>
        @empty<p class="monitoring-empty">Monthly quantities were not recorded; no monthly values are inferred.</p>@endforelse
        </article>
    @empty<p class="monitoring-empty">No Production entries.</p>@endforelse
@elseif($section === 'income')
    @forelse($record->production as $production)
        <article class="monitoring-card"><h3>{{ $production->projectProduct?->product_name ?? 'Product unavailable' }}</h3>
        @forelse($production->income as $income)
            @include('monitoring.partials.details', ['details' => ['Period' => ucfirst($income->period_type), 'Month' => $income->month ? DateTime::createFromFormat('!m', (string)$income->month)->format('F').' '.$record->year : null, 'Gross Income' => $income->gross_income, 'Remarks' => $income->remarks]])
        @empty<p class="monitoring-empty">No Income entries for this product.</p>@endforelse</article>
    @empty<p class="monitoring-empty">Save Production before recording Income.</p>@endforelse
@else
    @forelse($record->materials as $material)
        <article class="monitoring-card"><h3>{{ $material->projectMaterial?->item_name ?? 'Material unavailable' }}</h3>
        @include('monitoring.partials.details', ['details' => ['Condition' => $material->condition, 'Scheduled Maintenance' => $material->scheduled_maintenance?->format('M d, Y'), 'Actual Maintenance' => $material->actual_maintenance?->format('M d, Y'), 'Remarks' => $material->remarks]])</article>
    @empty<p class="monitoring-empty">No Materials observations.</p>@endforelse
@endif
</section></div>
@endsection
