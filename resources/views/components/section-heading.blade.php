@props(['icon'=>'info','tone'=>'info','tag'=>'h2'])
@php($headingTag=in_array($tag,['h2','h3'],true)?$tag:'h2')
<{{ $headingTag }} {{ $attributes->class(['section-heading']) }} data-tone="{{ $tone }}"><span class="ui-heading-icon" aria-hidden="true"><span class="ui-icon" style="--ui-icon:url('{{ asset('assets/icons/'.$icon.'.svg') }}')"></span></span><span>{{ $slot }}</span></{{ $headingTag }}>
