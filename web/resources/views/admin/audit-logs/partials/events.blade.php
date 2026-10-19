<p class="audit-scroll-hint" id="audit-scroll-help" @if(!$logs->count()) hidden @endif>Scroll horizontally to view every column.</p>
<div class="table-scroll" role="region" aria-label="Recorded audit events" tabindex="0" aria-describedby="audit-scroll-help">
    <table class="data-table audit-table {{ $logs->count() ? '' : 'audit-table-empty' }}">
        <caption class="sr-only">Recorded audit events, newest first. Times shown in {{ $timezone }}.</caption>
        <thead><tr><th scope="col">Date and time</th><th scope="col">Event / module</th><th scope="col">Actor</th><th scope="col">Affected record</th><th scope="col"><span class="sr-only">Details</span></th></tr></thead>
        <tbody>
            @forelse($logs as $event)
                <tr>
                    <td class="audit-timestamp"><time datetime="{{ $event->performed_at->toIso8601String() }}"><span>{{ $event->performed_at->setTimezone($timezone)->format('M j, Y') }}</span><small>{{ $event->performed_at->setTimezone($timezone)->format('H:i:s T') }}</small></time></td>
                    <th scope="row" class="audit-event-cell"><span class="audit-event-name">{{ $event->action_type }}</span><small>{{ $event->module }}</small></th>
                    <td><span class="audit-actor-name @if(!$event->actor) audit-actor-unavailable @endif">{{ $event->actor?->display_name ?? 'Deleted or unavailable actor' }}</span><small class="audit-identifier">{{ $event->actor_user_id ?? 'No retained actor identifier' }}</small></td>
                    <td><span class="badge badge-neutral audit-entity-type">{{ $event->entity_type ?? 'No entity type recorded' }}</span><small class="audit-identifier">{{ $event->entity_id ?? 'No entity identifier recorded' }}</small></td>
                    <td class="audit-row-action"><a class="button button-quiet" href="{{ route('admin.audit-logs.show', $event->id) }}" aria-label="View audit event {{ $event->id }}"><x-icon name="eye" />View event</a></td>
                </tr>
            @empty
                <tr><td colspan="5">
                    <div class="empty-state audit-empty">
                        <span class="audit-empty-icon"><x-icon name="receipt" /></span>
                        @if($logs->total() > 0)
                            <strong>No audit records are available on this page.</strong><p>Return to the first page to view matching events.</p>
                            <a class="button" href="{{ $logs->url(1) }}">First page</a>
                        @elseif($activeFilterCount)
                            <strong>No audit records match these filters.</strong><p>Adjust the filters or reset them to view the full register.</p>
                            <a class="button" href="{{ route('admin.audit-logs.index') }}">Reset filters</a>
                        @else
                            <strong>No audit records have been recorded.</strong><p>Recorded events will appear here when an audited action occurs.</p>
                        @endif
                    </div>
                </td></tr>
            @endforelse
        </tbody>
    </table>
</div>
