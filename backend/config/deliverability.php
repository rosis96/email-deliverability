<?php

/*
 * Default operational thresholds for the problem-detection and rotation
 * engines. These are starting points only — they are overridable per
 * deployment via the application_settings table (group = "thresholds") and
 * surfaced in the Settings module. They are explicitly NOT presented as
 * universally safe sending limits.
 */

return [

    'thresholds' => [
        'error_pct' => 10,                       // rule 1: error % >= 10
        'error_accounts' => 10,                  // rule 2: error accounts >= 10
        'spam_pct' => 20,                        // rule 3: placement spam % >= 20
        'elevated_bounce_rate' => 5.0,           // rule 4: persistently elevated bounce %
        'bounce_persistence_days' => 3,
        'rejection_spike_rate' => 5.0,           // rule 8: provider rejection spike (% of sent)
        'over_send_tolerance_pct' => 5,          // rule 9: sending exceeds capacity by >5%
        'under_utilisation_pct' => 50,           // rule 10: utilisation far below target
        'under_utilisation_days' => 3,
        'replacement_due_days' => 30,            // rule 12 + dashboard window
    ],

    'defaults' => [
        'warmup_days' => 21,
        'lifecycle_days' => 90,
        'rotation_overlap_days' => 7,
        'monthly_account_target' => 30,
    ],

    'ramp_example' => [
        ['week_no' => 1, 'daily_limit_per_account' => 5],
        ['week_no' => 2, 'daily_limit_per_account' => 10],
        ['week_no' => 3, 'daily_limit_per_account' => 15],
        ['week_no' => 4, 'daily_limit_per_account' => 20],
    ],

];
