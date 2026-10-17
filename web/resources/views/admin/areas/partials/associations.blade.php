<section class="card area-directory" aria-labelledby="connected-associations-title">
    <div class="area-section-heading"><div><h2 id="connected-associations-title">Connected associations</h2><p>{{ number_format($associations->total()) }} association(s), including inactive and archived history.</p></div></div>
    <p class="area-table-note">Association links open the existing Association Management listing for that location and status. Select View there to inspect the association.</p>
    <div class="table-scroll" role="region" aria-label="Connected associations" tabindex="0">
        <table class="data-table area-record-table"><caption class="sr-only">Associations connected through recorded addresses under this location.</caption><thead><tr><th scope="col">Association</th><th scope="col">Barangay</th><th scope="col">Recorded address</th><th scope="col">Status</th></tr></thead><tbody>
        @forelse($associations as $association)
            @php($associationUrl = route('admin.associations.index', ['municipality' => $municipalityName, 'barangay' => $association->barangay_name, 'status' => $association->archived_at ? 'archived' : $association->status]).'#associations')
            <tr><th scope="row"><a href="{{ $associationUrl }}" aria-label="Open Association Management for {{ $association->name }}">{{ $association->name }}</a></th><td><a href="{{ route('admin.barangays.show', $association->barangay_id) }}">{{ $association->barangay_name }}</a></td><td>{{ $association->address }}@if(!$association->area_active)<small>Inactive address record</small>@endif</td><td><x-status-badge :value="ucfirst($association->status)" />@if($association->archived_at)<span class="badge badge-neutral">Archived</span>@endif</td></tr>
        @empty<tr><td colspan="4"><x-empty-state title="No connected associations" message="No associations are registered through this location's recorded addresses." /></td></tr>@endforelse
        </tbody></table>
    </div>
    @include('admin.areas.partials.pagination', ['paginator' => $associations, 'label' => 'Connected association pages'])
</section>
