<dl class="monitoring-detail-grid">
    @foreach($details as $label => $value)
        <div><dt>{{ $label }}</dt><dd>{{ filled($value) ? $value : 'Not recorded' }}</dd></div>
    @endforeach
</dl>
