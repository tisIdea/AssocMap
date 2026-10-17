@include('partials.page-header', ['title' => 'Reports & Analytics', 'description' => 'Review authorized program records, observation coverage and reporting periods.', 'eyebrow' => 'Reporting workspace'])
<div class="reports-layout" data-report-workspace data-data-url="{{ route($prefix.'.data') }}" data-options-url="{{ route($prefix.'.options') }}">
    <section class="card report-configuration" aria-label="Report filters">
        <div class="section-heading"><span class="stat-icon"><x-icon name="receipt" /></span><div><h2>Configure report</h2><p>Set the scope and reporting period</p></div></div>
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
                <button class="button button-primary button-block" type="submit" data-report-submit>Apply filters</button>
                <a class="button button-quiet button-block" href="{{ route($prefix.'.index') }}">Reset filters</a>
            </div>
        </form>
        @if($canExport)
            <form method="POST" data-report-export>
                @csrf
                <div data-export-filters>@foreach($filters as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach</div>
                <p class="field-help">Exports use the applied filters and current authorized records.</p>
                <div class="format-options">
                    <button type="submit" class="button" formaction="{{ route($prefix.'.export', ['format' => 'pdf']) }}" @disabled(!$result)>Export PDF</button>
                    <button type="submit" class="button" formaction="{{ route($prefix.'.export', ['format' => 'xlsx']) }}" @disabled(!$result)>Export XLSX</button>
                </div>
            </form>
        @endif
    </section>
    <div class="report-preview">
        <section class="card report-document">
            <div class="report-document-header"><span class="brand-symbol"><x-icon name="map" /></span><div><strong>ASSOCMAP</strong><small>BFAR Region VII · SAAD Phase II</small></div><span class="badge badge-neutral">Authorized report</span></div>
            <p data-report-status role="status" aria-live="polite" @if($unavailable) data-feedback-fallback @endif @class(['notice-error' => $unavailable])>{{ $unavailable ? 'Reporting is temporarily unavailable. Please try again later.' : 'Report loaded from authorized records.' }}</p>
            <div data-report-result>
                @if($result)
                    <h2>{{ $result['title'] }}</h2>
                    <p>Generated {{ $result['meta']['generated_at'] }} · {{ $result['meta']['timezone'] }}</p>
                    <p>{{ $result['meta']['scope_basis'] }}</p>
                    @if($result['empty'])<p class="notice">No recorded results match the selected report and filters.</p>@endif
                    <details open><summary>Data quality and interpretation</summary><ul>@foreach($result['notes'] as $note)<li>{{ $note }}</li>@endforeach</ul></details>
                    @foreach($result['sections'] as $section)
                        <section class="report-section"><h3>{{ $section['title'] }}</h3>
                            <dl class="report-measures">@foreach($section['measures'] as $measure)<div><dt>{{ $measure['label'] }}</dt><dd>{{ $measure['value'] ?? 'Unobserved' }}</dd></div>@endforeach</dl>
                            @foreach($section['notes'] as $note)<p class="field-help">{{ $note }}</p>@endforeach
                            @if($section['rows'])
                                <div class="report-table-scroll" tabindex="0" aria-label="{{ $section['title'] }}"><table><caption>{{ $section['title'] }}</caption><thead><tr>@foreach($section['columns'] as $column)<th scope="col">{{ $column['label'] }}</th>@endforeach</tr></thead><tbody>
                                    @foreach(array_slice($section['rows'], 0, 100) as $row)<tr>@foreach($section['columns'] as $column)<td>{{ $row[$column['key']] ?? 'Unobserved / N/A' }}</td>@endforeach</tr>@endforeach
                                </tbody></table></div>
                                @if(count($section['rows']) > 100)<p>Showing the first 100 rows. Enable JavaScript for all table pages.</p>@endif
                            @else<p>No recorded rows for this section.</p>@endif
                        </section>
                    @endforeach
                @endif
            </div>
        </section>
    </div>
    @php $initialReport = ['result' => $result, 'options' => $options, 'filterMap' => $filterMap, 'defaultYear' => (int) now('Asia/Manila')->format('Y')]; @endphp
    <script type="application/json" data-report-initial>@json($initialReport)</script>
</div>
