<?php

namespace App\Support;

use App\Models\Setting;
use Closure;
use Illuminate\Validation\Rules\Password;

class PasswordPolicy
{
    public static function rules(): array
    {
        $minimum = max(15, (int) (Setting::where('section', 'security')->first()?->value['password_min'] ?? 15));

        return ['required', 'string', 'max:1024', Password::min($minimum), function (string $attribute, mixed $value, Closure $fail): void {
            $weak = file(resource_path('data/weak-passwords.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            if (in_array(mb_strtolower((string) $value), $weak, true)) {
                $fail('Choose a less predictable passphrase.');
            }
        }];
    }
}
