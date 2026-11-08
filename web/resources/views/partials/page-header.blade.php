@if($headingCard ?? false)<div class="card workspace-page-heading {{ $headingClass ?? '' }}">@endif
<header class="page-header"><div><div class="eyebrow">{{ $eyebrow ?? 'Administration' }}</div><h1 tabindex="-1">{{ $title }}</h1><p>{{ $description ?? '' }}</p>@if($headingCard ?? false){{ $headerContext ?? '' }}@endif</div><div class="page-header-actions">{{ $actions ?? '' }}</div></header>
@if($headingCard ?? false)</div>@endif
