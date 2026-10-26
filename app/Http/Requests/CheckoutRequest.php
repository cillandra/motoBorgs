<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('view', $this->route('cart'));
    }

    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:1'], 'request_key' => ['required', 'uuid'],
            'method' => ['required', 'in:cash,gcash,qrph,debit,credit'], 'received' => ['required', 'string', 'regex:/^\d{1,7}(\.\d{1,2})?$/D'],
            'verified' => ['nullable', 'boolean'], 'reference' => ['nullable', 'string', 'max:100']];
    }
}
