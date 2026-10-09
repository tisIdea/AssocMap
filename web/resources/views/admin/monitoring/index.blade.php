@php
    $catalog = $catalog ?? require resource_path('ui/admin.php');
    $monitoringPrefix = $monitoringPrefix ?? 'admin.monitoring';
    $rows = collect($monitoringRows)->map(fn ($row) => array_merge($row, ['update_url' => route($monitoringPrefix.'.update', $row['id'])]));
    $statuses = ['planned' => 'Planned', 'ongoing' => 'Ongoing', 'completed' => 'Completed', 'suspended' => 'Suspended', 'cancelled' => 'Cancelled'];
    $months = [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    $restore = $errors->any() && old('monitoring_form') === 'editor';
    $projectContext = collect($projectRows)->keyBy('id');
    $columns = ['project_title' => 'Project', 'association_name' => 'Association', 'year' => 'Year', 'quarter_name' => 'Quarter', 'monitoring_date' => 'Monitoring Date'];
    $fields = [
        ['project_id', 'Project', 'select', $projects->map(fn ($project) => ['value' => $project->id, 'label' => $project->title.' — '.($project->association?->name ?? 'Association unavailable')])->all()],
        ['year', 'Year', 'number'],
        ['quarter_id', 'Quarter', 'select', $quarters->map(fn ($quarter) => ['value' => $quarter->id, 'label' => $quarter->name])->all()],
        ['monitoring_date', 'Monitoring Date', 'date'],
        ['site_validated', 'Site Validated', 'select', [['value' => '1', 'label' => 'Yes'], ['value' => '0', 'label' => 'No']]],
        ['project_status', 'Project Status', 'select', collect($statuses)->map(fn ($label, $value) => compact('value', 'label'))->all()],
        ['issues_encountered', 'Issues Encountered', 'textarea'],
        ['actions_taken', 'Actions Taken', 'textarea'],
        ['overall_remarks', 'Overall Remarks', 'textarea'],
    ];
    $repeatFields = [
        'production' => [['project_product_id', 'Product', 'select', true], ['target_output', 'Target Output', 'number', true], ['actual_output', 'Actual Output', 'number', true], ['unit', 'Unit', 'text', true], ['remarks', 'Remarks', 'textarea', false]],
        'income' => [['period_type', 'Period Type', 'select', true], ['month', 'Month', 'select', false], ['gross_income', 'Gross Income', 'number', true], ['remarks', 'Remarks', 'textarea', false]],
        'materials' => [['project_material_id', 'Material', 'select', true], ['condition', 'Condition', 'text', true], ['scheduled_maintenance', 'Scheduled Maintenance', 'date', false], ['actual_maintenance', 'Actual Maintenance', 'date', false], ['remarks', 'Remarks', 'textarea', false]],
    ];
@endphp
@extends($monitoringLayout ?? 'layouts.admin')
@section('content')
<style>
    #monitoring-records, .monitoring-workspace { min-width: 0; }
    .monitoring-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr)); gap: 18px; padding: 18px 0; }
    #monitoring-records { padding: 20px; }
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
    @media (max-width: 640px) {
        .monitoring-workspace .form-grid { grid-template-columns: minmax(0, 1fr); }
        .monitoring-workspace .field-wide { grid-column: auto; }
        .monitoring-card { padding: 16px; }
        .modal:has(.monitoring-workspace) { padding: 16px; }
        .monitoring-card .detail-grid { grid-template-columns: minmax(0, 1fr); }
    }
