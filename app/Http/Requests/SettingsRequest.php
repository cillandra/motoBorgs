<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isManager() ?? false;
    }

    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:0'], 'values' => ['required', 'array'], 'manager_password' => $this->route('section') === 'security' ? ['required', 'string', 'max:1024'] : ['nullable', 'string', 'max:1024']];
    }
}
