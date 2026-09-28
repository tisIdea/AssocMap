<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light"><title>Dashboard · AssocMap</title>
    {{-- Local preview assets work before a Vite build is available. --}}
    <style>{!! file_get_contents(resource_path('css/app.css')) !!}</style>
</head>
<body>
    <a class="skip-link" href="#main-content">Skip to content</a>
    @include('partials.admin-sidebar')
    <div class="main-shell">@include('partials.admin-topbar')<main id="main-content" tabindex="-1">@yield('content')</main><footer class="app-footer"><span>AssocMap · GIS-Based Program Monitoring Information System</span><span>BFAR Region VII / SAAD Phase II</span></footer></div>
    <div class="toast" id="ui-toast" role="status" aria-live="polite" hidden></div>
    <x-confirmation-modal />
    <x-modal id="email-modal" title="Change email"><form data-preview-form><p>Request a new email for the selected demo account.</p><x-field :field="['name' => 'new_email', 'label' => 'New Email', 'type' => 'email', 'required' => true]" prefix="email-change" /><div class="modal-actions"><button type="button" class="button" data-close-dialog>Cancel</button><button type="submit" class="button button-primary">Preview email change</button></div></form></x-modal>
    @include('admin.areas.modals')
    <noscript><div class="noscript-notice">Enable JavaScript to navigate this UI preview and use its controls.</div></noscript>
    <script>{!! file_get_contents(resource_path('js/app.js')) !!}</script>
</body>
</html>
