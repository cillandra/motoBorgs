@props(['id','title'])
<dialog id="{{ $id }}" class="modal" aria-labelledby="{{ $id }}-title"><header><h2 id="{{ $id }}-title">{{ $title }}</h2><button type="button" class="icon-button" data-dialog-close aria-label="Close dialog">×</button></header>{{ $slot }}</dialog>
