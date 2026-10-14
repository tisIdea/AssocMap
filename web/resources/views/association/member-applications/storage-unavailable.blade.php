@extends('layouts.association')

@section('content')
    <header class="page-header"><div><h1 tabindex="-1">Application Not Submitted</h1></div></header>
    <section class="card form-section" aria-label="Submission unavailable">
        <p role="alert">{{ $message }}</p>
        <p>Your form details are available when you return. Reselect the applicant photo before submitting again.</p>
        <a class="button button-primary" href="{{ route('association.member-applications.index') }}">Return to Member Applications</a>
    </section>
@endsection
