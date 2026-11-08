@extends('layouts.public')
@section('title', 'Public GIS Map · AssocMap')
@section('content')
    <div class="gis-public-main">
        @include('partials.gis-workspace')
        <p class="gis-public-note"><x-icon name="shield" />Only approved public details, site classifications, and published geometry are displayed.</p>
    </div>
@endsection
