@php
    $introduction = match ($role) {
        'system_admin' => 'Current organizational records and administrative attention across all associations.',
        'field_officer' => 'Operational records for your currently assigned active, unarchived associations.',
        default => 'Your association records, applications, projects and monitoring overview.',
    };
    $roleLabel = match ($role) { 'system_admin' => 'System Administrator', 'field_officer' => 'Field Officer', default => 'Association Account' };
    $dashboardActions = match ($role) {
        'system_admin' => [
            ['admin.associations.index', 'Associations', 'building'], ['admin.users.index', 'Field Officers & accounts', 'user'],
            ['admin.association-officers.index', 'Association Officers', 'users'], ['admin.projects.index', 'Projects', 'briefcase'],
            ['admin.trainings.index', 'Trainings', 'graduation'], ['admin.monitoring.index', 'Monitoring', 'chart'],
            ['admin.reports.index', 'Reports & Analytics', 'receipt'], ['admin.gis.index', 'GIS Mapping', 'map'],
        ],
        'field_officer' => [
            ['field-officer.association-officers.index', 'Assigned association officers', 'building'], ['field-officer.members.index', 'Members', 'users'],
            ['field-officer.member-applications.index', 'Member Applications', 'receipt'], ['field-officer.projects.index', 'Projects', 'briefcase'],
            ['field-officer.trainings.index', 'Trainings', 'graduation'], ['field-officer.monitoring.index', 'Monitoring', 'chart'],
            ['field-officer.reports.index', 'Reports & Analytics', 'receipt'], ['field-officer.gis.index', 'GIS Mapping', 'map'],
        ],
        default => [
            ['association.member-applications.index', 'Member Applications', 'users'], ['association.monitoring.index', 'Projects & monitoring', 'briefcase'],
            ['association.reports.index', 'Reports & Analytics', 'receipt'], ['association.gis.index', 'GIS Mapping', 'map'],
        ],
    };
@endphp
@include('partials.page-header', ['title' => 'Dashboard', 'description' => $introduction, 'eyebrow' => $roleLabel])
@if($state !== 'ready')
    <section class="card dashboard-panel" role="alert">
        <h2>{{ $state === 'denied' ? 'Dashboard access unavailable' : 'Dashboard temporarily unavailable' }}</h2>
        <p class="dashboard-help">{{ $state === 'denied' ? 'Your account or association access is unavailable. Association Accounts need one active account link to an active, unarchived association. Ask the administrator to review access.' : 'Current records could not be read. No statistics are displayed. Retry when the database is available.' }}</p>
        <a class="button button-primary" href="{{ route($prefix.'.dashboard') }}">Retry dashboard</a>
    </section>
