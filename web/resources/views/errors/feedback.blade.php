@php
    [$title, $message] = match ($status) {
        401 => ['Sign In Required', 'Sign in to continue.'],
        403 => ['Access Denied', 'You do not have permission to perform this action.'],
        404 => ['Record Unavailable', 'The requested information is unavailable or outside your current access.'],
        405 => ['Request Not Available', 'This request method is not available. Return to the application and try again.'],
        413 => ['Request Too Large', 'The submitted information exceeds the supported size. Review the form before trying again.'],
        419 => ['Session Expired', 'Your session has expired. Keep any unsaved information before signing in again.'],
        429 => ['Please Wait', 'Too many requests were received. Wait a moment before trying again.'],
        default => ['Operation Unavailable', 'The operation could not be completed. Keep any unsaved information and try again later.'],
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $title }} · AssocMap</title>@vite(['resources/css/app.css', 'resources/js/app.js'])</head>
<body>
    <main id="main-content" tabindex="-1" class="audit-error-page">
        <section class="card audit-details"><h1>{{ $title }}</h1><p>{{ $message }}</p><p>Status: {{ $status }}</p>
            <a class="button" href="{{ route('home') }}">Return to AssocMap</a>
        </section>
    </main>
    <x-global-feedback :notification="['type' => 'error', 'title' => $title, 'message' => $message]" />
</body>
</html>
