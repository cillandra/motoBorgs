<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isManager();
    }

    public function rules(): array
    {
        return ['operation_key' => ['required', 'uuid'], 'amount' => ['required', 'string', 'regex:/^\d{1,7}(\.\d{1,2})?$/D'],
            'status' => ['required', 'in:pending,failed,paid'], 'reference' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'max:1024'],
            'confirmed' => $this->input('status') === 'paid' ? ['required', 'accepted'] : ['nullable', 'boolean']];
    }
}
