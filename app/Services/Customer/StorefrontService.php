<?php

namespace App\Services\Customer;

use App\Models\Setting;
use App\Services\Settings\SettingsService;

class StorefrontService
{
    public function content(): array
    {
        $defaults = SettingsService::DEFAULTS['storefront'];
        $stored = Setting::where('section', 'storefront')->first()?->value ?? [];

        return array_replace($defaults, array_intersect_key($stored, $defaults));
    }

    public function services(): array
    {
        return collect(explode("\n", $this->content()['services']))->map(function (string $line): array {
            $parts = explode('|', $line, 2);

            return ['name' => trim($parts[0]), 'description' => trim($parts[1] ?? '')];
        })->filter(fn (array $service) => $service['name'] !== '')->take(20)->values()->all();
    }
}
