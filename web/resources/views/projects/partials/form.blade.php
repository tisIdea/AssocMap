@php
    $prefix = $projectRoutePrefix ?? 'admin.projects';
    $editing = $editRow ?? null;
    $restore = $errors->any() && old('project_form') === 'editor'
        && (string) old('project_record_id', '') === (string) ($editing['id'] ?? '');
    $resourceSection = request()->query('resource_section');
    $fromMonitoring = $editing && request()->boolean('from_monitoring') && in_array($resourceSection, ['products', 'materials'], true);
    $returnRecord = request()->query('monitoring_record');
    $returnRecord = is_string($returnRecord) && Illuminate\Support\Str::isUuid($returnRecord) ? $returnRecord : null;
    $monitoringPrefix = $prefix === 'admin.projects' ? 'admin.monitoring' : 'field-officer.monitoring';
    $monitoringReturnUrl = $returnRecord
        ? route($monitoringPrefix.'.workspace', [$returnRecord, $resourceSection === 'products' ? 'production' : 'materials'])
        : route($monitoringPrefix.'.index');
    $statuses = ['planned' => 'Planned', 'ongoing' => 'Ongoing', 'completed' => 'Completed', 'terminated' => 'Terminated', 'suspended' => 'Suspended', 'cancelled' => 'Cancelled'];
    $delivery = ['planned' => 'Planned', 'not_delivered' => 'Not Delivered', 'partially_delivered' => 'Partially Delivered', 'delivered' => 'Delivered'];
    $productTypes = ['raw' => 'Raw', 'processed' => 'Processed', 'other' => 'Other'];
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
    $initialValues = $restore ? old() : ($editing ? array_replace($editing, ['status' => $editing['status_value']]) : []);
@endphp
    <form method="POST" action="{{ $editing['update_url'] ?? route($prefix.'.store') }}" id="project-form" @if($restore) data-feedback-error-bag="default" @endif>
        @csrf
        <input type="hidden" name="project_form" value="editor"><input type="hidden" name="project_record_id" value="{{ $editing['id'] ?? '' }}"><input type="hidden" name="_method" value="PATCH" @disabled(!$editing)>
        <p>Fields marked * are required.</p>
        @if(empty($catalog['options']['association_records']) || empty($catalog['options']['fisheries_category_records']))<p class="notice">An active association and fisheries category are required. No eligible choices are currently available for one of these fields.</p>@endif
        @include('program-management.partials.lookup', ['kind' => 'associations', 'target' => 'association_id', 'lookupRoute' => $prefix.'.index', 'label' => 'Find an association'])
        @if($restore)<div class="notice notice-error" role="alert" data-project-errors>@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        <h3>Basic Project Information</h3><div class="form-grid">@foreach(array_slice($fields, 0, 3) as $field)<x-field :field="$field" prefix="project" :value="$initialValues[$field['name']] ?? ''" />@endforeach</div>
        <h3>SAAD Classification</h3><p>Livelihood Project Delivery <small>(System-controlled)</small></p>
        <h3>Fisheries Information</h3><div class="form-grid">
            <x-field :field="$fields[3]" prefix="project" :value="$restore ? old('fisheries_category_id') : ($editing['fisheries_category_id'] ?? '')" />
            <div class="field"><label for="project-commodity">Commodity <span class="field-hint">Optional</span></label><select id="project-commodity"><option value="">No commodity</option></select></div>
            <input type="hidden" name="commodity_id" value="{{ $restore ? old('commodity_id') : ($editing['commodity_id'] ?? '') }}">
            <div class="field" id="project-new-commodity" hidden><label for="project-new-name">New Commodity Name *</label><input id="project-new-name" name="new_commodity_name" maxlength="255" value="{{ $restore ? old('new_commodity_name') : ($editing['new_commodity_name'] ?? '') }}" disabled></div>
        </div>
        <h3>Implementation</h3><div class="form-grid">@foreach(array_slice($fields, 4) as $field)<x-field :field="$field" prefix="project" :value="$initialValues[$field['name']] ?? ($field['name'] === 'status' ? 'planned' : '')" />@endforeach</div>
        <h3 id="project-materials-heading" tabindex="-1">Project Materials</h3><p>Removing a row requests deletion when saved. Materials with monitoring history cannot be removed.</p>
        <input type="hidden" name="materials" value=""><div id="project-materials"></div><button type="button" class="button" id="project-add-material"><x-icon name="plus" />Add Material</button>
        <h3 id="project-products-heading" tabindex="-1">Project Products</h3><p>Removing a row requests deletion when saved. Products with monitoring history cannot be removed.</p>
        <input type="hidden" name="products" value=""><div id="project-products"></div><button type="button" class="button" id="project-add-product"><x-icon name="plus" />Add Product</button>
        <div class="modal-actions">
            @if($fromMonitoring)<a class="button" href="{{ $monitoringReturnUrl }}" data-return-monitoring>Return to Monitoring</a>@endif
            @if($editing)<a class="button" href="{{ route($prefix.'.show', $editing['id']) }}">Cancel</a>@else<button type="button" class="button" data-close-dialog>Cancel</button>@endif
            <button type="submit" class="button button-primary" @disabled(empty($catalog['options']['association_records']) || empty($catalog['options']['fisheries_category_records']))>Save Project</button>
        </div>
    </form>
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
    const editingRecord = {{ Illuminate\Support\Js::from($editing) }};
    const commodities = {{ Illuminate\Support\Js::from($catalog['options']['commodity_records']) }};
    const initial = {{ Illuminate\Support\Js::from($initialValues) }};
    const restore = {{ Illuminate\Support\Js::from($restore) }};
    const navigationProject = {{ Illuminate\Support\Js::from($fromMonitoring ? $editing['id'] : null) }};
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
        if (!editingRecord) document.getElementById('project-editor-title').textContent = 'Add Project';
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
        if (!event.target.closest('[data-project-create]')) return;
        populate({});
        form.querySelector('[data-project-errors]')?.remove();
        form.elements.association_id.focus();
    });
    populate(initial, editingRecord);
    if ((restore || {{ Illuminate\Support\Js::from($showEditor ?? false) }}) && !editingRecord) document.getElementById('project-editor').dataset.feedbackRestore = 'true';
    document.querySelectorAll('[data-return-monitoring]').forEach(link => link.addEventListener('click', event => {
        if (!editingRecord && !document.getElementById('project-editor').open) return;
        event.preventDefault();
        document.dispatchEvent(new CustomEvent('assocmap:confirm-navigation', {detail: {
            link,
            title: 'Return to Monitoring?',
            message: 'Unsaved Project changes will be discarded. Save Project first to register your resources.',
            label: 'Leave and Return to Monitoring',
        }}));
    }));
</script>
