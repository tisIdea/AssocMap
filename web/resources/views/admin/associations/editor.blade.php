@php
    $geography = app(\App\Services\AssociationGeographyService::class);
    $directGeography = $geography->enabled();
    $associationAreas = collect($catalog['options']['area_records']);
    $associationEditorErrors = $errors->any() && old('association_form') === 'editor';
    $editingAssociation = $associationEditorRecord ?? ($associationEditorErrors ? collect($module['rows'])->firstWhere('id', old('association_record_id')) : null);
    $associationOld = fn ($key, $default = null) => $associationEditorErrors ? old($key, $default) : ($editingAssociation[match ($key) { 'saad_phase2_join_date' => 'joined_at', 'status' => 'status_value', default => $key }] ?? $default);
    $selectedAccreditation = $editingAssociation['selected_accreditation'] ?? null;
    $selectedAssociationArea = $associationAreas->firstWhere('value', $associationOld('area_id'));
    $associationUnavailable = $editingAssociation && ((!$directGeography && !$selectedAssociationArea)
        || !in_array($associationOld('primary_field_officer_id'), array_column($catalog['options']['field_officer_records'] ?? [], 'value'), true));
    $associationMunicipalities = $associationAreas->filter(fn ($area) => filled($area['municipality_id']))->unique('municipality_id');
    $selectedMunicipality = $selectedAssociationArea['municipality_id'] ?? '';
    $selectedBarangay = $selectedAssociationArea['barangay_id'] ?? '';
    if ($directGeography) {
        $associationMunicipalities = collect($catalog['options']['municipality_records']);
        $associationBarangays = collect($catalog['options']['barangay_records']);
        $selectedMunicipality = $associationOld('municipality_id', '');
        $selectedBarangay = $associationOld('barangay_id', '');
        $associationUnavailable = $editingAssociation && (
            !$associationMunicipalities->contains('value', $selectedMunicipality)
            || !$associationBarangays->where('municipality_id', $selectedMunicipality)->contains('value', $selectedBarangay)
            || (filled($associationOld('area_id')) && !$selectedAssociationArea)
            || !in_array($associationOld('primary_field_officer_id'), array_column($catalog['options']['field_officer_records'] ?? [], 'value'), true));
    }
