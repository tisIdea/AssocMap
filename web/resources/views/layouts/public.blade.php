<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="description" content="AssocMap — a GIS-based program monitoring system for community livelihood associations under BFAR Region VII, SAAD Phase II.">
    <title>@yield('title', 'AssocMap · Community Livelihood Monitoring')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="public-page">
    <a class="skip-link" href="#public-main">Skip to content</a>
    @include('partials.public-navbar')
    <main id="public-main" class="public-main" tabindex="-1">
        @yield('content')
    </main>
    @include('partials.public-footer')
</body>
</html>
