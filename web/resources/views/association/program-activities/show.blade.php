@extends('layouts.association')
@php
    $workflow = app(\App\View\WorkflowNavigation::class);
    $parents = $activity->relatedProject ? [['name' => 'association.projects.show', 'parameters' => ['project' => $activity->relatedProject->id], 'label' => $activity->relatedProject->title]] : [];
    $workflowBack = $workflow->back(request(), 'association.program-activities.index', [], $parents);
    $breadcrumbs = [['label' => $association->name]];
    foreach ($parents as $parent) $breadcrumbs[] = ['label' => $parent['label'], 'url' => $workflow->parent(request(), $parent['name'], $parent['parameters'])];
@endphp

@section('content')
<section class="program-management" data-workspace-module="association-program-activities" data-workspace-title="Program Activity Details" data-association-program-activities>
    <x-workflow-navigation :back="$workflowBack" :parents="$breadcrumbs" :current="$activity->title" />
    <header class="card program-heading"><div><p class="eyebrow">{{ $association->name }}</p><h1 tabindex="-1">Program Activity Details</h1><p>{{ $activity->title }}</p></div></header>
    <section class="card program-information" aria-labelledby="activity-information-title">
        <h2 id="activity-information-title">Program Activity information</h2>
        <p>Read-only information for your association.</p>
        @if($activity->archived_at)<p class="notice">This Program Activity is archived.</p>@endif
        <dl class="detail-grid">
            <div><dt>Title</dt><dd>{{ $activity->title }}</dd></div>
            <div><dt>Status</dt><dd><x-status-badge :value="ucfirst($activity->status)" /></dd></div>
            <div><dt>Program year</dt><dd>{{ $activity->program_year }}</dd></div>
            <div><dt>Activity date</dt><dd>{{ $activity->activity_date?->format('M j, Y') ?? 'Not recorded' }}</dd></div>
            <div><dt>Venue</dt><dd>{{ filled($activity->venue) ? $activity->venue : 'Not recorded' }}</dd></div>
            <div><dt>Conducted by</dt><dd>{{ filled($activity->conducted_by) ? $activity->conducted_by : 'Not recorded' }}</dd></div>
            <div><dt>Related project</dt><dd>@if($activity->relatedProject)<a href="{{ route('association.projects.show', $activity->relatedProject->id) }}">{{ $activity->relatedProject->title }}</a>@else{{ $activity->related_project_id ? 'Not available within your association' : 'Not recorded' }}@endif</dd></div>
        </dl>
        <h3>Description</h3><p class="program-preserve-text">{{ filled($activity->description) ? $activity->description : 'Not recorded' }}</p>
    </section>
</section>
@endsection
