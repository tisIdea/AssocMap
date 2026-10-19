@extends('layouts.association')

@section('content')
<section class="member-management" data-workspace-module="member-applications" data-workspace-title="Member Applications">
    <header class="card member-heading"><div><p class="eyebrow">Member Applications</p><h1 tabindex="-1">Application Not Submitted</h1><p>Review the submission issue before trying again.</p></div></header>
    <section class="card form-section" aria-label="Submission unavailable">
        <p role="alert">{{ $message }}</p>
        <p>Your form details are available when you return. Reselect the applicant photo before submitting again.</p>
        <a class="button button-primary" href="{{ route('association.member-applications.index') }}">Return to Member Applications</a>
    </section>
</section>
@endsection
