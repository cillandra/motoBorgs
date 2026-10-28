@props(['title'=>'No results','message'=>'Try changing your search or filters.'])
<section class="empty-state"><span class="ui-empty-icon" aria-hidden="true"><span class="ui-icon" style="--ui-icon:url('/assets/icons/search.svg')"></span></span><h2>{{ $title }}</h2><p>{{ $message }}</p>{{ $slot }}</section>
