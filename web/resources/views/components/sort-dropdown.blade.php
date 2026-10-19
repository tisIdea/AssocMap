@props(['id', 'audit' => false, 'project' => false, 'area' => false, 'association' => false, 'member' => false])
<div class="sort-control"><label for="{{ $id }}">Sort by</label><select id="{{ $id }}" data-sort>
    @unless($audit)<option value="az">Name A–Z</option><option value="za">Name Z–A</option>@endunless
    <option value="newest">{{ $association ? 'SAAD Join Date: Newest' : ($member ? 'Date Registered: Newest' : 'Newest') }}</option><option value="oldest">{{ $association ? 'SAAD Join Date: Oldest' : ($member ? 'Date Registered: Oldest' : 'Oldest') }}</option>
    @if($member)<option value="association">Association A–Z</option><option value="municipality">Municipality A–Z</option><option value="position">Position A–Z</option><option value="status">Status</option>@endif
    @if($association)<option value="municipality">Municipality A–Z</option><option value="barangay">Barangay A–Z</option><option value="field_officer">Field Officer A–Z</option><option value="status">Status</option>@endif
    @if($project)<option value="year">Program Year</option><option value="status">Status</option>@endif
    @if($area)<option value="barangay" data-specific-sort hidden>Barangay A–Z</option><option value="municipality">Municipality A–Z</option><option value="status">Status</option>@endif
</select></div>
