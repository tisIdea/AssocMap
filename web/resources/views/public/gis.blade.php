@extends('layouts.public')
@section('title', 'Public GIS Map · AssocMap')
@section('content')
    <div class="gis-public-main">
        <p class="notice"><x-icon name="shield" />Only approved public titles, descriptions, addresses, site classifications, and published geometry are displayed.</p>
        @include('partials.gis-workspace')
    </div>
@endsection
