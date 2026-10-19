@include('partials.page-header', ['title' => 'Association Management', 'description' => 'Manage association records and organizational officers.'])
<a class="button button-primary" href="{{ route('admin.associations.index') }}">Open Association Management</a>
@if(isset($catalog['options']['area_records']))
    @include('admin.associations.editor')
@endif
