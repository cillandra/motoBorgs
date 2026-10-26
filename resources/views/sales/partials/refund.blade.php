<x-dialog :id="$dialogId" title="Record payout outcome"><form action="{{ route('refunds.store',$return) }}" method="post" class="stack" data-refund-form>@csrf
<input type="hidden" name="operation_key" value="{{ $record?->operation_key??(string)\Illuminate\Support\Str::uuid() }}">
<p>Record the outcome after handling the refund outside MOTO. Check an existing pending or failed attempt before issuing another refund.</p>
<x-input id="amount-{{ $dialogId }}" name="amount" label="Payout amount (PHP)" :value="\App\Support\Money::format($defaultAmount)" inputmode="decimal" required :readonly="$record!==null" />
<x-input id="reference-{{ $dialogId }}" name="reference" label="Refund tracking reference" :value="$record?->reference??''" required maxlength="100" :readonly="$record!==null" />
<p class="caption">Use the same tracking reference when updating this payout’s status.</p>
<div class="field"><label for="status-{{ $dialogId }}">Payout status</label><div class="field-perimeter"><select id="status-{{ $dialogId }}" name="status" required>@foreach(['pending'=>'Pending — not paid','failed'=>'Failed — not paid','paid'=>'Paid — confirmed payout'] as $value=>$label)<option value="{{ $value }}" @selected(($record?->status??'pending')===$value)>{{ $label }}</option>@endforeach</select></div></div>
<div data-payout-confirmation @if(($record?->status??'pending')!=='paid') hidden @endif><label class="verification-choice"><input type="checkbox" name="confirmed" value="1">I confirm that this exact refund has been paid and verified.</label></div>
<x-input id="password-{{ $dialogId }}" name="password" label="Your current password" type="password" autocomplete="current-password" required maxlength="1024" />
<div class="action-row"><x-button variant="secondary" data-dialog-close>Back</x-button><x-button type="submit">Save payout outcome</x-button></div>
</form></x-dialog>