</style>
<div data-workspace-module="monitoring" data-workspace-title="Monitoring">
    <header class="page-header"><div><div class="eyebrow">{{ $monitoringPrefix === 'admin.monitoring' ? 'Program Operations' : 'Field Officer' }}</div><h1 tabindex="-1">Monitoring</h1><p>Record quarterly project progress, production, income, and material condition{{ $monitoringPrefix === 'field-officer.monitoring' ? ' for your assigned associations' : '' }}.</p></div></header>
    @if($monitoringPrefix === 'admin.monitoring')
        @if(session('success'))<div class="notice notice-success" role="status">{{ session('success') }}</div>@endif
        @if(session('error'))<div class="notice notice-error" role="alert">{{ session('error') }}</div>@endif
        @if($errors->any() && !$restore)<div class="notice notice-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    @endif
    <section class="card list-card" aria-label="Monitoring records">
        <div class="table-toolbar">
            <div class="field"><label for="monitoring-filter">Search Monitoring</label><input type="search" id="monitoring-filter" placeholder="Project, association, year, or quarter" aria-controls="monitoring-records"></div>
            <button type="button" class="button button-primary" data-monitoring-create data-dialog="monitoring-editor" @disabled($projects->isEmpty() || $quarters->isEmpty())><x-icon name="plus" />Add Monitoring Record</button>
        </div>
        <div class="monitoring-cards" id="monitoring-records">
        @foreach($rows as $row)
            <article class="monitoring-card" data-monitoring-row>
                <div class="monitoring-card-heading"><h2>{{ $row['association_name'] ?? 'Association unavailable' }}</h2><span class="badge badge-{{ $row['project_status'] === 'completed' ? 'success' : (in_array($row['project_status'], ['cancelled', 'suspended']) ? 'danger' : 'warning') }}">{{ $statuses[$row['project_status']] ?? $row['project_status'] }}</span></div>
                <p>{{ $row['project_title'] ?? 'Project unavailable' }}</p>
                <dl class="detail-grid">@foreach(['quarter_name' => 'Quarter', 'year' => 'Monitoring Year', 'monitoring_date' => 'Monitoring Date', 'monitored_by' => 'Monitored By'] as $key => $label)<div><dt>{{ $label }}</dt><dd>{{ $row[$key] ?? '—' }}</dd></div>@endforeach<div><dt>Site Validated</dt><dd>{{ $row['site_validated'] ? 'Yes' : 'No' }}</dd></div></dl>
                <div class="monitoring-card-actions"><button type="button" class="button" data-dialog="monitoring-view-{{ $row['id'] }}" aria-label="View monitoring for {{ $row['association_name'] }} · {{ $row['project_title'] }}">View</button><button type="button" class="button button-primary" data-monitoring-edit="{{ $row['id'] }}" data-dialog="monitoring-editor" aria-label="Edit monitoring for {{ $row['association_name'] }} · {{ $row['project_title'] }}">Edit</button></div>
            </article>
        @endforeach
        </div>
        <div id="monitoring-empty" @if($rows->isNotEmpty()) hidden @endif><x-empty-state title="No monitoring records found." message="Add a monitoring record for an available project or change your search." /></div>
        <footer class="table-footer"><span id="monitoring-count" role="status">{{ $rows->count() }} monitoring records</span></footer>
    </section>
    @if($projects->isEmpty())<p class="notice">No projects are available for creating monitoring records.</p>@endif
    @if($quarters->isEmpty())<p class="notice">No quarters are available. Contact your system administrator.</p>@endif
