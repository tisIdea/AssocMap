@extends('layouts.association')

@php
    $association = $associationAccount->association;
    $area = $association?->area;
    $barangay = $area?->barangay;
    $otherPositionId = $positions->firstWhere('code', 'OTHER')?->id;
    $isOtherPosition = $otherPositionId !== null && (string) old('position_id') === (string) $otherPositionId;
@endphp

@section('content')
    <header class="page-header"><div><div class="eyebrow">Association Account</div><h1 tabindex="-1">Member Applications</h1><p>Submit member applications and monitor their review status.</p></div></header>
    <section class="card form-section" aria-labelledby="linked-association-title">
        <div class="section-heading"><h2 id="linked-association-title">Linked Association</h2></div>
        <dl class="detail-grid">
            @foreach(['Association Name' => $association?->name, 'Acronym' => $association?->acronym, 'Municipality / City' => $barangay?->municipality?->name, 'Barangay' => $barangay?->name, 'Specific Area' => $area?->address] as $label => $value)
                <div><dt>{{ $label }}</dt><dd>{{ filled($value) ? $value : '—' }}</dd></div>
            @endforeach
        </dl>
    </section>
    <section class="card list-card" aria-label="Member applications">
        <div class="table-toolbar">
            <nav class="area-list-switch" aria-label="Application status">
                @foreach(['' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $status => $label)
                    <a class="button {{ $statusFilter === $status ? 'button-primary' : '' }}" href="{{ route('association.member-applications.index', $status === '' ? [] : ['status' => $status]) }}" @if($statusFilter === $status) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </nav>
            <button type="button" class="button button-primary" data-dialog="submit-member-application"><x-icon name="plus" />Submit Member Application</button>
        </div>
        <div class="table-scroll" role="region" aria-label="Submitted member applications" tabindex="0">
            <table class="data-table">
                <caption class="sr-only">Member applications submitted by this Association Account</caption>
                <thead><tr>@foreach(['Applicant Name', 'Position', 'FishR Registration Number', 'Submitted Date', 'Status', 'Reviewed Date', 'Actions'] as $heading)<th scope="col">{{ $heading }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach($applications as $application)
                        @php
                            $fullName = collect([$application->first_name, $application->middle_name, $application->last_name, $application->suffix])->filter(fn ($part) => filled($part))->implode(' ');
                            $tone = match ($application->status) { 'approved' => 'success', 'rejected' => 'danger', default => 'warning' };
                            $details = [
                                'Full Name' => $fullName,
                                'Birth Date' => $application->birth_date?->format('M j, Y'),
                                'Sex' => $application->sex?->name,
                                'Contact Number' => $application->contact_number,
                                'FishR Registration Number' => $application->fishr_registration_number,
                                'Position' => $application->position?->name,
                                'Member Classifications' => $application->classifications->pluck('name')->implode(', ') ?: 'None recorded',
                                'Application Status' => ucfirst($application->status),
                                'Submitted Date' => $application->created_at?->format('M j, Y g:i A'),
                            ];
                            if (filled($application->position_other_text)) $details['Other Position'] = $application->position_other_text;
                            if ($application->reviewed_at) $details['Reviewed Date'] = $application->reviewed_at->format('M j, Y g:i A');
                            if ($application->reviewedBy) $details['Reviewer'] = $application->reviewedBy->display_name;
                            if ($application->status === 'rejected') $details['Rejection Reason'] = $application->rejection_reason;
                        @endphp
                        <tr>
                            <td class="primary-cell">{{ $fullName }}</td>
                            <td>{{ $application->position?->name ?? '—' }}</td>
                            <td>{{ $application->fishr_registration_number ?? '—' }}</td>
                            <td>{{ $application->created_at?->format('M j, Y') ?? '—' }}</td>
                            <td><span class="badge badge-{{ $tone }}">{{ ucfirst($application->status) }}</span></td>
                            <td>{{ $application->reviewed_at?->format('M j, Y') ?? '—' }}</td>
                            <td><div class="row-actions"><button type="button" class="text-button" data-dialog="application-{{ $application->id }}" aria-label="View application for {{ $fullName }}">View</button></div></td>
                        </tr>
                        @push('dialogs')
                            <x-modal :id="'application-'.$application->id" title="Application Details">
                                <dl class="detail-grid">@foreach($details as $label => $value)<div><dt>{{ $label }}</dt><dd>{{ filled($value) ? $value : '—' }}</dd></div>@endforeach</dl>
                                <div class="modal-actions"><button type="button" class="button" data-close-dialog>Close</button></div>
                            </x-modal>
                        @endpush
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($applications->isEmpty())
            <x-empty-state :title="$statusFilter === '' ? 'No member applications have been submitted yet.' : 'No '.$statusFilter.' member applications were found.'" message="" />
        @endif
        <footer class="table-footer"><span>{{ $applications->count() }} {{ $applications->count() === 1 ? 'application' : 'applications' }}</span></footer>
    </section>
@endsection

@push('dialogs')
    <x-modal id="submit-member-application" title="Submit Member Application">
        <p>This application will be submitted for <strong>{{ $association?->name }}</strong> and will await Field Officer review.</p>
        @if($errors->any())
            <div class="notice notice-error" role="alert"><div>@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div></div>
        @endif
        <form method="POST" action="{{ route('association.member-applications.store') }}" id="member-application-form">
            @csrf
            <div class="form-grid">
                @foreach([
                    ['name' => 'first_name', 'label' => 'First Name', 'required' => true, 'max' => 150],
                    ['name' => 'middle_name', 'label' => 'Middle Name', 'required' => false, 'max' => 150],
                    ['name' => 'last_name', 'label' => 'Last Name', 'required' => true, 'max' => 150],
                    ['name' => 'suffix', 'label' => 'Suffix', 'required' => false, 'max' => 50],
                    ['name' => 'birth_date', 'label' => 'Birth Date', 'required' => true, 'type' => 'date'],
                    ['name' => 'contact_number', 'label' => 'Contact Number', 'required' => false, 'max' => 50, 'type' => 'tel'],
                    ['name' => 'fishr_registration_number', 'label' => 'FishR Registration Number', 'required' => false, 'max' => 100],
                ] as $field)
                    <div class="field">
                        <label for="application-{{ $field['name'] }}">{{ $field['label'] }} <span class="field-hint">{{ $field['required'] ? '*' : 'Optional' }}</span></label>
                        <input id="application-{{ $field['name'] }}" name="{{ $field['name'] }}" type="{{ $field['type'] ?? 'text' }}" value="{{ old($field['name']) }}" @required($field['required']) @if(isset($field['max'])) maxlength="{{ $field['max'] }}" @endif @if($field['name'] === 'birth_date') max="{{ now()->toDateString() }}" @endif aria-describedby="application-{{ $field['name'] }}-error" @if($errors->has($field['name'])) aria-invalid="true" @endif>
                        <span class="field-error" id="application-{{ $field['name'] }}-error">@error($field['name']){{ $message }}@enderror</span>
                    </div>
                @endforeach
                <div class="field">
                    <label for="application-sex">Sex <span class="field-hint">*</span></label>
                    <select id="application-sex" name="sex_id" required aria-describedby="application-sex-error" @if($errors->has('sex_id')) aria-invalid="true" @endif>
                        <option value="">Select…</option>
                        @foreach($sexCategories as $sex)<option value="{{ $sex->id }}" @selected((string) old('sex_id') === (string) $sex->id)>{{ $sex->name }}</option>@endforeach
                    </select>
                    <span class="field-error" id="application-sex-error">@error('sex_id'){{ $message }}@enderror</span>
                </div>
                <div class="field">
                    <label for="application-position">Position <span class="field-hint">*</span></label>
                    <select id="application-position" name="position_id" required aria-describedby="application-position-error" @if($errors->has('position_id')) aria-invalid="true" @endif>
                        <option value="">Select…</option>
                        @foreach($positions as $position)<option value="{{ $position->id }}" data-code="{{ $position->code }}" @selected((string) old('position_id') === (string) $position->id)>{{ $position->name }}</option>@endforeach
                    </select>
                    <span class="field-error" id="application-position-error">@error('position_id'){{ $message }}@enderror</span>
                </div>
                <div class="field" id="application-other-field" @if(!$isOtherPosition) hidden @endif>
                    <label for="application-other">Other Position Text <span class="field-hint">*</span></label>
                    <input id="application-other" name="position_other_text" type="text" value="{{ old('position_other_text') }}" maxlength="150" @required($isOtherPosition) @disabled(!$isOtherPosition) aria-describedby="application-other-error" @if($errors->has('position_other_text')) aria-invalid="true" @endif>
                    <span class="field-error" id="application-other-error">@error('position_other_text'){{ $message }}@enderror</span>
                </div>
                <div class="field field-wide">
                    <fieldset aria-describedby="application-classifications-help application-classifications-error">
                        <legend>Member Classification <span class="field-hint">Optional</span></legend>
                        <small class="field-help" id="application-classifications-help">Select all that apply. You may leave this section unselected.</small>
                        <div class="checkbox-options">
                            @foreach($classifications as $classification)
                                <label for="application-classification-{{ $classification->id }}">
                                    <input id="application-classification-{{ $classification->id }}" type="checkbox" name="classification_ids[]" value="{{ $classification->id }}" @checked(in_array((string) $classification->id, array_map('strval', (array) old('classification_ids', [])), true))>
                                    {{ $classification->name }}
                                </label>
                            @endforeach
                        </div>
                            <span class="field-error" id="application-classifications-error">
                                @foreach ($errors->get('classification_ids') as $message)
                                    <span>{{ $message }}</span>
                                @endforeach

                                @foreach ($errors->get('classification_ids.*') as $messages)
                                    @foreach ((array) $messages as $message)
                                        <span>{{ $message }}</span>
                                    @endforeach
                                @endforeach
                            </span>
                    </fieldset>
                </div>
            </div>
            <div class="modal-actions"><button type="button" class="button" data-close-dialog>Cancel</button><button type="submit" class="button button-primary">Submit Application</button></div>
        </form>
    </x-modal>
@endpush

@push('scripts')
    const position = document.getElementById('application-position');
    const otherField = document.getElementById('application-other-field');
    const otherInput = document.getElementById('application-other');
    function syncOtherPosition(clear = false) {
        const isOther = position.selectedOptions[0]?.dataset.code === 'OTHER';
        otherField.hidden = !isOther;
        otherInput.disabled = !isOther;
        otherInput.required = isOther;
        if (clear && !isOther) otherInput.value = '';
    }
    position.addEventListener('change', () => syncOtherPosition(true));
    syncOtherPosition();
    @if($errors->any())
        document.getElementById('submit-member-application').showModal();
    @endif
@endpush
