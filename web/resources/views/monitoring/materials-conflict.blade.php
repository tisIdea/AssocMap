<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Materials changed · AssocMap</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <main id="main-content" tabindex="-1" style="max-width:960px;margin:24px auto;padding:16px">
        <h1>Materials changed</h1><p role="alert">{{ $message }}</p>
        <p>This submitted draft has not been applied. Keep it for comparison before entering your changes again.</p>
        <label for="materials-draft">Complete submitted Materials draft and requested removals</label>
        <textarea id="materials-draft" readonly rows="24" style="width:100%;box-sizing:border-box">{{ json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) }}</textarea>
        @if($latestMaterialsUrl)<p><a href="{{ $latestMaterialsUrl }}" target="_blank" rel="noopener noreferrer">Open Latest Saved Materials</a></p>@endif
    </main>
    <x-global-feedback :notification="['type' => 'error', 'title' => 'Materials Record Updated', 'message' => $message]" />
</body>
</html>
