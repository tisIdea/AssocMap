
@php
    $monitoringPrefix = $monitoringPrefix ?? 'admin.monitoring';
    $rows = collect($monitoringRows)->map(fn ($row) => array_merge($row, ['update_url' => $section ? route($monitoringPrefix.'.section.update', [$row['id'], $section]) : route($monitoringPrefix.'.update', $row['id'])]));
    $statuses = ['planned' => 'Planned', 'ongoing' => 'Ongoing', 'completed' => 'Completed', 'suspended' => 'Suspended', 'cancelled' => 'Cancelled'];
    $months = [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    $restore = $errors->any() && old('monitoring_form') === 'editor';
    $projectChoices = $projects->values()->map(fn ($project, $index) => [
        'value' => $project->id,
        'label' => $project->title.' — '.($project->association?->name ?? 'Association unavailable').' · Program Year '.$project->program_year,
        'position' => $index + 1,
    ]);
    $projectLabelCounts = $projectChoices->countBy('label');
    $fields = [
        ['project_id', 'Project', 'select', $projectChoices->map(fn ($choice) => ['value' => $choice['value'], 'label' => $choice['label'].($projectLabelCounts[$choice['label']] > 1 ? ' · Option '.$choice['position'] : '')])->all()],
        ['year', 'Monitoring Year', 'number'],
        ['quarter_id', 'Quarter', 'select', $quarters->map(fn ($quarter) => ['value' => $quarter->id, 'label' => 'Q'.$quarter->quarter_number])->all()],
        ['monitoring_date', 'Monitoring Date', 'date'],
        ['site_validated', 'Site Validated', 'select', [['value' => '1', 'label' => 'Yes'], ['value' => '0', 'label' => 'No']]],
        ['project_status', 'Status During Monitoring', 'select', collect($statuses)->map(fn ($label, $value) => compact('value', 'label'))->all()],
        ['issues_encountered', 'Issues Encountered', 'textarea'],
        ['actions_taken', 'Actions Taken', 'textarea'],
        ['overall_remarks', 'Overall Remarks', 'textarea'],
    ];
    $registrationFields = [
        'production' => [
            ['product_name', 'Product Name', 'text', true],
            ['product_type', 'Product Type', 'select', true, ['raw' => 'Raw', 'processed' => 'Processed', 'other' => 'Other']],
            ['default_unit', 'Default Unit', 'text', false],
            ['description', 'Product Description', 'textarea', false],
        ],
        'materials' => [
            ['item_name', 'Material Name', 'text', true],
            ['quantity_planned', 'Planned Quantity', 'number', true],
            ['quantity_delivered', 'Delivered Quantity', 'number', false],
            ['unit', 'Material Unit', 'text', true],
            ['unit_cost', 'Unit Cost', 'number', false],
            ['delivery_status', 'Delivery Status', 'select', true, ['planned' => 'Planned', 'not_delivered' => 'Not Delivered', 'partially_delivered' => 'Partially Delivered', 'delivered' => 'Delivered']],
            ['delivery_date', 'Delivery Date', 'date', false],
            ['remarks', 'Registration Remarks', 'textarea', false],
        ],
    ];
    $repeatFields = [
        'production' => [['project_product_id', 'Project Product', 'select', true], ['target_output', 'Target Output', 'number', true], ['unit', 'Unit of Measurement', 'text', true], ['remarks', 'Remarks', 'textarea', false]],
        'income' => [['period_type', 'Period Type', 'select', true], ['month', 'Month', 'select', false], ['gross_income', 'Gross Income', 'number', true], ['remarks', 'Remarks', 'textarea', false]],
        'materials' => [['project_material_id', 'Project Material', 'select', true], ['condition', 'Condition', 'text', true], ['scheduled_maintenance', 'Scheduled Maintenance', 'date', false], ['actual_maintenance', 'Actual Maintenance', 'date', false], ['remarks', 'Remarks', 'textarea', false]],
    ];
@endphp
@extends($monitoringLayout)
@section('content')
<style>
    #monitoring-records, .monitoring-workspace { min-width: 0; }
    .monitoring-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr)); gap: 18px; padding: 18px 0; }
    #monitoring-records { padding: 20px; }
    .monitoring-project-group { margin-bottom: 24px; }
    .monitoring-project-heading { margin-bottom: 16px; }
    .monitoring-project-heading h2, .monitoring-project-heading p { overflow-wrap: anywhere; }
    .monitoring-quarter-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; }
    .monitoring-quarter { min-width: 0; }
    .monitoring-quarter > h3 { margin-bottom: 12px; }
    .monitoring-quarter .monitoring-card { margin-bottom: 12px; padding: 16px; }
    .monitoring-quarter .detail-grid { grid-template-columns: minmax(0, 1fr); }
    .monitoring-quarter .monitoring-card-actions { flex-wrap: wrap; }
    .monitoring-project-group[hidden], .monitoring-quarter-grid [hidden] { display: none; }
    .monitoring-card { border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 20px; background: var(--card-bg); min-width: 0; }
    .monitoring-card h2, .monitoring-card h3, .monitoring-card p { overflow-wrap: anywhere; }
    .monitoring-card h4 { margin: 0 0 12px; }
    .monitoring-card .detail-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; margin: 18px 0 0; }
    .monitoring-card-heading { display: flex; flex-wrap: wrap; align-items: start; justify-content: space-between; gap: 12px; }
    .monitoring-card-heading h2 { flex: 1 1 170px; }
    .monitoring-card > p { margin-top: 6px; }
    .monitoring-card-actions { display: flex; gap: 10px; margin-top: 20px; }
    .modal:has(.monitoring-workspace) { width: min(1000px, calc(100% - 30px)); }
    .monitoring-context { margin-bottom: 18px; border-left: 4px solid var(--primary); }
    .monitoring-context h2 { font-size: 22px; margin-top: 4px; }
    .monitoring-tabs { display: flex; gap: 6px; overflow-x: auto; border-bottom: 1px solid var(--border-color); padding: 4px 0 12px; margin-bottom: 20px; }
    .monitoring-tabs button { flex-shrink: 0; border: 1px solid var(--border-color); background: var(--card-bg); border-radius: var(--radius-sm); padding: 10px 16px; }
    .monitoring-tabs button[aria-selected="true"] { background: var(--primary); border-color: var(--primary); color: white; }
    .monitoring-workspace .form-section { padding: 0; }
    #editor-panel-overview .field-wide { border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 16px; }
    .monitoring-editor-cards { display: grid; gap: 18px; margin: 18px 0; }
    .monitoring-editor-cards > .monitoring-card { margin: 0; }
    .monitoring-income-group { margin: 20px 0; }
    .monitoring-empty { padding: 20px; border: 1px dashed var(--border-color); border-radius: var(--radius-sm); }
    .monitoring-text { white-space: pre-wrap; overflow-wrap: anywhere; }
    #monitoring-form .monitoring-editor-section { margin: 18px 0; padding-bottom: 18px; border-bottom: 1px solid var(--border-color); }
    #monitoring-form .monitoring-period-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    #monitoring-form .monitoring-context { margin: 14px 0 0; padding: 16px; }
    #monitoring-form .monitoring-context h2 { font-size: 18px; }
    #monitoring-form .monitoring-card { padding: 16px; }
    #monitoring-form .monitoring-income-group { margin: 14px 0; }
    #monitoring-form .monitoring-income-group h3 { margin-bottom: 12px; }
    #monitoring-form .monitoring-tabs button:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
    #monitoring-form [data-monitoring-panel][hidden] { display: none; }
    #monitoring-form .monitoring-frequency { margin: 12px 0; }
    @media (max-width: 1100px) {
        .monitoring-quarter-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 640px) {
        .monitoring-quarter-grid { grid-template-columns: minmax(0, 1fr); }
        #monitoring-form .monitoring-period-grid { grid-template-columns: minmax(0, 1fr); }
        .monitoring-workspace .form-grid { grid-template-columns: minmax(0, 1fr); }
        .monitoring-workspace .field-wide { grid-column: auto; }
        .monitoring-card { padding: 16px; }
        .modal:has(.monitoring-workspace) { padding: 16px; }
        .monitoring-card .detail-grid { grid-template-columns: minmax(0, 1fr); }
    }
