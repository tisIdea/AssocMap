@php
    $activityPrefix = $activityPrefix ?? $prefix.'.program-activities';
    $isFieldOfficer = $prefix === 'field-officer';
    $editing = $editRow ?? null;
    $restore = $errors->any() && old('program_activity_form') === 'editor'
        && (string) old('program_activity_record_id', '') === (string) ($editing['id'] ?? '');
    $initialValues = $restore ? old() : (isset($editing) ? array_replace($editing, ['status' => $editing['status_value']]) : []);
    $statuses = ['planned' => 'Planned', 'ongoing' => 'Ongoing', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
    $sections = [
        'General Information' => [
            ['name' => 'title', 'label' => 'Activity Title', 'type' => 'text', 'required' => true, 'maxlength' => 255],
            ['name' => 'description', 'label' => 'Description', 'type' => 'textarea'],
        ],
        'Program Information' => [
            ['name' => 'saad_activity_id', 'label' => 'SAAD Activity', 'type' => 'select', 'required' => true,
                'choices' => $activities->map(fn ($activity) => ['value' => $activity->id, 'label' => $activity->name])->all()],
        ],
        'Association and Project Relationships' => [
            ['name' => 'association_id', 'label' => 'Association', 'type' => 'select', 'required' => $isFieldOfficer,
                'choices' => $associations->map(fn ($association) => ['value' => $association->id, 'label' => $association->name])->all(),
                'empty_label' => $isFieldOfficer ? 'Select assigned association' : 'Program-wide (no association)'],
            ['name' => 'related_project_id', 'label' => 'Related Project', 'type' => 'select',
                'choices' => $projects->map(fn ($project) => ['value' => $project->id, 'label' => $project->title])->all(),
                'empty_label' => 'No related project'],
        ],
        'Activity Schedule and Status' => [
            ['name' => 'program_year', 'label' => 'Program Year', 'type' => 'number', 'required' => true, 'min' => 2000, 'max' => 2100, 'step' => 1],
            ['name' => 'activity_date', 'label' => 'Activity Date', 'type' => 'date', 'required' => true],
            ['name' => 'venue', 'label' => 'Venue', 'type' => 'text', 'maxlength' => 255],
            ['name' => 'conducted_by', 'label' => 'Conducted By', 'type' => 'text', 'maxlength' => 255],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true,
                'choices' => collect($statuses)->map(fn ($label, $value) => compact('value', 'label'))->all()],
            ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea'],
        ],
    ];
@endphp
<form method="POST" action="{{ $editing['update_url'] ?? route($activityPrefix.'.store') }}" id="program-activity-form" data-program-activity-form @if($restore) data-feedback-error-bag="default" @endif>
    @csrf
    @if($editing)
        @method('PATCH')
    @endif
    <input type="hidden" name="program_activity_form" value="editor">
    <input type="hidden" name="program_activity_record_id" value="{{ $editing['id'] ?? '' }}">
    <p>Fields marked * are required. Choices are limited to 25 matches; use Find to search for another record.</p>
    @if($activities->isEmpty() || ($isFieldOfficer && $associations->isEmpty()))
        <p class="notice">{{ $activities->isEmpty() ? 'No eligible SAAD activities are shown. Use Find to search for an available activity.' : 'No assigned active associations are shown. Use Find to search for an available association.' }}</p>
    @endif
    @if($restore)
        <div class="notice notice-error" role="alert" data-program-activity-errors>
            @foreach($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif
    @foreach($sections as $heading => $fields)
        <section class="form-section">
            <div class="section-heading"><h3>{{ $heading }}</h3></div>
            @if($heading === 'Association and Project Relationships')
                <p class="section-help">{{ $isFieldOfficer ? 'An assigned active association is required.' : 'Leave Association as Program-wide to record an activity without an association.' }} Related projects must belong to the selected association. Recorded project selections are retained; choose No related project only when you intend to remove the link.</p>
                <p class="notice" role="status" data-activity-project-notice hidden></p>
                @include('program-management.partials.lookup', ['kind' => 'associations', 'target' => 'association_id', 'lookupRoute' => $activityPrefix.'.index', 'label' => 'Find an association', 'emptyLabel' => $isFieldOfficer ? 'Select assigned association' : 'Program-wide (no association)'])
            @endif
            <div class="form-grid">
                @foreach($fields as $field)
                    @php
                        $rawValue = $initialValues[$field['name']] ?? ($field['name'] === 'status' ? 'planned' : '');
                        $value = is_scalar($rawValue) ? (string) $rawValue : (string) ($editing[$field['name']] ?? '');
                    @endphp
                    <div class="field {{ $field['type'] === 'textarea' ? 'field-wide' : '' }}">
                        <label for="program-activity-{{ $field['name'] }}">{{ $field['label'] }} <span class="field-hint">{{ ($field['required'] ?? false) ? '*' : 'Optional' }}</span></label>
                        @if($field['type'] === 'select')
                            <select id="program-activity-{{ $field['name'] }}" name="{{ $field['name'] }}" @required($field['required'] ?? false) @if($restore && $errors->has($field['name'])) aria-invalid="true" aria-describedby="program-activity-{{ $field['name'] }}-error" @endif>
                                <option value="">{{ $field['empty_label'] ?? 'Select...' }}</option>
                                @foreach($field['choices'] as $choice)
                                    <option value="{{ $choice['value'] }}" @selected($value === (string) $choice['value'])>{{ $choice['label'] }}</option>
                                @endforeach
                                @if($value !== '' && !collect($field['choices'])->contains(fn ($choice) => (string) $choice['value'] === $value))
                                    <option value="{{ $value }}" selected>Previous selection unavailable - review before saving</option>
                                @endif
                            </select>
                        @elseif($field['type'] === 'textarea')
                            <textarea id="program-activity-{{ $field['name'] }}" name="{{ $field['name'] }}" rows="4" @if($restore && $errors->has($field['name'])) aria-invalid="true" aria-describedby="program-activity-{{ $field['name'] }}-error" @endif>{{ $value }}</textarea>
                        @else
                            <input id="program-activity-{{ $field['name'] }}" name="{{ $field['name'] }}" type="{{ $field['type'] }}" value="{{ $value }}" @required($field['required'] ?? false) @foreach(['maxlength', 'min', 'max', 'step'] as $attribute) @if(isset($field[$attribute])) {{ $attribute }}="{{ $field[$attribute] }}" @endif @endforeach @if($restore && $errors->has($field['name'])) aria-invalid="true" aria-describedby="program-activity-{{ $field['name'] }}-error" @endif>
                        @endif
                        @if($restore)
                            @error($field['name'])<span class="field-error" id="program-activity-{{ $field['name'] }}-error">{{ $message }}</span>@enderror
                        @endif
                    </div>
                @endforeach
            </div>
            @if($heading === 'Program Information')
                @include('program-management.partials.lookup', ['kind' => 'activities', 'target' => 'saad_activity_id', 'lookupRoute' => $activityPrefix.'.index', 'label' => 'Find a SAAD activity'])
            @elseif($heading === 'Association and Project Relationships')
                @include('program-management.partials.lookup', ['kind' => 'projects', 'target' => 'related_project_id', 'lookupRoute' => $activityPrefix.'.index', 'label' => 'Find a related project'])
            @endif
        </section>
    @endforeach
    <div class="modal-actions">
        @if($editing)
            <a class="button" href="{{ route($activityPrefix.'.show', $editing['id'] ?? '') }}">Cancel</a>
        @else
            <button type="button" class="button" data-close-dialog>Cancel</button>
        @endif
        <button type="submit" class="button button-primary">Save Program Activity</button>
    </div>
</form>
