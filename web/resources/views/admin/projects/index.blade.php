@extends($projectLayout ?? 'layouts.admin')
@section('content')
@php
    $prefix = $projectRoutePrefix ?? 'admin.projects';
    $rows = collect($module['rows']);
    $monitoringPrefix = $prefix === 'admin.projects' ? 'admin.monitoring' : 'field-officer.monitoring';
    $resourceSection = request()->query('resource_section');
    $fromMonitoring = request()->boolean('from_monitoring') && in_array($resourceSection, ['products', 'materials'], true);
    $requestedProject = request()->query('project_id');
    $navigationProject = $fromMonitoring && is_string($requestedProject)
        ? $rows->first(fn ($row) => (string) $row['id'] === $requestedProject && !$row['archived_at'] && ($row['can_mutate'] ?? false) && collect($catalog['options']['association_records'])->contains('value', $row['association_id']))
        : null;
    $returnRecord = request()->query('monitoring_record');
    $returnRecord = is_string($returnRecord) && Illuminate\Support\Str::isUuid($returnRecord) ? $returnRecord : null;
    $monitoringReturnUrl = $navigationProject && $returnRecord
        ? route($monitoringPrefix.'.workspace', [$returnRecord, $resourceSection === 'products' ? 'production' : 'materials'])
        : route($monitoringPrefix.'.index');
    $statuses = ['planned' => 'Planned', 'ongoing' => 'Ongoing', 'completed' => 'Completed', 'terminated' => 'Terminated', 'suspended' => 'Suspended', 'cancelled' => 'Cancelled'];
    $delivery = ['planned' => 'Planned', 'not_delivered' => 'Not Delivered', 'partially_delivered' => 'Partially Delivered', 'delivered' => 'Delivered'];
    $productTypes = ['raw' => 'Raw', 'processed' => 'Processed', 'other' => 'Other'];
    $editorRecord = $rows->first(fn ($row) => (string) $row['id'] === (string) old('project_record_id') && !$row['archived_at'] && ($row['can_mutate'] ?? false));
    $restore = $errors->any() && old('project_form') === 'editor' && (!old('project_record_id') || $editorRecord);
    $editing = $restore ? $editorRecord : null;
    $fields = [
        ['name' => 'association_id', 'label' => 'Association', 'type' => 'select', 'choices' => $catalog['options']['association_records'], 'required' => true],
        ['name' => 'title', 'label' => 'Project Title', 'required' => true],
        ['name' => 'description', 'label' => 'Description', 'type' => 'textarea'],
        ['name' => 'fisheries_category_id', 'label' => 'Fisheries Category', 'type' => 'select', 'choices' => $catalog['options']['fisheries_category_records'], 'required' => true],
        ['name' => 'program_year', 'label' => 'Program Year', 'type' => 'number', 'required' => true],
        ['name' => 'implementation_start_date', 'label' => 'Implementation Start Date', 'type' => 'date', 'required' => true],
        ['name' => 'implementation_end_date', 'label' => 'Implementation End Date', 'type' => 'date'],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'choices' => collect($statuses)->except(['completed', 'terminated'])->map(fn ($label, $value) => compact('value', 'label'))->values()->all(), 'required' => true],
        ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea'],
    ];
    $materialFields = ['item_name' => ['Item Name', 'text', true], 'quantity_planned' => ['Planned Quantity', 'number', true], 'quantity_delivered' => ['Delivered Quantity', 'number', false], 'unit' => ['Unit', 'text', true], 'unit_cost' => ['Unit Cost', 'number', false], 'delivery_status' => ['Delivery Status', 'select', true], 'delivery_date' => ['Delivery Date', 'date', false], 'remarks' => ['Remarks', 'textarea', false]];
    $columns = ['title' => 'Project Title', 'association' => 'Association', 'municipality' => 'Municipality', 'component' => 'SAAD Classification', 'fisheries_category' => 'Fisheries Category', 'commodity' => 'Commodity', 'program_year' => 'Program Year', 'implementation_start_date' => 'Implementation Date'];
