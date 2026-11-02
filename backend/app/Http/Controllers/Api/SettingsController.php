<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApplicationSetting;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SettingsController extends Controller
{
    public function __construct(private SettingsService $settings, private AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'thresholds' => $this->settings->thresholds(),
            'defaults' => $this->settings->defaults(),
            'threshold_descriptions' => [
                'error_pct' => 'Error % at/above which a batch is investigated.',
                'error_accounts' => 'Absolute error-account count trigger.',
                'spam_pct' => 'Placement spam % trigger (batch-scoped tests only).',
                'elevated_bounce_rate' => 'Bounce % considered elevated.',
                'replacement_due_days' => 'Days-ahead window for replacement warnings.',
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'thresholds' => ['sometimes', 'array'],
            'defaults' => ['sometimes', 'array'],
        ]);

        foreach ($data as $key => $value) {
            ApplicationSetting::updateOrCreate(
                ['key' => $key],
                ['value' => ['value' => $value], 'group' => $key],
            );
            Cache::forget("app_setting:{$key}");
        }

        $this->audit->log('settings.updated', null, array_keys($data));

        return response()->json([
            'thresholds' => $this->settings->thresholds(),
            'defaults' => $this->settings->defaults(),
        ]);
    }
}