</div>
@foreach($rows as $row)
    <x-modal :id="'monitoring-view-'.$row['id']" title="Monitoring Details">
        <div class="monitoring-workspace" data-monitoring-workspace>
            <section class="monitoring-context monitoring-card" aria-label="Association and project context">
                <div class="eyebrow">Association</div><h2>{{ $row['association_name'] ?? 'Association unavailable' }}</h2><p>{{ $row['project_title'] ?? 'Project unavailable' }}</p>
                <p>{{ $row['quarter_name'] }} · {{ $row['year'] }} · {{ $row['monitoring_date'] ?? 'Date unavailable' }}</p>
                <dl class="detail-grid"><div><dt>Program Year</dt><dd>{{ data_get($projectContext->get($row['project_id']), 'program_year', '—') }}</dd></div><div><dt>Project Status</dt><dd><span class="badge badge-{{ $row['project_status'] === 'completed' ? 'success' : (in_array($row['project_status'], ['cancelled', 'suspended']) ? 'danger' : 'warning') }}">{{ $statuses[$row['project_status']] ?? $row['project_status'] }}</span></dd></div><div><dt>Site Validated</dt><dd>{{ $row['site_validated'] ? 'Yes' : 'No' }}</dd></div><div><dt>Monitored By</dt><dd>{{ $row['monitored_by'] }}</dd></div></dl>
            </section>
            <div class="monitoring-tabs" role="tablist" aria-label="Monitoring sections">
                @foreach(['overview' => 'Overview', 'production' => 'Production', 'income' => 'Income', 'materials' => 'Materials'] as $tab => $label)
                    <button type="button" role="tab" id="view-{{ $row['id'] }}-tab-{{ $tab }}" aria-controls="view-{{ $row['id'] }}-{{ $tab }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}" tabindex="{{ $loop->first ? '0' : '-1' }}" data-monitoring-tab="{{ $tab }}">{{ $label }}</button>
                @endforeach
            </div>
            <section role="tabpanel" tabindex="0" id="view-{{ $row['id'] }}-overview" aria-labelledby="view-{{ $row['id'] }}-tab-overview" data-monitoring-panel="overview">
                <div class="monitoring-card"><h3>General Monitoring</h3><dl class="detail-grid">@foreach($columns + ['monitored_by' => 'Monitored By'] as $key => $label)<div><dt>{{ $label }}</dt><dd>{{ $row[$key] ?? '—' }}</dd></div>@endforeach<div><dt>Project Status</dt><dd>{{ $statuses[$row['project_status']] ?? $row['project_status'] }}</dd></div><div><dt>Site Validated</dt><dd>{{ $row['site_validated'] ? 'Yes' : 'No' }}</dd></div></dl></div>
                <div class="monitoring-cards">@foreach(['issues_encountered' => 'Issues Encountered', 'actions_taken' => 'Actions Taken', 'overall_remarks' => 'Overall Remarks'] as $key => $label)<article class="monitoring-card"><h3>{{ $label }}</h3><p class="monitoring-text">{{ filled($row[$key] ?? null) ? $row[$key] : 'No information recorded.' }}</p></article>@endforeach</div>
            </section>
            <section role="tabpanel" tabindex="0" id="view-{{ $row['id'] }}-production" aria-labelledby="view-{{ $row['id'] }}-tab-production" data-monitoring-panel="production" hidden>
                <div class="monitoring-cards">@forelse($row['production'] as $production)
                    <article class="monitoring-card"><h3>{{ $production['product_name'] ?? 'Product unavailable' }}</h3>
                        @php($productType = collect(data_get($projectContext->get($row['project_id']), 'products', []))->firstWhere('id', $production['project_product_id']))
                        @if(data_get($productType, 'product_type'))<p>{{ ucfirst(data_get($productType, 'product_type')) }}</p>@endif
                        <dl class="detail-grid">@foreach(['target_output' => 'Target Output', 'actual_output' => 'Actual Output', 'unit' => 'Unit', 'remarks' => 'Remarks'] as $key => $label)<div><dt>{{ $label }}</dt><dd class="monitoring-text">{{ filled($production[$key] ?? null) ? $production[$key] : '—' }}</dd></div>@endforeach</dl>
                    </article>
                @empty<p class="monitoring-empty">No Production records yet.</p>@endforelse</div>
            </section>
            <section role="tabpanel" tabindex="0" id="view-{{ $row['id'] }}-income" aria-labelledby="view-{{ $row['id'] }}-tab-income" data-monitoring-panel="income" hidden>
                @forelse($row['production'] as $production)
                    <section class="monitoring-income-group"><h3>{{ $production['product_name'] ?? 'Product unavailable' }} Production</h3><div class="monitoring-cards">
                        @forelse($production['income'] as $income)<article class="monitoring-card"><h4>{{ $income['period_type'] === 'monthly' ? ($months[$income['month']] ?? 'Month unavailable') : 'Quarterly' }}</h4><dl><div><dt>Gross Income</dt><dd>₱{{ number_format((float) $income['gross_income'], 2) }}</dd></div><div><dt>Remarks</dt><dd class="monitoring-text">{{ filled($income['remarks'] ?? null) ? $income['remarks'] : '—' }}</dd></div></dl></article>@empty<p class="monitoring-empty">No Income records yet for this product.</p>@endforelse
                    </div></section>
                @empty<p class="monitoring-empty">No Income records yet.</p>@endforelse
            </section>
            <section role="tabpanel" tabindex="0" id="view-{{ $row['id'] }}-materials" aria-labelledby="view-{{ $row['id'] }}-tab-materials" data-monitoring-panel="materials" hidden>
                <div class="monitoring-cards">@forelse($row['materials'] as $material)<article class="monitoring-card"><h3>{{ $material['item_name'] ?? 'Material unavailable' }}</h3><dl class="detail-grid">@foreach(['condition' => 'Condition', 'scheduled_maintenance' => 'Scheduled Maintenance', 'actual_maintenance' => 'Actual Maintenance', 'remarks' => 'Remarks'] as $key => $label)<div><dt>{{ $label }}</dt><dd class="monitoring-text">{{ filled($material[$key] ?? null) ? $material[$key] : '—' }}</dd></div>@endforeach</dl></article>@empty<p class="monitoring-empty">No Materials monitored yet.</p>@endforelse</div>
            </section>
        </div>
        <div class="modal-actions"><button type="button" class="button" data-close-dialog>Close</button></div>
    </x-modal>