</style>
@include('monitoring.partials.styles')
<div data-workspace-module="monitoring" data-workspace-title="Monitoring">
<noscript><p class="notice">Enable JavaScript to populate and edit this Monitoring form.</p></noscript>
@if($record)
    @include('monitoring.partials.context')
    @include('monitoring.partials.project-shortcuts')
@else
    <header class="page-header"><div><h1>Add Monitoring Record</h1></div></header>
    <a class="button" href="{{ route($monitoringPrefix.'.index') }}">Dashboard</a>
@endif
<section class="card" id="monitoring-editor" style="padding:20px"><h2 id="monitoring-editor-title" tabindex="-1">Monitoring</h2>
    <form method="POST" action="{{ route($monitoringPrefix.'.store') }}" id="monitoring-form" class="monitoring-workspace" data-monitoring-workspace @if($restore) data-feedback-error-bag="default" @endif novalidate>
        @csrf
        <input type="hidden" name="monitoring_form" value="editor"><input type="hidden" name="monitoring_record_id"><input type="hidden" name="_method" value="PATCH" disabled>
        @if($record)
            @php($productionRevision = $restore ? old('production_revision') : $rows->first()['production_revision'])
            <input type="hidden" name="production_revision" value="{{ is_string($productionRevision) ? $productionRevision : '' }}">
            @if($section === 'income')
                @php($incomeRevision = $restore ? old('income_revision') : $rows->first()['income_revision'])
                <input type="hidden" name="income_revision" value="{{ is_string($incomeRevision) ? $incomeRevision : '' }}">
                <div class="notice notice-error" data-income-feedback role="alert" tabindex="-1" hidden>
                    <p data-income-message></p><ul data-income-errors></ul>
                    <a href="{{ route($monitoringPrefix.'.section.edit', [$record, 'income']) }}" target="_blank" rel="noopener">Open latest saved Income in a new tab</a>
                    <p>Your unsaved entries remain in this form. Review and copy them before choosing to leave or reload.</p>
                </div>
            @endif
            @if($section === 'materials')
                @php($materialsRevision = $restore ? old('materials_revision') : $rows->first()['materials_revision'])
                <input type="hidden" name="materials_revision" value="{{ is_string($materialsRevision) ? $materialsRevision : '' }}">
                <div class="notice notice-error" data-materials-feedback role="alert" tabindex="-1" hidden>
                    <p data-materials-message></p><ul data-materials-errors></ul>
                    <a href="{{ route($monitoringPrefix.'.section.edit', [$record, 'materials']) }}" target="_blank" rel="noopener noreferrer">Open latest saved Materials in a new tab</a>
                    <p>Your entries and removal selections remain in this form. Copy anything you need before leaving or refreshing.</p>
                </div>
            @endif
        @endif
        <input type="hidden" name="production" value=""><input type="hidden" name="materials" value="">
        <p class="field-hint">Fields marked * are required. {{ $section ? 'Only this section is saved. Save or cancel before navigating to another section.' : 'All creation tabs are saved together.' }}</p>
        @if($restore)<div class="notice notice-error" role="alert" data-monitoring-errors tabindex="-1">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        <p class="notice" id="monitoring-choice-notice" role="status" hidden></p>
        <section class="monitoring-editor-section" aria-labelledby="monitoring-project-heading" @if($record) hidden @endif>
            <div class="section-heading"><h3 id="monitoring-project-heading">1. Select Project</h3></div>
            <div class="field"><label for="monitoring-project_id">Project <span class="field-hint">*</span></label>
                <select name="project_id" id="monitoring-project_id" required aria-describedby="monitoring-project-help"><option value="">Select a project…</option>@foreach($fields[0][3] as $choice)<option value="{{ $choice['value'] }}">{{ $choice['label'] }}</option>@endforeach</select>
                <p class="field-hint" id="monitoring-project-help">The association, products and materials are determined by your selected project.</p>
            </div>
            @if($projects->isEmpty())<p class="monitoring-empty">No eligible projects are available. Contact your system administrator about project availability{{ $monitoringPrefix === 'field-officer.monitoring' ? ' or your association assignments' : '' }}.</p>@endif
            <section class="monitoring-context monitoring-card" aria-label="Selected project summary" aria-live="polite">
                <div class="eyebrow">Association</div><h2 data-context-association>Select a project to see its association</h2><p data-context-project>No project selected</p><p data-context-period></p>
                <dl class="detail-grid">@foreach(['program-year' => 'Project Program Year', 'status' => 'Status During Monitoring', 'validated' => 'Site Validated', 'officer' => 'Monitored By'] as $key => $label)<div><dt>{{ $label }}</dt><dd data-context-{{ $key }}>—</dd></div>@endforeach</dl>
            </section>
        </section>
        <section class="monitoring-editor-section" aria-labelledby="monitoring-period-heading">
            <div class="section-heading"><h3 id="monitoring-period-heading">2. Monitoring Period</h3></div>
            <div class="form-grid monitoring-period-grid">
                @foreach(array_slice($fields, 1, 3) as $field)
                    @php([$name, $label, $type] = $field)
                    <div class="field"><label for="monitoring-{{ $name }}">{{ $label }} <span class="field-hint">*</span></label>
                        @if($type === 'select')<select name="{{ $name }}" id="monitoring-{{ $name }}" required aria-describedby="monitoring-quarter-availability"><option value="">Select a quarter…</option>@foreach($field[3] as $choice)<option value="{{ $choice['value'] }}">{{ $choice['label'] }}</option>@endforeach</select>
                        @else<input type="{{ $type }}" name="{{ $name }}" id="monitoring-{{ $name }}" required @if($name === 'year') min="2000" max="2100" step="1" @endif>@endif
                    </div>
                @endforeach
            </div>
            <p class="field-hint" id="monitoring-quarter-availability" role="status" tabindex="-1"></p>
            <p class="field-hint">Production, income and materials share this reporting period. Monthly income uses the monitoring year and selected quarter.</p>
        </section>
        <section class="monitoring-editor-section" aria-labelledby="monitoring-details-heading">
            <div class="monitoring-tabs" role="tablist" aria-label="Editor sections">
                @foreach(['overview' => 'Overview', 'production' => 'Production', 'income' => 'Income', 'materials' => 'Materials'] as $tab => $label)<button type="button" role="tab" id="editor-tab-{{ $tab }}" aria-controls="editor-panel-{{ $tab }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}" tabindex="{{ $loop->first ? '0' : '-1' }}" data-monitoring-tab="{{ $tab }}">{{ $label }}</button>@endforeach
            </div>
            <section role="tabpanel" id="editor-panel-overview" aria-labelledby="editor-tab-overview" data-monitoring-panel="overview">
                <div class="form-grid">
                    @foreach(array_slice($fields, 4) as $field)
                        @php([$name, $label, $type] = $field)
                        <div class="field {{ $type === 'textarea' ? 'field-wide' : '' }}"><label for="monitoring-{{ $name }}">{{ $label }} <span class="field-hint">{{ $type === 'textarea' ? 'Optional' : '*' }}</span></label>
                            @if($type === 'select')<select name="{{ $name }}" id="monitoring-{{ $name }}" required><option value="">Select…</option>@foreach($field[3] as $choice)<option value="{{ $choice['value'] }}">{{ $choice['label'] }}</option>@endforeach</select>
                            @else<textarea name="{{ $name }}" id="monitoring-{{ $name }}" rows="3"></textarea>@endif
                        </div>
                    @endforeach
                </div>
                <div class="form-grid" data-initial-conductor>
                    <div class="field"><label for="monitoring-conducted_by_user_id">Conducted By — Assigned Field Officer</label><select name="conducted_by_user_id" id="monitoring-conducted_by_user_id"><option value="">Select assigned officer</option></select></div>
                    <div class="field"><label for="monitoring-conducted_by">Or Another Person’s Name</label><input name="conducted_by" id="monitoring-conducted_by" maxlength="255"></div>
                </div>
                <p class="field-hint" data-initial-conductor-help>Select the assigned officer or enter another name. Monitored By and audit attribution are assigned separately by the backend.</p>

            </section>
            @foreach(['production' => 'Production Monitoring', 'materials' => 'Material Monitoring'] as $kind => $heading)
                <section class="form-section" role="tabpanel" id="editor-panel-{{ $kind }}" aria-labelledby="editor-tab-{{ $kind }}" data-monitoring-panel="{{ $kind }}" hidden><div class="section-heading"><h3>{{ $heading }}</h3></div><p class="field-hint">{{ $kind === 'production' ? 'Record output for products belonging to the selected project.' : 'Record condition and maintenance observations for the selected project’s materials.' }}</p><div id="monitoring-{{ $kind }}" class="monitoring-editor-cards"></div><p class="monitoring-empty" id="monitoring-{{ $kind }}-empty">{{ $kind === 'production' ? 'No production entries yet. Select a project, then choose an existing product or stage a new product.' : 'No material observations yet. Select a project, then choose an existing material or stage a new material.' }}</p><button type="button" class="button" data-add-root="{{ $kind }}">Add {{ $kind === 'production' ? 'Production' : 'Material Observation' }}</button></section>
            @endforeach
            <section class="form-section" role="tabpanel" id="editor-panel-income" aria-labelledby="editor-tab-income" data-monitoring-panel="income" hidden><h3>Product Income</h3><div class="field"><label for="monitoring-income-product">Product</label><select id="monitoring-income-product" disabled><option value="">Add a Production product first</option></select></div><p class="field-hint">Choose monthly or quarterly income for the selected product. Switching products preserves every group’s values.</p><div id="monitoring-income-groups"></div><p id="monitoring-income-empty" class="monitoring-empty">Add a production entry first. Income must be linked to a product’s production record.</p>@if(!$section)<button type="button" class="button" data-go-production>Go to Production</button>@else<a class="button" href="{{ route($monitoringPrefix.'.workspace', [$record, 'production']) }}">Go to Production</a>@endif</section>
        </section>
        <div class="modal-actions"><a class="button" href="{{ $record ? route($monitoringPrefix.'.workspace', [$record, $section]) : route($monitoringPrefix.'.index') }}">Cancel</a><button type="submit" class="button button-primary">Save Monitoring Record</button></div>
        <p role="status" data-monitoring-submit-status></p>
    </form>
</section>
</div>
@include('monitoring.partials.editor-templates')
@include('monitoring.partials.removal-modal')
@if($record && $section === 'income')
    @include('monitoring.partials.income-conflict-modal')
@endif
@if($record && $section === 'materials')
    @include('monitoring.partials.materials-conflict-modal')
@endif
@include('monitoring.partials.editor-script')
@endsection
