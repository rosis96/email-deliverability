<?php

namespace App\Services;

/**
 * Deterministic diagnosis/recommendation engine (PRD §12). Recommendations are
 * mapped from detected rule findings — NOT invented. Every recommendation
 * states what was detected, why it matters, suggested investigation, expected
 * impact and whether approval is required. No automatic root causes.
 */
class RecommendationService
{
    /** Static playbook keyed by rule_key. */
    private const PLAYBOOK = [
        'error_pct' => [
            'why' => 'A high proportion of accounts in error reduces deliverable capacity and can signal an infrastructure or provider problem.',
            'investigation' => 'Open the account exceptions for this batch and check the actual provider error before any reconnection attempt.',
            'impact' => 'Restoring accounts recovers sending capacity.',
            'approval' => false,
        ],
        'error_accounts' => [
            'why' => 'A large absolute number of failing mailboxes warrants investigation regardless of percentage.',
            'investigation' => 'Review the exception list and group by error type (auth, IMAP, password, cancelled order).',
            'impact' => 'Targeted fixes reduce the error pool.',
            'approval' => false,
        ],
        'spam_pct' => [
            'why' => 'Elevated spam placement indicates a sender-reputation issue affecting inbox delivery.',
            'investigation' => 'Run a sender-reputation investigation and a controlled placement retest before changing sending.',
            'impact' => 'Confirms whether reputation remediation or rotation is required.',
            'approval' => false,
        ],
        'elevated_bounce_rate' => [
            'why' => 'Persistently elevated bounces harm reputation and may indicate list quality or sending-source issues.',
            'investigation' => 'Check recipient verification, bounce classifications (hard vs soft) and the sending source.',
            'impact' => 'Reducing bounces protects reputation and inbox placement.',
            'approval' => false,
        ],
        'smtp_auth' => [
            'why' => 'SMTP authentication failures stop the mailbox from sending.',
            'investigation' => 'Check credentials and provider-side authentication settings. Do not store or expose credentials.',
            'impact' => 'Resolving auth restores the affected mailboxes.',
            'approval' => false,
        ],
        'imap_disconnect' => [
            'why' => 'IMAP disconnection breaks reply tracking and warmup for the mailbox.',
            'investigation' => 'Inspect mailbox IMAP configuration and provider connection status.',
            'impact' => 'Reconnecting restores reply visibility and warmup.',
            'approval' => false,
        ],
        'auth_failure' => [
            'why' => 'SPF/DKIM/DMARC failures cause authentication rejection and spam folder placement.',
            'investigation' => 'Fix the failing DNS authentication records before sending from the affected domains.',
            'impact' => 'Correct authentication is a prerequisite for deliverability.',
            'approval' => false,
        ],
        'over_sending' => [
            'why' => 'Sending above approved capacity risks throttling and reputation damage.',
            'investigation' => 'Flag over-sending and request operator review of per-account limits and campaign assignment.',
            'impact' => 'Bringing sending within capacity reduces throttling risk.',
            'approval' => true,
        ],
        'under_utilisation' => [
            'why' => 'Sustained low utilisation suggests campaigns, schedules or account eligibility are not configured as intended.',
            'investigation' => 'Check campaign status, account eligibility, sending schedules and throttling.',
            'impact' => 'Correcting configuration recovers intended throughput.',
            'approval' => false,
        ],
        'readiness_missing' => [
            'why' => 'Warmup completing on the calendar does not mean a batch is verified ready; readiness checks are required.',
            'investigation' => 'Verify infrastructure, connectivity and placement, then record readiness before entering ramp.',
            'impact' => 'Prevents premature ramp on an unverified batch.',
            'approval' => false,
        ],
        'replacement_due' => [
            'why' => 'The batch is approaching the end of its configured lifecycle.',
            'investigation' => 'Prepare a replacement batch and plan the ~7-day overlap transition.',
            'impact' => 'Proactive replacement avoids a capacity gap.',
            'approval' => false,
        ],
    ];

    /** Build a recommendation payload from a detection finding. */
    public function fromFinding(array $finding): array
    {
        $rule = $finding['rule_key'] ?? 'unknown';
        $play = self::PLAYBOOK[$rule] ?? [
            'why' => 'Detected by an operational rule and worth reviewing.',
            'investigation' => 'Review the batch detail and supporting records.',
            'impact' => 'Unknown until investigated.',
            'approval' => false,
        ];

        return [
            'rule_key' => $rule,
            'detected' => $finding['symptom'] ?? '',
            'why' => $play['why'],
            'evidence' => $finding['evidence'] ?? null,
            'suggested_investigation' => $play['investigation'],
            'expected_impact' => $play['impact'],
            'approval_required' => $finding['approval_required'] ?? $play['approval'],
            'is_ai_generated' => false,
        ];
    }

    /** @param array<int,array> $findings */
    public function fromFindings(array $findings): array
    {
        return array_map(fn ($f) => $this->fromFinding($f), $findings);
    }
}
