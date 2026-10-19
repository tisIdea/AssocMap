@extends('layouts.public')
@section('title', 'GIS Unavailable · AssocMap')
@section('content')
    <section class="gis-public-main"><h1>GIS unavailable</h1><p class="notice" role="alert">{{ $message }}</p><a class="button" href="{{ route('home') }}">Back to AssocMap</a><a class="button" href="{{ route('login') }}">Sign in</a></section>
@endsection
