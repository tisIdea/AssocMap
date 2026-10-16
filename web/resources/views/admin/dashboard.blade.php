@include('partials.page-header', ['title' => 'Dashboard', 'description' => 'Open the current organization-wide overview.', 'eyebrow' => 'System Administrator'])
<a class="button button-primary" href="{{ route('admin.dashboard') }}">Open dashboard</a>
