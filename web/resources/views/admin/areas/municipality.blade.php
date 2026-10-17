@extends('layouts.admin')

@section('content')
<section class="area-management" data-workspace-module="areas" data-workspace-title="City / Municipality Details">
    <nav class="area-breadcrumbs" aria-label="Breadcrumb"><a href="{{ $backUrl }}">Area Management</a><span aria-hidden="true">/</span><span>{{ $record->name }}</span></nav>
    <header class="card area-page-heading"><div><p class="eyebrow">{{ ucfirst($record->locality_type) }} details</p><h1 tabindex="-1">{{ $record->name }}</h1><p>{{ $record->province }} / {{ $record->region }}</p></div><x-status-badge :value="$record->is_active ? 'Active' : 'Inactive'" /></header>
    <section class="card area-information" aria-labelledby="geographic-information-title">
        <div class="area-section-heading"><h2 id="geographic-information-title">Geographic information</h2>@include('admin.areas.partials.actions')</div>
        <dl class="area-information-grid"><div><dt>Name</dt><dd>{{ $record->name }}</dd></div><div><dt>Type</dt><dd>{{ ucfirst($record->locality_type) }}</dd></div><div><dt>Province</dt><dd>{{ $record->province }}</dd></div><div><dt>Region</dt><dd>{{ $record->region }}</dd></div><div><dt>PSGC code</dt><dd>{{ $record->psgc_code ?? 'Not recorded' }}</dd></div><div><dt>Source</dt><dd>{{ $record->source === 'psgc' ? 'PSGC' : 'Manual' }}</dd></div><div><dt>Connected barangays</dt><dd>{{ number_format($record->barangays_count) }}</dd></div><div><dt>Connected associations</dt><dd>{{ number_format($record->associations_count) }}</dd></div></dl>
        <p class="area-help">Connected counts include inactive geographic records and archived associations. Historical references remain available.</p>
    </section>
    <section class="card area-directory" aria-labelledby="connected-barangays-title">
        <div class="area-section-heading"><div><h2 id="connected-barangays-title">Connected barangays</h2><p>{{ number_format($barangays->total()) }} barangay record(s)</p></div><button class="button" type="button" data-area-add data-area-create-type="Barangay" data-area-parent="{{ $record->id }}" @disabled(!$record->is_active)><x-icon name="plus" />Add Barangay</button></div>
        <div class="table-scroll" role="region" aria-label="Connected barangays" tabindex="0"><table class="data-table area-record-table"><caption class="sr-only">Barangays belonging to {{ $record->name }}.</caption><thead><tr><th scope="col">Barangay</th><th scope="col">Associations</th><th scope="col">Status</th><th scope="col">View</th></tr></thead><tbody>
        @forelse($barangays as $barangay)<tr><th scope="row"><a href="{{ route('admin.barangays.show', $barangay->id) }}">{{ $barangay->name }}</a></th><td>{{ number_format($barangay->associations_count) }}</td><td><x-status-badge :value="$barangay->is_active ? 'Active' : 'Inactive'" /></td><td><a class="text-button" href="{{ route('admin.barangays.show', $barangay->id) }}"><x-icon name="eye" />View</a></td></tr>
        @empty<tr><td colspan="4"><x-empty-state title="No connected barangays" message="No barangays are recorded under this city or municipality." /></td></tr>@endforelse
        </tbody></table></div>
        @include('admin.areas.partials.pagination', ['paginator' => $barangays, 'label' => 'Connected barangay pages'])
    </section>
    @include('admin.areas.partials.associations', ['municipalityName' => $record->name])
    <a class="back-link" href="{{ $backUrl }}"><x-icon name="back" />Return to Area Management</a>
</section>
@endsection
