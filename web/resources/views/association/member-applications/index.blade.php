@extends('layouts.association')

@php
    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\AssociationPosition> $positions */
    $applicationRegister = true;
    $indexRoute = 'association.member-applications.index';
    $cards = ['total' => ['All Applications', '', 'users'], 'pending' => ['Pending Review', 'pending', 'clipboard-check'], 'approved' => ['Approved', 'approved', 'check'], 'rejected' => ['Rejected', 'rejected', 'close']];
    $summaryNote = 'Applications submitted by your account for the linked association.';
    $association = $associationAccount->association;
    $canSubmit = $association && !$association->archived_at && $association->status === 'active';
    $submissionErrors = $errors->any() && !$errors->hasAny(['q', 'status', 'sort', 'page', 'association']);
    $area = $association?->area;
    $barangay = $area?->barangay;
    $otherPositionId = $positions->firstWhere('code', 'OTHER')?->id;
    $isOtherPosition = $otherPositionId !== null && (string) old('position_id') === (string) $otherPositionId;
@endphp

@section('content')
<section class="member-management" data-workspace-module="member-applications" data-workspace-title="Member Applications">
    <header class="card member-heading"><div><p class="eyebrow">Association Account</p><h1 tabindex="-1">Member Applications</h1><p>Submit applications for your association and follow each review decision.</p></div>
        @if($canSubmit)<button type="button" class="button button-primary" data-dialog="submit-member-application"><x-icon name="plus" />Submit Member Application</button>@else<span class="badge badge-neutral">Association read only</span>@endif
    </header>
    @include('members.partials.summary')
    @if(!$canSubmit)<p class="notice">This association is archived or inactive. You can view application history; new applications are unavailable.</p>@endif
    <details class="card member-association-context"><summary>Linked association: {{ $association?->name ?? 'Unavailable' }}<x-icon name="chevron-down" /></summary>
        <dl class="detail-grid">
            @foreach(['Association Name' => $association?->name, 'Acronym' => $association?->acronym, 'Municipality / City' => $barangay?->municipality?->name, 'Barangay' => $barangay?->name, 'Specific Area' => $area?->address] as $label => $value)
                <div><dt>{{ $label }}</dt><dd>{{ filled($value) ? $value : '—' }}</dd></div>
            @endforeach
        </dl>
    </details>
    <section class="card member-register" aria-labelledby="application-register-title">
        <div class="member-section-heading"><div><h2 id="application-register-title">Application register</h2><p>{{ number_format($applications->total()) }} matching application(s)</p></div><p>Only submissions from this account are shown.</p></div>
        @include('members.partials.filters')
        <div class="table-scroll" role="region" aria-label="Submitted member applications" tabindex="0">
            <table class="data-table member-register-table">
                <caption class="sr-only">Member applications submitted by this Association Account</caption>
                <thead><tr>@foreach(['Applicant', 'Association', 'Position', 'Submitted', 'Status', 'Actions'] as $heading)<th scope="col">{{ $heading }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach($applications as $application)
                        @php
                            $fullName = collect([$application->first_name, $application->middle_name, $application->last_name, $application->suffix])->filter(fn ($part) => filled($part))->implode(' ');
                            $tone = match ($application->status) { 'approved' => 'success', 'rejected' => 'danger', default => 'warning' };
                            $details = [
                                'Full Name' => $fullName,
                                'Association' => $association?->name,
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
                            <th scope="row">{{ $fullName }}<small>FishR: {{ $application->fishr_registration_number ?: '—' }}</small></th>
                            <td>{{ $association?->name ?? '—' }}</td>
                            <td>{{ $application->position?->name ?? '—' }}</td>
                            <td>{{ $application->created_at?->format('M j, Y') ?? '—' }}</td>
                            <td><span class="badge badge-{{ $tone }}">{{ ucfirst($application->status) }}</span>@if($application->reviewed_at)<small>Reviewed {{ $application->reviewed_at->format('M j, Y') }}</small>@endif</td>
                            <td><div class="row-actions"><button type="button" class="button button-quiet" data-dialog="application-{{ $application->id }}" aria-label="View application for {{ $fullName }}"><x-icon name="eye" />View</button></div></td>
                        </tr>
                        @push('dialogs')
                            <x-modal :id="'application-'.$application->id" title="Application Details">
                                <div class="member-record-dialog">
                                <div class="member-detail-banner"><span class="member-avatar"><x-icon name="user" /></span><div><h3>{{ $fullName }}</h3><p>{{ $association?->name }}</p></div><span class="badge badge-{{ $tone }}">{{ ucfirst($application->status) }}</span></div>
                                <dl class="detail-grid">@foreach($details as $label => $value)<div><dt>{{ $label }}</dt><dd>{{ filled($value) ? $value : '—' }}</dd></div>@endforeach</dl>
                                @include('partials.member-application-evidence', ['photoRoute' => 'association.member-applications.photo'])
                                <div class="modal-actions"><button type="button" class="button" data-close-dialog>Close</button></div>
                                </div>
                            </x-modal>
                        @endpush
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($applications->isEmpty())
            <x-empty-state title="No matching applications" message="Try another search or status, or reset the filters. New submissions will appear here." />
        @endif
        @include('members.partials.pagination', ['paginator' => $applications, 'recordLabel' => 'applications'])
    </section>
</section>
@endsection

@push('dialogs')
    <x-modal id="submit-member-application" title="Submit Member Application">
        <div class="member-record-dialog">
        <p>This application will be submitted for <strong>{{ $association?->name }}</strong> and will await Field Officer review.</p>
        @if($submissionErrors)
            <div class="notice notice-error" role="alert"><div>@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div></div>
        @endif
        <form method="POST" action="{{ route('association.member-applications.store') }}" id="member-application-form" data-feedback-error-bag="default" enctype="multipart/form-data">
            @csrf
            <fieldset @disabled(!$canSubmit) class="member-application-fields"><legend>Applicant information</legend><p class="member-form-help">Required fields are marked *. Approval by your assigned Field Officer creates the official member record.</p>
            <div class="form-grid">
                @foreach([
                    ['name' => 'first_name', 'label' => 'First Name', 'required' => true, 'max' => 120],
                    ['name' => 'middle_name', 'label' => 'Middle Name', 'required' => false, 'max' => 120],
                    ['name' => 'last_name', 'label' => 'Last Name', 'required' => true, 'max' => 120],
                    ['name' => 'suffix', 'label' => 'Suffix', 'required' => false, 'max' => 20],
                    ['name' => 'birth_date', 'label' => 'Birth Date', 'required' => true, 'type' => 'date'],
                    ['name' => 'contact_number', 'label' => 'Contact Number', 'required' => false, 'max' => 30, 'type' => 'tel'],
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
                        @foreach($positions as $position)
                            @php
                                $isOccupied = in_array((string) $position->id, $occupiedExecutivePositionIds, true);
                            @endphp
                            <option value="{{ $position->id }}" data-code="{{ $position->code }}" @disabled($isOccupied) @selected(!$isOccupied && (string) old('position_id') === (string) $position->id)>
                                {{ $position->name }}
                                @if($isOccupied)
                                    &mdash; Already occupied
                                @endif
                            </option>
                        @endforeach
                    </select>
                    <span class="field-error" id="application-position-error">@error('position_id'){{ $message }}@enderror</span>
                </div>
                <div class="field" id="application-other-field" @if(!$isOtherPosition) hidden @endif>
                    <label for="application-other">Other Position Text <span class="field-hint">*</span></label>
                    <input id="application-other" name="position_other_text" type="text" value="{{ old('position_other_text') }}" maxlength="100" @required($isOtherPosition) @disabled(!$isOtherPosition) aria-describedby="application-other-error" @if($errors->has('position_other_text')) aria-invalid="true" @endif>
                    <span class="field-error" id="application-other-error">@error('position_other_text'){{ $message }}@enderror</span>
                </div>
                <div class="field field-wide"><h3 class="member-form-subheading">Application evidence</h3>
                    <label for="application-endorsement">Endorsement Letter <span class="field-hint">*</span></label>
                    <textarea id="application-endorsement" name="endorsement_letter" rows="8" required maxlength="10000" aria-describedby="application-endorsement-help application-endorsement-error" @if($errors->has('endorsement_letter')) aria-invalid="true" @endif>{{ is_string(old('endorsement_letter')) ? old('endorsement_letter') : '' }}</textarea>
                    <small class="field-help" id="application-endorsement-help">Enter the letter provided by an association officer. Typed content does not verify authorship. Maximum 10,000 characters. This letter is private application information.</small>
                    <span class="field-error" id="application-endorsement-error">@error('endorsement_letter'){{ $message }}@enderror</span>
                </div>
                <div class="field field-wide">
                    <label for="application-photo">Applicant Photo <span class="field-hint">Optional</span></label>
                    <input id="application-photo" name="applicant_photo" type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png" aria-describedby="application-photo-help application-photo-error" @if($errors->has('applicant_photo')) aria-invalid="true" @endif>
                    <small class="field-help" id="application-photo-help">You may submit without a photo. If selected, use a private JPEG or PNG photo with a matching filename extension. Maximum 2 MiB and 2048 by 2048 pixels. Reselect the file after any validation error.</small>
                    <span class="field-error" id="application-photo-error">@error('applicant_photo'){{ $message }}@enderror</span>
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
            </fieldset>
            <div class="modal-actions"><button type="button" class="button" data-close-dialog>Cancel</button><button type="submit" class="button button-primary" @disabled(!$canSubmit)>Submit Application</button></div>
        </form>
        </div>
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
    @if($submissionErrors)
        document.getElementById('submit-member-application').dataset.feedbackRestore = 'true';
    @endif
@endpush
