@props(['back', 'parents' => [], 'current'])
<div class="workflow-navigation">
    <a class="back-link" href="{{ $back['url'] }}" data-workflow-back><x-icon name="back" />Back to {{ $back['label'] }}</a>
    <nav aria-label="Breadcrumb"><ol>
        @foreach($parents as $parent)<li>@if($parent['url'] ?? null)<a href="{{ $parent['url'] }}">{{ $parent['label'] }}</a>@else<span>{{ $parent['label'] }}</span>@endif<span aria-hidden="true">/</span></li>@endforeach
        <li><span aria-current="page">{{ $current }}</span></li>
    </ol></nav>
</div>
