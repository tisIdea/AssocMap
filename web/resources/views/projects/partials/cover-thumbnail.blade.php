@php($coverId = $project->getAttribute('cover_photo_id'))
<span class="project-cover-thumbnail">
    @if($coverId)
        <img src="{{ route($prefix.'.projects.cover.image', [$project, $coverId, 'thumbnail']) }}" alt="Cover for {{ $project->title }}" loading="lazy" decoding="async" width="80" height="60" data-project-cover-thumbnail>
        <span class="project-cover-empty" data-cover-thumbnail-empty hidden>Photo unavailable</span>
    @else
        <span class="project-cover-empty">No cover photo</span>
    @endif
</span>
