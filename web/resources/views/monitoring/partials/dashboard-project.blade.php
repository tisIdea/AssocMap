@php
    $quarters = $project->monitoringRecords->groupBy('quarter.quarter_number');
    $projectLabel = $project->title.' · '.($project->association?->name ?? 'Association unavailable');
@endphp
<section class="monitoring-project-row" aria-labelledby="monitoring-project-{{ $project->id }}" data-monitoring-project="{{ $project->id }}">
    <header class="monitoring-project-heading">
        <div><p class="eyebrow">Reporting year {{ $selectedYear }}</p><h3 id="monitoring-project-{{ $project->id }}">{{ $project->title }}</h3><p>{{ $project->association?->name ?? 'Association unavailable' }}</p><p>{{ $project->matching_record_count }} matching record(s)</p></div>
        <div class="monitoring-project-status"><x-status-badge :value="ucfirst($project->status)" />
            @if($project->association?->archived_at)<span class="badge badge-neutral">Association Archived — Read Only</span>
            @elseif($project->association?->status !== 'active')<span class="badge badge-neutral">Association unavailable or inactive — Read Only</span>@endif
            @if($project->archived_at)<span class="badge badge-neutral">Project Archived — Read Only</span>
            @elseif($project->isFinalized())<span class="badge badge-neutral">Project finalized — Read Only</span>@endif
        </div>
    </header>
    <div class="monitoring-quarter-grid">
        @foreach([1, 2, 3, 4] as $quarterNumber)
            @include('monitoring.partials.quarter-card', ['quarterRecords' => $quarters->get($quarterNumber, collect())])
        @endforeach
    </div>
</section>
