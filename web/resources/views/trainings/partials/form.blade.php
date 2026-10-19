@php
    $trainingPrefix = $trainingPrefix ?? 'admin.trainings';
    $statuses = ['scheduled' => 'Scheduled', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
    $editing = $editRow ?? null;
    $restore = $errors->any() && old('training_form') === 'editor'
        && (string) old('training_record_id', '') === (string) ($editing['id'] ?? '');
    $nameOf = fn ($member) => $member ? collect([$member->first_name, $member->middle_name, $member->last_name, $member->suffix])->filter(fn ($part) => filled($part))->implode(' ') : 'Member unavailable';
    $memberOptions = $members->map(fn ($member) => ['value' => $member->id, 'label' => $nameOf($member), 'association_id' => $member->association_id])->values();
    $projectOptions = $projects->map(fn ($project) => ['value' => $project->id, 'label' => $project->title, 'association_id' => $project->association_id])->values();
    $sections = [
        'Basic Training Information' => [
            ['name' => 'association_id', 'label' => 'Association', 'type' => 'select', 'required' => true, 'choices' => $associations->map(fn ($association) => ['value' => $association->id, 'label' => $association->name])->all()],
            ['name' => 'title', 'label' => 'Training Title', 'type' => 'text', 'required' => true, 'maxlength' => 255],
            ['name' => 'description', 'label' => 'Description', 'type' => 'textarea'],
        ],
        'Classification' => [
            ['name' => 'saad_activity_id', 'label' => 'SAAD Training Activity', 'type' => 'select', 'required' => true, 'choices' => $activities->map(fn ($activity) => ['value' => $activity->id, 'label' => $activity->name])->all()],
            ['name' => 'related_project_id', 'label' => 'Related Project', 'type' => 'select', 'choices' => [], 'empty_label' => 'No related project'],
        ],
        'Implementation' => [
            ['name' => 'program_year', 'label' => 'Program Year', 'type' => 'number', 'required' => true, 'min' => 2000, 'max' => 2100, 'step' => 1],
            ['name' => 'start_date', 'label' => 'Start Date', 'type' => 'date', 'required' => true],
            ['name' => 'end_date', 'label' => 'End Date', 'type' => 'date'],
            ['name' => 'venue', 'label' => 'Venue', 'type' => 'text', 'maxlength' => 255],
            ['name' => 'conducted_by', 'label' => 'Conducted By', 'type' => 'text', 'maxlength' => 255],
            ['name' => 'training_cost', 'label' => 'Training Cost', 'type' => 'number', 'min' => 0, 'step' => 'any'],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'choices' => collect($statuses)->map(fn ($label, $value) => compact('value', 'label'))->all()],
            ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea'],
        ],
    ];
    $initialValues = $restore ? old() : ($editing ? array_replace($editing, ['status' => $editing['status_value']]) : []);
