<template id="monitoring-monthly-template"><fieldset class="monitoring-card" data-kind="monthly"><legend>Monthly Production</legend><input type="hidden" data-key="id" disabled>
    <div class="form-grid">
        <div class="field"><label data-label="month">Production Month *</label><select data-key="month" required></select></div>
        <div class="field"><label data-label="activity_count">Number of Production Activities *</label><input data-key="activity_count" type="number" min="1" max="2147483647" step="1" required></div>
        <div class="field"><label data-label="total_quantity">Total Production Quantity *</label><input data-key="total_quantity" type="number" min="0" max="999999999999.99" step="0.01" required></div>
        <div class="field"><label data-label="remarks">Production Remarks</label><textarea data-key="remarks" rows="2"></textarea></div>
    </div><div class="monitoring-card-actions"><button type="button" class="button" data-cancel-monthly>Cancel</button><button type="button" class="text-button text-danger" data-remove-monthly>Remove Month</button></div>
</fieldset></template>
@foreach($repeatFields as $kind => $definitions)
<template id="monitoring-{{ $kind }}-template"><fieldset class="repeat-row monitoring-card" data-kind="{{ $kind }}"><legend>{{ $kind === 'materials' ? 'Material' : ucfirst($kind) }}</legend><div class="repeat-row-heading"><strong data-row-heading></strong><button type="button" class="text-button text-danger" data-monitoring-remove-row>Remove {{ $kind === 'materials' ? 'Material' : ucfirst($kind) }}</button></div><input type="hidden" data-key="id" disabled>
    @if($kind !== 'income')
        <div class="field"><label data-resource-mode-label>Project {{ $kind === 'production' ? 'Product' : 'Material' }}</label><select data-resource-mode><option value="existing">Select Existing Project {{ $kind === 'production' ? 'Product' : 'Material' }}</option><option value="new">Register New Project {{ $kind === 'production' ? 'Product' : 'Material' }}</option></select></div>
        <fieldset data-registration-panel disabled hidden><legend>New Project {{ $kind === 'production' ? 'Product' : 'Material' }}</legend><p class="field-hint">This resource will be registered under the selected project when you save Monitoring.</p><div class="form-grid">
            @foreach($registrationFields[$kind] as $definition)
                @php([$resourceKey, $resourceLabel, $resourceType, $resourceRequired] = $definition)
                <div class="field {{ $resourceType === 'textarea' ? 'field-wide' : '' }}"><label data-resource-label="{{ $resourceKey }}">{{ $resourceLabel }} <span class="field-hint">{{ $resourceRequired ? '*' : 'Optional' }}</span></label>
                    @if($resourceType === 'select')<select data-resource-key="{{ $resourceKey }}" @required($resourceRequired)><option value="">Select…</option>@foreach($definition[4] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                    @elseif($resourceType === 'textarea')<textarea data-resource-key="{{ $resourceKey }}" rows="2"></textarea>
                    @else<input type="{{ $resourceType }}" data-resource-key="{{ $resourceKey }}" @required($resourceRequired) @if($resourceType === 'number') min="0" max="999999999999.99" step="0.01" @endif @if(in_array($resourceKey, ['unit', 'default_unit'])) maxlength="50" @elseif(in_array($resourceKey, ['item_name', 'product_name'])) maxlength="255" @endif>@endif
                </div>
            @endforeach
        </div></fieldset>
        <h4>Monitoring {{ $kind === 'production' ? 'Production' : 'Material Condition' }}</h4>
    @endif
    <div class="form-grid">
    @foreach($definitions as [$key, $label, $type, $required])
        <div class="field {{ $type === 'textarea' ? 'field-wide' : '' }}" @if($key === 'period_type') hidden @endif @if(in_array($key, ['project_product_id', 'project_material_id'])) data-existing-resource @endif><label data-label="{{ $key }}">{{ $label }} <span class="field-hint">{{ $required ? '*' : 'Optional' }}</span></label>
        @if($key === 'period_type')<input type="hidden" data-key="period_type">
        @elseif($type === 'select')<select data-key="{{ $key }}" @required($required)><option value="">Select…</option>@if($key === 'period_type')<option value="monthly">Monthly</option><option value="quarterly">Quarterly</option>@elseif($key === 'month')@foreach($months as $value => $month)<option value="{{ $value }}">{{ $month }}</option>@endforeach @endif</select>
        @elseif($type === 'textarea')<textarea data-key="{{ $key }}" rows="2"></textarea>
        @else<input type="{{ $type }}" data-key="{{ $key }}" @required($required) @if($type === 'number') min="0" step="any" @endif @if(in_array($key, ['unit', 'condition'])) maxlength="50" @endif>@endif
        </div>
    @endforeach
    </div>
    @if($kind === 'production')<p data-product-type hidden></p><h4>Monthly Production Details</h4><p>Months without production need no entry. Quantities use this quarterly product’s unit.</p><p data-monthly-subtotal></p><div data-monthly-container></div><button type="button" class="button" data-add-monthly>Add Production Month</button>@endif
    @if($kind === 'production')<input type="hidden" data-income-empty value=""><h4 data-income-title>Income Monitoring</h4><div data-income-container class="monitoring-editor-cards"></div><p data-income-notice>No Income records yet.</p><button type="button" class="button" data-add-income>Add Income</button>@endif
</fieldset></template>
@endforeach
