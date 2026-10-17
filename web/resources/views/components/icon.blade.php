@props(['name' => 'grid'])
@php
    $library = require resource_path('ui/icons.php');
    $iconName = $library['aliases'][$name] ?? $name;
    $svg = $library['icons'][$iconName] ?? throw new InvalidArgumentException('Unknown interface icon: '.$name);
@endphp
<svg {{ $attributes->class(['icon']) }} data-icon="{{ $iconName }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">{!! $svg !!}{{ $slot }}</svg>
