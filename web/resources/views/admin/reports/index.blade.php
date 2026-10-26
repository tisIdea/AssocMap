@php
    $defaultYear = (int) now('Asia/Manila')->format('Y');
    $activeFilterCount = count(array_filter($filters, fn ($value, $key) => $key !== 'report_type'
        && !($key === 'year' && (int) $value === $defaultYear), ARRAY_FILTER_USE_BOTH));
@endphp
<div class="reports-layout" data-report-workspace data-data-url="{{ route($prefix.'.data') }}" data-options-url="{{ route($prefix.'.options') }}">
    <header class="card report-page-header">
        <div class="report-page-heading">
            <p class="eyebrow">Reporting workspace</p>
            <h1 tabindex="-1">Reports &amp; Analytics</h1>
            <p>Review authorized program records, observation coverage and reporting periods.</p>
            <span class="report-access-label"><x-icon name="shield" />{{ match ($role) { 'system_admin' => 'Organizational reporting', 'field_officer' => 'Current assigned associations', default => 'Your association · View only' } }}</span>
        </div>
        <div class="report-toolbar" role="group" aria-label="Report actions">
            <button class="button" type="button" data-report-filter-toggle aria-controls="report-filter-panel" aria-expanded="true" hidden><x-icon name="filter" />Filters<span class="report-filter-count" data-report-filter-count @if(!$activeFilterCount) hidden @endif>{{ $activeFilterCount }}</span><x-icon name="chevron-down" /></button>
            @if($canExport)
                <details class="report-export-menu" data-report-export-menu>
                    <summary class="button button-primary"><x-icon name="download" />Export<x-icon name="chevron-down" /></summary>
                    <form method="POST" data-report-export class="report-export-panel">
                        @csrf
                        <div data-export-filters>@foreach($filters as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach</div>
                        <p class="field-help">Exports use the applied filters and current authorized records. PDF/XLSX contain tables, summaries and notes; chart images are shown on this page.</p>
                        <button type="submit" class="button" formaction="{{ route($prefix.'.export', ['format' => 'pdf']) }}" @disabled(!$result)>Export PDF</button>
                        <button type="submit" class="button" formaction="{{ route($prefix.'.export', ['format' => 'xlsx']) }}" @disabled(!$result)>Export XLSX</button>
                    </form>
                </details>
            @endif
        </div>
    </header>
    <section id="report-filter-panel" class="card report-configuration" aria-labelledby="report-filter-title">
        <div class="section-heading"><span class="stat-icon"><x-icon name="filter" /></span><div><h2 id="report-filter-title">Report filters</h2><p>Set the scope and reporting period</p></div><span class="report-filter-indicator" data-report-filter-status role="status">{{ $activeFilterCount ? $activeFilterCount.' active filter(s)' : 'Default filters' }}</span></div>
        <form data-report-form method="GET" action="{{ route($prefix.'.index') }}">
            <noscript><p>Choose a report type below before setting its filters:</p><ul>@foreach($types as $value => $label)<li><a href="{{ route($prefix.'.index', ['report_type' => $value]) }}">{{ $label }}</a></li>@endforeach</ul></noscript>
            <div class="field"><label for="report-type">Report type</label><select id="report-type" name="report_type" required>
                @foreach($types as $value => $label)<option value="{{ $value }}" @selected($filters['report_type'] === $value)>{{ $label }}</option>@endforeach
            </select></div>
            @if($role !== 'association_account')
                <div class="field"><label for="report-municipality">Municipality/City</label><select id="report-municipality" name="municipality_id"><option value="">All authorized municipalities</option>
                    @foreach($options['municipalities'] as $option)<option value="{{ $option['id'] }}" @selected(($filters['municipality_id'] ?? '') === $option['id'])>{{ $option['name'] }}</option>@endforeach
                </select></div>
                <div class="field"><label for="report-association">Association</label><select id="report-association" name="association_id"><option value="">All authorized associations</option>
                    @foreach($options['associations'] as $option)<option value="{{ $option['id'] }}" data-municipality="{{ $option['municipality_id'] }}" @selected(($filters['association_id'] ?? '') === $option['id'])>{{ $option['name'] }}</option>@endforeach
                </select></div>
            @else
                <p class="field-help">Your current active association determines the reporting scope.</p>
            @endif
            @foreach(['program_year' => 'Program year', 'year' => 'Reporting year', 'quarter' => 'Quarter', 'month' => 'Recorded month', 'project_id' => 'Project', 'product_id' => 'Product', 'component_id' => 'Program component'] as $key => $label)
                <div class="field" data-report-filter="{{ $key }}" @if(!in_array($key, $filterMap[$filters['report_type']], true)) hidden @endif>
                    <label for="report-{{ $key }}">{{ $label }}</label>
                    <select id="report-{{ $key }}" name="{{ $key }}" @disabled(!in_array($key, $filterMap[$filters['report_type']], true)) @required($key === 'year' && in_array($key, $filterMap[$filters['report_type']], true))>
                        @if($key !== 'year')<option value="">All recorded {{ $label === 'Recorded month' ? 'months' : strtolower($label).'s' }}</option>@endif
                        @php
                            $choices = match ($key) {
                                'program_year' => collect($options['program_years'])->map(fn($year) => ['id' => $year, 'name' => $year]),
                                'year' => collect($options['years'])->map(fn($year) => ['id' => $year, 'name' => $year]),
                                'quarter' => collect(range(1, 4))->map(fn($quarter) => ['id' => $quarter, 'name' => 'Q'.$quarter]),
                                'month' => collect(range(1, 12))->map(fn($month) => ['id' => $month, 'name' => DateTime::createFromFormat('!m', (string) $month)->format('F')]),
                                'project_id' => $options['projects'], 'product_id' => $options['products'], default => $options['components'],
                            };
                        @endphp
                        @foreach($choices as $option)
                            @php $option = (array) $option; @endphp
                            <option value="{{ $option['id'] }}" @selected((string)($filters[$key] ?? '') === (string)$option['id'])>{{ $option['name'] }}</option>
                        @endforeach
                    </select>
                </div>
            @endforeach
            <p class="field-help">Reporting periods apply to recorded events. Association/member counts remain current snapshots. Month filters use recorded details only.</p>
            <div class="report-filter-actions">
                <a class="button button-quiet" href="{{ route($prefix.'.index') }}">Reset filters</a>
                <button class="button button-primary" type="submit" data-report-submit>Apply filters</button>
            </div>
        </form>
    </section>
    <div class="report-preview">
        <section class="card report-document">
            <div class="report-document-header"><span class="brand-symbol"><x-assocmap-mark /></span><div><strong>ASSOCMAP</strong><small>Association and project monitoring</small></div><span class="badge badge-neutral">Authorized report</span></div>
            <p data-report-status role="{{ isset($sizeLimitMessage) ? 'alert' : 'status' }}" aria-live="polite"
                @if($unavailable || isset($sizeLimitMessage)) data-feedback-fallback @endif
                @class(['notice-error' => $unavailable || isset($sizeLimitMessage)])>{{ $sizeLimitMessage ?? ($unavailable ? 'Reporting is temporarily unavailable. Please try again later.' : 'Report loaded from authorized records.') }}</p>
            <div data-report-result>
                @if($result)
                    <h2>{{ $result['title'] }}</h2>
                    <section class="report-context-card" id="report-scope" aria-labelledby="report-scope-title">
                        <h3 id="report-scope-title">Reporting scope and period</h3>
                        <p>{{ $result['meta']['scope_basis'] }}</p>
                        <p>Associations: {{ implode('; ', $result['meta']['scope']) ?: 'None in this scope' }}</p>
                        <p class="field-help">Generated {{ $result['meta']['generated_at'] }} · {{ $result['meta']['timezone'] }}</p>
                        <p class="field-help">@foreach($result['meta']['filters'] as $key => $value){{ str_replace('_', ' ', $key) }}: {{ $value }}{{ $loop->last ? '' : ' · ' }}@endforeach</p>
                        <p class="field-help">Current association/member counts and project statuses are snapshots. Program year selects a cohort; reporting year/quarter selects recorded events and monitoring periods.</p>
                    </section>
                    @if($result['empty'])<p class="notice">No recorded results match the selected report and filters.</p>@endif
                    <section class="report-summary" aria-labelledby="report-summary-title">
                        <h3 id="report-summary-title">Summary indicators</h3>
                        <p class="field-help">Recorded values within the applied scope. Unobserved values are not zero.</p>
                        @foreach($result['sections'] as $section)
                            @if($section['measures'])
                                <h4>{{ $section['title'] }}</h4>
                                <dl class="report-measures">@foreach($section['measures'] as $measure)<div><dt>{{ $measure['label'] }}</dt><dd><x-summary-counter :value="$measure['value'] ?? 'Unobserved'" /></dd></div>@endforeach</dl>
                            @endif
                        @endforeach
                    </section>
                    <section class="report-analytics" aria-labelledby="report-analytics-title">
                        <h3 id="report-analytics-title">Visual analytics</h3>
                        <p class="field-help">Enable JavaScript for charts. Summary indicators and detailed data tables remain available. PDF/XLSX contain canonical tables, summaries and notes; chart images are shown on this page.</p>
                    </section>
                    <details class="report-quality" open><summary>Data interpretation and quality</summary>
                        <ul>@foreach($result['notes'] as $note)<li>{{ $note }}</li>@endforeach</ul>
                        @foreach($result['sections'] as $section)
                            @if($section['notes'])<h4>{{ $section['title'] }}</h4><ul>@foreach($section['notes'] as $note)<li>{{ $note }}</li>@endforeach</ul>@endif
                        @endforeach
                    </details>
                    <h3>Detailed data tables</h3>
                    @foreach($result['sections'] as $section)
                        <section class="report-section report-data-section" id="report-table-{{ $section['key'] ?? $loop->index }}" tabindex="-1"><h4>{{ $section['title'] }}</h4>
                            @if($section['rows'])
                                <div class="report-table-scroll" role="region" tabindex="0" aria-label="{{ $section['title'] }}"><table><caption>{{ $section['title'] }}</caption><thead><tr>@foreach($section['columns'] as $column)<th scope="col" @class(['report-numeric' => in_array($column['type'], ['integer', 'decimal'], true)])>{{ $column['label'] }}</th>@endforeach</tr></thead><tbody>
                                    @foreach(array_slice($section['rows'], 0, 100) as $row)<tr>@foreach($section['columns'] as $column)<td @class(['report-numeric' => in_array($column['type'], ['integer', 'decimal'], true)])>{{ $row[$column['key']] ?? 'Unobserved / N/A' }}</td>@endforeach</tr>@endforeach
                                </tbody></table></div>
                                @if(count($section['rows']) > 100)<p>Showing the first 100 rows. Enable JavaScript for all table pages.</p>@endif
                            @else<p class="report-empty">No recorded rows for this section.</p>@endif
                        </section>
                    @endforeach
                @endif
            </div>
        </section>
    </div>
    @php $initialReport = ['result' => $result, 'options' => $options, 'filterMap' => $filterMap, 'defaultYear' => (int) now('Asia/Manila')->format('Y')]; @endphp
    <script type="application/json" data-report-initial>@json($initialReport)</script>
</div>
