@extends($layout)
@section('content')
    <h1 tabindex="-1">Synthetic feedback workspace</h1>
    <form method="POST" data-feedback-error-bag="monitoringVisit">
        <label for="synthetic-draft">Draft</label>
        <input id="synthetic-draft" name="remarks" value="{{ old('remarks') }}">
    </form>
@endsection
