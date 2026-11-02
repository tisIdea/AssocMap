@props(['title' => 'No records found.', 'message' => 'No records are available in this view.'])
<div class="empty-state"><span class="empty-icon"><x-icon name="search" /></span><h3>{{ $title }}</h3><p>{{ $message }}</p></div>
