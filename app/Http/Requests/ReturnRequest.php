<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('view', $this->route('sale'));
    }

    public function rules(): array
    {
        return ['lines' => ['required', 'array', 'min:1', 'max:100'], 'lines.*' => ['required', 'integer', 'min:0', 'max:1000000'],
            'reason' => ['required', 'string', 'min:3', 'max:1000']];
    }

    public function attributes(): array
    {
        return ['lines.*' => 'return quantity'];
    }
}
