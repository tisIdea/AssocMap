@extends($prefix === 'admin' ? 'layouts.admin' : 'layouts.field-officer')
@section('content')
    @include('associations.partials.directory')
    @if($prefix === 'admin' && isset($catalog['options']['area_records']))
        @include('admin.associations.editor')
    @endif
@endsection
