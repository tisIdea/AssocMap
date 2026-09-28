@props(['title' => 'No records found.', 'message' => 'Try another search or clear the filters.'])
<div class="empty-state"><span class="empty-icon"><x-icon name="search" /></span><h3>{{ $title }}</h3><p>{{ $message }}</p></div>