@endphp
    <form method="POST" action="{{ $editing['update_url'] ?? route($trainingPrefix.'.store') }}" id="training-form" @if($restore) data-feedback-error-bag="default" @endif>
        @csrf
        <input type="hidden" name="training_form" value="editor"><input type="hidden" name="training_record_id" value="{{ $editing['id'] ?? '' }}"><input type="hidden" name="_method" value="PATCH" @disabled(!$editing)>
        <p>Fields marked * are required.</p>
        @if($associations->isEmpty() || $activities->isEmpty())<p class="notice">An active association and SAAD training activity are required. No eligible choices are currently available for one of these fields.</p>@endif
        @include('program-management.partials.lookup', ['kind' => 'associations', 'target' => 'association_id', 'lookupRoute' => $trainingPrefix.'.index', 'label' => 'Find an association'])
        @if($restore)<div class="notice notice-error" role="alert" data-training-errors>@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        @foreach($sections as $heading => $fields)
            <section class="form-section"><div class="section-heading"><h3>{{ $heading }}</h3></div><div class="form-grid">
            @foreach($fields as $field)
                @php($value = $initialValues[$field['name']] ?? ($field['name'] === 'status' ? 'scheduled' : ''))
                <div class="field {{ $field['type'] === 'textarea' ? 'field-wide' : '' }}">
                    <label for="training-{{ $field['name'] }}">{{ $field['label'] }} <span class="field-hint">{{ ($field['required'] ?? false) ? '*' : 'Optional' }}</span></label>
                    @if($field['type'] === 'select')
                        <select id="training-{{ $field['name'] }}" name="{{ $field['name'] }}" @required($field['required'] ?? false) @if($restore && $errors->has($field['name'])) aria-invalid="true" aria-describedby="training-{{ $field['name'] }}-error" @endif>
                            <option value="">{{ $field['empty_label'] ?? 'Select…' }}</option>@foreach($field['choices'] as $choice)<option value="{{ $choice['value'] }}" @selected((string) $value === (string) $choice['value'])>{{ $choice['label'] }}</option>@endforeach
                        </select>
                    @elseif($field['type'] === 'textarea')
                        <textarea id="training-{{ $field['name'] }}" name="{{ $field['name'] }}" rows="3" @if($restore && $errors->has($field['name'])) aria-invalid="true" aria-describedby="training-{{ $field['name'] }}-error" @endif>{{ $value }}</textarea>
                    @else
                        <input id="training-{{ $field['name'] }}" name="{{ $field['name'] }}" type="{{ $field['type'] }}" value="{{ $value }}" @required($field['required'] ?? false) @foreach(['maxlength', 'min', 'max', 'step'] as $attribute) @if(isset($field[$attribute])) {{ $attribute }}="{{ $field[$attribute] }}" @endif @endforeach @if($restore && $errors->has($field['name'])) aria-invalid="true" aria-describedby="training-{{ $field['name'] }}-error" @endif>
                    @endif
                    @if($restore) @error($field['name'])<span class="field-error" id="training-{{ $field['name'] }}-error">{{ $message }}</span>@enderror @endif
                </div>
            @endforeach
            </div>
            @if($heading === 'Classification')@include('program-management.partials.lookup', ['kind' => 'projects', 'target' => 'related_project_id', 'lookupRoute' => $trainingPrefix.'.index', 'label' => 'Find a related project'])@endif
            </section>
        @endforeach
        <p class="notice" id="training-choice-notice" role="status" hidden></p>
        <section class="form-section"><div class="section-heading"><h3>Participants and Attendance</h3></div><p class="section-help">Select an association first. Each member can be included once. Recorded participants remain available even if their membership is now inactive or archived. Attendance and remarks can be corrected. Remove Participant explicitly removes that participation when saved; to replace a recorded member, remove their row and add the correct member.</p>
            @if(isset($participants))<p class="notice">Showing participation records {{ $participants->firstItem() ?? 0 }}–{{ $participants->lastItem() ?? 0 }} of {{ $participants->total() }}. Save changes before opening another page. Participants on other pages are retained.</p>@endif
            @include('program-management.partials.lookup', ['kind' => 'members', 'target' => 'participant_choices', 'lookupRoute' => $trainingPrefix.'.index', 'label' => 'Find a member to add'])
            <input type="hidden" name="expected_updated_at" value="{{ $initialValues['expected_updated_at'] ?? '' }}"><input type="hidden" name="participants" value=""><div id="training-removed-participants"></div><div id="training-participants"></div><p id="training-participants-empty" class="participant-empty muted">No participants selected.</p>
            <button type="button" class="button" id="training-add-participant"><x-icon name="plus" />Add Participant</button>
        </section>
        <div class="modal-actions">@if($editing)<a class="button" href="{{ route($trainingPrefix.'.show', $editing['id']) }}">Cancel</a>@else<button type="button" class="button" data-close-dialog>Cancel</button>@endif<button type="submit" class="button button-primary">Save Training</button></div>
    </form>
@if(isset($participants))@include('program-management.partials.pagination', ['paginator' => $participants, 'label' => 'participants', 'guardUnsaved' => true])@endif
<x-confirmation-modal :navigation="true" />
<template id="training-participant-template"><fieldset class="repeat-row"><legend>Participant</legend><div class="repeat-row-heading"><strong data-participant-heading></strong><button type="button" class="text-button text-danger" data-remove-participant>Remove Participant</button></div><div class="form-grid">
    <div class="field"><label data-participant-label="member_id">Member *</label><select data-participant-input="member_id" required><option value="">Select member</option></select></div>
    <div class="field"><label data-participant-label="attendance_status">Attendance Status *</label><select data-participant-input="attendance_status" required><option value="present">Present</option><option value="absent">Absent</option></select></div>
    <div class="field field-wide"><label data-participant-label="remarks">Remarks <span class="field-hint">Optional</span></label><textarea data-participant-input="remarks" rows="2"></textarea></div>
