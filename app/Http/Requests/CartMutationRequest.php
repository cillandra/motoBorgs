<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CartMutationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->route('cart'));
    }

    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:1'], 'action' => ['required', 'in:add,set,remove,refresh_prices'],
            'variant_id' => ['required_unless:action,refresh_prices', 'integer', 'exists:variants,id'],
            'quantity' => ['required_if:action,add,set', 'integer', 'min:1', 'max:1000000']];
    }
}
