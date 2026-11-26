<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ImportedRecord;
use App\Models\InfrastructurePlan;
use App\Models\Workspace;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Email Infrastructure & Meeting Forecasting.
 *
 * Stores the editable INPUTS per workspace. The forecasting maths (scenarios,
 * accounts required, costs) runs in the frontend from these inputs so edits
 * update instantly; the backend persists and validates them. `live` is a
 * read-only reference built from the latest Instantly sync.
 */
class InfrastructurePlanController extends Controller
{
    private const ERROR_STATUSES = ['connection_error', 'sending_error', 'soft_bounce_error'];

    public function __construct(private AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        $workspaces = Workspace::orderByDesc('counts_toward_totals')->orderBy('name')->get();
        $plans = InfrastructurePlan::with('updater:id,name')->get()->keyBy('workspace_id');
        $live = $this->liveSnapshot($workspaces->pluck('id')->all());

        return response()->json(['data' => $workspaces->map(function (Workspace $w) use ($plans, $live) {
            $plan = $plans->get($w->id);

            return [
                'workspace_id' => $w->id,
                'name' => $w->name,
                'code' => $w->code,
                'status' => $w->status,
                'counts_toward_totals' => (bool) $w->counts_toward_totals,
                'inputs' => $plan?->inputs,
                'updated_at' => $plan?->updated_at,
                'updated_by' => $plan?->updater?->name,
                'live' => $live[$w->id] ?? null,
            ];
        })->values()]);
    }

    public function update(Request $request, Workspace $workspace): JsonResponse
    {
        $data = $request->validate([
            'inputs' => ['required', 'array'],
            'inputs.providers' => ['present', 'array', 'max:30'],
            'inputs.providers.*.id' => ['required', 'string', 'max:64'],
            'inputs.providers.*.name' => ['required', 'string', 'max:80'],
            'inputs.providers.*.kind' => ['required', Rule::in(['google', 'microsoft', 'smtp', 'other'])],
            'inputs.providers.*.cost_per_account' => ['required', 'numeric', 'min:0', 'max:10000'],
            'inputs.providers.*.daily_limit' => ['required', 'numeric', 'min:0', 'max:10000'],
            'inputs.providers.*.mix_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'inputs.providers.*.active' => ['required', 'integer', 'min:0', 'max:1000000'],
            'inputs.providers.*.backup' => ['required', 'integer', 'min:0', 'max:1000000'],
            'inputs.providers.*.disconnected' => ['required', 'integer', 'min:0', 'max:1000000'],
            'inputs.sending_days_per_month' => ['required', 'numeric', 'min:1', 'max:31'],
            'inputs.meeting_target' => ['required', 'numeric', 'min:0', 'max:100000'],
            'inputs.reply_to_meeting_pct' => ['required', 'numeric', 'gt:0', 'max:100'],
            'inputs.emails_per_prospect' => ['required', 'numeric', 'min:1', 'max:50'],
            'inputs.backup_pct' => ['required', 'numeric', 'min:0', 'max:500'],
            'inputs.selected_reply_rate' => ['required', 'numeric', 'gt:0', 'max:100'],
            'inputs.reply_rates' => ['required', 'array', 'min:1', 'max:20'],
            'inputs.reply_rates.*' => ['numeric', 'gt:0', 'max:100'],
            'inputs.notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $mix = round((float) collect($data['inputs']['providers'])->sum('mix_pct'), 2);
        if ($data['inputs']['providers'] && abs($mix - 100) > 0.5) {
            return response()->json(['message' => "Provider mix must add up to 100% (currently {$mix}%)."], 422);
        }

        $plan = InfrastructurePlan::firstOrNew(['workspace_id' => $workspace->id]);
        $before = $plan->inputs;
        $plan->inputs = $data['inputs'];
        $plan->updated_by = $request->user()?->id;
        $plan->save();

        $this->audit->log('infrastructure.plan.updated', $plan, ['before' => $before, 'after' => $plan->inputs]);

        return response()->json(['message' => "Plan saved for {$workspace->name}.", 'data' => [
            'inputs' => $plan->inputs,
            'updated_at' => $plan->updated_at,
            'updated_by' => $request->user()?->name,
        ]]);
    }

    /** Account counts per workspace from the latest Instantly sync (no live calls). */
    private function liveSnapshot(array $workspaceIds): array
    {
        $out = [];
        ImportedRecord::where('source', 'instantly')->where('entity_type', 'account')
            ->whereIn('workspace_id', $workspaceIds)
            ->orderBy('id')
            ->select(['id', 'workspace_id', 'payload', 'imported_at'])
            ->chunk(1000, function ($rows) use (&$out) {
                foreach ($rows as $r) {
                    $p = $r->payload ?? [];
                    $s = $out[$r->workspace_id] ?? ['total' => 0, 'active' => 0, 'paused' => 0, 'error' => 0,
                        'other' => 0, 'daily_capacity' => 0, 'by_provider' => [], 'synced_at' => null];
                    $s['total']++;
                    $status = $p['status'] ?? null;
                    match (true) {
                        $status === 'active' => $s['active']++,
                        $status === 'paused' => $s['paused']++,
                        in_array($status, self::ERROR_STATUSES, true) => $s['error']++,
                        default => $s['other']++,
                    };
                    if ($status === 'active' && is_numeric($p['daily_limit'] ?? null)) {
                        $s['daily_capacity'] += (int) $p['daily_limit'];
                    }
                    $prov = $p['provider'] ?? 'unknown';
                    $s['by_provider'][$prov] = ($s['by_provider'][$prov] ?? 0) + 1;
                    $at = $r->imported_at?->toIso8601String();
                    if ($at && ($s['synced_at'] === null || $at > $s['synced_at'])) {
                        $s['synced_at'] = $at;
                    }
                    $out[$r->workspace_id] = $s;
                }
            });

        return $out;
    }
}
