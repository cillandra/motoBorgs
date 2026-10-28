@props(['status','label'=>null])
@php
    $normal=mb_strtolower(str_replace('_',' ',(string)$status));
    $tone=match($normal){
        'in stock','active','success','complete','completed','paid','accepted','approved'=>'success',
        'low stock','pending','held','partial','partially refunded','awaiting review'=>'warning',
        'failed','denied','rejected','out of stock'=>'danger',
        'manager','refunded'=>'violet',
        'staff','running'=>'info',
        default=>'neutral',
    };
@endphp
<span {{ $attributes->class(['status-badge','ui-status']) }} data-tone="{{ $tone }}">{{ $label??ucfirst((string)$status) }}</span>
