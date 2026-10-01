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
    @if(session('success'))<div class="notice notice-success" role="status"><x-icon name="check" /><span>{{ session('success') }}</span></div>@endif
    @if(session('error'))<div class="notice notice-error" role="alert"><x-icon name="info" /><span>{{ session('error') }}</span></div>@endif
    <section class="page-view" data-page="dashboard" data-title="Dashboard" data-initial-page="{{ $requestedModule['key'] ?? 'dashboard' }}">@include('admin.dashboard')</section>
    @foreach($catalog['modules'] as $module)
        <section class="page-view" data-page="{{ $module['key'] }}" data-title="{{ $module['title'] }}" hidden>@include('admin.'.$module['key'].'.index', ['module' => $module])</section>
        @if(isset($module['action']))
            <section class="page-view" data-page="{{ $module['key'] }}/create" data-title="{{ $module['action'] }}" hidden>@include('admin.'.$module['key'].'.create', ['module' => $module])</section>
            @if($module['key'] !== 'users')<section class="page-view" data-page="{{ $module['key'] }}/edit" data-title="Edit {{ $module['singular'] }}" hidden>@include('admin.'.$module['key'].'.edit', ['module' => $module])</section>@endif
        @endif
        @if($module['key'] !== 'areas')<section class="page-view" data-page="{{ $module['key'] }}/show" data-title="{{ $module['singular'] }} Details" hidden>@include('admin.'.$module['key'].'.show', ['module' => $module])</section>@endif
    @endforeach
    <section class="page-view" data-page="gis" data-title="GIS Mapping" hidden>@include('admin.gis.index')</section>
    <section class="page-view" data-page="reports" data-title="Reports & Analytics" hidden>@include('admin.reports.index')</section>
@endsection
