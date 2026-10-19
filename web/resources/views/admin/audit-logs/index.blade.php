@extends('layouts.admin')
@php
    $filters = $filters ?? [];
    $choices = $choices ?? [];
    $actors = $actors ?? collect();
    $logs = $logs ?? null;
    $timezone = $timezone ?? config('app.timezone', 'UTC');
    $activeFilterCount = collect($filters)->except(['per_page', 'page'])
        ->filter(fn ($value) => $value !== null && $value !== '')->count();
    $globalFeedbackNotification = ($unavailable ?? false) || ($invalidFilters ?? false)
        ? ['type' => 'error', 'title' => 'Audit Logs Unavailable', 'message' => ($invalidFilters ?? false)
            ? 'Review the audit filter messages and try again.'
            : 'Audit logs are temporarily unavailable. No audit history was changed.']
        : null;
@endphp

@section('content')
<section class="audit-module" data-workspace-module="audit-logs" data-workspace-title="Audit Log">
    <header class="card audit-page-heading">
        <div>
            <p class="eyebrow">System Administrator</p>
            <h1 tabindex="-1">Audit Log</h1>
            <p>Review recorded system activity, the people involved, and affected records.</p>
        </div>
        <span class="audit-readonly"><x-icon name="shield" />Read-only history</span>
    </header>

    <p class="audit-context"><x-icon name="info" /><span>This register shows recorded events. Some workflows may not yet generate audit events.</span></p>

    @if($invalidFilters ?? false)
        <div id="audit-filter-errors" class="notice notice-error" role="alert">
            <div><strong>Correct the filter parameters.</strong><ul>@foreach($filterErrors ?? [] as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        </div>
    @endif
    @if($unavailable ?? false)
        <div class="notice notice-error audit-unavailable" role="alert" data-feedback-fallback>
            <div><strong>Audit logs are temporarily unavailable.</strong><p>No audit history was changed.</p></div>
            <a class="button" href="{{ route('admin.audit-logs.index') }}">Retry audit logs</a>
        </div>
    @endif

    <details class="card audit-filter-disclosure" @if($activeFilterCount || ($invalidFilters ?? false)) open @endif>
        <summary class="audit-filter-summary" aria-controls="audit-filter-panel">
            <span class="audit-filter-copy"><strong>Filter audit events</strong><small>{{ $activeFilterCount ? $activeFilterCount.' active filter(s)' : 'All recorded events' }}</small></span>
            <span class="button audit-filter-toggle">
                <x-icon name="filter" />Filters
                @if($activeFilterCount)
                    <span class="audit-filter-count">{{ $activeFilterCount }}<span class="sr-only"> active filters</span></span>
                @endif
                <x-icon name="chevron-down" />
            </span>
        </summary>
        @include('admin.audit-logs.partials.filters')
    </details>

    <section class="card audit-register" aria-labelledby="audit-register-title">
        <header class="audit-register-heading">
            <div><h2 id="audit-register-title">Audit events</h2>
                @if($logs !== null)
                    <p><strong>{{ number_format($logs->total()) }}</strong> {{ $activeFilterCount ? 'matching event(s)' : 'recorded event(s)' }}</p>
                @else
                    <p>{{ ($invalidFilters ?? false) ? 'Correct the filters to load records.' : 'Records unavailable' }}</p>
                @endif
            </div>
            <p class="audit-order">Newest first <span>Times shown in {{ $timezone }}</span></p>
        </header>
        @if($logs !== null)
            @include('admin.audit-logs.partials.events')
            @include('admin.audit-logs.partials.pagination')
        @endif
    </section>
</section>
@endsection
