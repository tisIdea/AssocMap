@php
    $requestedModule = $module ?? null;
    $catalog = $catalog ?? require resource_path('ui/admin.php');

    if (isset($requestedModule['key'], $catalog['modules'][$requestedModule['key']])) {
        $catalog['modules'][$requestedModule['key']] = array_replace(
            $catalog['modules'][$requestedModule['key']],
            $requestedModule
        );
    }
@endphp

@extends('layouts.admin')

@section('content')
    @if(session('success'))
        <div class="notice notice-success" role="status">
            <x-icon name="check" />
            <span>{{ session('success') }}</span>
        </div>
    @endif

    @if(session('error'))
        <div class="notice notice-error" role="alert">
            <x-icon name="info" />
            <span>{{ session('error') }}</span>
        </div>
    @endif

    <section
        class="page-view"
        data-page="dashboard"
        data-title="Dashboard"
        data-initial-page="{{ $requestedModule['key'] ?? 'dashboard' }}"
    >
        @include('admin.dashboard')
    </section>

    @foreach($catalog['modules'] as $module)
        <section
            class="page-view"
            data-page="{{ $module['key'] }}"
            data-title="{{ $module['title'] }}"
            hidden
        >
            @if($module['key'] === 'projects')
                <header class="page-header">
                    <div>
                        <h1 tabindex="-1">Projects</h1>
                        <p>Manage association projects and material deliveries.</p>
                    </div>
                </header>

                <a
                    class="button button-primary"
                    href="{{ route('admin.projects.index') }}"
                >
                    Open Project Management
                </a>

            @elseif($module['key'] === 'trainings')
                <header class="page-header">
                    <div>
                        <h1 tabindex="-1">Trainings</h1>
                        <p>Manage training activities, participants, and attendance.</p>
                    </div>
                </header>

                <a
                    class="button button-primary"
                    href="{{ route('admin.trainings.index') }}"
                >
                    Open Training Management
                </a>

            @elseif($module['key'] === 'program-activities')
                <header class="page-header">
                    <div>
                        <h1 tabindex="-1">Program Activities</h1>
                        <p>Manage association-specific and program-wide activities.</p>
                    </div>
                </header>

                <a
                    class="button button-primary"
                    href="{{ route('admin.program-activities.index') }}"
                >
                    Open Program Activities
                </a>

            @elseif($module['key'] === 'monitoring')
                <header class="page-header">
                    <div>
                        <h1 tabindex="-1">Monitoring</h1>
                        <p>
                            Record and review quarterly project monitoring,
                            production, income, and material conditions.
                        </p>
                    </div>
                </header>

                <a
                    class="button button-primary"
                    href="{{ route('admin.monitoring.index') }}"
                >
                    Open Monitoring
                </a>

            @elseif($module['key'] === 'audit-logs')
                <header class="page-header"><div><h1 tabindex="-1">Audit Logs</h1><p>Review recorded application events and permitted change details.</p></div></header>
                <a class="button button-primary" href="{{ route('admin.audit-logs.index') }}">Open Audit Logs</a>
            @else
                @include(
                    'admin.'.$module['key'].'.index',
                    ['module' => $module]
                )
            @endif
        </section>

        @if(
            isset($module['action'])
            && !in_array(
                $module['key'],
                [
                    'trainings',
                    'program-activities',
                    'monitoring',
                    'audit-logs',
                ],
                true
            )
        )
            <section
                class="page-view"
                data-page="{{ $module['key'] }}/create"
                data-title="{{ $module['action'] }}"
                hidden
            >
                @include(
                    'admin.'.$module['key'].'.create',
                    ['module' => $module]
                )
            </section>

            @if($module['key'] !== 'users')
                <section
                    class="page-view"
                    data-page="{{ $module['key'] }}/edit"
                    data-title="Edit {{ $module['singular'] }}"
                    hidden
                >
                    @include(
                        'admin.'.$module['key'].'.edit',
                        ['module' => $module]
                    )
                </section>
            @endif
        @endif

        @if(
            !in_array(
                $module['key'],
                [
                    'areas',
                    'trainings',
                    'program-activities',
                    'monitoring',
                    'audit-logs',
                ],
                true
            )
        )
            <section
                class="page-view"
                data-page="{{ $module['key'] }}/show"
                data-title="{{ $module['singular'] }} Details"
                hidden
            >
                @include(
                    'admin.'.$module['key'].'.show',
                    ['module' => $module]
                )
            </section>
        @endif
    @endforeach

    <section
        class="page-view"
        data-page="gis"
        data-title="GIS Mapping"
        hidden
    >
        @include('admin.gis.index')
    </section>

    <section
        class="page-view"
        data-page="reports"
        data-title="Reports & Analytics"
        hidden
    >
        @include('admin.reports.index')
    </section>
@endsection