</div></fieldset></template>
<script type="module">
    const editingRecord = {{ Illuminate\Support\Js::from($editing) }};
    let projects = {{ Illuminate\Support\Js::from($projectOptions) }};
    let members = {{ Illuminate\Support\Js::from($memberOptions) }};
    const initial = {{ Illuminate\Support\Js::from($initialValues) }};
    const restore = {{ Illuminate\Support\Js::from($restore) }};
    const choicesAvailable = {{ Illuminate\Support\Js::from($associations->isNotEmpty() && $activities->isNotEmpty()) }};
    const createUrl = {{ Illuminate\Support\Js::from(route($trainingPrefix.'.store')) }};
    const form = document.getElementById('training-form');
    const association = form.elements.association_id;
    const relatedProject = form.elements.related_project_id;
    const container = document.getElementById('training-participants');
    const removals = document.getElementById('training-removed-participants');
    const addButton = document.getElementById('training-add-participant');
    const notice = document.getElementById('training-choice-notice');
    let nextParticipant = 0;
    function matchingMembers() { return members.filter(member => String(member.association_id) === association.value); }
    function refreshParticipants() {
        const selects = Array.from(container.querySelectorAll('[data-participant-input="member_id"]'));
        const selected = selects.map(select => select.value).filter(Boolean);
        selects.forEach(select => {
            Array.from(select.options).forEach(option => { option.disabled = Boolean(option.value && option.value !== select.value && selected.includes(option.value)); });
        });
        addButton.disabled = !association.value || matchingMembers().every(member => selected.includes(String(member.value)));
        document.getElementById('training-participants-empty').hidden = selects.length > 0;
    }
    function memberChoices(select, selected = '') {
        const previous = Array.from(select.options).find(option => option.value === String(selected));
        select.replaceChildren(new Option('Select member', ''));
        matchingMembers().forEach(member => select.add(new Option(member.label, member.value)));
        if (selected && previous && !Array.from(select.options).some(option => option.value === String(selected))) select.add(previous.cloneNode(true));
        select.value = String(selected);
        if (select.selectedIndex < 0) select.value = '';
        return Boolean(selected && select.value !== String(selected));
    }
    function projectChoices(selected = '') {
        relatedProject.replaceChildren(new Option('No related project', ''));
        projects.filter(project => String(project.association_id) === association.value).forEach(project => relatedProject.add(new Option(project.label, project.value)));
        const unavailable = Boolean(selected && !Array.from(relatedProject.options).some(option => option.value === String(selected)));
        if (unavailable) {
            const retained = editingRecord && String(editingRecord.related_project_id) === String(selected);
            const label = retained ? `${editingRecord.related_project} (retained; unavailable for new selection)` : 'Unavailable Project selection (review required)';
            relatedProject.add(new Option(label, selected));
        }
        relatedProject.value = String(selected);
        return unavailable;
    }
    function addParticipant(values = {}, index = nextParticipant++) {
        nextParticipant = Math.max(nextParticipant, Number(index) + 1);
        const fragment = document.getElementById('training-participant-template').content.cloneNode(true);
        const row = fragment.querySelector('fieldset');
        if (values.id) {
            row.dataset.participantId = values.id;
            const identity = document.createElement('input');
            identity.type = 'hidden'; identity.name = `participants[${index}][id]`; identity.value = values.id;
            row.append(identity);
        }
        fragment.querySelector('[data-participant-heading]').textContent = `Participant ${Number(index) + 1}`;
        let unavailable = false;
        fragment.querySelectorAll('[data-participant-input]').forEach(input => {
            const key = input.dataset.participantInput;
            input.id = `training-participant-${index}-${key}`;
            input.name = `participants[${index}][${key}]`;
            fragment.querySelector(`[data-participant-label="${key}"]`).htmlFor = input.id;
            if (key === 'member_id') {
                unavailable = memberChoices(input, values[key] ?? '');
                if (values.id && values.member_id) {
                    if (unavailable) input.add(new Option(values.member_name || 'Recorded participant', values.member_id));
                    input.value = values.member_id;
                    input.disabled = true;
                    const retained = document.createElement('input');
                    retained.type = 'hidden'; retained.name = input.name; retained.value = values.member_id;
                    row.append(retained);
                    unavailable = false;
                }
            }
            else input.value = values[key] ?? (key === 'attendance_status' ? 'present' : '');
        });
        container.append(fragment);
        refreshParticipants();
        return unavailable;
    }
    function populate(values, record = null) {
        form.reset();
        form.action = record?.update_url ?? createUrl;
        form.elements._method.disabled = !record;
        form.elements.training_record_id.value = record?.id ?? '';
        form.elements.expected_updated_at.value = values.expected_updated_at ?? '';
        form.elements.expected_updated_at.disabled = !record;
        const unavailableEdit = Boolean(values.training_record_id && !record);
        form.querySelector('button[type="submit"]').disabled = unavailableEdit || !choicesAvailable;
        if (!editingRecord) document.getElementById('training-editor-title').textContent = 'Add Training';
        for (const key of ['association_id', 'title', 'description', 'saad_activity_id', 'program_year', 'start_date', 'end_date', 'venue', 'conducted_by', 'training_cost', 'status', 'remarks']) {
            form.elements[key].value = values[key] ?? (key === 'status' ? 'scheduled' : '');
        }
        const unavailableProject = projectChoices(values.related_project_id ?? '');
        let unavailable = unavailableProject || Boolean(values.association_id && !association.value) || Boolean(values.saad_activity_id && !form.elements.saad_activity_id.value);
        container.replaceChildren();
        removals.replaceChildren();
        (Array.isArray(values.removed_participant_ids) ? values.removed_participant_ids : []).forEach(addRemoval);
        nextParticipant = 0;
        Object.entries(values.participants ?? {}).forEach(([index, participant]) => {
            if (!participant || typeof participant !== 'object' || Array.isArray(participant)) return;
            const recorded = record?.participants.find(existing => existing.id === participant.id || existing.member_id === participant.member_id);
            unavailable = addParticipant({...recorded, ...participant}, index) || unavailable;
        });
        notice.hidden = !unavailable;
        notice.textContent = unavailableProject
            ? 'The previous Project selection has been preserved but is unavailable for new selection. Saving must still pass server validation. To change the relationship, choose an eligible Project or explicitly select No related project. Review the association, activity, and participants before saving.'
            : 'Some previous selections are no longer available. Review the association, activity, project, and participants before saving.';
        if (unavailableEdit) {
            notice.hidden = false;
            notice.textContent = 'This training is no longer available for editing in the current list. Close this dialog and reopen an available training.';
        }
        refreshParticipants();
    }
    association.addEventListener('change', () => {
        projectChoices();
        container.querySelectorAll('[data-participant-input="member_id"]:not(:disabled)').forEach(select => memberChoices(select));
        notice.textContent = 'Association changed. Retained participation prevents moving a training to another association. Review the association, project, and participants before saving.';
        notice.hidden = false;
        refreshParticipants();
    });
    addButton.addEventListener('click', () => {
        addParticipant();
        container.lastElementChild.querySelector('select').focus();
    });
    container.addEventListener('change', refreshParticipants);
    form.addEventListener('assocmap:lookup-results', event => {
        if (event.detail.kind === 'projects') projects = event.detail.options;
        if (event.detail.kind !== 'members') return;
        members = event.detail.options;
        container.querySelectorAll('[data-participant-input="member_id"]:not(:disabled)').forEach(select => memberChoices(select, select.value));
        refreshParticipants();
    });
    function addRemoval(id) {
        const input = document.createElement('input');
        input.type = 'hidden'; input.name = 'removed_participant_ids[]'; input.value = id;
        removals.append(input);
    }
    container.addEventListener('click', event => {
        const button = event.target.closest('[data-remove-participant]');
        if (!button) return;
        const row = button.closest('fieldset');
        if (row.dataset.participantId) addRemoval(row.dataset.participantId);
        const next = row.nextElementSibling?.querySelector('select') ?? row.previousElementSibling?.querySelector('select');
        row.remove();
        refreshParticipants();
        (next ?? addButton).focus();
    });
    document.addEventListener('click', event => {
        if (!event.target.closest('[data-training-create]')) return;
        populate({});
        form.querySelector('[data-training-errors]')?.remove();
        form.querySelectorAll('.field-error').forEach(element => element.remove());
        form.querySelectorAll('[aria-invalid]').forEach(element => { element.removeAttribute('aria-invalid'); element.removeAttribute('aria-describedby'); });
        association.focus();
    });
    populate(initial, editingRecord);
    if ((restore || {{ Illuminate\Support\Js::from($showEditor ?? false) }}) && !editingRecord) document.getElementById('training-editor').dataset.feedbackRestore = 'true';
</script>
