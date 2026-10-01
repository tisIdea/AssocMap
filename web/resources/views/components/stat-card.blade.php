@props(['label', 'value' => '—', 'icon' => 'chart', 'note' => 'Data will appear once connected'])
<article class="stat-card"><div class="stat-top"><span>{{ $label }}</span><span class="stat-icon"><x-icon :name="$icon" /></span></div><strong>{{ $value }}</strong><small>{{ $note }}</small></article>
