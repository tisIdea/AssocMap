@extends('layouts.admin')
@php
    $applicationRegister = false;
    $indexRoute = 'admin.members.index';
    $cards = ['current' => ['Current Members', '', 'users'], 'active' => ['Active Members', 'active', 'check'], 'inactive' => ['Inactive Members', 'inactive', 'user'], 'archived' => ['Archived Members', 'archived', 'archive']];
    $summaryNote = 'Current, Active and Inactive exclude archived members. Archived records remain available for reference.';
@endphp
@section('content')
<section class="member-management" data-workspace-module="members" data-workspace-title="Member Management">
    <header class="card member-heading"><div><p class="eyebrow">System Administrator</p><h1 tabindex="-1">Member Management</h1><p>View official member profiles, association positions, and membership history.</p></div><span class="badge badge-blue">View only</span></header>
    @include('members.partials.summary')
    <section class="card member-register" aria-labelledby="member-register-title">
        <div class="member-section-heading"><div><h2 id="member-register-title">Member register</h2><p>{{ number_format($members->total()) }} matching member(s)</p></div><p>Members are admitted through approved applications.</p></div>
        @include('members.partials.filters')
        <div class="table-scroll" role="region" aria-label="Member register" tabindex="0"><table class="data-table member-register-table"><caption class="sr-only">Official association members. Administrator access is view only.</caption>
            <thead><tr><th scope="col">Member</th><th scope="col">Association</th><th scope="col">Position</th><th scope="col">FishR number</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead><tbody>
            @forelse($members as $member)
                @php($fullName = collect([$member->first_name, $member->middle_name, $member->last_name, $member->suffix])->filter(fn ($part) => filled($part))->implode(' '))
                <tr><th scope="row">{{ $fullName }}<small>Registered {{ $member->date_registered?->format('M j, Y') ?? '—' }}</small></th>
                    <td>{{ $member->association?->name ?? '—' }}<small>{{ $member->association?->area?->barangay?->municipality?->name ?? 'Location unavailable' }}@if($member->association?->archived_at) · Archived association @elseif($member->association?->status !== 'active') · Inactive association @endif</small></td>
                    <td>{{ $member->position?->name ?? '—' }}@if($member->position_other_text)<small>{{ $member->position_other_text }}</small>@endif</td>
                    <td>{{ $member->fishr_registration_number ?: '—' }}</td>
                    <td><span class="badge badge-{{ $member->archived_at ? 'neutral' : ($member->member_status === 'active' ? 'success' : 'warning') }}">{{ $member->archived_at ? 'Archived' : ucfirst($member->member_status) }}</span>@if($member->archived_at)<small>{{ ucfirst($member->member_status) }} membership</small>@endif</td>
                    <td><button class="button button-quiet" type="button" data-dialog="admin-member-{{ $member->id }}" aria-label="View {{ $fullName }}"><x-icon name="eye" />View</button></td>
                </tr>
            @empty<tr><td colspan="6"><x-empty-state title="No matching members" message="Try another search, status, or association, or reset the filters." /></td></tr>@endforelse
            </tbody></table></div>
        @include('members.partials.pagination', ['paginator' => $members, 'recordLabel' => 'members'])
    </section>
</section>
@foreach($members as $member)
    @php($fullName = collect([$member->first_name, $member->middle_name, $member->last_name, $member->suffix])->filter(fn ($part) => filled($part))->implode(' '))
    <x-modal :id="'admin-member-'.$member->id" title="Member Details">
        <div class="member-record-dialog">@include('members.partials.details')</div>
        <div class="modal-actions"><button class="button" type="button" data-close-dialog>Close</button></div>
    </x-modal>
@endforeach
@endsection
