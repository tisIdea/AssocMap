@extends($prefix === 'admin' ? 'layouts.admin' : 'layouts.field-officer')

@section('content')
<section data-workspace-module="associations" data-workspace-title="Association Officers">
    @if($prefix === 'admin')<a class="back-link" href="{{ route('admin.associations.index') }}"><x-icon name="back" />Back to Association Management</a>@endif
    @include('partials.page-header', ['title' => 'Association Officers', 'description' => 'Manage organizational positions held by association members.', 'eyebrow' => 'Association Management'])
    <div class="notice"><p>Association Officers are members holding organizational positions. Assigning a position does not create a system account or change account permissions.</p></div>
    <section class="card list-card">
        <div class="table-scroll"><table class="data-table">
            <caption>Associations available to your account</caption>
            <thead><tr><th scope="col">Association</th><th scope="col">President</th><th scope="col">Status</th><th scope="col">Officers</th></tr></thead>
            <tbody>@forelse($associations as $association)
                <tr><td>{{ $association->name }}</td><td>{{ $association->currentPresident ? collect([$association->currentPresident->first_name, $association->currentPresident->middle_name, $association->currentPresident->last_name, $association->currentPresident->suffix])->filter(fn ($part) => filled($part))->implode(' ') : 'No active President assigned' }}</td><td>{{ $association->archived_at ? 'Archived' : ucfirst($association->status) }}</td>
                    <td><a class="text-button" href="{{ route($prefix.'.association-officers.show', $association) }}">{{ $association->archived_at || $association->status !== 'active' ? 'View officers' : 'Manage officers' }}</a></td></tr>
            @empty<tr><td colspan="4">No associations are currently available to your account.</td></tr>@endforelse</tbody>
        </table></div>
        <nav aria-label="Association pages">@if($associations->previousPageUrl())<a class="button" href="{{ $associations->previousPageUrl() }}">Previous</a>@endif @if($associations->nextPageUrl())<a class="button" href="{{ $associations->nextPageUrl() }}">Next</a>@endif</nav>
    </section>
</section>
@endsection
