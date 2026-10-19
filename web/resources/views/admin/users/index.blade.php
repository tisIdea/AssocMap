@include('partials.page-header', ['title' => 'User Management', 'description' => 'View application profiles and manage Field Officer association assignments.', 'eyebrow' => 'Administration'])
<a class="button button-primary" href="{{ route('admin.users.index') }}">Open User Management</a>
<a class="button" href="{{ route('admin.user-invitations.create') }}">Invite User</a>
