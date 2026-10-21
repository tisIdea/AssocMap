<section aria-label="Existing accreditations">
    <h3>Existing accreditations</h3>
    <p>Update details from an existing accreditation document. This does not issue, approve or renew accreditation.</p>
    @forelse($editingAssociation['accreditations'] ?? [] as $item)
        <p>
            {{ $item['agency'] }} — {{ $item['registration_number'] }}
            @if($item['agency_active'] && ($editingAssociation['status_value'] ?? null) === 'active')
                <a class="text-button" href="{{ $item['update_url'] }}">Update Accreditation</a>
            @else
                <span>Read-only while the association or issuing agency is inactive.</span>
            @endif
        </p>
    @empty
        <p>No existing accreditation records. Registration records are preserved separately.</p>
    @endforelse
    @if($selectedAccreditation)
        @php
            $accreditationValue = function ($key) use ($associationEditorErrors, $selectedAccreditation) {
                $value = $associationEditorErrors ? old('registration.'.$key, $selectedAccreditation[$key] ?? null) : ($selectedAccreditation[$key] ?? null);
                return is_string($value) ? $value : '';
            };
        @endphp
        <fieldset class="form-grid">
            <legend>Update Accreditation</legend>
            <input type="hidden" name="registration[id]" value="{{ $selectedAccreditation['id'] }}">
            <input type="hidden" name="registration[expected_updated_at]" value="{{ $accreditationValue('expected_updated_at') }}">
            <p>Issuing agency: {{ $selectedAccreditation['agency'] }} (read-only)</p>
            <p>Primary: {{ $selectedAccreditation['is_primary'] ? 'Yes' : 'No' }} (read-only)</p>
            <div class="field"><label for="accreditation-number">Accreditation number *</label><input id="accreditation-number" name="registration[registration_number]" maxlength="150" value="{{ $accreditationValue('registration_number') }}" required></div>
            <div class="field"><label for="accreditation-date">Registration date *</label><input id="accreditation-date" name="registration[registration_date]" type="date" value="{{ $accreditationValue('registration_date') }}" required></div>
            <div class="field"><label for="accreditation-expiration">Expiration date (optional)</label><input id="accreditation-expiration" name="registration[expiration_date]" type="date" value="{{ $accreditationValue('expiration_date') }}"></div>
            <div class="field"><label for="accreditation-remarks">Remarks (optional)</label><textarea id="accreditation-remarks" name="registration[remarks]" maxlength="4000" rows="2">{{ $accreditationValue('remarks') }}</textarea></div>
        </fieldset>
        <p><a class="text-button" href="{{ route('admin.associations.index', ['editor' => $editingAssociation['id']]) }}">Cancel accreditation update</a></p>
    @endif
</section>
