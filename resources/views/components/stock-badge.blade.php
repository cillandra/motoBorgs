@props(['variant'])
@php($status = $variant->archived_at || $variant->product->archived_at ? 'Archived' : ($variant->stock === 0 ? 'Out of stock' : ($variant->stock <= $variant->threshold ? 'Low stock' : 'In stock')))
<span @class(['badge','badge-danger'=>$status==='Out of stock','badge-warning'=>$status==='Low stock','badge-success'=>$status==='In stock','badge-neutral'=>$status==='Archived'])><span class="badge-dot" aria-hidden="true"></span>{{ $status }}</span>
