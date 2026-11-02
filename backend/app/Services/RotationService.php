<?php

namespace App\Services;

use App\Models\Batch;
use Carbon\CarbonInterface;

/**
 * Rotation candidacy (PRD §13). Rotation is a recommendation — it never
 * automatically pauses or deletes anything.
 */
class RotationService
{
    /** Finding rule_keys that make a batch a rotation candidate. */
    private const ROTATION_RULES = [
        'error_pct', 'error_accounts', 'spam_pct', 'elevated_bounce_rate',
        'rejection_spike', 'replacement_due',
    ];

    public function __construct(
        private ProblemDetectionService $detection,
    ) {}

    /** @return array<int,string> human-readable reasons, empty if not a candidate */
    public function candidateReasons(Batch $batch, ?CarbonInterface $today = null): array
    {
        if (in_array($batch->phase, ['retired'], true)) {
            return [];
        }

        $reasons = [];
        foreach ($this->detection->evaluate($batch, $today) as $finding) {
            if (in_array($finding['rule_key'], self::ROTATION_RULES, true)) {
                $reasons[] = $finding['symptom'];
            }
        }

        return array_values(array_unique($reasons));
    }

    public function isCandidate(Batch $batch, ?CarbonInterface $today = null): bool
    {
        return count($this->candidateReasons($batch, $today)) > 0;
    }
}
