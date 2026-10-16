@extends('layouts.admin')

@section('content')
    <section class="audit-module" data-workspace-module="audit-logs" data-workspace-title="Audit Event">
        <a class="back-link" href="{{ route('admin.audit-logs.index') }}"><x-icon name="back" />Back to Audit Logs</a>
        @include('partials.page-header', ['title' => 'Audit Event', 'description' => 'A read-only record of a recorded application action.', 'eyebrow' => 'System Administrator'])
        <section class="card audit-details">
            <h2>Event information</h2>
            <dl class="detail-grid">
                <div><dt>Audit identifier</dt><dd class="audit-identifier">{{ $event->id }}</dd></div>
                <div><dt>Performed at</dt><dd><time datetime="{{ $event->performed_at->toIso8601String() }}">{{ $event->performed_at->setTimezone($timezone)->format('Y-m-d H:i:s.u T') }}</time></dd></div>
                <div><dt>Action</dt><dd>{{ $event->action_type }}</dd></div>
                <div><dt>Module</dt><dd>{{ $event->module }}</dd></div>
                <div><dt>Actor (current profile name)</dt><dd>{{ $event->actor?->display_name ?? 'Deleted or unavailable actor' }}</dd></div>
                <div><dt>Actor identifier</dt><dd class="audit-identifier">{{ $event->actor_user_id ?? 'No retained actor identifier' }}</dd></div>
                <div><dt>Entity type</dt><dd>{{ $event->entity_type ?? 'No entity type recorded' }}</dd></div>
                <div><dt>Entity identifier</dt><dd class="audit-identifier">{{ $event->entity_id ?? 'No entity identifier recorded' }}</dd></div>
            </dl>
            <p class="field-help">Actor names come from current profiles. {{ in_array($event->entity_type, ['project_termination', 'project_closure'], true) ? 'The actor role and identifier for this lifecycle action are retained in the After snapshot.' : 'Historical actor names and roles were not stored for this event type.' }} Entity references do not require the current business record to exist.</p>
        </section>
        @if($event->action_type === 'DELETE GIS' && $event->entity_type === 'gis_location')
            <div class="notice">This event records permanent GIS deletion. The minimal deletion audit does not retain geometry or record snapshots.</div>
        @else
            <div class="notice">Change details describe this event. The referenced record may have changed or become unavailable since then.</div>
        @endif
        <div class="audit-snapshots">
            @foreach(['Before' => $oldDetails, 'After' => $newDetails] as $label => $details)
                <section class="card audit-details"><h2>{{ $label }}</h2>
                    @if($details['json'] !== null)<pre class="audit-snapshot" tabindex="0" aria-label="{{ $label }} snapshot"><code>{{ $details['json'] }}</code></pre>
                    @else<p class="muted">{{ $details['message'] }}</p>@endif
                </section>
            @endforeach
        </div>
        <p class="field-help">Only approved snapshot fields are displayed. Historical events remain readable when their referenced record is unavailable.</p>
    </section>
@endsection