@endforeach
<x-modal id="monitoring-editor" title="Add Monitoring Record">
    <form method="POST" action="{{ route($monitoringPrefix.'.store') }}" id="monitoring-form" class="monitoring-workspace" data-monitoring-workspace novalidate>
        @csrf
        <input type="hidden" name="monitoring_form" value="editor"><input type="hidden" name="monitoring_record_id"><input type="hidden" name="_method" value="PATCH" disabled>
        <input type="hidden" name="production" value=""><input type="hidden" name="materials" value="">
        <p>Fields marked * are required. Removing a detail card takes effect when you save.</p>
        @if($restore)<div class="notice notice-error" role="alert" data-monitoring-errors>@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        <p class="notice" id="monitoring-choice-notice" role="status" hidden></p>
        <section class="monitoring-context monitoring-card" aria-label="Selected association and project" aria-live="polite">
            <div class="eyebrow">Association</div><h2 data-context-association>Select a project to see its association</h2><p data-context-project>No project selected</p><p data-context-period></p>
            <dl class="detail-grid">@foreach(['program-year' => 'Program Year', 'status' => 'Project Status', 'validated' => 'Site Validated', 'officer' => 'Monitored By'] as $key => $label)<div><dt>{{ $label }}</dt><dd data-context-{{ $key }}>—</dd></div>@endforeach</dl>
        </section>
        <div class="monitoring-tabs" role="tablist" aria-label="Editor sections">
            @foreach(['overview' => 'Overview', 'production' => 'Production', 'income' => 'Income', 'materials' => 'Materials'] as $tab => $label)<button type="button" role="tab" id="editor-tab-{{ $tab }}" aria-controls="editor-panel-{{ $tab }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}" tabindex="{{ $loop->first ? '0' : '-1' }}" data-monitoring-tab="{{ $tab }}">{{ $label }}</button>@endforeach
        </div>
        <section role="tabpanel" id="editor-panel-overview" aria-labelledby="editor-tab-overview" data-monitoring-panel="overview">
        <section class="form-section"><div class="section-heading"><h3>General Monitoring</h3></div><div class="form-grid">
        @foreach($fields as $field)
            @php([$name, $label, $type] = $field)
            <div class="field {{ $type === 'textarea' ? 'field-wide' : '' }}"><label for="monitoring-{{ $name }}">{{ $label }} <span class="field-hint">{{ $type === 'textarea' ? 'Optional' : '*' }}</span></label>
                @if($type === 'select')<select name="{{ $name }}" id="monitoring-{{ $name }}" required><option value="">Select…</option>@foreach($field[3] as $choice)<option value="{{ $choice['value'] }}">{{ $choice['label'] }}</option>@endforeach</select>
                @elseif($type === 'textarea')<textarea name="{{ $name }}" id="monitoring-{{ $name }}" rows="3"></textarea>
                @else<input type="{{ $type }}" name="{{ $name }}" id="monitoring-{{ $name }}" required @if($name === 'year') min="2000" max="2100" step="1" @endif>@endif
            </div>
        @endforeach
        </div></section>
        </section>
        @foreach(['production' => 'Production Monitoring', 'materials' => 'Material Monitoring'] as $kind => $heading)
            <section class="form-section" role="tabpanel" id="editor-panel-{{ $kind }}" aria-labelledby="editor-tab-{{ $kind }}" data-monitoring-panel="{{ $kind }}" hidden><div class="section-heading"><h3>{{ $heading }}</h3></div><div id="monitoring-{{ $kind }}" class="monitoring-editor-cards"></div><p class="monitoring-empty" id="monitoring-{{ $kind }}-empty">No {{ $kind === 'production' ? 'Production records' : 'Materials monitored' }} yet.</p><button type="button" class="button" data-add-root="{{ $kind }}">Add {{ $kind === 'production' ? 'Production' : 'Material' }}</button></section>
        @endforeach
        <section class="form-section" role="tabpanel" id="editor-panel-income" aria-labelledby="editor-tab-income" data-monitoring-panel="income" hidden><h3>Income by Production</h3><p>Income is recorded for each production card.</p><div id="monitoring-income-groups"></div><p id="monitoring-income-empty" class="monitoring-empty">No Income records yet. Add Production first to record income.</p><button type="button" class="button" data-go-production>Go to Production</button></section>
        <div class="modal-actions"><button type="button" class="button" data-close-dialog>Cancel</button><button type="submit" class="button button-primary">Save Monitoring Record</button></div>
    </form>
</x-modal>
@foreach($repeatFields as $kind => $definitions)
<template id="monitoring-{{ $kind }}-template"><fieldset class="repeat-row monitoring-card" data-kind="{{ $kind }}"><legend>{{ $kind === 'materials' ? 'Material' : ucfirst($kind) }}</legend><div class="repeat-row-heading"><strong data-row-heading></strong><button type="button" class="text-button text-danger" data-remove-row>Remove {{ $kind === 'materials' ? 'Material' : ucfirst($kind) }}</button></div><input type="hidden" data-key="id" disabled><div class="form-grid">
    @foreach($definitions as [$key, $label, $type, $required])
        <div class="field {{ $type === 'textarea' ? 'field-wide' : '' }}"><label data-label="{{ $key }}">{{ $label }} <span class="field-hint">{{ $required ? '*' : 'Optional' }}</span></label>
        @if($type === 'select')<select data-key="{{ $key }}" @required($required)><option value="">Select…</option>@if($key === 'period_type')<option value="monthly">Monthly</option><option value="quarterly">Quarterly</option>@elseif($key === 'month')@foreach($months as $value => $month)<option value="{{ $value }}">{{ $month }}</option>@endforeach @endif</select>
        @elseif($type === 'textarea')<textarea data-key="{{ $key }}" rows="2"></textarea>
        @else<input type="{{ $type }}" data-key="{{ $key }}" @required($required) @if($type === 'number') min="0" step="any" @endif @if(in_array($key, ['unit', 'condition'])) maxlength="100" @endif>@endif
        </div>
    @endforeach
    </div>
    @if($kind === 'production')<p data-product-type hidden></p>@endif
    @if($kind === 'production')<input type="hidden" data-income-empty value=""><h4>Income Monitoring</h4><div data-income-container class="monitoring-editor-cards"></div><p data-income-notice>No Income records yet.</p><button type="button" class="button" data-add-income>Add Income</button>@endif