@else
    <div class="dashboard-context">
        <p>Updated <time datetime="{{ $dashboard['generated_at'] }}">{{ \Carbon\CarbonImmutable::parse($dashboard['generated_at'])->format('M j, Y, g:i a') }}</time> · {{ $dashboard['timezone'] }}</p>
        <a class="button" href="{{ route($prefix.'.dashboard') }}">Refresh dashboard</a>
    </div>
    @if($role === 'association_account')
        <section class="card dashboard-panel dashboard-association" aria-labelledby="dashboard-association-title">
            <span class="stat-icon"><x-icon name="building" /></span>
            <div><h2 id="dashboard-association-title">{{ $dashboard['association']['name'] }}</h2><p>{{ $dashboard['association']['municipality'] }} · {{ ucfirst($dashboard['association']['status']) }}</p><p class="dashboard-help">SAAD Phase II join date: {{ $dashboard['association']['joined_on'] }}. This dashboard contains only this association's records.</p></div>
        </section>
    @elseif($role === 'field_officer' && $dashboard['scope_count'] === 0)
        <p class="notice" role="status">No active, unarchived associations are currently assigned to you. Ask the administrator to review assignments.</p>
    @endif
    <section class="dashboard-metrics" aria-label="Dashboard summary">
        @foreach($dashboard['metrics'] as $metric)
            <x-stat-card :label="$metric['label']" :value="$metric['value'] === null ? 'Unavailable' : number_format($metric['value'])" :icon="$metric['icon']" :note="$metric['note']" />
        @endforeach
    </section>
    <section class="card dashboard-panel" aria-labelledby="dashboard-actions-title">
        <h2 id="dashboard-actions-title">Quick actions</h2>
        <nav class="dashboard-actions" aria-label="Dashboard quick actions">
            @foreach($dashboardActions as [$routeName, $label, $icon])
                <a class="button" href="{{ route($routeName) }}"><x-icon :name="$icon" />{{ $label }}</a>
            @endforeach
            @if($role === 'field_officer')<a class="button" href="#assigned-associations"><x-icon name="building" />Assigned associations</a>@endif
        </nav>
    </section>
    @if($role === 'system_admin')
        <section class="card dashboard-panel" aria-labelledby="dashboard-attention-title">
            <h2 id="dashboard-attention-title">Administrative attention</h2>
            @if($dashboard['officer_attention'] === null)<p class="dashboard-empty">Officer assignment checks are temporarily unavailable.</p>
            @else<p class="dashboard-help"><strong>{{ number_format($dashboard['officer_attention']) }}</strong> active, unarchived associations have an inactive or invalid assigned Field Officer profile/role.</p>@endif
            <a class="button" href="{{ route('admin.users.index') }}">Review accounts & assignments</a>
            <a class="button" href="{{ route('admin.audit-logs.index') }}">Open Audit Logs</a>
        </section>
    @else
        <section class="card dashboard-panel" aria-labelledby="dashboard-applications-title">
            <h2 id="dashboard-applications-title">{{ $role === 'field_officer' ? 'Applications awaiting your review' : 'Member application status' }}</h2>
            @if($dashboard['applications'] === null)<p class="dashboard-empty">Application counts are temporarily unavailable.</p>
            @else
                <dl class="dashboard-summary">
                    @foreach($dashboard['applications'] as $status => $count)<div><dt>{{ ucfirst($status) }}</dt><dd>{{ number_format($count) }}</dd></div>@endforeach
                </dl>
            @endif
            <p class="dashboard-help">{{ $role === 'field_officer' ? 'Review pending applications through the existing workflow. Approval creates an official member record.' : 'Approval and rejection belong to the Field Officer. Application totals are separate from official member totals.' }}</p>
            <a class="button button-primary" href="{{ route($prefix.'.member-applications.index', ['status' => 'pending']) }}">{{ $role === 'field_officer' ? 'Review pending applications' : 'Open Member Applications' }}</a>
        </section>
    @endif
    <div class="dashboard-panels">
        @foreach($dashboard['charts'] as $chart)
            @if($chart === null)<section class="card dashboard-panel"><h2>Chart unavailable</h2><p class="dashboard-empty">This summary could not be read. Other available widgets remain visible.</p></section>
            @else @include('dashboard.chart', ['chart' => $chart]) @endif
        @endforeach
    </div>
    @if($role !== 'association_account')
        <section class="card dashboard-panel" id="assigned-associations" aria-labelledby="dashboard-associations-title">
            <h2 id="dashboard-associations-title">{{ $role === 'field_officer' ? 'Assigned associations' : 'Association overview' }}</h2>
            <p class="dashboard-help">Up to 10 associations, ordered by name. Member counts are active/unarchived; project counts are unarchived.</p>
            @if($dashboard['associations'] === null)<p class="dashboard-empty">The association list is temporarily unavailable.</p>
            @elseif(!$dashboard['associations'])<p class="dashboard-empty">No eligible associations in this scope.</p>
            @else
                <div class="dashboard-table-scroll" tabindex="0" role="region" aria-label="Association overview">
                    <table><caption class="sr-only">{{ $role === 'field_officer' ? 'Current eligible assignments' : 'Registered associations' }}</caption><thead><tr><th scope="col">Association</th><th scope="col">Municipality / City</th><th scope="col">Status</th><th scope="col">Active members</th><th scope="col">Unarchived projects</th><th scope="col">Officers</th></tr></thead><tbody>
                        @foreach($dashboard['associations'] as $association)
                            <tr><th scope="row">{{ $association['name'] }}</th><td>{{ $association['municipality'] }}</td><td><span class="badge badge-neutral">{{ ucfirst($association['status']) }}</span></td><td>{{ number_format($association['active_members']) }}</td><td>{{ number_format($association['unarchived_projects']) }}</td><td><a href="{{ route($prefix.'.association-officers.show', $association['id']) }}">View officers<span class="sr-only"> for {{ $association['name'] }}</span></a></td></tr>
                        @endforeach
                    </tbody></table>
                </div>
                @if($dashboard['scope_count'] > 10)<p class="dashboard-help">{{ number_format($dashboard['scope_count']) }} associations are in scope; this list shows the first 10. Use the association/officer workspace for the remaining records.</p>@endif
            @endif
        </section>
    @endif
    <div class="dashboard-panels">
        <section class="card dashboard-panel" aria-labelledby="dashboard-training-title">
            <h2 id="dashboard-training-title">Training history</h2>
            @if($dashboard['training'] === null)<p class="dashboard-empty">Training summaries are temporarily unavailable.</p>
            @else
                <dl class="dashboard-summary"><div><dt>Recorded activities</dt><dd>{{ number_format($dashboard['training']['records']) }}</dd></div><div><dt>Completed activities</dt><dd>{{ number_format($dashboard['training']['completed']) }}</dd></div><div><dt>Distinct members marked present</dt><dd>{{ number_format($dashboard['training']['distinct_present_members']) }}</dd></div></dl>
            @endif
            <p class="dashboard-help">All recorded dates. Archived training and member history is retained. Distinct attendees are people, not attendance occurrences.</p>
        </section>
        <section class="card dashboard-panel" aria-labelledby="dashboard-monitoring-title">
            <h2 id="dashboard-monitoring-title">Monitoring overview</h2>
            @if($dashboard['monitoring'] === null)<p class="dashboard-empty">Monitoring summaries are temporarily unavailable.</p>
            @else
                <dl class="dashboard-summary"><div><dt>Quarterly records</dt><dd>{{ number_format($dashboard['monitoring']['records']) }}</dd></div><div><dt>Distinct year / quarter periods</dt><dd>{{ number_format($dashboard['monitoring']['periods']) }}</dd></div><div><dt>Visit reports</dt><dd>{{ $dashboard['monitoring']['visits'] === null ? 'Unavailable' : number_format($dashboard['monitoring']['visits']['total']) }}</dd></div></dl>
                <p class="dashboard-help">{{ $dashboard['monitoring']['visits'] === null ? 'Visit-report data could not be read.' : number_format($dashboard['monitoring']['visits']['legacy_imports']).' visit reports are legacy imports.' }} Records, periods and visits are different measures.</p>
            @endif
            <p class="dashboard-help">All recorded years for unarchived projects. Missing records do not imply failed or incomplete activities.</p>
            <a class="button" href="{{ route($prefix.'.monitoring.index') }}">Open Monitoring</a>
        </section>
    </div>
    <section class="card dashboard-panel" aria-labelledby="dashboard-projects-title">
        <h2 id="dashboard-projects-title">Recently registered projects</h2>
        <p class="dashboard-help">Up to six unarchived projects, ordered by registration timestamp. This is a record list, not an audit feed.</p>
        @if($dashboard['recent_projects'] === null)<p class="dashboard-empty">Project records are temporarily unavailable.</p>
        @elseif(!$dashboard['recent_projects'])<p class="dashboard-empty">No unarchived projects in this scope.</p>
        @else
            <ul class="dashboard-records">
                @foreach($dashboard['recent_projects'] as $project)<li><div><strong>{{ $project['title'] }}</strong><p>{{ $project['association'] }} · Program year {{ $project['program_year'] }}</p><small>Registered {{ \Carbon\CarbonImmutable::parse($project['created_at'])->setTimezone('Asia/Manila')->format('M j, Y') }}</small></div><span class="badge badge-neutral">{{ ucfirst($project['status']) }}</span></li>@endforeach
            </ul>
        @endif
        <a class="button" href="{{ route($role === 'association_account' ? 'association.monitoring.index' : $prefix.'.projects.index') }}">{{ $role === 'association_account' ? 'View projects in Monitoring' : 'Open Projects' }}</a>
    </section>
    <div class="dashboard-panels">
        <section class="card dashboard-panel" aria-labelledby="dashboard-activities-title">
            <h2 id="dashboard-activities-title">Latest dated program activities</h2>
            <p class="dashboard-help">Up to six unarchived activities ordered by activity date, including planned dates. {{ $role === 'system_admin' ? 'Includes program-wide activities.' : 'Only association-linked activities in your scope.' }}</p>
            @if($dashboard['activities'] === null)<p class="dashboard-empty">Program activity records are temporarily unavailable.</p>
            @else
                <ul class="dashboard-records">@forelse($dashboard['activities'] as $activity)<li><div><strong>{{ $activity['title'] }}</strong><p>{{ $activity['activity_date'] }}</p></div><span class="badge badge-neutral">{{ ucfirst($activity['status']) }}</span></li>@empty<li>No recorded activities in this scope.</li>@endforelse</ul>
            @endif
        </section>
        <section class="card dashboard-panel" aria-labelledby="dashboard-recent-monitoring-title">
            <h2 id="dashboard-recent-monitoring-title">Latest dated monitoring records</h2>
            <p class="dashboard-help">Up to six quarterly records by monitoring date. Status here is the saved historical monitoring status.</p>
            @if($dashboard['monitoring'] === null)<p class="dashboard-empty">Monitoring records are temporarily unavailable.</p>
            @else
                <ul class="dashboard-records">@forelse($dashboard['monitoring']['recent'] as $record)<li><div><strong>{{ $record['title'] }}</strong><p>{{ $record['year'] }} · Q{{ $record['quarter_number'] }} · {{ $record['monitoring_date'] }}</p></div><span class="badge badge-neutral">Status During Monitoring: {{ ucfirst(str_replace('_', ' ', $record['project_status'])) }}</span></li>@empty<li>No quarterly monitoring records in this scope.</li>@endforelse</ul>
            @endif
        </section>
    </div>
    <p class="dashboard-help">Dashboard totals use the scopes stated above. Detailed reports can use different periods and retain additional archived project history. Income and production quantities are available in Reports &amp; Analytics.</p>
@endif
