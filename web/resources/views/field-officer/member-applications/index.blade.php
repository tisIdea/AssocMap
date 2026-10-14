@extends('layouts.field-officer')

@section('content')
    <header class="page-header"><div><div class="eyebrow">Field Officer</div><h1 tabindex="-1">Member Applications</h1><p>Review applications from your assigned associations.</p></div></header>
    <section class="card list-card" aria-label="Member applications">
        <div class="table-toolbar">
            <nav class="area-list-switch" aria-label="Application status">
                @foreach(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $status => $label)
                    <a class="button {{ $statusFilter === $status ? 'button-primary' : '' }}" href="{{ route('field-officer.member-applications.index', ['status' => $status]) }}" @if($statusFilter === $status) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </nav>
        </div>
        <div class="table-scroll" role="region" aria-label="{{ ucfirst($statusFilter) }} member applications" tabindex="0">
            <table class="data-table">
                <caption class="sr-only">{{ ucfirst($statusFilter) }} member applications from assigned associations</caption>
                <thead><tr>@foreach(['Applicant Name', 'Association', 'Municipality', 'Barangay', 'Position', 'FishR Registration Number', 'Submitted Date', 'Status', 'Actions'] as $heading)<th scope="col">{{ $heading }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach($applications as $application)
                        @php
                            $fullName = collect([$application->first_name, $application->middle_name, $application->last_name, $application->suffix])->filter(fn ($part) => filled($part))->implode(' ');
                            $area = $application->association?->area;
                            $barangay = $area?->barangay;
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
                            <td class="primary-cell">{{ $fullName }}</td>
                            <td>{{ $application->association?->name ?? '—' }}</td>
                            <td>{{ $barangay?->municipality?->name ?? '—' }}</td>
                            <td>{{ $barangay?->name ?? '—' }}</td>
                            <td>{{ $application->position?->name ?? '—' }}</td>
                            <td>{{ $application->fishr_registration_number ?? '—' }}</td>
                            <td>{{ $application->created_at?->format('M j, Y') ?? '—' }}</td>
                            <td><span class="badge badge-{{ $tone }}">{{ ucfirst($application->status) }}</span></td>
                            <td><div class="row-actions">
                                <button type="button" class="text-button" data-dialog="application-{{ $application->id }}" aria-label="View application for {{ $fullName }}">View</button>
                                @if($application->status === 'pending')
                                    <button type="button" class="text-button" data-dialog="approve-{{ $application->id }}" aria-label="Approve application for {{ $fullName }}">Approve</button>
                                    <button type="button" class="text-button text-danger" data-dialog="reject-{{ $application->id }}" aria-label="Reject application for {{ $fullName }}">Reject</button>
                                @endif
                            </div></td>
                        </tr>
                        @push('dialogs')
                            <x-modal :id="'application-'.$application->id" title="Application Details">
                                <dl class="detail-grid">@foreach($details as $label => $value)<div><dt>{{ $label }}</dt><dd>{{ filled($value) ? $value : '—' }}</dd></div>@endforeach</dl>
                                @include('partials.member-application-evidence', ['photoRoute' => 'field-officer.member-applications.photo'])
                                <div class="modal-actions"><button type="button" class="button" data-close-dialog>Close</button></div>
                            </x-modal>
                            @if($application->status === 'pending')
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
                                    <form method="POST" action="{{ route('field-officer.member-applications.reject', $application) }}" onsubmit="this.elements.rejection_reason.value = this.elements.rejection_reason.value.trim(); return this.reportValidity();">
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
                                        document.getElementById(@json('reject-'.$application->id)).showModal();
                                    @endpush
                                @endif
                            @endif
                        @endpush
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($applications->isEmpty())<x-empty-state :title="'No '.$statusFilter.' member applications were found.'" message="" />@endif
        <footer class="table-footer"><span>{{ $applications->count() }} {{ $applications->count() === 1 ? 'application' : 'applications' }}</span></footer>
    </section>
@endsection
