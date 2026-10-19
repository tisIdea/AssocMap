<a class="back-link" href="#users"><x-icon name="back" />Back to User Management</a>
@include('partials.page-header', ['title' => 'Invite User', 'description' => 'Invite a Field Officer or an Association Account through Supabase Auth.', 'eyebrow' => 'User Management'])
<a class="button button-primary" href="{{ route('admin.user-invitations.create') }}">Open invitation form</a>
