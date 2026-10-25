@php
    $catalog = $catalog ?? require resource_path('ui/admin.php');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light"><title>Dashboard · AssocMap</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <a class="skip-link" href="#main-content">Skip to content</a>
    @include('partials.admin-sidebar')
    <div class="main-shell">@include('partials.admin-topbar')<main id="main-content" tabindex="-1">@yield('content')</main><footer class="app-footer"><span>AssocMap · GIS-Based Program Monitoring Information System</span><span>Capstone Project Prototype</span></footer></div>
    <x-confirmation-modal />
    <x-modal id="email-modal" title="Change email"><form data-form><p>Email changes are not connected yet.</p><x-field :field="['name' => 'new_email', 'label' => 'New Email', 'type' => 'email', 'required' => true]" prefix="email-change" /><div class="modal-actions"><button type="button" class="button" data-close-dialog>Cancel</button><button type="button" disabled class="button button-primary">Change Email</button></div></form></x-modal>
    @include('admin.areas.modals')
    <x-global-feedback :notification="$globalFeedbackNotification ?? null" />
    <noscript><div class="noscript-notice">Enable JavaScript to navigate the Admin workspace and use its controls.</div></noscript>
</body>
</html>
