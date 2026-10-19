<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Reports unavailable · AssocMap</title>@vite(['resources/css/app.css', 'resources/js/app.js'])</head>
<body><main id="main-content"><section class="card report-document"><h1>Reports unavailable</h1>
@foreach($errors as $messages) @foreach($messages as $error)<p>{{ $error }}</p>@endforeach @endforeach
<p><a href="{{ url('/login') }}">Return to sign in</a></p></section></main>
<x-global-feedback :notification="['type' => 'error', 'title' => 'Report Unavailable', 'message' => $message]" />
</body>
</html>
