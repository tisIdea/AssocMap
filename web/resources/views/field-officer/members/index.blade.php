@extends('layouts.field-officer')
@php
    $nameOf = fn ($member) => collect([$member->first_name, $member->middle_name, $member->last_name, $member->suffix])->filter(fn ($part) => filled($part))->implode(' ');
    $fields = ['first_name' => ['First Name', 'text', 120, true], 'middle_name' => ['Middle Name', 'text', 120, false], 'last_name' => ['Last Name', 'text', 120, true], 'suffix' => ['Suffix', 'text', 20, false], 'birth_date' => ['Birth Date', 'date', null, true], 'contact_number' => ['Contact Number', 'tel', 30, false], 'fishr_registration_number' => ['FishR Registration Number', 'text', 100, false]];
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
<section data-workspace-module="members" data-workspace-title="Member Management">
    @include('partials.page-header', ['title' => 'Member Management', 'description' => 'Manage active members and archive or reactivate records in your assigned associations.', 'eyebrow' => 'Field Officer'])
    <section class="card list-card" aria-labelledby="member-list-title">
        <div class="table-toolbar member-directory-toolbar">
            <nav class="area-list-switch" aria-label="Membership status">
                @foreach(['active' => 'Active Members', 'archived' => 'Archived Members'] as $value => $label)
                    <a class="button {{ $status === $value ? 'button-primary' : '' }}" href="{{ route('field-officer.members.index', array_merge($filters, ['status' => $value])) }}" @if($status === $value) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </nav>
        </div>
        <section class="filter-panel member-filter-panel" aria-labelledby="member-filter-title">
            <div class="panel-heading"><h2 id="member-filter-title">Filter members</h2><span class="muted">Search within your assigned associations</span></div>
            <form method="GET" action="{{ route('field-officer.members.index') }}" class="form-grid member-filters">
                <input type="hidden" name="status" value="{{ $status }}">
                <div class="field">
                    <label for="member-association-filter">Association</label>
                    <select id="member-association-filter" name="association">
                        <option value="">All assigned associations</option>
                        @foreach($associations as $association)
                            <option value="{{ $association->id }}" @selected(($filters['association'] ?? '') === $association->id)>{{ $association->name }}{{ $association->archived_at ? ' (archived)' : '' }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="member-search">Member name or FishR number</label>
                    <input id="member-search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="120" type="search" placeholder="Enter a member name or FishR number">
                </div>
                <div class="form-actions"><button type="submit" class="button button-primary"><x-icon name="filter" />Apply Filters</button><a class="button" href="{{ route('field-officer.members.index', ['status' => $status]) }}">Clear Filters</a></div>
            </form>
        </section>
        <div class="member-results-heading">
            <div><h2 id="member-list-title">{{ $listTitle }}</h2><p id="member-list-help">{{ $listDescription }}</p></div>
            <span class="badge badge-blue">{{ $members->total() }} {{ $members->total() === 1 ? 'member' : 'members' }}</span>
        </div>
        @if($isLegacyView)
            <div class="notice member-legacy-notice"><x-icon name="info" /><p>These records have not been archived. They remain view-only and are separate from the normal archive and reactivate workflow.</p></div>
        @endif
        <div class="table-scroll" role="region" aria-labelledby="member-list-title" aria-describedby="member-list-help" tabindex="0"><table class="data-table member-directory-table"><caption class="sr-only">{{ $listTitle }} in assigned associations</caption>
            <thead><tr><th scope="col">Member Name</th><th scope="col">Association</th><th scope="col">Position</th><th scope="col">FishR Registration Number</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead>
            <tbody>@foreach($members as $member)
                @php
                    $manageable = $member->association && !$member->association->archived_at;
                @endphp
                <tr>
                    <td class="primary-cell">{{ $nameOf($member) }}</td>
                    <td>{{ $member->association?->name }}@if($member->association?->archived_at)<small class="member-secondary">Archived association &middot; Read-only</small>@endif</td>
                    <td>{{ $member->position?->name ?? 'Position unavailable' }}</td>
                    <td>{{ $member->fishr_registration_number ?: '—' }}</td>
                    <td>
                        <span class="badge badge-{{ $member->archived_at ? 'neutral' : ($member->member_status === 'active' ? 'success' : 'warning') }}">{{ $member->archived_at ? 'Archived' : ucfirst($member->member_status) }}</span>
                        @if($member->archived_at)
                            <small class="member-secondary">{{ ucfirst($member->member_status) }} membership</small>
                        @elseif($member->member_status === 'inactive')
                            <small class="member-secondary">Not archived &middot; View only</small>
                        @endif
                    </td>
                    <td><div class="row-actions member-row-actions"><button type="button" class="button button-quiet" data-dialog="member-view-{{ $member->id }}" aria-label="View {{ $nameOf($member) }}"><x-icon name="eye" />View</button>
                        @if($manageable && !$member->archived_at && $member->member_status === 'active')
                            <button type="button" class="button button-outline" data-dialog="member-profile-{{ $member->id }}" aria-label="Edit {{ $nameOf($member) }}">Edit</button>
                            <button type="button" class="button button-danger-outline" data-dialog="member-archive-{{ $member->id }}" aria-label="Archive {{ $nameOf($member) }}">Archive</button>
                        @elseif($manageable && $member->archived_at)
                            <button type="button" class="button button-primary" data-dialog="member-reactivate-{{ $member->id }}" aria-label="Reactivate {{ $nameOf($member) }}">Reactivate</button>
                        @endif
                    </div></td></tr>
            @endforeach</tbody>
        </table></div>
        @if($members->isEmpty())
            <x-empty-state :title="'No '.strtolower($listTitle).' found.'" message="Try clearing the search or selecting another assigned association." />
        @endif
        <footer class="table-footer member-directory-footer">
            <span>Showing {{ $members->firstItem() ?? 0 }}&ndash;{{ $members->lastItem() ?? 0 }} of {{ $members->total() }} members</span>
            <nav class="member-pagination" aria-label="Member pages">
                @if($members->previousPageUrl())<a class="button" href="{{ $members->previousPageUrl() }}" rel="prev">Previous</a>@endif
                <span>Page {{ $members->currentPage() }} of {{ $members->lastPage() }}</span>
                @if($members->nextPageUrl())<a class="button" href="{{ $members->nextPageUrl() }}" rel="next">Next</a>@endif
            </nav>
        </footer>
    </section>
</section>
@endsection

@push('dialogs')
@foreach($members as $member)
    @php
        $manageable = $member->association && !$member->association->archived_at;
        $restoring = $errors->any() && old('member_record_id') === $member->id;
        $version = $member->updated_at?->format('Y-m-d\TH:i:s.uP');
        $oldVersion = is_string(old('expected_updated_at')) ? old('expected_updated_at') : '';
        $selectedSex = $restoring && old('member_action') === 'profile' ? old('sex_id') : $member->sex_id;
        $selectedSex = is_scalar($selectedSex) ? (string) $selectedSex : '';
    @endphp
    <x-modal :id="'member-view-'.$member->id" title="Member Details">
        <dl class="detail-grid">
            @foreach(['Member Name' => $nameOf($member), 'Association' => $member->association?->name, 'Municipality / City' => $member->association?->area?->barangay?->municipality?->name, 'Barangay' => $member->association?->area?->barangay?->name, 'Specific Area' => $member->association?->area?->address, 'Birth Date' => $member->birth_date?->format('M j, Y'), 'Sex' => $member->sex?->name, 'Contact Number' => $member->contact_number, 'FishR Registration Number' => $member->fishr_registration_number, 'Position' => $member->position?->name, 'Other Position Text' => $member->position_other_text, 'Classifications' => $member->classifications->pluck('name')->implode(', '), 'Date Registered' => $member->date_registered?->format('M j, Y'), 'Membership Status' => ucfirst($member->member_status), 'Archived' => $member->archived_at ? 'Yes' : 'No', 'Archived At' => $member->archived_at?->format('M j, Y g:i A')] as $label => $value)
                <div><dt>{{ $label }}</dt><dd>{{ filled($value) ? $value : '—' }}</dd></div>
            @endforeach
        </dl>
        @if(!$manageable)
            <p class="notice">This association is archived. Member records are read-only.</p>
        @elseif(!$member->archived_at && $member->member_status === 'inactive')
            <p class="notice">This member is inactive without an archive record. This legacy record is view-only.</p>
        @endif
        <div class="modal-actions"><button type="button" class="button" data-close-dialog>Close</button></div>
    </x-modal>
    @if($manageable && !$member->archived_at && $member->member_status === 'active')
        <x-modal :id="'member-profile-'.$member->id" title="Edit Member">
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