</fieldset></template>
@endforeach
<script type="module">
    const records = {{ Illuminate\Support\Js::from($rows->values()) }};
    const projects = {{ Illuminate\Support\Js::from($projectRows) }};
    const initial = {{ Illuminate\Support\Js::from($restore ? old() : []) }};
    const errors = {{ Illuminate\Support\Js::from($restore ? $errors->messages() : []) }};
    const restore = {{ Illuminate\Support\Js::from($restore) }};
    const createUrl = {{ Illuminate\Support\Js::from(route($monitoringPrefix.'.store')) }};
    const fieldOfficer = {{ Illuminate\Support\Js::from($monitoringPrefix === 'field-officer.monitoring') }};
    if (fieldOfficer) {
        document.title = 'Monitoring · AssocMap';
        document.querySelector('.topbar-context > span').textContent = 'Monitoring';
    }
    const form = document.getElementById('monitoring-form');
    const projectSelect = form.elements.project_id;
    const notice = document.getElementById('monitoring-choice-notice');
    const containers = {production: document.getElementById('monitoring-production'), materials: document.getElementById('monitoring-materials')};
    const counters = {production: 0, materials: 0};
    let editingRecord = null;
    const ownInputs = row => Array.from(row.querySelectorAll('[data-key]')).filter(input => input.closest('[data-kind]') === row);
    const rootRows = kind => Array.from(containers[kind].children);
    const incomeRows = row => Array.from(row.incomeGroup.querySelector('[data-income-container]').children);
    function activateTab(workspace, name, focus = false) {
        workspace.querySelectorAll('[data-monitoring-tab]').forEach(tab => {
            const active = tab.dataset.monitoringTab === name;
            tab.setAttribute('aria-selected', String(active));
            tab.tabIndex = active ? 0 : -1;
            if (active && focus) tab.focus();
        });
        workspace.querySelectorAll('[data-monitoring-panel]').forEach(panel => { panel.hidden = panel.dataset.monitoringPanel !== name; });
    }
    document.querySelectorAll('[data-monitoring-workspace]').forEach(workspace => {
        workspace.addEventListener('click', event => {
            const tab = event.target.closest('[data-monitoring-tab]');
            if (tab) activateTab(workspace, tab.dataset.monitoringTab);
            if (event.target.closest('[data-go-production]')) activateTab(workspace, 'production', true);
        });
        workspace.addEventListener('keydown', event => {
            const tab = event.target.closest('[data-monitoring-tab]');
            if (!tab || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            const tabs = Array.from(workspace.querySelectorAll('[data-monitoring-tab]'));
            const current = tabs.indexOf(tab);
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (current + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
            activateTab(workspace, tabs[next].dataset.monitoringTab, true);
        });
    });
    function updateMaterialHeading(row) {
        const select = row.querySelector('[data-key="project_material_id"]');
        row.querySelector('[data-row-heading]').textContent = choices('materials').find(material => String(material.id) === select.value)?.item_name ?? 'Select a material';
    }
    function updateProductHeading(row) {
        const select = row.querySelector('[data-key="project_product_id"]');
        const product = choices('production').find(product => String(product.id) === select.value);
        row.querySelector('[data-row-heading]').textContent = product?.product_name ?? 'Select a product';
        const type = row.querySelector('[data-product-type]');
        type.textContent = product?.product_type ? `Product Type: ${product.product_type.charAt(0).toUpperCase()}${product.product_type.slice(1)}` : '';
        type.hidden = !product?.product_type;
        row.incomeGroup.querySelector('[data-income-heading]').textContent = product ? `${product.product_name} Production` : 'Production - select a product in the Production tab';
    }
    function updateContext() {
        const project = selectedProject();
        const set = (key, value) => { form.querySelector(`[data-context-${key}]`).textContent = value; };
        set('association', project?.association_name ?? (!projectSelect.value ? editingRecord?.association_name : null) ?? 'Select a project to see its association');
        set('project', project?.title ?? (!projectSelect.value ? editingRecord?.project_title : null) ?? 'No project selected');
        set('program-year', project?.program_year ?? '—');
        const quarter = form.elements.quarter_id;
        set('period', [quarter.value ? quarter.selectedOptions[0].textContent : 'Quarter not selected', form.elements.year.value || 'Year not entered', form.elements.monitoring_date.value || 'Date not entered'].join(' · '));
        const status = form.elements.project_status;
        const badge = document.createElement('span');
        badge.className = `badge badge-${status.value === 'completed' ? 'success' : ['cancelled', 'suspended'].includes(status.value) ? 'danger' : 'warning'}`;
        badge.textContent = status.value ? status.selectedOptions[0].textContent : 'Status not selected';
        form.querySelector('[data-context-status]').replaceChildren(badge);
        set('validated', form.elements.site_validated.value === '' ? 'Not selected' : form.elements.site_validated.value === '1' ? 'Yes' : 'No');
        set('officer', form.dataset.monitoredBy);
    }
    const contextFields = ['project_id', 'year', 'quarter_id', 'monitoring_date', 'site_validated', 'project_status'];
    ['input', 'change'].forEach(type => form.addEventListener(type, event => {
        if (contextFields.includes(event.target.name)) updateContext();
    }));
    form.addEventListener('invalid', event => {
        const panel = event.target.closest('[data-monitoring-panel]');
        if (panel) activateTab(form, panel.dataset.monitoringPanel);
    }, true);
    function selectedProject() { return projects.find(project => String(project.id) === projectSelect.value); }
    function choices(kind) { return selectedProject()?.[kind === 'production' ? 'products' : 'materials'] ?? []; }
    function fillChoices(select, kind, selected = '') {
        select.replaceChildren(new Option('Select…', ''));
        choices(kind).forEach(choice => select.add(new Option(choice[kind === 'production' ? 'product_name' : 'item_name'], choice.id)));
        select.value = String(selected ?? '');
        if (select.selectedIndex < 0) select.value = '';
        return Boolean(selected && select.value !== String(selected));
    }
    function refreshUnique(kind) {
        const key = kind === 'production' ? 'project_product_id' : 'project_material_id';
        const selects = rootRows(kind).map(row => row.querySelector(`[data-key="${key}"]`));
        const selected = selects.map(select => select.value).filter(Boolean);
        selects.forEach(select => {
            Array.from(select.options).forEach(option => { option.disabled = Boolean(option.value && option.value !== select.value && selected.includes(option.value)); });
            select.setCustomValidity(select.value && selected.filter(value => value === select.value).length > 1 ? 'Select each item only once.' : '');
        });
        form.querySelector(`[data-add-root="${kind}"]`).disabled = !projectSelect.value || selects.length >= choices(kind).length;
        document.getElementById(`monitoring-${kind}-empty`).hidden = selects.length > 0;
        document.getElementById('monitoring-income-empty').hidden = rootRows('production').length > 0;
    }
    function refreshIncome(production) {
        const rows = incomeRows(production);
        const monthly = rows.filter(row => row.querySelector('[data-key="period_type"]').value === 'monthly');
        const quarterly = rows.filter(row => row.querySelector('[data-key="period_type"]').value === 'quarterly');
        const months = monthly.map(row => row.querySelector('[data-key="month"]').value).filter(Boolean);
        rows.forEach(row => {
            const period = row.querySelector('[data-key="period_type"]');
            const month = row.querySelector('[data-key="month"]');
            row.querySelector('[data-row-heading]').textContent = period.value === 'quarterly' ? 'Quarterly Income' : month.value ? `${month.selectedOptions[0].textContent} Income` : 'Monthly Income';
            month.disabled = period.value !== 'monthly';
            month.required = period.value === 'monthly';
            if (month.disabled) month.value = '';
            Array.from(month.options).forEach(option => { option.disabled = Boolean(option.value && option.value !== month.value && months.includes(option.value)); });
            month.setCustomValidity(!month.disabled && month.value && months.filter(value => value === month.value).length > 1 ? 'Select each month only once.' : '');
            period.querySelector('option[value="quarterly"]').disabled = period.value !== 'quarterly' && quarterly.length > 0;
            period.querySelector('option[value="monthly"]').disabled = period.value !== 'monthly' && monthly.length >= 12;
            period.setCustomValidity(period.value === 'quarterly' && quarterly.length > 1 ? 'Only one quarterly income row is allowed.' : '');
        });
        production.incomeGroup.querySelector('[data-add-income]').disabled = rows.length >= 13;
        production.incomeGroup.querySelector('[data-income-notice]').hidden = rows.length > 0;
        production.incomeGroup.querySelector('[data-income-empty]').disabled = rows.length > 0;
    }
    function addRow(kind, values = {}, index = null, parent = null) {
        if (index === null) index = kind === 'income' ? Number(parent.dataset.nextIncome || 0) : counters[kind];
        if (kind === 'income') parent.dataset.nextIncome = String(Math.max(Number(parent.dataset.nextIncome || 0), Number(index) + 1));
        else counters[kind] = Math.max(counters[kind], Number(index) + 1);
        const row = document.getElementById(`monitoring-${kind}-template`).content.firstElementChild.cloneNode(true);
        const prefix = kind === 'income' ? `${parent.dataset.prefix}[income][${index}]` : `${kind}[${index}]`;
        row.dataset.prefix = prefix;
        row.querySelector('[data-row-heading]').textContent = `${kind === 'materials' ? 'Material' : kind === 'production' ? 'Production' : 'Income'} ${Number(index) + 1}`;
        ownInputs(row).forEach(input => {
            const key = input.dataset.key;
            input.name = `${prefix}[${key}]`;
            input.id = `monitoring-${input.name.replace(/[^a-zA-Z0-9_-]/g, '-')}`;
            row.querySelector(`[data-label="${key}"]`)?.setAttribute('for', input.id);
            input.value = values[key] ?? '';
            if (key === 'id') input.disabled = !values.id;
        });
        if (kind !== 'income') {
            const key = kind === 'production' ? 'project_product_id' : 'project_material_id';
            if (fillChoices(row.querySelector(`[data-key="${key}"]`), kind, values[key])) {
                notice.textContent = 'An unavailable product or material was cleared. Select an item belonging to the current project.';
                notice.hidden = false;
            }
            containers[kind].append(row);
            if (kind === 'materials') updateMaterialHeading(row);
            if (kind === 'production') {
                const group = document.createElement('section');
                group.className = 'monitoring-income-group monitoring-card';
                group.dataset.productionIncome = '';
                group.productionRow = row;
                row.incomeGroup = group;
                const heading = document.createElement('h3');
                heading.dataset.incomeHeading = '';
                group.append(heading);
                ['[data-income-empty]', 'h4', '[data-income-container]', '[data-income-notice]', '[data-add-income]'].forEach(selector => group.append(row.querySelector(selector)));
                document.getElementById('monitoring-income-groups').append(group);
                updateProductHeading(row);
                row.incomeGroup.querySelector('[data-income-empty]').name = `${prefix}[income]`;
                Object.entries(values.income ?? {}).forEach(([incomeIndex, income]) => addRow('income', income, incomeIndex, row));
                refreshIncome(row);
            }
            refreshUnique(kind);
        } else {
            parent.incomeGroup.querySelector('[data-income-container]').append(row);
            refreshIncome(parent);
        }
        return row;
    }
    function populate(values, record = null) {
        editingRecord = record;
        form.reset();
        form.querySelectorAll('[data-field-error]').forEach(error => error.remove());
        form.querySelectorAll('[aria-invalid]').forEach(input => { input.removeAttribute('aria-invalid'); input.removeAttribute('aria-describedby'); });
        form.action = record?.update_url ?? createUrl;
        form.elements._method.disabled = !record;
        form.elements.monitoring_record_id.value = record?.id ?? '';
        form.dataset.monitoredBy = record?.monitored_by ?? 'Recorded by the signed-in officer on save';
        document.getElementById('monitoring-editor-title').textContent = record ? 'Edit Monitoring Record' : 'Add Monitoring Record';
        notice.hidden = true;
        document.getElementById('monitoring-income-groups').replaceChildren();
        activateTab(form, 'overview');
        for (const key of ['project_id', 'year', 'quarter_id', 'monitoring_date', 'site_validated', 'project_status', 'issues_encountered', 'actions_taken', 'overall_remarks']) {
            let value = values[key] ?? '';
            if (key === 'site_validated' && typeof value === 'boolean') value = value ? '1' : '0';
            form.elements[key].value = value;
        }
        if (values.project_id && !projectSelect.value) { notice.textContent = 'The original project is unavailable. Select an available project before saving.'; notice.hidden = false; }
        for (const kind of ['production', 'materials']) {
            containers[kind].replaceChildren();
            counters[kind] = 0;
            Object.entries(values[kind] ?? {}).forEach(([index, row]) => addRow(kind, row, index));
            refreshUnique(kind);
        }
        updateContext();
    }
    projectSelect.addEventListener('change', () => {
        let cleared = false;
        for (const kind of ['production', 'materials']) {
            const key = kind === 'production' ? 'project_product_id' : 'project_material_id';
            rootRows(kind).forEach(row => {
                const select = row.querySelector(`[data-key="${key}"]`);
                if (fillChoices(select, kind, select.value)) {
                    cleared = true;
                    if (kind === 'production') row.querySelector('[data-key="unit"]').value = '';
                }
            });
            refreshUnique(kind);
        }
        rootRows('production').forEach(updateProductHeading);
        rootRows('materials').forEach(updateMaterialHeading);
        updateContext();
        notice.textContent = 'Selections that do not belong to the new project were cleared. Review production and material details before saving.';
        notice.hidden = !cleared;
    });
    form.addEventListener('click', event => {
        const root = event.target.closest('[data-add-root]');
        if (root) { const row = addRow(root.dataset.addRoot); ownInputs(row).find(input => input.type !== 'hidden')?.focus(); }
        const income = event.target.closest('[data-add-income]');
        if (income) {
            const parent = income.closest('[data-production-income]').productionRow;
            const period = incomeRows(parent).filter(row => row.querySelector('[data-key="period_type"]').value === 'monthly').length >= 12 ? 'quarterly' : 'monthly';
            addRow('income', {period_type: period}, null, parent).querySelector('[data-key="period_type"]').focus();
        }
        const remove = event.target.closest('[data-remove-row]');
        if (remove) {
            const row = remove.closest('[data-kind]');
            const kind = row.dataset.kind;
            const parent = kind === 'income' ? row.closest('[data-production-income]').productionRow : null;
            if (kind === 'production') row.incomeGroup.remove();
            row.remove();
            if (parent) { refreshIncome(parent); parent.incomeGroup.querySelector('[data-add-income]').focus(); }
            else { refreshUnique(kind); form.querySelector(`[data-add-root="${kind}"]`).focus(); }
        }
    });
    form.addEventListener('change', event => {
        const input = event.target;
        const row = input.closest('[data-kind]');
        if (!row) return;
        if (input.dataset.key === 'project_product_id') {
            row.querySelector('[data-key="unit"]').value = choices('production').find(product => String(product.id) === input.value)?.default_unit ?? '';
            refreshUnique('production');
            updateProductHeading(row);
        }
        if (input.dataset.key === 'project_material_id') {
            refreshUnique('materials');
            updateMaterialHeading(row);
        }
        if (['period_type', 'month'].includes(input.dataset.key)) refreshIncome(row.closest('[data-production-income]').productionRow);
    });
    form.addEventListener('submit', event => {
        refreshUnique('production'); refreshUnique('materials');
        rootRows('production').forEach(refreshIncome);
        const invalid = Array.from(form.elements).find(input => input.willValidate && !input.validity.valid);
        if (invalid) {
            event.preventDefault();
            activateTab(form, invalid.closest('[data-monitoring-panel]').dataset.monitoringPanel);
            invalid.reportValidity();
        }
    });
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-monitoring-create], [data-monitoring-edit]');
        if (!button) return;
        const record = button.hasAttribute('data-monitoring-edit') ? records.find(row => String(row.id) === button.dataset.monitoringEdit) : null;
        if (button.hasAttribute('data-monitoring-edit') && !record) return;
        populate(record ?? {}, record);
        form.querySelector('[data-monitoring-errors]')?.remove();
    });
    document.getElementById('monitoring-filter').addEventListener('input', event => {
        const query = event.target.value.trim().toLocaleLowerCase();
        const rows = Array.from(document.querySelectorAll('[data-monitoring-row]'));
        rows.forEach(row => { row.hidden = !row.textContent.toLocaleLowerCase().includes(query); });
        const count = rows.filter(row => !row.hidden).length;
        document.getElementById('monitoring-empty').hidden = count > 0;
        document.getElementById('monitoring-count').textContent = `${count} monitoring records`;
    });
    const editing = restore ? records.find(row => String(row.id) === String(initial.monitoring_record_id)) : null;
    populate(initial, editing);
    if (restore) {
        Object.entries(errors).forEach(([key, messages]) => {
            const parts = key.split('.');
            const name = parts[0] + parts.slice(1).map(part => `[${part}]`).join('');
            const input = Array.from(form.querySelectorAll('input, select, textarea')).find(input => input.name === name && input.type !== 'hidden');
            if (!input) return;
            const error = document.createElement('p');
            error.className = 'field-error'; error.dataset.fieldError = ''; error.id = `${input.id}-error`;
            error.textContent = messages.join(' ');
            input.setAttribute('aria-invalid', 'true'); input.setAttribute('aria-describedby', error.id);
            input.closest('.field').append(error);
        });
        const firstError = form.querySelector('[aria-invalid="true"]');
        if (firstError) activateTab(form, firstError.closest('[data-monitoring-panel]').dataset.monitoringPanel);
        document.getElementById('monitoring-editor').showModal();
    }
</script>
@endsection
