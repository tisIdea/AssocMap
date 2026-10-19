<footer class="table-footer member-register-pagination">
    <span>Showing {{ $paginator->firstItem() ?? 0 }}–{{ $paginator->lastItem() ?? 0 }} of {{ number_format($paginator->total()) }} {{ $recordLabel }}</span>
    <nav aria-label="{{ ucfirst($recordLabel) }} pages">
        @if($paginator->previousPageUrl())<a class="button" href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a>@endif
        <span>Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>
        @if($paginator->nextPageUrl())<a class="button" href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a>@endif
    </nav>
</footer>
