<footer class="audit-pagination-bar">
    <p>
        @if($logs->count())Showing <strong>{{ $logs->firstItem() }}&ndash;{{ $logs->lastItem() }}</strong> of {{ number_format($logs->total()) }} {{ $activeFilterCount ? 'matching' : 'recorded' }} events.
        @else {{ number_format($logs->total()) }} {{ $activeFilterCount ? 'matching' : 'recorded' }} events.
        @endif
        <span>Page {{ $logs->currentPage() }} of {{ $logs->lastPage() }}.</span>
    </p>
    <nav class="audit-pagination" aria-label="Audit pagination">
        @if($logs->currentPage() > 1)<a class="button" href="{{ $logs->url(1) }}">First page</a>@endif
        @if($logs->previousPageUrl())<a class="button" href="{{ $logs->previousPageUrl() }}" rel="prev"><x-icon name="back" />Previous</a>@endif
        @if($logs->nextPageUrl())<a class="button" href="{{ $logs->nextPageUrl() }}" rel="next">Next<x-icon name="arrow" /></a>@endif
    </nav>
</footer>
