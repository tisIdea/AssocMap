@props(['href', 'label', 'icon', 'active' => false])

<a {{ $attributes->class(['nav-link', 'active' => $active]) }} href="{{ $href }}" title="{{ $label }}" aria-label="{{ $label }}" @if($active) aria-current="page" @endif><x-icon :name="$icon" /><span>{{ $label }}</span></a>
