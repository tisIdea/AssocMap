@if(request()->routeIs('admin.associations.index') && !isset($requestedModule))
    @include('dashboard.index')
@else
    @include('admin.shared.index')
    @if($errors->has('association'))
        <div class="notice notice-error" role="alert"><x-icon name="info" /><span>{{ $errors->first('association') }}</span></div>
    @endif
    @if(isset($catalog['options']['area_records']))
        @php
            $associationAreas = collect($catalog['options']['area_records']);
            $editingAssociation = collect($module['rows'])->firstWhere('id', old('association_record_id'));
            $associationEditorErrors = $errors->hasAny(['name', 'acronym', 'area_id', 'saad_phase2_join_date', 'primary_field_officer_id', 'status']);
            $selectedAssociationArea = $associationAreas->firstWhere('value', old('area_id'));
            $associationMunicipalities = $associationAreas->filter(fn ($area) => filled($area['municipality_id']))->unique('municipality_id');
            $selectedMunicipality = $selectedAssociationArea['municipality_id'] ?? '';
            $selectedBarangay = $selectedAssociationArea['barangay_id'] ?? '';
        @endphp
        <x-modal id="association-editor" :title="$editingAssociation ? 'Edit Association' : 'Add Association'">
            <form method="POST" action="{{ $editingAssociation['update_url'] ?? route('admin.associations.store') }}" data-form data-association-form
                data-create-action="{{ route('admin.associations.store') }}"
                data-area-records="{{ json_encode($catalog['options']['area_records']) }}"
                data-has-errors="{{ $associationEditorErrors ? 'true' : 'false' }}">
                @csrf
                <input type="hidden" name="_method" value="PATCH" data-association-method @disabled(!$editingAssociation)>
                <input type="hidden" name="association_record_id" value="{{ $editingAssociation['id'] ?? '' }}" @disabled(!$editingAssociation)>
                <p>Fields marked * are required.</p>
                @if($associationEditorErrors)
                    <div class="notice notice-error" role="alert" data-association-validation>
                        <x-icon name="info" />
                        <div>@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
                    </div>
                @endif
                <div class="form-grid">
                    <x-field :field="['name' => 'name', 'label' => 'Association Name', 'required' => true]" prefix="association-editor" :value="old('name')" />
                    <x-field :field="['name' => 'acronym', 'label' => 'Acronym', 'required' => false]" prefix="association-editor" :value="old('acronym')" />
                    <div class="field">
                        <label for="association-municipality">Municipality / City <span class="field-hint">*</span></label>
                        <select id="association-municipality" data-association-municipality required>
                            <option value="">Select municipality / city</option>
                            @foreach($associationMunicipalities as $area)
                                <option value="{{ $area['municipality_id'] }}" @selected($selectedMunicipality === $area['municipality_id'])>{{ $area['municipality'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="association-barangay">Barangay <span class="field-hint">*</span></label>
                        <select id="association-barangay" data-association-barangay required>
                            <option value="">Select barangay</option>
                            @foreach($associationAreas->where('municipality_id', $selectedMunicipality)->unique('barangay_id') as $area)
                                <option value="{{ $area['barangay_id'] }}" @selected($selectedBarangay === $area['barangay_id'])>{{ $area['barangay'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <x-field :field="['name' => 'area_id', 'label' => 'Specific Area', 'type' => 'select', 'choices' => $associationAreas->where('barangay_id', $selectedBarangay)->values()->all(), 'required' => true]" prefix="association-editor" :value="old('area_id')" />
                    <x-field :field="['name' => 'saad_phase2_join_date', 'label' => 'SAAD Phase II Join Date', 'type' => 'date', 'required' => true]" prefix="association-editor" :value="old('saad_phase2_join_date')" />
                    <x-field :field="['name' => 'primary_field_officer_id', 'label' => 'Primary Field Officer', 'type' => 'select', 'choices' => $catalog['options']['field_officer_records'] ?? [], 'required' => true]" prefix="association-editor" :value="old('primary_field_officer_id')" />
                    <x-field :field="['name' => 'status', 'label' => 'Status', 'type' => 'select', 'choices' => [['value' => 'active', 'label' => 'Active'], ['value' => 'inactive', 'label' => 'Inactive']], 'required' => true]" prefix="association-editor" :value="old('status', 'active')" />
                </div>
                <p class="notice notice-error" role="alert" data-association-unavailable hidden>The current area or Field Officer is no longer an available active choice. Select an active Specific Area and Field Officer before saving.</p>
                @if($associationAreas->isEmpty())<p class="notice notice-error" role="alert">No active Specific Areas are available. Add an area before creating an association.</p>@endif
                @if(empty($catalog['options']['field_officer_records']))<p class="notice notice-error" role="alert">No active Field Officers are available.</p>@endif
                <div class="modal-actions">
                    <button type="button" class="button" data-close-dialog>Cancel</button>
                    <button type="submit" class="button button-primary" data-association-submit @disabled($associationAreas->isEmpty() || empty($catalog['options']['field_officer_records']))>{{ $editingAssociation ? 'Save Changes' : 'Save Association' }}</button>
                </div>
            </form>
        </x-modal>
    @endif
    <x-modal id="association-modal" title="Association Details">
        <dl class="detail-grid">
            @foreach($module['columns'] as $key => $label)
                <div><dt>{{ $label }}</dt><dd data-association-detail="{{ $key }}">—</dd></div>
            @endforeach
            <div><dt>Archived</dt><dd data-association-detail="archived">—</dd></div>
            <div data-association-archived-date hidden><dt>Archived At</dt><dd data-association-detail="archived_at">—</dd></div>
        </dl>
    </x-modal>
@endif
