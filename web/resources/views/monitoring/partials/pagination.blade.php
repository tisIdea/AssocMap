<div class="monitoring-pagination">
    <p role="status">{{ number_format($paginator->total()) }} {{ $label }} · Showing {{ $paginator->firstItem() ?? 0 }}–{{ $paginator->lastItem() ?? 0 }}</p>
    {{ $paginator->links() }}
</div>
