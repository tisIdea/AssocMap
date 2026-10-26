@props(['id', 'label'])

<section class="nav-section" aria-labelledby="{{ $id }}">
    <h2 class="sidebar-section-label" id="{{ $id }}">{{ $label }}</h2>
    {{ $slot }}
</section>
