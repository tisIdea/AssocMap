<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Audit Logs · AssocMap</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <main id="main-content" tabindex="-1" class="audit-error-page">
        <section class="card audit-details" aria-labelledby="audit-error-title">
            <h1 id="audit-error-title">Audit logs unavailable</h1>
            <p>Status: {{ $status }}</p>
            <p><a class="button" href="{{ route('admin.audit-logs.index') }}">Return to Audit Logs</a> <a class="button" href="{{ route('home') }}">Home</a></p>
        </section>
    </main>
    <x-global-feedback :notification="['type' => 'error', 'title' => 'Audit Logs Unavailable', 'message' => $message]" />
</body>
</html>
