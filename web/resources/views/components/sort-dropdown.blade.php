@props(['id', 'audit' => false, 'project' => false])
<div class="sort-control"><label for="{{ $id }}">Sort by</label><select id="{{ $id }}" data-sort>
    @unless($audit)<option value="az">Name A–Z</option><option value="za">Name Z–A</option>@endunless
    <option value="newest">Newest</option><option value="oldest">Oldest</option>
    @if($project)<option value="year">Program Year</option><option value="status">Status</option>@endif
</select></div>