@endphp
<header class="page-header"><div><div class="eyebrow">{{ $prefix === 'admin.projects' ? 'Program Operations' : 'Field Officer' }}</div><h1 tabindex="-1">Projects</h1><p>{{ $prefix === 'admin.projects' ? 'Manage association projects and material deliveries.' : 'Manage projects for your assigned associations.' }}</p></div></header>
@if($fromMonitoring)
    <p class="notice">Register resources in Project Management, save the project, then return to Monitoring to select them. <a class="button" href="{{ $monitoringReturnUrl }}" data-return-monitoring>Return to Monitoring</a></p>
    @if(!$navigationProject)<p class="notice notice-error" role="alert">The requested project is unavailable or is outside your current project permissions.</p>@endif
@endif
@if($prefix === 'admin.projects')
    @if($errors->any() && !$restore)<div class="notice notice-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@endif
<section class="card list-card" aria-label="Projects">
    <div class="table-toolbar">
        <form method="GET" action="{{ route($prefix.'.index') }}"><div class="field"><label for="project-status-filter">Project Status</label><select id="project-status-filter" name="status"><option value="">All current projects</option>@foreach($statuses + ['archived' => 'Archived'] as $value => $label)<option value="{{ $value }}" @selected(($module['status_filter'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div><button type="submit" class="button">Apply Filter</button></form>
        <button type="button" class="button button-primary" data-project-create data-dialog="project-editor" @disabled(empty($catalog['options']['association_records']) || empty($catalog['options']['fisheries_category_records']))><x-icon name="plus" />Add Project</button>
    </div>
    <div class="table-scroll" role="region" aria-label="Project list" tabindex="0"><table class="data-table"><caption class="sr-only">Association projects and implementation status</caption><thead><tr>@foreach($columns as $label)<th scope="col">{{ $label }}</th>@endforeach<th scope="col">Status</th><th scope="col">Actions</th></tr></thead><tbody>
    @foreach($rows as $row)
        <tr>@foreach($columns as $key => $label)<td @class(['primary-cell' => $key === 'title'])>{{ filled($row[$key] ?? null) ? $row[$key] : '—' }}</td>@endforeach
        <td><span class="badge badge-{{ $row['status_value'] === 'completed' ? 'success' : ($row['status_value'] === 'terminated' ? 'danger' : ($row['archived_at'] ? 'neutral' : 'warning')) }}">{{ in_array($row['status_value'], ['completed', 'terminated'], true) ? strtoupper($row['status_value']) : ($row['archived_at'] ? 'Archived' : $row['status']) }}</span>@if(in_array($row['status_value'], ['completed', 'terminated'], true) && $row['archived_at'])<span class="badge badge-neutral">Archived</span>@endif</td>
        <td><div class="row-actions"><button type="button" class="text-button" data-dialog="project-view-{{ $row['id'] }}" aria-label="View {{ $row['title'] }}">View</button><a class="text-button" href="{{ $row['detail_url'] }}">History / Details</a>@if($row['can_mutate'] ?? false)@if(!$row['archived_at'])<button type="button" class="text-button" data-project-edit="{{ $row['id'] }}" data-dialog="project-editor" aria-label="Edit {{ $row['title'] }}">Edit</button>@if($row['status_value'] === 'ongoing')<a class="text-button text-danger" href="{{ route($prefix.'.show', ['project' => $row['id'], 'finalize' => 'completed']) }}">Complete Project</a><a class="text-button text-danger" href="{{ route($prefix.'.show', ['project' => $row['id'], 'finalize' => 'terminated']) }}">Terminate Project</a>@endif @endif<button type="button" class="text-button" data-dialog="project-action-{{ $row['id'] }}">{{ $row['archived_at'] ? 'Unarchive' : 'Archive' }}</button>@else<span>Read-only</span>@endif</div></td></tr>
    @endforeach
    </tbody></table></div>
    @if($rows->isEmpty())<x-empty-state title="No projects found." message="Try another status filter or add a project for an available association." />@endif
    <footer class="table-footer"><span>{{ $rows->count() }} {{ $rows->count() === 1 ? 'project' : 'projects' }}</span></footer>
</section>
@if(empty($catalog['options']['association_records']))<p class="notice">No active associations are available for creating projects.</p>@endif
@if(empty($catalog['options']['fisheries_category_records']))<p class="notice">No active fisheries categories are available.</p>@endif
@foreach($rows as $row)
    <x-modal :id="'project-view-'.$row['id']" title="Project Details">
        <dl class="detail-grid">@foreach($columns + ['description' => 'Description', 'implementation_end_date' => 'Implementation End Date', 'status' => 'Status', 'remarks' => 'Remarks', 'archived_at' => 'Archived At'] as $key => $label)<div><dt>{{ $label }}</dt><dd>{{ filled($row[$key] ?? null) ? $row[$key] : '—' }}</dd></div>@endforeach</dl>
        @if(in_array($row['status_value'], ['completed', 'terminated'], true))<p class="notice">This project is permanently read-only and cannot be reopened. <a href="{{ $row['detail_url'] }}">View finalization information and history</a></p>
        @elseif(!($row['can_mutate'] ?? false))<p class="notice">This project's association is read-only. Operational changes are unavailable.</p>
        @elseif($row['archived_at'])<p class="notice">This project is archived. Unarchive it before editing.</p>@endif
        @include('projects.partials.location', ['row' => $row])
        <h3>Project Materials</h3>
        @forelse($row['materials'] as $material)<div class="repeat-row"><dl class="detail-grid">@foreach($materialFields as $key => $definition)<div><dt>{{ $definition[0] }}</dt><dd>{{ $key === 'delivery_status' ? ($delivery[$material[$key]] ?? $material[$key]) : (filled($material[$key] ?? null) ? $material[$key] : '—') }}</dd></div>@endforeach</dl></div>@empty<p>No materials recorded.</p>@endforelse
        <h3>Project Products</h3>
        @forelse($row['products'] as $product)
            <div class="repeat-row"><dl class="detail-grid">
                <div><dt>Product Name</dt><dd>{{ $product['product_name'] }}</dd></div>
                <div><dt>Product Type</dt><dd>{{ $productTypes[$product['product_type']] ?? $product['product_type'] }}</dd></div>
                <div><dt>Default Unit</dt><dd>{{ filled($product['default_unit']) ? $product['default_unit'] : '—' }}</dd></div>
                <div><dt>Active Status</dt><dd>{{ $product['is_active'] ? 'Active' : 'Inactive' }}</dd></div>
                <div><dt>Description</dt><dd>{{ filled($product['description']) ? $product['description'] : '—' }}</dd></div>
            </dl></div>
        @empty<p>No products recorded.</p>@endforelse
        <div class="modal-actions"><button type="button" class="button" data-close-dialog>Close</button></div>
    </x-modal>
    @if($row['can_mutate'] ?? false)
    <x-modal :id="'project-action-'.$row['id']" :title="$row['archived_at'] ? 'Unarchive Project' : 'Archive Project'">
        <p>{{ $row['archived_at'] ? 'Restore' : 'Archive' }} <strong>{{ $row['title'] }}</strong>? {{ $row['archived_at'] ? 'The project will return to the current project list.' : 'Archived projects remain available for viewing and cannot be edited.' }}</p>
        <form method="POST" action="{{ $row['archived_at'] ? $row['unarchive_url'] : $row['archive_url'] }}">@csrf @method('PATCH')<div class="modal-actions"><button type="button" class="button" data-close-dialog>Cancel</button><button type="submit" class="button {{ $row['archived_at'] ? 'button-primary' : 'button-danger' }}">{{ $row['archived_at'] ? 'Unarchive Project' : 'Archive Project' }}</button></div></form>
    </x-modal>
    @endif
@endforeach
<x-modal id="project-editor" :title="$editing ? 'Edit Project' : 'Add Project'">
    <form method="POST" action="{{ $editing['update_url'] ?? route($prefix.'.store') }}" id="project-form" @if($restore) data-feedback-error-bag="default" @endif>
        @csrf
        <input type="hidden" name="project_form" value="editor"><input type="hidden" name="project_record_id" value="{{ $editing['id'] ?? '' }}"><input type="hidden" name="_method" value="PATCH" @disabled(!$editing)>
        <p>Fields marked * are required.</p>
        @if($restore)<div class="notice notice-error" role="alert" data-project-errors>@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        <h3>Basic Project Information</h3><div class="form-grid">@foreach(array_slice($fields, 0, 3) as $field)<x-field :field="$field" prefix="project" :value="$restore ? old($field['name']) : ''" />@endforeach</div>
        <h3>SAAD Classification</h3><p>Livelihood Project Delivery <small>(System-controlled)</small></p>
        <h3>Fisheries Information</h3><div class="form-grid">
            <x-field :field="$fields[3]" prefix="project" :value="$restore ? old('fisheries_category_id') : ''" />
            <div class="field"><label for="project-commodity">Commodity <span class="field-hint">Optional</span></label><select id="project-commodity"><option value="">No commodity</option></select></div>
            <input type="hidden" name="commodity_id" value="{{ $restore ? old('commodity_id') : '' }}">
            <div class="field" id="project-new-commodity" hidden><label for="project-new-name">New Commodity Name *</label><input id="project-new-name" name="new_commodity_name" maxlength="255" value="{{ $restore ? old('new_commodity_name') : '' }}" disabled></div>
        </div>
        <h3>Implementation</h3><div class="form-grid">@foreach(array_slice($fields, 4) as $field)<x-field :field="$field" prefix="project" :value="$restore ? old($field['name']) : ($field['name'] === 'status' ? 'planned' : '')" />@endforeach</div>
        <h3 id="project-materials-heading" tabindex="-1">Project Materials</h3><p>Removing a row requests deletion when saved. Materials with monitoring history cannot be removed.</p>
        <input type="hidden" name="materials" value=""><div id="project-materials"></div><button type="button" class="button" id="project-add-material"><x-icon name="plus" />Add Material</button>
        <h3 id="project-products-heading" tabindex="-1">Project Products</h3><p>Removing a row requests deletion when saved. Products with monitoring history cannot be removed.</p>
        <input type="hidden" name="products" value=""><div id="project-products"></div><button type="button" class="button" id="project-add-product"><x-icon name="plus" />Add Product</button>
        <div class="modal-actions">@if($fromMonitoring)<a class="button" href="{{ $monitoringReturnUrl }}" data-return-monitoring>Return to Monitoring</a>@endif<button type="button" class="button" data-close-dialog>Cancel</button><button type="submit" class="button button-primary">Save Project</button></div>
    </form>
</x-modal>
<template id="project-material-template"><fieldset class="repeat-row"><legend>Material</legend><div class="repeat-row-heading"><strong data-material-heading></strong><button type="button" class="text-button text-danger" data-remove-material>Remove Material</button></div><div class="form-grid">
    @foreach($materialFields as $key => [$label, $type, $required])
        <div class="field {{ $type === 'textarea' ? 'field-wide' : '' }}"><label data-material-label="{{ $key }}">{{ $label }} <span class="field-hint">{{ $required ? '*' : 'Optional' }}</span></label>
        @if($type === 'select')<select data-material-input="{{ $key }}" @required($required)>@foreach($delivery as $value => $friendly)<option value="{{ $value }}">{{ $friendly }}</option>@endforeach</select>
        @elseif($type === 'textarea')<textarea data-material-input="{{ $key }}" rows="2"></textarea>
        @else<input data-material-input="{{ $key }}" type="{{ $type }}" @required($required) @if($type === 'number') min="0" step="any" @elseif($type === 'text') maxlength="{{ $key === 'unit' ? 100 : 255 }}" @endif>@endif</div>
    @endforeach
</div></fieldset></template>
<template id="project-product-template">
    <fieldset class="repeat-row"><legend>Product</legend>
        <div class="repeat-row-heading"><strong data-product-heading></strong><button type="button" class="text-button text-danger" data-remove-product>Remove Product</button></div>
        <div class="form-grid">
            <div class="field"><label data-product-label="product_name">Product Name *</label><input data-product-input="product_name" type="text" required maxlength="255"></div>
            <div class="field"><label data-product-label="product_type">Product Type *</label><select data-product-input="product_type" required><option value="">Select product type</option>@foreach($productTypes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
            <div class="field"><label data-product-label="default_unit">Default Unit <span class="field-hint">Optional</span></label><input data-product-input="default_unit" type="text" maxlength="100"></div>
            <div class="field"><label data-product-label="is_active">Active Status</label><select data-product-input="is_active"><option value="1">Active</option><option value="0">Inactive</option></select></div>
            <div class="field field-wide"><label data-product-label="description">Description <span class="field-hint">Optional</span></label><textarea data-product-input="description" rows="2"></textarea></div>
        </div>
    </fieldset>
</template>
<x-confirmation-modal :navigation="true" />
<script type="module">
    const records = {{ Illuminate\Support\Js::from($rows->values()) }};
    const commodities = {{ Illuminate\Support\Js::from($catalog['options']['commodity_records']) }};
    const initial = {{ Illuminate\Support\Js::from($restore ? old() : []) }};
    const restore = {{ Illuminate\Support\Js::from($restore) }};
    const navigationProject = {{ Illuminate\Support\Js::from($navigationProject['id'] ?? null) }};
    const resourceSection = {{ Illuminate\Support\Js::from($fromMonitoring ? $resourceSection : null) }};
    const returnRecord = {{ Illuminate\Support\Js::from($returnRecord) }};
    const createUrl = {{ Illuminate\Support\Js::from(route($prefix.'.store')) }};
    const form = document.getElementById('project-form');
    const category = form.elements.fisheries_category_id;
    const commodity = document.getElementById('project-commodity');
    const newName = form.elements.new_commodity_name;
    const materials = document.getElementById('project-materials');
    const products = document.getElementById('project-products');
    let nextMaterial = 0;
    let nextProduct = 0;
    function preserveRowId(row, collection, index, id) {
        if (!id) return;
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = `${collection}[${index}][id]`;
        input.value = id;
        row.querySelector('fieldset').append(input);
    }
    function setCommodityMode() {
        const adding = commodity.value === 'new';
        form.elements.commodity_id.disabled = adding;
        form.elements.commodity_id.value = adding ? '' : commodity.value;
        document.getElementById('project-new-commodity').hidden = !adding;
        newName.disabled = !adding;
        newName.required = adding;
    }
    function filterCommodities(selected = '') {
        commodity.replaceChildren(new Option('No commodity', ''));
        commodities.filter(record => String(record.category_id) === category.value).forEach(record => commodity.add(new Option(record.label, record.value)));
        if (category.value) commodity.add(new Option('Add New Commodity', 'new'));
        commodity.value = String(selected);
        if (commodity.selectedIndex < 0) commodity.value = '';
        setCommodityMode();
    }
    function addMaterial(values = {}) {
        const index = nextMaterial++;
        const row = document.getElementById('project-material-template').content.cloneNode(true);
        row.querySelector('[data-material-heading]').textContent = `Material ${index + 1}`;
        row.querySelectorAll('[data-material-input]').forEach(input => {
            const key = input.dataset.materialInput;
            input.id = `project-material-${index}-${key}`;
            input.name = `materials[${index}][${key}]`;
            input.value = values[key] ?? (key === 'delivery_status' ? 'planned' : '');
            row.querySelector(`[data-material-label="${key}"]`).htmlFor = input.id;
        });
        preserveRowId(row, 'materials', index, values.id);
        materials.append(row);
    }
    function addProduct(values = {}) {
        const index = nextProduct++;
        const row = document.getElementById('project-product-template').content.cloneNode(true);
        row.querySelector('[data-product-heading]').textContent = `Product ${index + 1}`;
        row.querySelectorAll('[data-product-input]').forEach(input => {
            const key = input.dataset.productInput;
            input.id = `project-product-${index}-${key}`;
            input.name = `products[${index}][${key}]`;
            input.value = key === 'is_active'
                ? ([false, 0, '0'].includes(values.is_active) ? '0' : '1')
                : (values[key] ?? '');
            row.querySelector(`[data-product-label="${key}"]`).htmlFor = input.id;
        });
        preserveRowId(row, 'products', index, values.id);
        products.append(row);
    }
    function populate(values, record = null) {
        form.reset();
        const action = new URL(record?.update_url ?? createUrl, window.location.origin);
        if (record && navigationProject === String(record.id)) {
            action.searchParams.set('from_monitoring', '1');
            action.searchParams.set('project_id', record.id);
            action.searchParams.set('resource_section', resourceSection);
            if (returnRecord) action.searchParams.set('monitoring_record', returnRecord);
        }
        form.action = action.href;
        form.elements._method.disabled = !record;
        form.elements.project_record_id.value = record?.id ?? '';
        document.getElementById('project-editor-title').textContent = record ? 'Edit Project' : 'Add Project';
        for (const key of ['association_id', 'title', 'description', 'fisheries_category_id', 'program_year', 'implementation_start_date', 'implementation_end_date', 'status', 'remarks', 'new_commodity_name']) {
            form.elements[key].value = values[key] ?? (key === 'status' ? 'planned' : '');
        }
        filterCommodities(values.new_commodity_name ? 'new' : (values.commodity_id ?? ''));
        materials.replaceChildren();
        nextMaterial = 0;
        Object.values(values.materials ?? {}).forEach(addMaterial);
        products.replaceChildren();
        nextProduct = 0;
        Object.values(values.products ?? {}).forEach(addProduct);
    }
    category.addEventListener('change', () => filterCommodities());
    commodity.addEventListener('change', setCommodityMode);
    document.getElementById('project-add-material').addEventListener('click', () => addMaterial());
    document.getElementById('project-add-product').addEventListener('click', () => addProduct());
    materials.addEventListener('click', event => {
        const button = event.target.closest('[data-remove-material]');
        if (!button) return;
        const row = button.closest('fieldset');
        const next = row.nextElementSibling?.querySelector('[data-material-input]') ?? row.previousElementSibling?.querySelector('[data-material-input]');
        row.remove();
        (next ?? document.getElementById('project-add-material')).focus();
    });
    products.addEventListener('click', event => {
        const button = event.target.closest('[data-remove-product]');
        if (!button) return;
        const row = button.closest('fieldset');
        const next = row.nextElementSibling?.querySelector('[data-product-input]') ?? row.previousElementSibling?.querySelector('[data-product-input]');
        row.remove();
        (next ?? document.getElementById('project-add-product')).focus();
    });
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-project-create], [data-project-edit]');
        if (!button) return;
        const record = button.hasAttribute('data-project-edit') ? records.find(row => String(row.id) === button.dataset.projectEdit && !row.archived_at && row.can_mutate) : null;
        if (button.hasAttribute('data-project-edit') && !record) return;
        populate(record ? {...record, status: record.status_value} : {}, record);
        form.querySelector('[data-project-errors]')?.remove();
        form.elements.association_id.focus();
    });
    populate(initial, restore ? records.find(row => String(row.id) === String(initial.project_record_id) && !row.archived_at && row.can_mutate) : null);
    if (restore) document.getElementById('project-editor').dataset.feedbackRestore = 'true';
    else if (navigationProject) {
        const record = records.find(row => String(row.id) === navigationProject && !row.archived_at && row.can_mutate);
        if (record) {
            populate({...record, status: record.status_value}, record);
            document.getElementById('project-editor').dataset.feedbackRestore = 'true';
            const heading = document.getElementById('project-' + resourceSection + '-heading');
            document.getElementById('project-editor').feedbackFocusTarget = heading;
        }
    }
    document.querySelectorAll('[data-return-monitoring]').forEach(link => link.addEventListener('click', event => {
        if (!document.getElementById('project-editor').open) return;
        event.preventDefault();
        document.dispatchEvent(new CustomEvent('assocmap:confirm-navigation', {detail: {
            link,
            title: 'Return to Monitoring?',
            message: 'Unsaved Project changes will be discarded. Save Project first to register your resources.',
            label: 'Leave and Return to Monitoring',
        }}));
    }));
</script>
@endsection
