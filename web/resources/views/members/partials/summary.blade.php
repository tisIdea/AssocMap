<nav class="member-summary summary-cards" aria-label="{{ $applicationRegister ? 'Application' : 'Member' }} status groups">
    @foreach($cards as $key => [$label, $value, $icon])
        <a href="{{ route($indexRoute, array_replace($filters, ['status' => $value])) }}" @if($filters['status'] === $value) aria-current="page" @endif>
            <x-stat-card :label="$label" :value="number_format($summary[$key])" :icon="$icon" note="View matching records" :animate="false" />
        </a>
    @endforeach
</nav>
<p class="member-summary-help">{{ $summaryNote }} Counts cover your authorized records before search and filters.</p>
