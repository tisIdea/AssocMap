<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Audit Logs · AssocMap</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <main class="audit-error-page">
        <section class="card audit-details" aria-labelledby="audit-error-title">
            <h1 id="audit-error-title">Audit logs unavailable</h1>
            <p class="notice notice-error" role="alert">{{ $message }}</p>
            <p>Status: {{ $status }}</p>
            <p><a class="button" href="{{ route('admin.audit-logs.index') }}">Return to Audit Logs</a> <a class="button" href="{{ route('home') }}">Home</a></p>
        </section>
    </main>
</body>
</html>
