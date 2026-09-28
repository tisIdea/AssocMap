@props(['id', 'placeholder' => 'Search records…'])
<div class="search-box"><x-icon name="search" /><label for="{{ $id }}" class="sr-only">{{ $placeholder }}</label><input id="{{ $id }}" type="search" data-search placeholder="{{ $placeholder }}" autocomplete="off"></div>
