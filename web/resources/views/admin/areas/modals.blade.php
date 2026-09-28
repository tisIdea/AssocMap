<x-modal id="area-editor" title="Add Area">

    <form data-preview-form data-area-form>

        <p>
            Maintain geographic reference records. Fields marked * are required.
            This preview does not save changes.
        </p>

        <div class="form-grid">

            <div class="field-wide">
                <x-field
                    :field="[
                        'name' => 'area_type',
                        'label' => 'Area Type',
                        'type' => 'select',
                        'choices' => ['Municipality', 'Barangay'],
                        'required' => true
                    ]"
                    prefix="area-editor"
                    value="Municipality"
                />
            </div>

            {{-- Municipality fields --}}
            <div class="form-grid field-wide" data-area-fields="Municipality">

                <div class="field-wide">
                    <x-field
                        :field="[
                            'name' => 'municipality_name',
                            'label' => 'Municipality Name',
                            'required' => true
                        ]"
                        prefix="area-editor"
                    />
                </div>

                {{-- Locked province --}}
                <x-field
                    :field="[
                        'name' => 'province',
                        'label' => 'Province',
                        'readonly' => true
                    ]"
                    prefix="area-editor"
                    value="Cebu"
                />

                {{-- Locked region --}}
                <x-field
                    :field="[
                        'name' => 'region',
                        'label' => 'Region',
                        'readonly' => true
                    ]"
                    prefix="area-editor"
                    value="Region VII"
                />

            </div>

            {{-- Barangay fields --}}
            <div
                class="form-grid field-wide"
                data-area-fields="Barangay"
                hidden
            >

                <x-field
                    :field="[
                        'name' => 'municipality',
                        'label' => 'Municipality',
                        'type' => 'search-select',
                        'choices' => $catalog['options']['municipality'],
                        'required' => true,
                        'help' => 'Search and choose a municipality.'
                    ]"
                    prefix="area-editor"
                />

                <x-field
                    :field="[
                        'name' => 'barangay_name',
                        'label' => 'Barangay Name',
                        'required' => true
                    ]"
                    prefix="area-editor"
                />

            </div>

            {{-- Automatically populated PSGC --}}
            <x-field
                :field="[
                    'name' => 'psgc_code',
                    'label' => 'PSGC Code',
                    'readonly' => true,
                    'help' => 'Automatically assigned from the selected municipality.'
                ]"
                prefix="area-editor"
            />

        </div>

        <div class="modal-actions">
            <button
                type="button"
                class="button"
                data-close-dialog
            >
                Cancel
            </button>

            <button
                type="submit"
                class="button button-primary"
                data-area-submit
            >
                Preview Area
            </button>
        </div>

    </form>

</x-modal>