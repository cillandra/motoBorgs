@if(Route::has('pos.checkout'))
<x-dialog id="payment" title="Payment">@include('pos.partials.payment-form')</x-dialog>
@endif
