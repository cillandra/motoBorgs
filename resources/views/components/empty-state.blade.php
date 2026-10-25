@props(['title'=>'No results','message'=>'Try changing your search or filters.'])
<section class="empty-state"><h2>{{ $title }}</h2><p>{{ $message }}</p>{{ $slot }}</section>
