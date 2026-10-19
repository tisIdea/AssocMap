<nav class="program-summary" aria-label="{{ $moduleLabel }} status groups">
    @foreach($cards as $key => [$label, $status, $icon])
        <a href="{{ route($prefix.'.'.$module.'.index', array_replace($filters, ['status' => $status])) }}" @if($filters['status'] === $status) aria-current="page" @endif><x-stat-card :label="$label" :value="number_format($summary[$key])" :icon="$icon" note="View matching records" :animate="false" /></a>
    @endforeach
</nav>
<p class="program-help">Current and lifecycle counts exclude archived records. Archived is a separate record state. Counts cover your authorized records before search and filters.</p>
