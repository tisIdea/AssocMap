@props(['value', 'animate' => true])
<span @if($animate) data-summary-counter @endif>{{ $value }}</span>
