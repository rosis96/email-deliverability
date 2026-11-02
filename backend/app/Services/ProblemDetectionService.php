<?php

namespace App\Services;

use App\Models\Batch;
use Carbon\CarbonInterface;

/**
 * Configurable rules engine (PRD §11). Produces INVESTIGATION TRIGGERS only —
 * it never takes corrective action. Each finding is evidence-backed and the
 * thresholds are all configurable via SettingsService.
 */
class ProblemDetectionService
{
    public function __construct(
        private LifecycleService $lifecycle,
        private MetricsService $metrics,
        private SettingsService $settings,
    ) {}

    /** @return array<int,array> list of findings for a batch */
    public function evaluate(Batch $batch, ?CarbonInterface $today = null): array
    {
        $t = $this->settings->thresholds();
        $findings = [];

        // Rule 1 — error percentage.
        $errorPct = $batch->errorPercentage();
        if ($batch->actual_account_count > 0 && $errorPct >= $t['error_pct']) {
            $findings[] = $this->finding('error_pct', 'high',
                "Error rate {$errorPct}% is at/above the {$t['error_pct']}% threshold.",
                "{$batch->error_account_count}/{$batch->actual_account_count} accounts in error.",
                $batch->error_account_count);
        }

        // Rule 2 — absolute error account count.
        if ($batch->error_account_count >= $t['error_accounts']) {
            $findings[] = $this->finding('error_accounts', 'high',
                "{$batch->error_account_count} accounts in error (>= {$t['error_accounts']}).",
                "Absolute error count exceeds threshold.",
                $batch->error_account_count);
        }

        // Rule 3 — batch-scoped placement spam %. Workspace-level results are NOT used.
        $placement = $batch->placementTests()->whereNotNull('spam_pct')->latest('test_date')->first();
        if ($placement && (float) $placement->spam_pct >= $t['spam_pct']) {
            $findings[] = $this->finding('spam_pct', 'high',
                "Inbox placement spam {$placement->spam_pct}% is at/above {$t['spam_pct']}%.",
                "Placement test {$placement->test_date->toDateString()} (batch-mapped).",
                null);
        }

        // Rule 4 — persistently elevated bounce rate.
        if ($this->bouncePersistentlyElevated($batch, $t, $today)) {
            $findings[] = $this->finding('elevated_bounce_rate', 'high',
                "Bounce rate >= {$t['elevated_bounce_rate']}% on {$t['bounce_persistence_days']}+ recent days.",
                "Derived from daily_activity bounce/sent.", null);
        }

        // Rules 5 & 6 — open mailbox exceptions by type.
        foreach (['smtp_auth' => 'SMTP authentication failure', 'imap_disconnect' => 'IMAP disconnection'] as $type => $label) {
            $count = $batch->accountExceptions()->where('type', $type)->where('status', '!=', 'resolved')->count();
            if ($count > 0) {
                $findings[] = $this->finding($type, 'medium',
                    "{$count} open exception(s): {$label}.",
                    "account_exceptions type={$type}.", $count);
            }
        }

        // Rule 7 — authentication (SPF/DKIM/DMARC) failures on the batch's domains.
        $authFail = $batch->domains()
            ->where(fn ($q) => $q->where('spf_status', 'fail')
                ->orWhere('dkim_status', 'fail')
                ->orWhere('dmarc_status', 'fail'))
            ->count();
        if ($authFail > 0) {
            $findings[] = $this->finding('auth_failure', 'critical',
                "{$authFail} domain(s) with SPF/DKIM/DMARC failure.",
                "Fix authentication before sending.", null);
        }

        // Rule 9 — sending exceeds configured capacity.
        if ($over = $this->overSending($batch, $t, $today)) {
            $findings[] = $over;
        }

        // Rule 10 — utilisation far below approved target for multiple days.
        if ($under = $this->underUtilisation($batch, $t, $today)) {
            $findings[] = $under;
        }

        // Rule 11 — warmup complete but readiness checks missing.
        $warmup = $this->lifecycle->warmup($batch, $today);
        if ($warmup['status'] === 'complete' && ! $batch->readiness_checks_passed
            && in_array($batch->phase, ['setup', 'warmup'], true)) {
            $findings[] = $this->finding('readiness_missing', 'medium',
                'Warmup complete but readiness checks are not recorded.',
                'Readiness must be verified before ramp — 21 days alone is not sufficient.', null);
        }

        // Rule 12 — replacement due within the configured window.
        if ($this->lifecycle->isReplacementDueWithin($batch, (int) $t['replacement_due_days'], $today)) {
            $due = $this->lifecycle->replacementDue($batch)?->toDateString();
            $findings[] = $this->finding('replacement_due', 'medium',
                "Replacement due by {$due} (within {$t['replacement_due_days']} days).",
                "Derived from active_start + lifecycle_days.", null);
        }

        return $findings;
    }

    private function bouncePersistentlyElevated(Batch $batch, array $t, ?CarbonInterface $today): bool
    {
        $need = (int) $t['bounce_persistence_days'];
        $rolling = $this->metrics->rolling($batch, max($need, 7), $today);
        // Use the window bounce_rate as the signal, plus require enough days of data.
        return $rolling['days_with_data'] >= $need
            && $rolling['bounce_rate'] !== null
            && $rolling['bounce_rate'] >= $t['elevated_bounce_rate'];
    }

    private function overSending(Batch $batch, array $t, ?CarbonInterface $today): ?array
    {
        $y = $this->metrics->yesterday($batch, $today);
        if (! $y || ($y['capacity'] ?? null) === null || $y['capacity'] <= 0) {
            return null;
        }
        $limit = $y['capacity'] * (1 + $t['over_send_tolerance_pct'] / 100);
        if ($y['sent'] > $limit) {
            return $this->finding('over_sending', 'high',
                "Sent {$y['sent']} exceeds capacity {$y['capacity']} (>{$t['over_send_tolerance_pct']}% over).",
                "Yesterday ({$y['date']}).", null, true);
        }

        return null;
    }

    private function underUtilisation(Batch $batch, array $t, ?CarbonInterface $today): ?array
    {
        $window = (int) $t['under_utilisation_days'];
        $rolling = $this->metrics->rolling($batch, $window, $today);
        if ($rolling['days_with_data'] < $window || $rolling['utilisation'] === null) {
            return null;
        }
        if ($rolling['utilisation'] < $t['under_utilisation_pct']) {
            return $this->finding('under_utilisation', 'low',
                "Utilisation {$rolling['utilisation']}% is below {$t['under_utilisation_pct']}% over {$window} days.",
                "Check campaign status, schedules, eligibility and throttling.", null);
        }

        return null;
    }

    private function finding(string $rule, string $severity, string $symptom, string $evidence, ?int $accounts, bool $approval = false): array
    {
        return [
            'rule_key' => $rule,
            'severity' => $severity,
            'symptom' => $symptom,
            'evidence' => $evidence,
            'accounts_affected' => $accounts,
            'approval_required' => $approval,
        ];
    }
}
