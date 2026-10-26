@props(['prefix', 'type', 'filters' => [], 'scope', 'related' => false, 'association' => null])

@php
    $reportActor = request()->attributes->get('current_user');
    $reportRole = $reportActor?->role?->code;
    $reportTitles = ['associations' => 'Association Distribution Report', 'projects' => 'Project Portfolio Report'];
    $reportVisible = ($prefix === 'admin' && $reportRole === 'system_admin')
        || ($prefix === 'field-officer' && $reportRole === 'field_officer'
            && (!$association || ($association->status === 'active' && !$association->archived_at
                && $association->primary_field_officer_id === $reportActor->id)));
    $reportVisible = $reportVisible && isset($reportTitles[$type])
        && !array_diff(array_keys($filters), \App\Services\Reporting\ReportCatalog::filters($type));
@endphp

@if($reportVisible)
    <div class="contextual-report" data-contextual-report>
        <div class="contextual-report-actions">
            <a class="button" href="{{ route($prefix.'.reports.index', ['report_type' => $type] + $filters) }}"
                aria-label="{{ ($related ? 'Open related ' : 'View ').$reportTitles[$type] }}"><x-icon name="chart" />{{ $related ? 'Open related report' : 'View report' }}</a>
            @unless($related)
                <details class="contextual-report-menu">
                    <summary class="button"><x-icon name="download" />Export {{ $reportTitles[$type] }}<x-icon name="chevron-down" /></summary>
                    <form method="POST" action="{{ route($prefix.'.reports.export', ['format' => 'pdf']) }}" class="contextual-report-panel" data-contextual-report-export>
                        @csrf
                        <input type="hidden" name="report_type" value="{{ $type }}">
                        @foreach($filters as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
                        @foreach(['pdf' => 'PDF', 'xlsx' => 'XLSX'] as $format => $formatLabel)
                            <button type="submit" class="button" formaction="{{ route($prefix.'.reports.export', ['format' => $format]) }}"
                                aria-label="Export {{ $reportTitles[$type] }} as {{ $formatLabel }}">Export {{ $formatLabel }}</button>
                        @endforeach
                        <p class="field-help" data-report-download-status role="status" aria-live="polite"></p>
                    </form>
                </details>
            @endunless
        </div>
        <p class="contextual-report-scope"><strong>{{ $reportTitles[$type] }}.</strong> {{ $scope }}</p>
    </div>
@endif
