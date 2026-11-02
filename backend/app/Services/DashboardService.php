<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\BatchProblem;
use App\Models\RotationPlan;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Assembles the central command-centre overview and the batch dashboard rows
 * (PRD §5). The batch — never the mailbox — is the unit of the table. All
 * totals derive from verified records; unknown values stay null so the UI can
 * render "Not verified" / "Not tested" / "Dates needed".
 */
class DashboardService
{
    private const SEVERITY_RANK = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];

    public function __construct(
        private LifecycleService $lifecycle,
        private MetricsService $metrics,
        private ProblemDetectionService $detection,
        private RotationService $rotation,
        private SettingsService $settings,
    ) {}

    /** Build one dashboard row for a batch. */
    public function batchRow(Batch $batch, ?CarbonInterface $today = null): array
    {
        $warmup = $this->lifecycle->warmup($batch, $today);
        $active = $this->lifecycle->active($batch, $today);
        $yesterday = $this->metrics->yesterday($batch, $today);
        $r7 = $this->metrics->rolling($batch, 7, $today);
        $r30 = $this->metrics->rolling($batch, 30, $today);
        $findings = $this->detection->evaluate($batch, $today);

        $currentCapacity = ($batch->active_account_count && $batch->active_daily_limit)
            ? $batch->active_account_count * $batch->active_daily_limit
            : null;

        $utilisation = ($yesterday && $currentCapacity)
            ? round($yesterday['sent'] / $currentCapacity * 100, 2)
            : null;

        return [
            'id' => $batch->id,
            'batch_id' => $batch->batch_id,
            'workspace' => $batch->workspace?->name,
            'workspace_code' => $batch->workspace?->code,
            'provider' => $batch->provider_type,
            'domains_count' => $batch->domains_count ?? $batch->domains()->count(),
            'total_accounts' => $batch->actual_account_count,
            'active_accounts' => $batch->active_account_count,
            'error_accounts' => $batch->error_account_count,
            'error_pct' => $batch->errorPercentage(),
            'lifecycle_phase' => $batch->phase,
            'lifecycle_phase_label' => LifecycleService::PHASE_LABELS[$batch->phase] ?? $batch->phase,
            'warmup_status' => $warmup['status'],
            'warmup_start' => $warmup['start'],
            'warmup_end' => $warmup['end'],
            'warmup_days_remaining' => $warmup['remaining'],
            'active_start' => $active['active_start'],
            'active_age' => $active['active_age'],
            'replacement_due' => $active['replacement_due'],
            'days_until_replacement' => $active['days_until_replacement'],
            'daily_limit_per_account' => $batch->active_daily_limit,
            'yesterday_sent' => $yesterday['sent'] ?? null,
            'daily_capacity' => $currentCapacity,
            'utilisation' => $utilisation,
            'sent_7d' => $r7['sent'],
            'sent_30d' => $r30['sent'],
            'reply_rate' => $r30['reply_rate'],
            'bounce_rate' => $r30['bounce_rate'],
            'inbox_placement' => $this->placementStatus($batch),
            'rotation_flag' => count(array_filter($findings, fn ($f) => in_array($f['rule_key'], ['error_pct', 'error_accounts', 'spam_pct', 'elevated_bounce_rate', 'replacement_due'], true))) > 0,
            'open_problems' => $batch->problems()->whereIn('status', BatchProblem::OPEN_STATES)->count(),
            'next_action' => $this->nextAction($batch, $findings, $warmup),
        ];
    }

    private function placementStatus(Batch $batch): string
    {
        $test = $batch->placementTests()->whereNotNull('spam_pct')->latest('test_date')->first();
        if (! $test) {
            return 'not_tested';
        }

        return (float) $test->spam_pct >= $this->settings->threshold('spam_pct') ? 'poor' : 'good';
    }

    private function nextAction(Batch $batch, array $findings, array $warmup): string
    {
        if (! empty($findings)) {
            usort($findings, fn ($a, $b) => (self::SEVERITY_RANK[$b['severity']] ?? 0) <=> (self::SEVERITY_RANK[$a['severity']] ?? 0));

            return 'Investigate: '.$findings[0]['symptom'];
        }

        return match (true) {
            $warmup['status'] === 'dates_needed' => 'Enter warmup start date',
            $warmup['status'] === 'warming_up' => "Warming up — {$warmup['remaining']} day(s) remaining",
            $warmup['status'] === 'complete' && ! $batch->readiness_checks_passed => 'Verify readiness for ramp',
            $batch->phase === 'active' => 'Healthy — continue sending',
            default => 'Monitor',
        };
    }

    /** Central command-centre top-level metrics. */
    public function overview(?CarbonInterface $today = null): array
    {
        $batches = Batch::with('workspace')->get();
        $counting = $batches->filter(fn (Batch $b) => $b->workspace && $b->workspace->counts_toward_totals);

        $rotationCandidates = $batches->filter(fn (Batch $b) => $this->rotation->isCandidate($b, $today))->count();
        $dueSoon = $batches->filter(fn (Batch $b) => $this->lifecycle->isReplacementDueWithin($b, (int) $this->settings->threshold('replacement_due_days'), $today))->count();

        return [
            'totals' => [
                'verified_accounts' => (int) $counting->sum('actual_account_count'),
                'connected_accounts' => (int) $counting->sum('active_account_count'),
                'accounts_in_error' => (int) $counting->sum('error_account_count'),
                'total_batches' => $batches->count(),
            ],
            'phases' => [
                'warming_up' => $batches->where('phase', 'warmup')->count(),
                'ramp' => $batches->where('phase', 'ramp')->count(),
                'active' => $batches->where('phase', 'active')->count(),
                'resting' => $batches->where('phase', 'resting')->count(),
                'retired' => $batches->where('phase', 'retired')->count(),
            ],
            'operations' => [
                'rotation_candidates' => $rotationCandidates,
                'open_problems' => BatchProblem::whereIn('status', BatchProblem::OPEN_STATES)->count(),
                'replacement_batches_ready' => RotationPlan::whereIn('status', ['placement_validated', 'approved', 'overlapping'])->count(),
                'replacements_due_30d' => $dueSoon,
            ],
            'excluded_workspaces' => $this->excludedWorkspaces(),
        ];
    }

    /** Expired/unverified workspaces are reported explicitly, never as zero. */
    private function excludedWorkspaces(): Collection
    {
        return Workspace::where('counts_toward_totals', false)->get()->map(fn (Workspace $w) => [
            'name' => $w->name,
            'code' => $w->code,
            'status' => $w->status,
            'display' => $w->status === 'expired'
                ? 'Not verified — expired plan blocks access'
                : 'Not verified',
        ]);
    }
}
