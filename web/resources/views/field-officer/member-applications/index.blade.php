@extends('layouts.field-officer')
@php
    $applicationRegister = true;
    $indexRoute = 'field-officer.member-applications.index';
    $cards = ['pending' => ['Pending Review', 'pending', 'clipboard-check'], 'approved' => ['Approved', 'approved', 'check'], 'rejected' => ['Rejected', 'rejected', 'close']];
    $summaryNote = 'Applications from your assigned associations, including read-only association history.';
@endphp

@section('content')
<section class="member-management" data-workspace-module="member-applications" data-workspace-title="Member Applications">
    <header class="card member-heading"><div><p class="eyebrow">Field Officer</p><h1 tabindex="-1">Member Applications</h1><p>Review applicants and application evidence from your assigned associations.</p></div><a class="button" href="{{ route('field-officer.members.index') }}"><x-icon name="users" />Member Register</a></header>
    @include('members.partials.summary')
    <section class="card member-register" aria-labelledby="application-register-title">
        <div class="member-section-heading"><div><h2 id="application-register-title">{{ ucfirst($statusFilter) }} applications</h2><p>{{ number_format($applications->total()) }} matching application(s)</p></div><p>Review the applicant details and evidence before deciding.</p></div>
        @include('members.partials.filters')
        <div class="table-scroll" role="region" aria-label="{{ ucfirst($statusFilter) }} member applications" tabindex="0">
            <table class="data-table member-register-table">
                <caption class="sr-only">{{ ucfirst($statusFilter) }} member applications from assigned associations</caption>
                <thead><tr>@foreach(['Applicant', 'Association', 'Position', 'Submitted', 'Status', 'Actions'] as $heading)<th scope="col">{{ $heading }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach($applications as $application)
                        @php
                            $fullName = collect([$application->first_name, $application->middle_name, $application->last_name, $application->suffix])->filter(fn ($part) => filled($part))->implode(' ');
                            $reviewable = $application->association && !$application->association->archived_at && $application->association->status === 'active';
                            $area = $application->association?->area;
                            $barangay = $application->association?->locationBarangay();
                            $tone = match ($application->status) { 'approved' => 'success', 'rejected' => 'danger', default => 'warning' };
                            $restoreRejection = old('review_application_id') === $application->id;
                            $details = [
                                'Full Name' => $fullName,
                                'Association' => $application->association?->name,
                                'Municipality / City' => $barangay?->municipality?->name,
                                'Barangay' => $barangay?->name,
                                'Specific Area' => $area?->address,
                                'Birth Date' => $application->birth_date?->format('M j, Y'),
                                'Sex' => $application->sex?->name,
                                'Contact Number' => $application->contact_number,
                                'FishR Registration Number' => $application->fishr_registration_number,
                                'Association Position' => $application->position?->name,
                                'Member Classifications' => $application->classifications->pluck('name')->implode(', ') ?: 'None recorded',
                                'Application Status' => ucfirst($application->status),
                                'Submitted Date' => $application->created_at?->format('M j, Y g:i A'),
                            ];
                            if (filled($application->position_other_text)) $details['Other Position'] = $application->position_other_text;
                            if ($application->reviewed_at) {
                                $details['Reviewed Date'] = $application->reviewed_at->format('M j, Y g:i A');
                                $details['Reviewer'] = $application->reviewedBy?->display_name;
                            }
                            if ($application->status === 'rejected') $details['Rejection Reason'] = $application->rejection_reason;
                        @endphp
                        <tr>
                            <th scope="row">{{ $fullName }}<small>FishR: {{ $application->fishr_registration_number ?: '—' }}</small></th>
                            <td>{{ $application->association?->name ?? '—' }}<small>{{ $barangay?->name ?? '—' }} · {{ $barangay?->municipality?->name ?? '—' }}</small>@if(!$reviewable)<small>Association read only</small>@endif</td>
                            <td>{{ $application->position?->name ?? '—' }}</td>
                            <td>{{ $application->created_at?->format('M j, Y') ?? '—' }}</td>
                            <td><span class="badge badge-{{ $tone }}">{{ ucfirst($application->status) }}</span></td>
                            <td><div class="row-actions member-row-actions">
                                <button type="button" class="button button-quiet" data-dialog="application-{{ $application->id }}" aria-label="View application for {{ $fullName }}"><x-icon name="eye" />{{ $application->status === 'pending' && $reviewable ? 'Review' : 'View' }}</button>
                                @if($application->status === 'pending' && $reviewable)
                                    <button type="button" class="button button-outline" data-dialog="approve-{{ $application->id }}" aria-label="Approve application for {{ $fullName }}">Approve</button>
                                    <button type="button" class="button button-danger-outline" data-dialog="reject-{{ $application->id }}" aria-label="Reject application for {{ $fullName }}">Reject</button>
                                @endif
                            </div></td>
                        </tr>
                        @push('dialogs')
                            <x-modal :id="'application-'.$application->id" title="Application Details">
                                <div class="member-record-dialog">
                                <div class="member-detail-banner"><span class="member-avatar"><x-icon name="user" /></span><div><h3>{{ $fullName }}</h3><p>{{ $application->association?->name }}</p></div><span class="badge badge-{{ $tone }}">{{ ucfirst($application->status) }}</span></div>
                                @if(!$reviewable)<p class="notice">This association is archived or inactive. Application history is read-only.</p>@endif
                                <dl class="detail-grid">@foreach($details as $label => $value)<div><dt>{{ $label }}</dt><dd>{{ filled($value) ? $value : '—' }}</dd></div>@endforeach</dl>
                                @include('partials.member-application-evidence', ['photoRoute' => 'field-officer.member-applications.photo'])
                                <div class="modal-actions"><button type="button" class="button" data-close-dialog>Close</button></div>
                                </div>
                            </x-modal>
                            @if($application->status === 'pending' && $reviewable)
                                <x-modal :id="'approve-'.$application->id" title="Approve Application">
                                    <p>Approve the application for <strong>{{ $fullName }}</strong>? Approval will create an active member record for this association.</p>
                                    <form method="POST" action="{{ route('field-officer.member-applications.approve', $application) }}">
                                        @csrf
                                        @method('PATCH')
                                        <div class="modal-actions"><button type="button" class="button" data-close-dialog>Cancel</button><button type="submit" class="button button-primary">Approve Application</button></div>
                                    </form>
                                </x-modal>
                                <x-modal :id="'reject-'.$application->id" title="Reject Application">
                                    <p>Reject the application for <strong>{{ $fullName }}</strong>? Provide a reason for this decision.</p>
                                    <form method="POST" action="{{ route('field-officer.member-applications.reject', $application) }}" @if($restoreRejection) data-feedback-error-bag="default" @endif onsubmit="this.elements.rejection_reason.value = this.elements.rejection_reason.value.trim(); return this.reportValidity();">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="review_application_id" value="{{ $application->id }}">
                                        <div class="field">
                                            <label for="reason-{{ $application->id }}">Rejection Reason <span class="field-hint">*</span></label>
                                            <textarea id="reason-{{ $application->id }}" name="rejection_reason" rows="4" required maxlength="1000" aria-describedby="reason-help-{{ $application->id }}" @if($restoreRejection && $errors->has('rejection_reason')) aria-invalid="true" @endif oninput="this.setCustomValidity(this.value.trim() ? '' : 'Enter a rejection reason.');">{{ $restoreRejection ? old('rejection_reason') : '' }}</textarea>
                                            <small class="field-help" id="reason-help-{{ $application->id }}">Required. Maximum 1,000 characters.</small>
                                            @if($restoreRejection) @error('rejection_reason')<span class="field-error" role="alert">{{ $message }}</span>@enderror @endif
                                        </div>
                                        <div class="modal-actions"><button type="button" class="button" data-close-dialog>Cancel</button><button type="submit" class="button button-danger">Reject Application</button></div>
                                    </form>
                                </x-modal>
                                @if($restoreRejection && $errors->has('rejection_reason'))
                                    @push('scripts')
                                        document.getElementById(@json('reject-'.$application->id)).dataset.feedbackRestore = 'true';
                                    @endpush
                                @endif
                            @endif
                        @endpush
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($applications->isEmpty())<x-empty-state title="No matching applications" message="Try another search, status, or assigned association, or reset the filters." />@endif
        @include('members.partials.pagination', ['paginator' => $applications, 'recordLabel' => 'applications'])
    </section>
</section>
@endsection
