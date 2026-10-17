@props(['label', 'value' => '—', 'icon' => 'chart', 'note' => 'Data will appear once connected', 'animate' => true])
<article class="stat-card"><div class="stat-top"><span>{{ $label }}</span><span class="stat-icon"><x-icon :name="$icon" /></span></div><strong><x-summary-counter :value="$value" :animate="$animate" /></strong><small>{{ $note }}</small></article>
