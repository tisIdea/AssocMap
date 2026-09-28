@props(['title', 'number' => null])
<section class="card form-section"><div class="section-heading">@if($number)<span class="section-number">{{ $number }}</span>@endif<h2>{{ $title }}</h2></div>{{ $slot }}</section>
