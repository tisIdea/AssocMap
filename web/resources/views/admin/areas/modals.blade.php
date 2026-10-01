<x-modal id="area-editor" title="Add Area">
    <form data-form onsubmit="return false;" data-area-form>
        <p>Maintain geographic reference records. Fields marked * are required. Saving is not connected yet.</p>
        <div class="form-grid">
            <div class="field-wide">
                <x-field :field="['name' => 'area_type', 'label' => 'Area Type', 'type' => 'select', 'choices' => ['Municipality', 'Barangay'], 'required' => true]" prefix="area-editor" value="Municipality" />
            </div>
            <div class="form-grid field-wide" data-area-fields="Municipality">
                <div class="field-wide"><x-field :field="['name' => 'municipality_name', 'label' => 'Municipality Name', 'required' => true]" prefix="area-editor" /></div>
                <x-field :field="['name' => 'province', 'label' => 'Province']" prefix="area-editor" value="Cebu" />
                <x-field :field="['name' => 'region', 'label' => 'Region']" prefix="area-editor" value="Region VII" />
            </div>
            <div class="form-grid field-wide" data-area-fields="Barangay" hidden>
                <x-field :field="['name' => 'municipality', 'label' => 'Municipality', 'type' => 'search-select', 'choices' => $catalog['options']['municipality'], 'required' => true, 'help' => 'Municipalities will appear once connected.']" prefix="area-editor" />
                <x-field :field="['name' => 'barangay_name', 'label' => 'Barangay Name', 'required' => true]" prefix="area-editor" />
            </div>
            <x-field :field="['name' => 'psgc_code', 'label' => 'PSGC Code']" prefix="area-editor" />
            <x-field :field="['name' => 'status', 'label' => 'Status', 'type' => 'select', 'choices' => ['Active', 'Inactive']]" prefix="area-editor" value="Active" />
            <x-field :field="['name' => 'source', 'label' => 'Source', 'type' => 'select', 'choices' => ['PSGC', 'Manual']]" prefix="area-editor" value="Manual" />
        </div>
        <div class="modal-actions">
            <button type="button" class="button" data-close-dialog>Cancel</button>
            <button type="button" disabled class="button button-primary" data-area-submit>Save Area</button>
        </div>
    </form>
</x-modal>

<x-modal id="area-modal" title="Area Details">
    <dl class="detail-grid">
        <div><dt>Area Name</dt><dd data-area-detail="name">—</dd></div>
        <div><dt>Type</dt><dd><span class="badge badge-neutral" data-area-detail="area_type">—</span></dd></div>
        <div><dt>Municipality</dt><dd data-area-detail="municipality">—</dd></div>
        <div><dt>PSGC Code</dt><dd data-area-detail="psgc_code">—</dd></div>
        <div data-area-province><dt>Province</dt><dd data-area-detail="province">—</dd></div>
        <div data-area-region><dt>Region</dt><dd data-area-detail="region">—</dd></div>
        <div><dt>Source</dt><dd><span class="badge badge-neutral" data-area-detail="source">—</span></dd></div>
        <div><dt>Status</dt><dd><span class="badge badge-neutral" data-area-detail="status">—</span></dd></div>
    </dl>
    <p class="muted">No area selected.</p>
</x-modal>
