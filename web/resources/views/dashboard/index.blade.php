@php($catalog = require resource_path('demo/admin.php'))
@extends('layouts.admin')
@section('content')
    <div class="preview-notice"><x-icon name="info" /><span>Design preview <span class="notice-separator">/</span> All records are fictional. Changes are not saved.</span></div>
    <section class="page-view" data-page="dashboard" data-title="Dashboard">@include('admin.dashboard')</section>
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
