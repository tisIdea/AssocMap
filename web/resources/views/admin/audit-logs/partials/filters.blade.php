<form id="audit-filter-panel" class="audit-filters" method="GET" action="{{ route('admin.audit-logs.index') }}" aria-describedby="audit-filter-help{{ ($invalidFilters ?? false) ? ' audit-filter-errors' : '' }}">
    <fieldset @disabled($unavailable ?? false)>
        <legend class="sr-only">Filter audit events</legend>
        <p id="audit-filter-help" class="field-help">Choose recorded values to narrow the register. Filters match exact values. Dates use {{ $timezone }}.</p>
        <div class="audit-filter-grid">
            @foreach(['action_type' => ['Action', 'All actions'], 'module' => ['Module', 'All modules'], 'entity_type' => ['Entity type', 'All entity types']] as $field => [$label, $default])
                <div class="field">
                    <label for="audit-{{ $field }}">{{ $label }}</label>
                    <select id="audit-{{ $field }}" name="{{ $field }}">
                        <option value="">{{ $default }}</option>
                        @foreach($choices[$field] ?? [] as $choice)
                            <option value="{{ $choice }}" @selected(($filters[$field] ?? '') === $choice)>{{ $field === 'entity_type' ? \Illuminate\Support\Str::headline($choice) : $choice }}</option>
                        @endforeach
                    </select>
                </div>
            @endforeach
            <div class="field">
                <label for="audit-per-page">Events per page</label>
                <select id="audit-per-page" name="per_page">@foreach([25, 50, 100] as $count)<option value="{{ $count }}" @selected((int) ($filters['per_page'] ?? 25) === $count)>{{ $count }}</option>@endforeach</select>
            </div>
            <div class="field audit-actor-filter">
                <label for="audit-actor">Performed by</label>
                <select id="audit-actor" name="actor_user_id" aria-describedby="audit-actor-help">
                    <option value="">All actors</option>
                    <option value="unavailable" @selected(($filters['actor_user_id'] ?? '') === 'unavailable')>Deleted or unavailable actor</option>
                    @foreach($actors as $actor)
                        <option value="{{ $actor->id }}" @selected(($filters['actor_user_id'] ?? '') === $actor->id)>{{ $actor->display_name ?? 'Unavailable profile' }} ({{ $actor->id }})</option>
                    @endforeach
                </select>
                <small class="field-help" id="audit-actor-help">Current names and identifiers distinguish actors with the same name. Deleted or unavailable actor selects events without an actor reference.</small>
            </div>
            <div class="field"><label for="audit-date-from">Date from</label><input id="audit-date-from" name="date_from" type="date" value="{{ $filters['date_from'] ?? '' }}"></div>
            <div class="field"><label for="audit-date-to">Date to</label><input id="audit-date-to" name="date_to" type="date" value="{{ $filters['date_to'] ?? '' }}"></div>
        </div>
        <div class="audit-filter-actions">
            <button class="button button-primary" type="submit">Apply filters</button>
            <a class="button" href="{{ route('admin.audit-logs.index') }}">Reset filters</a>
        </div>
    </fieldset>
</form>
