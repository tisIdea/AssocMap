@extends('layouts.admin')

@section('content')
<section class="audit-module" data-workspace-module="audit-logs" data-workspace-title="Audit Event">
    <header class="card audit-page-heading">
        <div>
            <p class="eyebrow">Audit Log</p><h1 tabindex="-1">Audit Event</h1>
            <p>Review the recorded action and its available change details.</p>
            <span class="audit-readonly"><x-icon name="shield" />Read-only history</span>
        </div>
        <a class="button" href="{{ route('admin.audit-logs.index') }}"><x-icon name="back" />Back to Audit Logs</a>
    </header>

    <section class="card audit-details" aria-labelledby="audit-event-title">
        <header class="audit-detail-heading">
            <span class="stat-icon"><x-icon name="receipt" /></span>
            <div><p class="eyebrow">{{ $event->module }}</p><h2 id="audit-event-title">{{ $event->action_type }}</h2><p>Event information</p></div>
        </header>
        <dl class="audit-detail-grid">
            <div><dt>Performed at</dt><dd><time datetime="{{ $event->performed_at->toIso8601String() }}">{{ $event->performed_at->setTimezone($timezone)->format('M j, Y') }}<span class="audit-secondary">{{ $event->performed_at->setTimezone($timezone)->format('H:i:s.u T') }}</span></time></dd></div>
            <div><dt>Actor (current profile name)</dt><dd>{{ $event->actor?->display_name ?? 'Deleted or unavailable actor' }}</dd></div>
            <div><dt>Actor identifier</dt><dd class="audit-identifier">{{ $event->actor_user_id ?? 'No retained actor identifier' }}</dd></div>
            <div><dt>Entity type</dt><dd>{{ $event->entity_type ?? 'No entity type recorded' }}</dd></div>
            <div><dt>Entity identifier</dt><dd class="audit-identifier">{{ $event->entity_id ?? 'No entity identifier recorded' }}</dd></div>
            <div><dt>Audit identifier</dt><dd class="audit-identifier">{{ $event->id }}</dd></div>
        </dl>
        <p class="audit-detail-note">Actor names come from current profiles. {{ in_array($event->entity_type, ['project_termination', 'project_closure'], true) ? 'The actor role and identifier for this lifecycle action are retained in the After snapshot.' : 'Historical actor names and roles were not stored for this event type.' }} Entity references do not require the current business record to exist.</p>
    </section>

    <section aria-labelledby="audit-changes-title">
        <div class="audit-changes-heading"><h2 id="audit-changes-title">Recorded changes</h2><p>Only approved change details are shown.</p></div>
        @if($event->action_type === 'DELETE GIS' && $event->entity_type === 'gis_location')
            <p class="audit-context"><x-icon name="info" /><span>This event records permanent GIS deletion. The minimal deletion audit does not retain geometry or record snapshots.</span></p>
        @else
            <p class="audit-context"><x-icon name="info" /><span>These details describe the event. The affected record may have changed or become unavailable since then.</span></p>
        @endif
        <div class="audit-snapshots">
            @foreach(['Before' => $oldDetails, 'After' => $newDetails] as $label => $details)
                <section class="card audit-details audit-snapshot-card" aria-labelledby="audit-snapshot-{{ strtolower($label) }}">
                    <header class="audit-snapshot-heading"><h3 id="audit-snapshot-{{ strtolower($label) }}">{{ $label }}</h3><span>{{ $label === 'Before' ? 'Previous recorded values' : 'Values recorded by this event' }}</span></header>
                    @if($details['json'] !== null)
                        <pre class="audit-snapshot" tabindex="0" aria-label="{{ $label }} snapshot"><code>{{ $details['json'] }}</code></pre>
                    @else
                        <p class="audit-snapshot-empty">{{ $details['message'] }}</p>
                    @endif
                </section>
            @endforeach
        </div>
    </section>
</section>
@endsection
