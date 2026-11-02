<?php

namespace App\Services;

use App\Models\ApplicationSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves operational thresholds/defaults. Precedence:
 *   application_settings row  ->  config/deliverability.php default.
 */
class SettingsService
{
    public function thresholds(): array
    {
        $defaults = config('deliverability.thresholds');
        $override = $this->setting('thresholds');

        return is_array($override) ? array_merge($defaults, $override) : $defaults;
    }

    public function threshold(string $key): mixed
    {
        return $this->thresholds()[$key] ?? null;
    }

    public function defaults(): array
    {
        $defaults = config('deliverability.defaults');
        $override = $this->setting('defaults');

        return is_array($override) ? array_merge($defaults, $override) : $defaults;
    }

    private function setting(string $key): mixed
    {
        // Avoid caching during tests so overrides take effect immediately.
        if (app()->runningUnitTests()) {
            return ApplicationSetting::get($key);
        }

        return Cache::remember("app_setting:{$key}", 60, fn () => ApplicationSetting::get($key));
    }
}
