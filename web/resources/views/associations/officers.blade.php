@extends($prefix === 'admin' ? 'layouts.admin' : 'layouts.field-officer')

@section('content')
<section data-workspace-module="associations" data-workspace-title="Association Officers">
    <a class="back-link" href="{{ route($prefix.'.association-officers.index') }}"><x-icon name="back" />Back to Association Officers</a>
    @include('partials.page-header', ['title' => 'Association Officers', 'description' => $association->name, 'eyebrow' => 'Association Management'])
    <p>President: {{ $association->currentPresident ? collect([$association->currentPresident->first_name, $association->currentPresident->middle_name, $association->currentPresident->last_name, $association->currentPresident->suffix])->filter(fn ($part) => filled($part))->implode(' ') : 'No active President assigned' }}</p>
    @if($prefix === 'admin' && session('success'))<div class="notice notice-success" role="status">{{ session('success') }}</div>@endif
    @php($officerErrors = $errors->getBag('officerPosition'))
    @if($officerErrors->any())<div class="notice notice-error" role="alert"><ul>@foreach($officerErrors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <div class="notice"><p>Each member has one current organizational position. Only one active, nonarchived President and Vice President may hold office; vacancies are allowed. Other officer positions may have multiple holders. Changing positions preserves membership and account access.</p></div>
    @if($association->archived_at)<p class="notice">This association is archived. Officer records are read-only.</p>@endif
    <section class="card form-section">
        <h2>Current active officers ({{ $officers->total() }})</h2>
        <div class="table-scroll"><table class="data-table">
            <caption>Active, nonarchived officeholders</caption>
            <thead><tr><th scope="col">Member</th><th scope="col">Position</th><th scope="col">Actions</th></tr></thead>
            <tbody>@forelse($officers as $officer)
                <tr><td>{{ $officer->first_name }} {{ $officer->last_name }}</td><td>{{ $officer->position?->name ?? 'Position unavailable' }}{{ $officer->position?->is_active ? '' : ' (inactive position)' }}</td>
                    <td>@if(!$association->archived_at)
                        @if($officer->position?->is_active)<a class="button" href="{{ route($prefix.'.association-officers.show', ['association' => $association, 'replace' => $officer->id]) }}">Replace</a>
                        @else<p>Inactive positions cannot be assigned to a replacement.</p>@endif
                        <form method="POST" action="{{ route($prefix.'.association-officers.update', $association) }}">
                            @csrf @method('PATCH')
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="member_id" value="{{ $officer->id }}">
                            <input type="hidden" name="expected_position_id" value="{{ $officer->position_id }}">
                            <input type="hidden" name="expected_updated_at" value="{{ $officer->updated_at?->format('Y-m-d\TH:i:s.uP') }}">
                            <label><input type="checkbox" name="confirm_change" value="1" required> Return {{ $officer->first_name }} {{ $officer->last_name }} to Member; preserve membership.</label>
                            <button class="button" type="submit">Remove officer position</button>
                        </form>
                    @else Read-only @endif</td></tr>
            @empty<tr><td colspan="3">No active officers are recorded.</td></tr>@endforelse</tbody>
        </table></div>
        <nav aria-label="Officer pages">@if($officers->previousPageUrl())<a class="button" href="{{ $officers->previousPageUrl() }}">Previous</a>@endif @if($officers->nextPageUrl())<a class="button" href="{{ $officers->nextPageUrl() }}">Next</a>@endif</nav>
    </section>
    @if(!$association->archived_at)
        @if($former)
            <div class="notice"><p>Replace {{ $former->first_name }} {{ $former->last_name }} as {{ $former->position?->name }}. The former holder will become Member when the new appointment succeeds.</p><a href="{{ route($prefix.'.association-officers.show', $association) }}">Cancel replacement</a></div>
        @endif
        <section class="card form-section">
            <h2>{{ $former ? 'Choose a replacement' : 'Choose a member to appoint' }}</h2>
            <p>Only active, nonarchived members are available. A linked application must be approved. Existing directly registered members remain eligible.</p>
            <div class="table-scroll"><table class="data-table">
                <caption>Eligible members</caption>
                <thead><tr><th scope="col">Member</th><th scope="col">Current position</th><th scope="col">Select</th></tr></thead>
                <tbody>@forelse($members as $member)
                    <tr><td>{{ $member->first_name }} {{ $member->last_name }}</td><td>{{ $member->position?->name ?? 'Position unavailable' }}</td><td>
                        @if($former && ($former->id === $member->id || $former->position_id === $member->position_id))Already holds this position
                        @else<a class="text-button" href="{{ route($prefix.'.association-officers.show', ['association' => $association, 'member' => $member->id, 'replace' => $former?->id]) }}#officer-appointment">Select member</a>@endif
                    </td></tr>
                @empty<tr><td colspan="3">No eligible members are available.</td></tr>@endforelse</tbody>
            </table></div>
            <nav aria-label="Eligible member pages">@if($members->previousPageUrl())<a class="button" href="{{ $members->previousPageUrl() }}">Previous</a>@endif @if($members->nextPageUrl())<a class="button" href="{{ $members->nextPageUrl() }}">Next</a>@endif</nav>
        </section>
        @if($selected)
            <form id="officer-appointment" class="card form-section" method="POST" action="{{ route($prefix.'.association-officers.update', $association) }}">
                @csrf @method('PATCH')
                <h2>{{ $former ? 'Confirm replacement' : 'Assign officer position' }}</h2>
                <p>{{ $selected->first_name }} {{ $selected->last_name }} — currently {{ $selected->position?->name }}. The new position replaces this member's current position.</p>
                <input type="hidden" name="action" value="{{ $former ? 'replace' : 'assign' }}">
                <input type="hidden" name="member_id" value="{{ $selected->id }}">
                <input type="hidden" name="expected_position_id" value="{{ $selected->position_id }}">
                <input type="hidden" name="expected_updated_at" value="{{ $selected->updated_at?->format('Y-m-d\TH:i:s.uP') }}">
                @if($former)
                    <p>Position: {{ $former->position?->name }}. Former holder: {{ $former->first_name }} {{ $former->last_name }}.</p>
                    <input type="hidden" name="former_member_id" value="{{ $former->id }}">
                    <input type="hidden" name="expected_former_position_id" value="{{ $former->position_id }}">
                    <input type="hidden" name="expected_former_updated_at" value="{{ $former->updated_at?->format('Y-m-d\TH:i:s.uP') }}">
                @else
                    <div class="field"><label for="officer-position">Officer position *</label>
                        <select id="officer-position" name="position_id" required>
                            <option value="">Select officer position</option>
                            @foreach($positions as $position)
                                @php($unavailable = in_array($position->id, $occupied) || $selected->position_id === $position->id)
                                <option value="{{ $position->id }}" @disabled($unavailable)>{{ $position->name }}{{ $unavailable ? ' — occupied or already assigned' : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <p>Use Replace on the current officer row to change an occupied executive office.</p>
                    @if($positions->isEmpty())<p class="notice" role="status">No active officer positions are available. Contact the administrator.</p>@endif
                @endif
                <p><label><input type="checkbox" name="confirm_change" value="1" required> I confirm this position change{{ $former ? ' and the former holder’s return to Member' : '' }}.</label></p>
                <div class="form-actions"><a class="button" href="{{ route($prefix.'.association-officers.show', $association) }}">Cancel</a><button class="button button-primary" type="submit">Save officer position</button></div>
            </form>
        @endif
    @endif
</section>
@endsection
