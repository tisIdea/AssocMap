@extends('layouts.admin')

@section('content')
<section data-workspace-module="users" data-workspace-title="Field Officer assignments">
    <a class="back-link" href="{{ route('admin.users.index') }}"><x-icon name="back" />Back to User Management</a>
    @include('partials.page-header', ['title' => 'Association assignments', 'description' => $officer->display_name, 'eyebrow' => 'Field Officer'])
    @if(session('success'))<div class="notice" role="status">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="notice notice-error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <section class="card form-section">
        <h2>Current assignments ({{ $assigned->total() }})</h2>
        <ul>@forelse($assigned as $association)<li>{{ $association->name }} — {{ ucfirst($association->status) }}{{ $association->archived_at ? ' / archived, read-only' : '' }}</li>@empty<li>No current association assignments.</li>@endforelse</ul>
        <nav aria-label="Current assignment pages">@if($assigned->previousPageUrl())<a class="button" href="{{ $assigned->previousPageUrl() }}">Previous</a>@endif @if($assigned->nextPageUrl())<a class="button" href="{{ $assigned->nextPageUrl() }}">Next</a>@endif</nav>
    </section>
    <div class="notice"><p>Each association requires one primary Field Officer. Selecting an association transfers that primary assignment to {{ $officer->display_name }}. Unselected associations and Association Account links stay unchanged. To remove an assignment from this officer, open another eligible officer and transfer it there. Archived associations cannot be changed.</p></div>
    <form method="POST" action="{{ route('admin.users.assignments.update', $officer->id, false) }}">
        @csrf
        @method('PATCH')
        <section class="card form-section">
            <h2>Select associations to transfer</h2>
            <p class="field-help">Save selections on this page before moving to another page. Associations already assigned to this officer are checked and read-only.</p>
            <p class="field-help">Inactive, nonarchived associations can retain or change their primary officer under the existing Association Management rules. Other modules keep their own lifecycle restrictions.</p>
            <div class="table-scroll"><table class="data-table"><caption>Nonarchived associations, page {{ $choices->currentPage() }} of {{ $choices->lastPage() }}.</caption>
                <thead><tr><th scope="col">Select</th><th scope="col">Association</th><th scope="col">Status</th><th scope="col">Current primary officer</th></tr></thead>
                <tbody>@forelse($choices as $association)
                    @php($alreadyAssigned = $association->primary_field_officer_id === $officer->id)
                    <tr>
                        <td><input type="checkbox" id="assign-{{ $association->id }}" name="association_ids[]" value="{{ $association->id }}" @checked($alreadyAssigned) @disabled($alreadyAssigned)>
                            @if(!$alreadyAssigned)<input type="hidden" name="expected_officers[{{ $association->id }}]" value="{{ $association->primary_field_officer_id }}">@endif
                        </td>
                        <td><label for="assign-{{ $association->id }}">{{ $association->name }}</label></td>
                        <td>{{ ucfirst($association->status) }}</td>
                        <td>{{ $association->primaryFieldOfficer?->display_name ?? 'User unavailable' }}{{ $alreadyAssigned ? ' (already assigned)' : '' }}</td>
                    </tr>
                @empty<tr><td colspan="4">No nonarchived associations on this page.</td></tr>@endforelse</tbody>
            </table></div>
            <nav aria-label="Eligible association pages">@if($choices->previousPageUrl())<a class="button" href="{{ $choices->previousPageUrl() }}">Previous</a>@endif @if($choices->nextPageUrl())<a class="button" href="{{ $choices->nextPageUrl() }}">Next</a>@endif</nav>
            <p><label><input type="checkbox" name="confirm_reassignment" value="1" required> I confirm that the selected associations will use this Field Officer as their primary officer.</label></p>
            <div class="form-actions"><a class="button" href="{{ route('admin.users.index') }}">Cancel</a><button class="button button-primary" type="submit">Save assignments</button></div>
        </section>
    </form>
</section>
@endsection
