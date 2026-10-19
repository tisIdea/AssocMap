@props(['title', 'icon'])
<article class="public-feature-card">
    <span class="public-feature-icon"><x-icon :name="$icon" /></span>
    <h3>{{ $title }}</h3>
    <p>{{ $slot }}</p>
</article>
