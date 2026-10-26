@extends('layouts.field-officer')
@php
    $workflow = app(\App\View\WorkflowNavigation::class);
    $workflowPrefix = 'field-officer';
    $nameOf = fn ($member) => collect([$member->first_name, $member->middle_name, $member->last_name, $member->suffix])->filter(fn ($part) => filled($part))->implode(' ');
    $fields = ['first_name' => ['First Name', 'text', 120, true], 'middle_name' => ['Middle Name', 'text', 120, false], 'last_name' => ['Last Name', 'text', 120, true], 'suffix' => ['Suffix', 'text', 20, false], 'birth_date' => ['Birth Date', 'date', null, true], 'contact_number' => ['Contact Number', 'tel', 30, false], 'fishr_registration_number' => ['FishR Registration Number', 'text', 100, false]];
    $applicationRegister = false;
    $indexRoute = 'field-officer.members.index';
    $cards = ['active' => ['Active Members', 'active', 'check'], 'inactive' => ['Legacy Inactive', 'inactive', 'user'], 'archived' => ['Archived Members', 'archived', 'archive']];
    $summaryNote = 'Active and Legacy Inactive exclude archived members. Legacy inactive records remain view only.';
    $isLegacyView = $status === 'inactive';
    $listTitle = match ($status) {
        'archived' => 'Archived Members',
        'inactive' => 'Legacy Inactive Records',
        default => 'Active Members',
    };
    $listDescription = match ($status) {
        'archived' => 'Archived members are inactive. Reactivation restores active membership when permitted.',
        'inactive' => 'Inactive members without an archive record are available for review only.',
        default => 'Active members can be edited or archived when their association permits changes.',
    };
@endphp
@section('content')
<section class="member-management" data-workspace-module="members" data-workspace-title="Member Management">
    @include('members.partials.workflow-navigation')
    <header class="card member-heading"><div><p class="eyebrow">Field Officer</p><h1 tabindex="-1">Member Management</h1><p>Manage member profiles and membership records in your assigned associations.</p></div><a class="button button-primary" href="{{ route('field-officer.member-applications.index') }}"><x-icon name="clipboard-check" />Review Applications</a></header>
    @include('members.partials.summary')
    <section class="card member-register" aria-labelledby="member-list-title">
        <div class="member-section-heading"><div><h2 id="member-list-title">{{ $listTitle }}</h2><p id="member-list-help">{{ $listDescription }}</p></div><span class="badge badge-blue">{{ number_format($members->total()) }} matching member(s)</span></div>
        @include('members.partials.filters')
        @if($isLegacyView)<p class="notice member-register-notice">These inactive records have not been archived. They remain view only.</p>@endif
        <div class="table-scroll" role="region" aria-labelledby="member-list-title" aria-describedby="member-list-help" tabindex="0"><table class="data-table member-register-table"><caption class="sr-only">{{ $listTitle }} in assigned associations</caption>
            <thead><tr><th scope="col">Member</th><th scope="col">Association</th><th scope="col">Position</th><th scope="col">FishR number</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead><tbody>
            @forelse($members as $member)
                @php
                    $manageable = $member->association && !$member->association->archived_at && $member->association->status === 'active';
                @endphp
                <tr><th scope="row">{{ $nameOf($member) }}<small>Registered {{ $member->date_registered?->format('M j, Y') ?? '—' }}</small></th>
                    <td>{{ $member->association?->name ?? '—' }}@if(!$manageable)<small>Association read only</small>@endif</td>
                    <td>{{ $member->position?->name ?? 'Position unavailable' }}@if($member->position_other_text)<small>{{ $member->position_other_text }}</small>@endif</td>
                    <td>{{ $member->fishr_registration_number ?: '—' }}</td>
                    <td><span class="badge badge-{{ $member->archived_at ? 'neutral' : ($member->member_status === 'active' ? 'success' : 'warning') }}">{{ $member->archived_at ? 'Archived' : ucfirst($member->member_status) }}</span>@if($member->archived_at)<small>{{ ucfirst($member->member_status) }} membership</small>@elseif($member->member_status === 'inactive')<small>Not archived · View only</small>@endif</td>
                    <td><div class="row-actions member-row-actions"><button type="button" class="button button-quiet" data-dialog="member-view-{{ $member->id }}" aria-label="View {{ $nameOf($member) }}"><x-icon name="eye" />View</button>
                        @if($manageable && !$member->archived_at && $member->member_status === 'active')
                            <button type="button" class="button button-outline" data-dialog="member-profile-{{ $member->id }}" aria-label="Edit {{ $nameOf($member) }}"><x-icon name="pencil" />Edit</button>
                            <button type="button" class="button button-danger-outline" data-dialog="member-archive-{{ $member->id }}" aria-label="Archive {{ $nameOf($member) }}">Archive</button>
                        @elseif($manageable && $member->archived_at)
                            <button type="button" class="button button-primary" data-dialog="member-reactivate-{{ $member->id }}" aria-label="Reactivate {{ $nameOf($member) }}">Reactivate</button>
                        @endif
                    </div></td></tr>
            @empty<tr><td colspan="6"><x-empty-state title="No matching members" message="Try another search, status, or assigned association, or reset the filters." /></td></tr>@endforelse
            </tbody></table></div>
        @include('members.partials.pagination', ['paginator' => $members, 'recordLabel' => 'members'])
    </section>
