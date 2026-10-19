@extends('layouts.admin')

@section('content')
<section class="area-management" data-workspace-module="areas" data-workspace-title="Barangay Details">
    <nav class="area-breadcrumbs" aria-label="Breadcrumb"><a href="{{ $backUrl }}">Area Management</a><span aria-hidden="true">/</span><a href="{{ route('admin.municipalities.show', $record->municipality_id) }}">{{ $record->municipality?->name }}</a><span aria-hidden="true">/</span><span>{{ $record->name }}</span></nav>
    <header class="card area-page-heading"><div><p class="eyebrow">Barangay details</p><h1 tabindex="-1">{{ $record->name }}</h1><p><a href="{{ route('admin.municipalities.show', $record->municipality_id) }}">{{ $record->municipality?->name }}</a> / {{ $record->municipality?->province }} / {{ $record->municipality?->region }}</p></div><x-status-badge :value="$record->is_active ? 'Active' : 'Inactive'" /></header>
    <section class="card area-information" aria-labelledby="geographic-information-title">
        <div class="area-section-heading"><h2 id="geographic-information-title">Barangay information</h2>@include('admin.areas.partials.actions')</div>
        <dl class="area-information-grid"><div><dt>Name</dt><dd>{{ $record->name }}</dd></div><div><dt>City / Municipality</dt><dd><a href="{{ route('admin.municipalities.show', $record->municipality_id) }}">{{ $record->municipality?->name }}</a></dd></div><div><dt>PSGC code</dt><dd>{{ $record->psgc_code ?? 'Not recorded' }}</dd></div><div><dt>Source</dt><dd>{{ $record->source === 'psgc' ? 'PSGC' : 'Manual' }}</dd></div><div><dt>Connected associations</dt><dd>{{ number_format($record->associations_count) }}</dd></div><div><dt>Created</dt><dd>{{ $record->created_at?->timezone('Asia/Manila')->format('M d, Y') ?? 'Not recorded' }}</dd></div></dl>
        <p class="area-help">Connected associations include active, inactive, and archived records. Inactive geography remains readable.</p>
    </section>
    @include('admin.areas.partials.associations', ['municipalityName' => $record->municipality?->name])
    <section class="card area-directory" aria-labelledby="recorded-addresses-title">
        <div class="area-section-heading"><div><h2 id="recorded-addresses-title">Recorded areas and addresses</h2><p>{{ number_format($areas->total()) }} existing location record(s) under this barangay.</p></div><button class="button button-primary" type="button" data-area-add data-area-create-type="Specific Area" data-area-parent="{{ $record->municipality_id }}" data-area-barangay="{{ $record->id }}" @disabled(!$record->is_active || !$record->municipality?->is_active)><x-icon name="plus" />Add address</button></div>
        <p class="area-table-note">These addresses belong to Area records. Association names and their exact recorded addresses appear together in the Connected associations table.</p>
        <div class="table-scroll" role="region" aria-label="Recorded areas and addresses" tabindex="0"><table class="data-table area-record-table"><caption class="sr-only">Existing Area records under {{ $record->name }}. No address values are inferred.</caption><thead><tr><th scope="col">Recorded address / Sitio / Purok</th><th scope="col">Associations</th><th scope="col">Source</th><th scope="col">Status</th></tr></thead><tbody>
        @forelse($areas as $area)<tr><th scope="row">{{ $area->address }}</th><td>{{ number_format($area->associations_count) }}</td><td>{{ ucfirst($area->source) }}</td><td><x-status-badge :value="$area->is_active ? 'Active' : 'Inactive'" /></td></tr>
        @empty<tr><td colspan="4"><x-empty-state title="No recorded addresses" message="No Area records have been recorded under this barangay." /></td></tr>@endforelse
        </tbody></table></div>
        @include('admin.areas.partials.pagination', ['paginator' => $areas, 'label' => 'Recorded address pages'])
    </section>
    <a class="back-link" href="{{ $backUrl }}"><x-icon name="back" />Return to Area Management</a>
</section>
@endsection
