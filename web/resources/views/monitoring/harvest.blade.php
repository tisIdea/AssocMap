@extends($monitoringLayout)
@section('content')
<div class="monitoring-management" data-workspace-module="monitoring" data-workspace-title="Monitoring">
    @include('monitoring.partials.context')
    <section class="card monitoring-section" aria-labelledby="harvest-heading">
        <div class="monitoring-section-heading">
            <div><h2 id="harvest-heading">Daily Harvest: {{ $production->projectProduct?->product_name }}</h2>
                <p>Q{{ $record->quarter->quarter_number }} {{ $record->year }} · {{ $production->unit }} · {{ $ongoing ? 'Recorded to date' : 'Recorded harvests' }}</p></div>
            <a class="button" href="{{ route($monitoringPrefix.'.workspace', [$record, 'production']) }}">Back to Production</a>
        </div>
        <div class="monitoring-section-body">
            @if($errors->any())<div class="notice notice-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
            <article class="monitoring-card">
                <h3>Recorded Production Summary</h3>
                @include('monitoring.partials.details', ['details' => [
                    'Quarterly recorded quantity' => $activeCount > 0 ? $production->actual_output.' '.$production->unit : 'No harvest records submitted',
                    'Quarterly target' => $production->target_output === null ? 'Not set' : $production->target_output.' '.$production->unit,
                    'Goal attainment' => $attainment !== null ? $attainment.'%' : ($activeCount === 0 ? 'No harvest records submitted' : 'Not applicable: target missing or zero'),
                ]])
                <div class="table-scroll" role="region" aria-label="Monthly harvest summary" tabindex="0">
                    <table class="data-table monitoring-detail-table"><thead><tr><th scope="col">Month</th><th scope="col">Harvesting days</th><th scope="col">Recorded quantity ({{ $production->unit }})</th></tr></thead><tbody>
                        @foreach($production->monthlyDetails as $month)<tr>
                            <th scope="row">{{ DateTime::createFromFormat('!m', (string) $month->month)->format('F') }} {{ $record->year }}</th>
                            <td>{{ $month->activity_count > 0 ? $month->activity_count : 'No harvest records' }}</td>
                            <td>{{ $month->activity_count > 0 ? $month->total_quantity : 'No harvest records' }}</td>
                        </tr>@endforeach
                    </tbody></table>
                </div>
                <p class="field-hint">Only active harvest entries contribute to these totals. A month without entries does not confirm zero production. Monthly quantities and quarterly actual output represent the same production.</p>
            </article>
            @if($canManage)
                <article class="monitoring-card">
                    <h3>{{ $editing ? 'Edit Harvest' : 'Add Daily Harvest' }}</h3>
                    <p class="notice">Only one active entry is allowed per product and date. Combine all harvests of this product on that date into one quantity.</p>
                    <form method="POST" action="{{ $editing ? route($monitoringPrefix.'.harvest.update', [$record, $production, $editing]) : route($monitoringPrefix.'.harvest.store', [$record, $production]) }}">
                        @csrf @if($editing) @method('PATCH') @endif
                        <input type="hidden" name="production_revision" value="{{ $productionRevision }}">
                        <div class="form-grid">
                            <div class="field"><label for="harvest-date">Harvest date</label><input id="harvest-date" name="harvest_date" type="date" min="{{ $minDate }}" max="{{ $maxDate }}" value="{{ old('harvest_date', $editing?->harvest_date?->format('Y-m-d')) }}" required></div>
                            <div class="field"><label for="harvest-quantity">Quantity ({{ $production->unit }})</label><input id="harvest-quantity" name="quantity" type="number" step="0.01" min="0.01" max="999999999999.99" value="{{ old('quantity', $editing?->quantity) }}" required></div>
                            <div class="field"><label for="harvest-remarks">Remarks (optional)</label><textarea id="harvest-remarks" name="remarks" maxlength="2000">{{ old('remarks', $editing?->remarks) }}</textarea></div>
                        </div>
                        <div class="monitoring-actions">
                            @if($editing)<a class="button" href="{{ route($monitoringPrefix.'.harvest.index', [$record, $production]) }}">Cancel Edit</a>@endif
                            <button class="button button-primary" type="submit">{{ $editing ? 'Save Correction' : 'Save Harvest' }}</button>
                        </div>
                    </form>
                </article>
                <article class="monitoring-card">
                    <h3>Quarterly Goal</h3>
                    <form method="POST" action="{{ route($monitoringPrefix.'.harvest.goal', [$record, $production]) }}">
                        @csrf @method('PATCH')
                        <input type="hidden" name="production_revision" value="{{ $productionRevision }}">
                        <div class="form-grid">
                            <div class="field"><label for="harvest-goal">Target ({{ $production->unit }})</label><input id="harvest-goal" type="number" name="target_output" min="0" step="0.01" max="999999999999.99" value="{{ old('target_output', $production->target_output) }}" required></div>
                            <div class="field"><label for="goal-reason">Reason for setting or revising the goal</label><textarea id="goal-reason" name="reason" maxlength="2000" required>{{ old('reason') }}</textarea></div>
                        </div>
                        <div class="monitoring-actions"><button class="button button-primary" type="submit">Save Quarterly Goal</button></div>
                    </form>
                </article>
            @endif
            <article class="monitoring-card">
                <h3>Harvest Records and Void History</h3>
                <p class="field-hint">Entries are ordered by harvest date. Voided entries remain visible and are excluded from totals.</p>
                <div class="table-scroll" role="region" aria-label="Harvest records" tabindex="0">
                    <table class="data-table monitoring-detail-table"><thead><tr><th scope="col">Date</th><th scope="col">Quantity ({{ $production->unit }})</th><th scope="col">Remarks</th><th scope="col">Status</th>@if($canManage)<th scope="col">Actions</th>@endif</tr></thead><tbody>
                        @forelse($entries as $entry)<tr>
                            <th scope="row">{{ $entry->harvest_date->format('M d, Y') }}</th><td>{{ $entry->quantity }}</td><td class="monitoring-text">{{ $entry->remarks ?? 'Not recorded' }}</td>
                            <td>@if($entry->voided_at)Voided {{ $entry->voided_at->setTimezone('Asia/Manila')->format('M d, Y H:i') }} PHT<p class="monitoring-text">{{ $entry->void_reason }}</p>@else Active @endif</td>
                            @if($canManage)<td>@if(!$entry->voided_at)
                                <a class="button" href="{{ route($monitoringPrefix.'.harvest.edit', [$record, $production, $entry]) }}">Edit</a>
                                <details><summary>Void entry</summary>
                                    <form method="POST" action="{{ route($monitoringPrefix.'.harvest.void', [$record, $production, $entry]) }}">
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="production_revision" value="{{ $productionRevision }}">
                                        <div class="field"><label for="void-{{ $entry->id }}">Reason for voiding</label><textarea id="void-{{ $entry->id }}" name="void_reason" maxlength="2000" required>{{ old('void_reason') }}</textarea></div>
                                        <button class="button" type="submit">Void Harvest</button>
                                    </form>
                                </details>
                            @else No actions @endif</td>@endif
                        </tr>@empty<tr><td colspan="{{ $canManage ? 5 : 4 }}">No harvest records submitted.</td></tr>@endforelse
                    </tbody></table>
                </div>
                {{ $entries->links() }}
            </article>
        </div>
    </section>
</div>
@endsection
