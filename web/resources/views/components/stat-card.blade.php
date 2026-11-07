@props(['label', 'value' => '—', 'icon' => 'chart', 'note' => null, 'animate' => true])
<article class="stat-card"><div class="stat-top"><span>{{ $label }}</span><span class="stat-icon"><x-icon :name="$icon" /></span></div><strong><x-summary-counter :value="$value" :animate="$animate" /></strong>@if($note)<small>{{ $note }}</small>@endif</article>
