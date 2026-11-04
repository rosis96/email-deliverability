<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\BatchProblem;
use App\Models\Recommendation;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Persists detection-engine findings as batch_problems.
 *
 * Previously this logic lived only in ProblemController::detect(), which was
 * never called automatically — so incidents were only recorded when someone
 * manually hit the endpoint. It is now shared by the controller, the post-sync
 * pipeline and the scheduled `deliverability:refresh` command.
 *
 * Auto-resolution: an engine-created problem that is still untouched (status
 * "open", root cause still "unknown") is resolved when its rule no longer fires,
 * e.g. the error accounts were fixed in Instantly. Problems an operator is
 * working on, and manually logged problems, are never auto-closed.
 */
class ProblemRecorder
{
    /** Rule keys produced by ProblemDetectionService::evaluate(). */
    public const ENGINE_RULES = [
        'error_pct', 'error_accounts', 'spam_pct', 'elevated_bounce_rate',
        'smtp_auth', 'imap_disconnect', 'auth_failure', 'over_sending',
        'under_utilisation', 'readiness_missing', 'replacement_due',
    ];

    public function __construct(
        private ProblemDetectionService $detection,
        private RecommendationService $recommendations,
    ) {}

    /** @return array{findings: array, created: array<int,BatchProblem>, resolved: int} */
    public function record(Batch $batch): array
    {
        $findings = $this->detection->evaluate($batch);
        $firing = array_column($findings, 'rule_key');
        $created = [];
        $resolved = 0;

        DB::transaction(function () use ($batch, $findings, $firing, &$created, &$resolved) {
            foreach ($findings as $finding) {
                $exists = BatchProblem::where('batch_id', $batch->id)
                    ->where('rule_key', $finding['rule_key'])
                    ->whereIn('status', BatchProblem::OPEN_STATES)
                    ->exists();
                if ($exists) {
                    continue;
                }

                $problem = BatchProblem::create([
                    'problem_code' => $this->nextCode(),
                    'workspace_id' => $batch->workspace_id,
                    'batch_id' => $batch->id,
                    'rule_key' => $finding['rule_key'],
                    'accounts_affected' => $finding['accounts_affected'],
                    'severity' => $finding['severity'],
                    'symptom' => $finding['symptom'],
                    'evidence' => $finding['evidence'],
                    'root_cause_status' => 'unknown',
                    'approval_required' => $finding['approval_required'],
                    'status' => 'open',
                    'detected_at' => now(),
                ]);

                $rec = $this->recommendations->fromFinding($finding);
                Recommendation::create(array_merge($rec, [
                    'batch_problem_id' => $problem->id,
                    'batch_id' => $batch->id,
                    'workspace_id' => $batch->workspace_id,
                    'status' => 'suggested',
                ]));

                $created[] = $problem;
            }

            $stale = BatchProblem::where('batch_id', $batch->id)
                ->whereIn('rule_key', self::ENGINE_RULES)
                ->whereNotIn('rule_key', $firing ?: ['__none__'])
                ->where('status', 'open')
                ->where('root_cause_status', 'unknown')
                ->get();
            foreach ($stale as $problem) {
                $problem->update([
                    'status' => 'resolved',
                    'resolved_at' => now(),
                    'action_taken' => 'Auto-resolved: detection rule no longer fires on the latest synced data.',
                ]);
                $resolved++;
            }
        });

        return ['findings' => $findings, 'created' => $created, 'resolved' => $resolved];
    }

    /** @return array{batches: int, created: int, resolved: int} */
    public function recordWorkspace(Workspace $workspace): array
    {
        $created = $resolved = 0;
        $batches = $workspace->batches()->with('workspace')->get();
        foreach ($batches as $batch) {
            $r = $this->record($batch);
            $created += count($r['created']);
            $resolved += $r['resolved'];
        }

        return ['batches' => $batches->count(), 'created' => $created, 'resolved' => $resolved];
    }

    private function nextCode(): string
    {
        // Count-based codes can collide if rows were ever hard-deleted, so walk
        // forward until a free code is found.
        $n = BatchProblem::withTrashed()->count() + 1;
        do {
            $code = 'PRB-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            $n++;
        } while (BatchProblem::withTrashed()->where('problem_code', $code)->exists());

        return $code;
    }
}
