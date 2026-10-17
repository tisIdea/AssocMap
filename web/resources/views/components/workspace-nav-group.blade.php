@props(['id', 'label' => 'Program Operations', 'icon' => 'briefcase', 'active' => false])

<button type="button" class="nav-link program-toggle {{ $active ? 'active' : '' }}" data-disclosure="{{ $id }}" aria-expanded="{{ $active ? 'true' : 'false' }}" aria-controls="{{ $id }}" title="{{ $label }}" aria-label="{{ $label }}"><x-icon :name="$icon" /><span>{{ $label }}</span><x-icon name="chevron" class="disclosure-chevron" /></button>
<div class="subnav" id="{{ $id }}" @if(!$active) hidden @endif>
    {{ $slot }}
</div>
