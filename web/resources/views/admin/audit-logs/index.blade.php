@extends('layouts.admin')

@section('content')
    @php
        $filters = $filters ?? [];
        $choices = $choices ?? [];
        $actors = $actors ?? collect();
        $logs = $logs ?? null;
        $timezone = $timezone ?? config('app.timezone', 'UTC');
    @endphp
    <section class="audit-module" data-workspace-module="audit-logs" data-workspace-title="Audit Logs">
        @include('partials.page-header', ['title' => 'Audit Logs', 'description' => 'Review recorded application events and permitted change details.', 'eyebrow' => 'System Administrator'])
        <div class="notice"><x-icon name="shield" /><span>Read-only history. Audit events cannot be edited or deleted here. This register contains recorded events; other workflows may not yet generate audits.</span></div>
        @if($invalidFilters ?? false)
            <div class="notice notice-error" role="alert"><div><strong>Correct the filter parameters.</strong><ul>@foreach($filterErrors ?? [] as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>
        @endif
        @if($unavailable ?? false)
            <div class="notice notice-error" role="alert">Audit logs are temporarily unavailable. No audit history was changed.</div>
            <p><a class="button" href="{{ route('admin.audit-logs.index') }}">Retry audit logs</a></p>
        @endif
        <section class="card list-card">
            <form class="audit-filters" method="GET" action="{{ route('admin.audit-logs.index') }}">
                <fieldset @disabled($unavailable ?? false)>
                    <legend>Filter audit events</legend>
                    <p class="field-help">Action, module, and entity filters match exact values. Suggestions come from recorded events. Dates use {{ $timezone }}.</p>
                    <div class="filter-grid">
                        @foreach(['action_type' => 'Action', 'module' => 'Module', 'entity_type' => 'Entity type'] as $field => $label)
                            <div class="field"><label for="audit-{{ $field }}">{{ $label }}</label><input id="audit-{{ $field }}" name="{{ $field }}" list="audit-{{ $field }}-choices" maxlength="100" value="{{ $filters[$field] ?? '' }}"><datalist id="audit-{{ $field }}-choices">@foreach($choices[$field] ?? [] as $choice)<option value="{{ $choice }}">@endforeach</datalist></div>
                        @endforeach
                        <div class="field"><label for="audit-actor">Actor identifier</label><input id="audit-actor" name="actor_user_id" list="audit-actor-choices" maxlength="36" value="{{ $filters['actor_user_id'] ?? '' }}" placeholder="Actor UUID or unavailable" aria-describedby="audit-actor-help"><datalist id="audit-actor-choices"><option value="unavailable" label="Deleted or unavailable actor">@foreach($actors as $actor)<option value="{{ $actor->id }}" label="{{ $actor->display_name }}">@endforeach</datalist><small class="field-help" id="audit-actor-help">Suggestions show current profile names. Use unavailable for events without an actor reference.</small></div>
                        <div class="field"><label for="audit-date-from">Date from</label><input id="audit-date-from" name="date_from" type="date" value="{{ $filters['date_from'] ?? '' }}"></div>
                        <div class="field"><label for="audit-date-to">Date to</label><input id="audit-date-to" name="date_to" type="date" value="{{ $filters['date_to'] ?? '' }}"></div>
                        <div class="field"><label for="audit-per-page">Events per page</label><select id="audit-per-page" name="per_page">@foreach([25, 50, 100] as $count)<option value="{{ $count }}" @selected((int) ($filters['per_page'] ?? 25) === $count)>{{ $count }}</option>@endforeach</select></div>
                    </div>
                    <div class="filter-actions"><a class="button" href="{{ route('admin.audit-logs.index') }}">Clear filters</a><button class="button button-primary" type="submit">Apply filters</button></div>
                </fieldset>
            </form>
            @if($logs !== null)
                <div class="table-scroll"><table class="data-table">
                    <caption class="audit-caption">Recorded audit events, newest first. Times shown in {{ $timezone }}.</caption>
                    <thead><tr><th scope="col">Date and time</th><th scope="col">Actor</th><th scope="col">Action</th><th scope="col">Module</th><th scope="col">Affected entity</th><th scope="col">Details</th></tr></thead>
                    <tbody>
                        @forelse($logs as $event)
                            <tr>
                                <td><time datetime="{{ $event->performed_at->toIso8601String() }}">{{ $event->performed_at->setTimezone($timezone)->format('Y-m-d H:i:s T') }}</time></td>
                                <td>{{ $event->actor?->display_name ?? 'Deleted or unavailable actor' }}<small class="audit-identifier">{{ $event->actor_user_id ?? 'No retained actor identifier' }}</small></td>
                                <td>{{ $event->action_type }}</td><td>{{ $event->module }}</td>
                                <td>{{ $event->entity_type ?? 'No entity type recorded' }}<small class="audit-identifier">{{ $event->entity_id ?? 'No entity identifier recorded' }}</small></td>
                                <td><a class="text-button" href="{{ route('admin.audit-logs.show', $event->id) }}" aria-label="View audit event {{ $event->id }}">View event</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="6"><div class="empty-state"><strong>{{ $logs->total() > 0 ? 'No audit records are available on this page.' : (collect($filters)->except(['per_page', 'page'])->filter()->isNotEmpty() ? 'No audit records match these filters.' : 'No audit records have been recorded.') }}</strong><p>Clear filters to return to the full audit register. An empty page does not confirm that a workflow was audited.</p></div></td></tr>
                        @endforelse
                    </tbody>
                </table></div>
                <div class="table-footer"><span>{{ $logs->total() }} event(s). @if($logs->count())Showing {{ $logs->firstItem() }}–{{ $logs->lastItem() }}.@endif Page {{ $logs->currentPage() }} of {{ $logs->lastPage() }}.</span>
                    <nav class="audit-pagination" aria-label="Audit pagination">
                        @if($logs->previousPageUrl())<a class="button" href="{{ $logs->previousPageUrl() }}" rel="prev">Previous</a>@endif
                        @if($logs->nextPageUrl())<a class="button" href="{{ $logs->nextPageUrl() }}" rel="next">Next</a>@endif
                        @if($logs->currentPage() > 1)<a class="button" href="{{ $logs->url(1) }}">First page</a>@endif
                    </nav>
                </div>
            @endif
        </section>
    </section>
@endsection
