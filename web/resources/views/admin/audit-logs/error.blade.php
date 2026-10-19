<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Audit Logs · AssocMap</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <main id="main-content" tabindex="-1" class="audit-error-page">
        <section class="card audit-details audit-error-card" aria-labelledby="audit-error-title">
            <span class="stat-icon"><x-icon name="shield" /></span>
            <p class="eyebrow">Audit Log</p>
            <h1 id="audit-error-title">Audit logs unavailable</h1>
            <p role="alert" data-feedback-fallback>{{ $message }}</p>
            <p class="field-help">Status: {{ $status }}</p>
            <div class="audit-error-actions"><a class="button button-primary" href="{{ route('admin.audit-logs.index') }}">Return to Audit Logs</a><a class="button" href="{{ route('home') }}">Home</a></div>
        </section>
    </main>
    <x-global-feedback :notification="['type' => 'error', 'title' => 'Audit Logs Unavailable', 'message' => $message]" />
</body>
</html>
