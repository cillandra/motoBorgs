@props(['variant'])
@php($status = $variant->archived_at || $variant->product->archived_at ? 'Archived' : ($variant->stock === 0 ? 'Out of stock' : ($variant->stock <= $variant->threshold ? 'Low stock' : 'In stock')))
<x-status-badge :status="$status" class="badge" />
