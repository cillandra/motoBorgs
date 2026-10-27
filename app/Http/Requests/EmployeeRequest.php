<?php

namespace App\Http\Requests;

use App\Support\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['username' => mb_strtolower(trim((string) $this->input('username')))]);
    }

    public function authorize(): bool
    {
        return $this->user()?->isManager() ?? false;
    }

    public function rules(): array
    {
        $employee = $this->route('employee');

        return ['name' => ['required', 'string', 'min:2', 'max:100'], 'username' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9._-]*$/', Rule::unique('users', 'username')->ignore($employee?->id)], 'email' => ['nullable', 'email', 'max:190'], 'role' => ['required', 'in:manager,staff'],
            'password' => $employee ? ['prohibited'] : PasswordPolicy::rules(), 'manager_password' => ['required', 'string', 'max:1024'], 'version' => $employee ? ['required', 'integer', 'min:1'] : ['nullable'],
            'role_confirmed' => ($this->input('role') === 'manager' && (! $employee || $employee->role !== 'manager')) || ($employee && $employee->role !== $this->input('role')) ? ['accepted'] : ['nullable']];
    }
}