@endphp
<x-modal id="association-editor" :title="$selectedAccreditation ? 'Update Accreditation' : ($editingAssociation ? 'Edit Association' : 'Add Association')">
    <form method="POST" action="{{ $editingAssociation['update_url'] ?? route('admin.associations.store') }}" data-form data-association-form
        data-create-action="{{ route('admin.associations.store') }}"
        data-area-records="{{ json_encode($catalog['options']['area_records']) }}"
        data-direct-geography="{{ $directGeography ? 'true' : 'false' }}"
        @if($directGeography) data-barangay-records="{{ json_encode($catalog['options']['barangay_records']) }}" @endif
        data-association-auto-open="{{ ($associationOpenEditor ?? false) ? 'true' : 'false' }}"
        data-has-errors="{{ $associationEditorErrors ? 'true' : 'false' }}" @if($associationEditorErrors) data-feedback-error-bag="default" @endif>
        @csrf
        <input type="hidden" name="association_form" value="editor">
        <input type="hidden" name="_method" value="PATCH" data-association-method @disabled(!$editingAssociation)>
        <input type="hidden" name="association_record_id" value="{{ $editingAssociation['id'] ?? '' }}" @disabled(!$editingAssociation)>
        @if($editingAssociation)
            <input type="hidden" name="expected_primary_field_officer_id" value="{{ $associationOld('expected_primary_field_officer_id') }}">
        @endif
        @if($geography->usesDirectGeography() && $editingAssociation)
            <input type="hidden" name="expected_updated_at" value="{{ $associationOld('expected_updated_at') }}">
        @endif
        <p>Fields marked * are required.</p>
        @if(($editingAssociation['status_value'] ?? null) === 'inactive')<p class="notice">Use Active status to reactivate this association before making operational changes.</p>@endif
        @if($associationEditorErrors)
            <div class="notice notice-error" role="alert" data-association-validation>
                <x-icon name="info" />
                <div>@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
            </div>
        @endif
        <div class="form-grid">
            <x-field :field="['name' => 'name', 'label' => 'Association Name', 'required' => true]" prefix="association-editor" :value="$associationOld('name')" />
            <x-field :field="['name' => 'acronym', 'label' => 'Acronym', 'required' => false]" prefix="association-editor" :value="$associationOld('acronym')" />
            <div class="field">
                <label for="association-municipality">Municipality / City <span class="field-hint">*</span></label>
                <select id="association-municipality" data-association-municipality @if($directGeography) name="municipality_id" @endif required>
                    <option value="">Select municipality / city</option>
                    @foreach($associationMunicipalities as $area)
                        @php($municipalityId = $directGeography ? $area['value'] : $area['municipality_id'])
                        <option value="{{ $municipalityId }}" @selected($selectedMunicipality === $municipalityId)>{{ $directGeography ? $area['label'] : $area['municipality'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="association-barangay">Barangay <span class="field-hint">*</span></label>
                <select id="association-barangay" data-association-barangay @if($directGeography) name="barangay_id" @endif required>
                    <option value="">Select barangay</option>
                    @foreach(($directGeography ? $associationBarangays : $associationAreas)->where('municipality_id', $selectedMunicipality)->unique($directGeography ? 'value' : 'barangay_id') as $area)
                        @php($barangayId = $directGeography ? $area['value'] : $area['barangay_id'])
                        <option value="{{ $barangayId }}" @selected($selectedBarangay === $barangayId)>{{ $directGeography ? $area['label'] : $area['barangay'] }}</option>
                    @endforeach
                </select>
            </div>
            <x-field :field="['name' => 'area_id', 'label' => 'Specific Area', 'type' => 'select', 'choices' => $associationAreas->where('barangay_id', $selectedBarangay)->values()->all(), 'required' => !$directGeography, 'empty_label' => $directGeography ? 'No Specific Area' : 'Select specific area']" prefix="association-editor" :value="is_string($associationOld('area_id')) ? $associationOld('area_id') : ''" />
            @if($directGeography)
                <div class="field field-wide">
                    <label for="association-specific-address">Association Address / Sitio / Purok <span class="field-hint">Optional</span></label>
                    <textarea id="association-specific-address" name="specific_address" maxlength="500" rows="3" aria-describedby="association-address-help">{{ is_string($associationOld('specific_address')) ? $associationOld('specific_address') : '' }}</textarea>
                    <small id="association-address-help">Independent of the selected Specific Area. Leave blank if unavailable.</small>
                </div>
                <p class="field-wide notice" data-association-area-cleared role="status" hidden>The previous Specific Area was cleared because it does not belong to the selected Barangay. Review your selection before saving.</p>
            @endif
            <x-field :field="['name' => 'saad_phase2_join_date', 'label' => 'SAAD Phase II Join Date', 'type' => 'date', 'required' => true]" prefix="association-editor" :value="$associationOld('saad_phase2_join_date')" />
            <x-field :field="['name' => 'primary_field_officer_id', 'label' => 'Primary Field Officer', 'type' => 'select', 'choices' => $catalog['options']['field_officer_records'] ?? [], 'required' => true]" prefix="association-editor" :value="$associationOld('primary_field_officer_id')" />
            @if($editingAssociation['retained_officer'] ?? false)
                <p class="field-wide notice">The current officer is retained for historical continuity. You may keep this designation for other permitted edits. A replacement must be an eligible, activated Field Officer.</p>
            @endif
            <x-field :field="['name' => 'status', 'label' => 'Status', 'type' => 'select', 'choices' => [['value' => 'active', 'label' => 'Active'], ['value' => 'inactive', 'label' => 'Inactive']], 'required' => true]" prefix="association-editor" :value="$associationOld('status', 'active')" />
        </div>
        @if($editingAssociation)
            @include('admin.associations.accreditation-editor')
        @endif
        @unless($editingAssociation)
        <div data-association-registration>
            <h3>Existing registration or accreditation</h3>
            <p>Optional: record details from an existing document. This does not approve an association or issue a registration.</p>
            <label><input type="checkbox" data-registration-toggle @checked($associationOld('registration') !== null)> Record an existing registration</label>
            <fieldset class="form-grid" data-registration-fields @disabled($editingAssociation || $associationOld('registration') === null)>
                <legend>Registration details</legend>
                <div class="field"><label for="association-registration-agency">Registration agency *</label><select id="association-registration-agency" name="registration[agency_id]" required><option value="">Select agency</option>@foreach($catalog['options']['registration_agencies'] ?? [] as $agency)<option value="{{ $agency['value'] }}" @selected((string) $associationOld('registration.agency_id') === (string) $agency['value'])>{{ $agency['label'] }}</option>@endforeach</select></div>
                <div class="field"><label for="association-registration-number">Registration number *</label><input id="association-registration-number" name="registration[registration_number]" maxlength="150" value="{{ $associationOld('registration.registration_number') }}" required></div>
                <div class="field"><label for="association-registration-date">Registration date *</label><input id="association-registration-date" name="registration[registration_date]" type="date" value="{{ $associationOld('registration.registration_date') }}" required></div>
                <div class="field"><label for="association-registration-expiration">Expiration date (optional)</label><input id="association-registration-expiration" name="registration[expiration_date]" type="date" value="{{ $associationOld('registration.expiration_date') }}"></div>
                <div class="field"><label for="association-registration-primary">Primary registration</label><select id="association-registration-primary" name="registration[is_primary]"><option value="0">No</option><option value="1" @selected($associationOld('registration.is_primary') == '1')>Yes</option></select><small>A primary registration must not post-date SAAD Phase II joining.</small></div>
                <div class="field"><label for="association-registration-remarks">Remarks (optional)</label><textarea id="association-registration-remarks" name="registration[remarks]" maxlength="4000" rows="2">{{ $associationOld('registration.remarks') }}</textarea></div>
            </fieldset>
            @if(empty($catalog['options']['registration_agencies']))<p class="notice">No active registration agencies are available. The association may be added without registration details.</p>@endif
        </div>
        @endunless
        <p class="notice notice-error" role="alert" data-association-unavailable @if(!$associationUnavailable) hidden @endif>{{ $directGeography ? 'A recorded geographic choice or Field Officer is unavailable. Review the selections before saving.' : 'The current area or Field Officer is no longer an available active choice. Select an active Specific Area and Field Officer before saving.' }}</p>
        @if(!$directGeography && $associationAreas->isEmpty())<p class="notice notice-error" role="alert">No active Specific Areas are available. Add an area before creating an association.</p>@endif
        @if(empty($catalog['options']['field_officer_records']))<p class="notice notice-error" role="alert">No active Field Officers are available.</p>@endif
        <div class="modal-actions">
            <button type="button" class="button" data-close-dialog>Cancel</button>
            <button type="submit" class="button button-primary" data-association-submit @disabled((!$directGeography && $associationAreas->isEmpty()) || empty($catalog['options']['field_officer_records']))>{{ $selectedAccreditation ? 'Update Accreditation' : ($editingAssociation ? 'Save Changes' : 'Save Association') }}</button>
        </div>
    </form>
</x-modal>
