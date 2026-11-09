<?php

namespace App\Services\Settings;

use App\Models\Approval;
use App\Models\Setting;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\System\DiagnosticsService;
use App\Services\System\OperationGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SettingsService
{
    public const DEFAULTS = [
        'general' => ['shop_name' => 'BORG’S Motorcycle Parts and Accessories', 'address' => '', 'contact' => '', 'currency' => 'PHP', 'timezone' => 'Asia/Manila'],
        'storefront' => [
            'display_name' => 'BORG’S Motorcycle Parts and Accessories',
            'about' => 'Explore motorcycle parts and accessories, check our catalog, and ask the shop about the right fit for your motorcycle.',
            'history' => 'BORG’S Motorcycle Parts and Accessories was established in 2021 to provide motorcycle owners with accessible and affordable motorcycle parts and accessories. The small business serves its customers through direct sales.',
            'mission' => 'To provide affordable and quality motorcycle parts and accessories while maintaining good customer service and ensuring that products are properly monitored and available to customers.',
            'vision' => 'To become a trusted and reliable motorcycle parts and accessories store that provides quality products and excellent service to motorcycle owners.',
            'goals' => "To provide customers with quality motorcycle parts and accessories.\nTo maintain sufficient stocks of commonly purchased products.",
            'profile_pending' => true,
            'address' => 'Sample shop location · owner-confirmed address pending',
            'hours' => 'Sample hours: Monday–Saturday, 8:00 AM–5:00 PM · to be confirmed',
            'phone' => '', 'messenger_page' => '', 'demo_content' => true,
            'services' => "Parts compatibility check | Sample service: ask about a part that fits your motorcycle.\nOil change assistance | Sample service: ask about suitable oil and service availability.\nBasic parts installation | Sample service: ask whether installation is offered for your chosen part.",
        ],
        'sales' => ['methods' => ['cash', 'gcash', 'qrph', 'debit', 'credit'], 'discount_cap' => 10, 'manual_verification' => true],
        'receipts' => ['width' => 80, 'copies' => 1, 'footer' => 'Academic demonstration receipt', 'tax_enabled' => false, 'show_logo' => true],
        'inventory' => ['threshold' => 3, 'max_image_mb' => 5, 'allow_negative_stock' => false],
        'notifications' => ['stock' => true, 'requests' => true, 'system' => true],
        'security' => ['idle_minutes' => 15, 'absolute_hours' => 8, 'failed_attempts' => 5, 'cooldown_minutes' => 15, 'password_min' => 15],
        'backup' => ['enabled' => false, 'schedule' => '20:00', 'retain' => 7],
        'system' => ['maintenance' => false],
    ];

    public function read(User $actor, string $section): array
    {
        abort_unless($actor->fresh()?->isManager(), 403, 'Manager access is required.');
        abort_unless(isset(self::DEFAULTS[$section]), 404);
        $setting = Setting::where('section', $section)->first();

        return ['values' => array_replace(self::DEFAULTS[$section], $setting?->value ?? []), 'version' => (int) ($setting?->version ?? 0)];
    }

    public function rules(string $section): array
    {
        $rules = match ($section) {
            'general' => ['shop_name' => ['required', 'string', 'min:2', 'max:160'], 'address' => ['nullable', 'string', 'max:500'], 'contact' => ['nullable', 'string', 'regex:/^[+()0-9 .-]{7,40}$/'], 'currency' => ['required', 'in:PHP'], 'timezone' => ['required', 'in:Asia/Manila']],
            'storefront' => ['display_name' => ['required', 'string', 'max:160'], 'about' => ['required', 'string', 'max:1000'], 'history' => ['nullable', 'string', 'max:2000'], 'mission' => ['nullable', 'string', 'max:1000'], 'vision' => ['nullable', 'string', 'max:1000'], 'goals' => ['nullable', 'string', 'max:2000'], 'profile_pending' => ['required', 'boolean'], 'address' => ['required', 'string', 'max:1000'], 'hours' => ['required', 'string', 'max:1000'], 'phone' => ['nullable', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/D'], 'messenger_page' => ['nullable', 'string', 'max:100', 'regex:/^[a-zA-Z0-9.]{3,100}$/D'], 'demo_content' => ['required', 'boolean'], 'services' => ['nullable', 'string', 'max:4000', function (string $attribute, mixed $value, \Closure $fail): void {
                $lines = array_values(array_filter(explode("\n", (string) $value), fn ($line) => trim($line) !== ''));
                if (count($lines) > 20 || collect($lines)->contains(fn ($line) => mb_strlen(trim(explode('|', $line, 2)[0])) > 200)) {
                    $fail('Use up to 20 services with names no longer than 200 characters.');
                }
            }]],
            'sales' => ['methods' => ['required', 'array', 'min:1', 'max:5'], 'methods.*' => ['required', 'distinct', 'in:cash,gcash,qrph,debit,credit'], 'discount_cap' => ['required', 'integer', 'between:0,10'], 'manual_verification' => ['accepted']],
            'receipts' => ['width' => ['required', 'integer', 'in:58,80'], 'copies' => ['required', 'integer', 'between:1,3'], 'footer' => ['nullable', 'string', 'max:200'], 'tax_enabled' => ['declined'], 'show_logo' => ['required', 'boolean']],
            'inventory' => ['threshold' => ['required', 'integer', 'between:0,1000000'], 'max_image_mb' => ['required', 'integer', 'between:1,5'], 'allow_negative_stock' => ['declined']],
            'notifications' => ['stock' => ['required', 'boolean'], 'requests' => ['required', 'boolean'], 'system' => ['required', 'boolean']],
            'security' => ['idle_minutes' => ['required', 'integer', 'between:5,60'], 'absolute_hours' => ['required', 'integer', 'between:1,12'], 'failed_attempts' => ['required', 'integer', 'between:3,5'], 'cooldown_minutes' => ['required', 'integer', 'between:15,60'], 'password_min' => ['required', 'integer', 'between:15,128']],
            'backup' => ['enabled' => ['required', 'boolean'], 'schedule' => ['required', 'date_format:H:i'], 'retain' => ['required', 'integer', 'in:7']],
            'system' => ['maintenance' => ['declined']],
            default => [],
        };

        return $rules;
    }

    public function save(User $manager, string $section, array $values, int $expectedVersion): array
    {
        abort_unless(isset(self::DEFAULTS[$section]), 404);
        abort_unless($manager->fresh()?->isManager(), 403);
        abort_if($section === 'system', 409, 'Use the protected maintenance action to change system status.');
        if ($section === 'backup' && filter_var($values['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            abort_unless(app(DiagnosticsService::class)->scheduler()['verified'], 422, 'Install and verify the local scheduler before enabling automatic backups.');
        }
        $rules = $this->rules($section);
        $validated = Validator::make(['values' => $values], ['values' => ['required', 'array:'.implode(',', array_keys(self::DEFAULTS[$section]))], ...collect($rules)->mapWithKeys(fn ($rule, $key) => ['values.'.$key => $rule])->all()], ['values.contact.regex' => 'Enter a valid shop contact number.', 'values.methods.required' => 'Enable at least one payment method.', 'values.methods.min' => 'Enable at least one payment method.'], collect(array_keys(self::DEFAULTS[$section]))->mapWithKeys(fn ($key) => ['values.'.$key => str_replace('_', ' ', $key)])->all())->validate()['values'];
        foreach (self::DEFAULTS[$section] as $key => $default) {
            if (is_bool($default)) {
                $validated[$key] = filter_var($validated[$key], FILTER_VALIDATE_BOOLEAN);
            } elseif (is_int($default)) {
                $validated[$key] = (int) $validated[$key];
            } elseif (is_string($default) && ($validated[$key] ?? null) === null) {
                $validated[$key] = '';
            }
        }

        return app(OperationGuard::class)->write(fn () => DB::transaction(function () use ($manager, $section, $validated, $expectedVersion): array {
            $manager = User::whereKey($manager->id)->lockForUpdate()->firstOrFail();
            abort_unless($manager->isManager(), 403);
            Setting::firstOrCreate(['section' => $section], ['value' => self::DEFAULTS[$section], 'version' => 0]);
            $setting = Setting::where('section', $section)->lockForUpdate()->firstOrFail();
            abort_if((int) $setting->version !== $expectedVersion, 409, 'These settings changed. Reload before saving.');
            $setting->update(['value' => $validated, 'version' => (int) $setting->version + 1]);
            if ($section === 'sales') {
                Approval::whereNull('consumed_at')->whereNull('invalidated_at')->update(['invalidated_at' => now()]);
            }
            app(AuditRecorder::class)->record($manager, 'Settings updated', 'settings', 'settings', $section, 'success', ['changes' => $validated]);
            app(NotificationService::class)->publish('system', 'settings', $section, ['event_id' => 'version-'.$setting->version]);

            return ['values' => $validated, 'version' => (int) $setting->version];
        }, 3));
    }
}
