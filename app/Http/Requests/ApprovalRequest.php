<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->route('cart'));
    }

    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:1'], 'action' => ['required', 'in:discount,cancel_exception'],
            'manager_username' => ['required', 'string', 'max:80'], 'manager_password' => ['required', 'string', 'max:1024'],
            'discount' => ['required_if:action,discount', 'nullable', 'string', 'regex:/^\d{1,7}(\.\d{1,2})?$/D'],
            'reason' => ['required', 'string', 'min:3', 'max:1000']];
    }
}
