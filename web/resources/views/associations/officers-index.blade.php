@extends($prefix === 'admin' ? 'layouts.admin' : 'layouts.field-officer')
@section('content')
    @include('associations.partials.directory')
@endsection
