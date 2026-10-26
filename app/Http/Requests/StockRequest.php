<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isManager();
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'in:receive,adjust'],
            'base_stock' => ['required_if:adjustment_mode,increase,decrease', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'adjustment_mode' => ['nullable', 'in:count,increase,decrease'],
            'quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'reference' => ['required_if:type,receive', 'nullable', 'string', 'max:100'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'operation_key' => ['required', 'uuid'],
            'expected_version' => ['required_if:type,adjust', 'nullable', 'integer', 'min:1'],
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Manila')->toDateString()],
        ];
    }
}
