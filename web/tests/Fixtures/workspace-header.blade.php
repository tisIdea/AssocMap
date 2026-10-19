@extends($layout)

@section('content')
    <section data-workspace-module="{{ $module }}" data-workspace-title="{{ $workspaceTitle }}">
        <h1 tabindex="-1">{{ $workspaceTitle }}</h1>
        <p>Synthetic header verification workspace.</p>
    </section>
@endsection
