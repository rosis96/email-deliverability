<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBatchRequest;
use App\Models\Batch;
use App\Models\BatchLifecycleEvent;
use App\Services\AuditLogger;
use App\Services\DashboardService;
use App\Services\LifecycleService;
use App\Services\MetricsService;
use App\Services\ProblemDetectionService;
use App\Services\RecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BatchController extends Controller
{
    public function __construct(
        private DashboardService $dashboard,
        private LifecycleService $lifecycle,
        private MetricsService $metrics,
        private ProblemDetectionService $detection,
        private RecommendationService $recommendations,
        private AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $batches = Batch::with('workspace')->withCount('domains')->get();

        return response()->json([
            'data' => $batches->map(fn (Batch $b) => $this->dashboard->batchRow($b))->values(),
        ]);
    }

    public function store(StoreBatchRequest $request): JsonResponse
    {
        $data = $request->validated();

        $batch = DB::transaction(function () use ($data, $request) {
            $batch = Batch::create([
                'batch_id' => $data['batch_id'],
                'workspace_id' => $data['workspace_id'],
                'provider_type' => $data['provider_type'],
                'domain_family' => $data['domain_family'] ?? null,
                'planned_account_count' => $data['planned_account_count'],
                'warmup_start' => $data['warmup_start'],
                'warmup_days' => $data['warmup_days'] ?? config('deliverability.defaults.warmup_days'),
                'lifecycle_days' => $data['lifecycle_days'] ?? config('deliverability.defaults.lifecycle_days'),
                'active_daily_limit' => $data['active_daily_limit'],
                'batch_owner' => $data['batch_owner'] ?? null,
                'owner_user_id' => $request->user()?->id,
                'notes' => $data['notes'] ?? null,
                'phase' => 'setup',
                'status' => 'active',
            ]);

            foreach ($data['domains'] as $name) {
                $batch->domains()->create([
                    'workspace_id' => $batch->workspace_id,
                    'name' => $name,
                ]);
            }

            BatchLifecycleEvent::create([
                'batch_id' => $batch->id,
                'phase' => 'setup',
                'from_phase' => null,
                'entered_at' => now(),
                'changed_by_user_id' => $request->user()?->id,
                'note' => 'Batch registered.',
            ]);

            // Seed an editable example ramp schedule (explicitly not "safe limits").
            foreach (config('deliverability.ramp_example') as $step) {
                $batch->rampSchedules()->create($step + ['approved' => false]);
            }

            return $batch;
        });

        $this->audit->log('batch.created', $batch, ['batch_id' => $batch->batch_id]);

        // Optionally create the matching tag in Instantly (label = batch ID).
        // Tagging mailboxes with it is what links them to this batch on sync.
        $tagMessage = null;
        if ($request->boolean('create_instantly_tag')) {
            try {
                (new \App\Services\Integrations\InstantlyClient(
                    (string) \App\Models\IntegrationConnection::where('provider', 'instantly')
                        ->where('workspace_id', $batch->workspace_id)->first()?->apiKey()
                ))->ensureCustomTag($batch->batch_id, 'RevCadence batch');
                $tagMessage = "Tag \"{$batch->batch_id}\" created in Instantly — add it to this batch's mailboxes there, or assign them from Unassigned mailboxes.";
                $this->audit->log('instantly.tag.created', $batch, ['label' => $batch->batch_id]);
            } catch (\Throwable $e) {
                $tagMessage = 'Batch saved, but the Instantly tag could not be created: '.$e->getMessage();
            }
        }

        return response()->json(['data' => $this->detail($batch->fresh()), 'message' => $tagMessage], 201);
    }

    public function show(Batch $batch): JsonResponse
    {
        return response()->json(['data' => $this->detail($batch)]);
    }

    public function update(Request $request, Batch $batch): JsonResponse
    {
        $data = $request->validate([
            // batch_id is intentionally NOT updatable.
            'warmup_start' => ['sometimes', 'nullable', 'date'],
            'warmup_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'active_start' => ['sometimes', 'nullable', 'date'],
            'lifecycle_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'active_daily_limit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'domain_family' => ['sometimes', 'nullable', 'string', 'max:255'],
            'batch_owner' => ['sometimes', 'nullable', 'string', 'max:255'],
            'readiness_checks_passed' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        $before = $batch->only(array_keys($data));
        $batch->update($data);
        $this->audit->log('batch.updated', $batch, ['before' => $before, 'after' => $data]);

        return response()->json(['data' => $this->detail($batch->fresh())]);
    }

    /** Explicit lifecycle phase transition with guardrails. */
    public function transition(Request $request, Batch $batch): JsonResponse
    {
        $data = $request->validate([
            'phase' => ['required', Rule::in(LifecycleService::PHASES)],
            'note' => ['nullable', 'string'],
            'active_start' => ['nullable', 'date'],
        ]);

        $target = $data['phase'];

        // Guardrail: ramp requires completed warmup AND recorded readiness.
        if ($target === 'ramp') {
            $warmup = $this->lifecycle->warmup($batch);
            if ($warmup['status'] !== 'complete' || ! $batch->readiness_checks_passed) {
                return response()->json([
                    'message' => 'Batch cannot enter ramp: warmup must be complete and readiness checks recorded. Elapsed days alone are not sufficient.',
                ], 422);
            }
        }

        // Guardrail: active needs a verified active_start (never invented).
        if ($target === 'active') {
            if (empty($batch->active_start) && empty($data['active_start'])) {
                return response()->json(['message' => 'An explicit active_start date is required to enter the Active phase.'], 422);
            }
            if (! empty($data['active_start'])) {
                $batch->active_start = $data['active_start'];
            }
        }

        $from = $batch->phase;
        $batch->phase = $target;
        $batch->save();

        BatchLifecycleEvent::create([
            'batch_id' => $batch->id,
            'phase' => $target,
            'from_phase' => $from,
            'entered_at' => now(),
            'changed_by_user_id' => $request->user()?->id,
            'note' => $data['note'] ?? null,
        ]);

        $this->audit->log('batch.phase_changed', $batch, ['from' => $from, 'to' => $target]);

        return response()->json(['data' => $this->detail($batch->fresh())]);
    }

    /** Historical batches are never hard-deleted. */
    public function destroy(Batch $batch): JsonResponse
    {
        return response()->json([
            'message' => 'Batches cannot be deleted. Retire the batch instead to preserve history.',
        ], 409);
    }

    /** Full batch detail payload for the detail page tabs. */
    private function detail(Batch $batch): array
    {
        $batch->loadCount('domains');
        $findings = $this->detection->evaluate($batch);

        return [
            'header' => $this->dashboard->batchRow($batch),
            'lifecycle' => $this->lifecycle->snapshot($batch),
            'activity' => $this->metrics->summaryForBatch($batch),
            'deliverability' => [
                'infrastructure' => $this->infraStatus($batch),
                'account_connectivity' => $this->connectivityStatus($batch),
                'sender_reputation' => $this->reputationStatus($batch),
                'inbox_placement' => $this->dashboard->batchRow($batch)['inbox_placement'],
            ],
            'domains' => $batch->domains()->get(),
            'account_exceptions' => $batch->accountExceptions()->with('emailAccount:id,email,status')->orderByDesc('detected_at')->get(),
            'problems' => $batch->problems()->orderByDesc('detected_at')->get(),
            'placement_tests' => $batch->placementTests()->orderByDesc('test_date')->get(),
            'ramp_schedules' => $batch->rampSchedules()->orderBy('week_no')->get(),
            'lifecycle_history' => $batch->lifecycleEvents()->orderByDesc('entered_at')->get(),
            'recommendations' => $this->recommendations->fromFindings($findings),
            'rotation_plan' => $batch->workspace
                ? \App\Models\RotationPlan::where('current_batch_id', $batch->id)->first()
                : null,
        ];
    }

    private function infraStatus(Batch $batch): string
    {
        $domains = $batch->domains()->get();
        if ($domains->isEmpty() || $domains->contains(fn ($d) => $d->spf_status === null && $d->dkim_status === null && $d->dmarc_status === null)) {
            return 'unknown';
        }
        $failed = $domains->contains(fn ($d) => in_array('fail', [$d->spf_status, $d->dkim_status, $d->dmarc_status, $d->mx_status], true));

        return $failed ? 'failed' : 'verified';
    }

    private function connectivityStatus(Batch $batch): string
    {
        if ($batch->actual_account_count <= 0) {
            return 'unknown';
        }
        $errorPct = $batch->errorPercentage();

        return match (true) {
            $errorPct >= 50 => 'disconnected',
            $errorPct > 0 => 'degraded',
            default => 'healthy',
        };
    }

    private function reputationStatus(Batch $batch): string
    {
        $latest = \App\Models\ReputationSnapshot::where('batch_id', $batch->id)->latest('captured_at')->first();
        if (! $latest) {
            return 'insufficient_data';   // never inferred when source data is missing
        }
        if ($latest->hard_bounce_rate !== null && $latest->hard_bounce_rate >= 5) {
            return 'critical';
        }

        return $latest->reputation_label ?: 'monitor';
    }
}