</section>
@endsection

@push('dialogs')
@foreach($members as $member)
    @php
        $manageable = $member->association && !$member->association->archived_at && $member->association->status === 'active';
        $restoring = $errors->any() && old('member_record_id') === $member->id;
        $version = $member->updated_at?->format('Y-m-d\TH:i:s.uP');
        $oldVersion = is_string(old('expected_updated_at')) ? old('expected_updated_at') : '';
        $selectedSex = $restoring && old('member_action') === 'profile' ? old('sex_id') : $member->sex_id;
        $selectedSex = is_scalar($selectedSex) ? (string) $selectedSex : '';
    @endphp
    <x-modal :id="'member-view-'.$member->id" title="Member Details">
        <div class="member-record-dialog">
        @include('members.partials.details', ['fullName' => $nameOf($member)])
        @if(!$manageable)
            <p class="notice">This association is archived or inactive. Member records are read-only.</p>
        @elseif(!$member->archived_at && $member->member_status === 'inactive')
            <p class="notice">This member is inactive without an archive record. This legacy record is view-only.</p>
        @endif
        <div class="modal-actions"><button type="button" class="button" data-close-dialog>Close</button></div>
        </div>
    </x-modal>
    @if($manageable && !$member->archived_at && $member->member_status === 'active')
        <x-modal :id="'member-profile-'.$member->id" title="Edit Member">
            <div class="member-record-dialog">
            <p>{{ $nameOf($member) }} · {{ $member->association->name }}</p>
            @if($restoring && old('member_action') === 'profile')<div class="notice notice-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
            <form method="POST" action="{{ route('field-officer.members.update', $member) }}" @if($restoring && old('member_action') === 'profile') data-feedback-error-bag="default" @endif>
                @csrf @method('PATCH')
                <input type="hidden" name="member_record_id" value="{{ $member->id }}"><input type="hidden" name="member_action" value="profile">
                <input type="hidden" name="expected_updated_at" value="{{ $restoring && old('member_action') === 'profile' ? $oldVersion : $version }}">
                <p>Association, position, classifications, and membership status are maintained through their authorized workflows.</p>
                <div class="form-grid">
                    @foreach($fields as $field => [$label, $type, $maximum, $required])
                        @php($value = $restoring && old('member_action') === 'profile' ? old($field) : ($field === 'birth_date' ? $member->birth_date?->format('Y-m-d') : $member->{$field}))
                        <div class="field"><label for="member-{{ $member->id }}-{{ $field }}">{{ $label }} <span class="field-hint">{{ $required ? '*' : 'Optional' }}</span></label>
                            <input id="member-{{ $member->id }}-{{ $field }}" name="{{ $field }}" type="{{ $type }}" value="{{ is_scalar($value) ? $value : '' }}" @required($required) @if($maximum) maxlength="{{ $maximum }}" @endif @if($field === 'birth_date') max="{{ now()->toDateString() }}" @endif @if($restoring && $errors->has($field)) aria-invalid="true" aria-describedby="member-{{ $member->id }}-{{ $field }}-error" @endif>
                            @if($restoring) @error($field)<span class="field-error" id="member-{{ $member->id }}-{{ $field }}-error">{{ $message }}</span>@enderror @endif
                        </div>
                    @endforeach
                    <div class="field"><label for="member-{{ $member->id }}-sex">Sex *</label><select id="member-{{ $member->id }}-sex" name="sex_id" required @if($restoring && $errors->has('sex_id')) aria-invalid="true" @endif><option value="">Select sex</option>
                        @if($member->sex && !$sexCategories->contains('id', $member->sex_id))<option value="{{ $member->sex_id }}" @selected($selectedSex === (string) $member->sex_id)>{{ $member->sex->name }} (inactive reference)</option>@endif
                        @foreach($sexCategories as $sex)<option value="{{ $sex->id }}" @selected($selectedSex === (string) $sex->id)>{{ $sex->name }}</option>@endforeach
                    </select>@if($restoring) @error('sex_id')<span class="field-error">{{ $message }}</span>@enderror @endif</div>
                </div>
                <div class="modal-actions"><button type="button" class="button" data-close-dialog>Cancel</button><button type="submit" class="button button-primary">Save Changes</button></div>
            </form>
            </div>
        </x-modal>
    @endif
    @if($manageable && ($member->archived_at || $member->member_status === 'active'))
        @php($action = $member->archived_at ? 'reactivate' : 'archive')
        <x-modal :id="'member-'.$action.'-'.$member->id" :title="$action === 'archive' ? 'Archive Member' : 'Reactivate Member'">
            <p><strong>{{ $nameOf($member) }}</strong> · {{ $member->association->name }}</p>
            @if($action === 'archive')
                <p>Are you sure you want to archive this member?</p>
                <p>Archiving will mark the member as inactive and remove them from the active-member list. Their information and previous training participation records will be preserved.</p>
                <p>The member can be reactivated if they return to the association. Archived members cannot be edited until reactivated.</p>
            @else
                <p>Reactivate this member and return them to the active-member list? Their information and previous training participation records will be preserved.</p>
                <p>The retained position is {{ $member->position?->name ?? 'unavailable' }}. Reactivation cannot replace an existing active President or Vice President.</p>
            @endif
            @if($restoring && old('member_action') === $action)<div class="notice notice-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
            <form method="POST" action="{{ route('field-officer.members.'.$action, $member) }}" @if($restoring && old('member_action') === $action) data-feedback-error-bag="default" @endif>
                @csrf @method('PATCH')
                <input type="hidden" name="member_record_id" value="{{ $member->id }}"><input type="hidden" name="member_action" value="{{ $action }}">
                <input type="hidden" name="expected_updated_at" value="{{ $restoring && old('member_action') === $action ? $oldVersion : $version }}">
                <label><input type="checkbox" name="confirm_change" value="1" required> I confirm that I want to {{ $action }} {{ $nameOf($member) }}.</label>
                <div class="modal-actions"><button type="button" class="button" data-close-dialog>Cancel</button><button type="submit" class="button {{ $action === 'archive' ? 'button-danger' : 'button-primary' }}">{{ $action === 'archive' ? 'Archive Member' : 'Reactivate Member' }}</button></div>
            </form>
        </x-modal>
    @endif
@endforeach
@endpush
@push('scripts')
    const memberAction = {{ Illuminate\Support\Js::from($errors->any() ? old('member_action') : null) }};
    const memberRecord = {{ Illuminate\Support\Js::from(old('member_record_id')) }};
    if (['profile', 'archive', 'reactivate'].includes(memberAction) && typeof memberRecord === 'string') {
        const restoredDialog = document.getElementById('member-' + memberAction + '-' + memberRecord);
        if (restoredDialog) restoredDialog.dataset.feedbackRestore = 'true';
    }
@endpush
