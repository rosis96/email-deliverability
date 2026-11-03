export type Role =
  | "super_admin"
  | "deliverability_manager"
  | "operator"
  | "viewer";

export interface AuthUser {
  id: number;
  name: string;
  email: string;
  role: Role | null;
  role_label: string | null;
  permissions: string[];
}

export interface BatchRow {
  id: string;
  batch_id: string;
  workspace: string | null;
  workspace_code: string | null;
  provider: string;
  domains_count: number;
  total_accounts: number;
  active_accounts: number;
  error_accounts: number;
  error_pct: number;
  lifecycle_phase: string;
  lifecycle_phase_label: string;
  warmup_status: string;
  warmup_start: string | null;
  warmup_end: string | null;
  warmup_days_remaining: number | null;
  active_start: string | null;
  active_age: number | null;
  replacement_due: string | null;
  days_until_replacement: number | null;
  daily_limit_per_account: number | null;
  yesterday_sent: number | null;
  daily_capacity: number | null;
  utilisation: number | null;
  sent_7d: number;
  sent_30d: number;
  reply_rate: number | null;
  bounce_rate: number | null;
  inbox_placement: "good" | "poor" | "not_tested";
  rotation_flag: boolean;
  open_problems: number;
  next_action: string;
}

export interface Overview {
  totals: {
    verified_accounts: number;
    connected_accounts: number;
    accounts_in_error: number;
    total_batches: number;
  };
  phases: Record<string, number>;
  operations: {
    rotation_candidates: number;
    open_problems: number;
    replacement_batches_ready: number;
    replacements_due_30d: number;
  };
  excluded_workspaces: {
    name: string;
    code: string;
    status: string;
    display: string;
  }[];
}

export interface WorkspaceSummary {
  id: string;
  name: string;
  code: string;
  status: string;
  status_display: string;
  counts_toward_totals: boolean;
  integration_status: string;
  batches_count: number;
  total_accounts: number | null;
  active_accounts: number | null;
  error_accounts: number | null;
  provider_distribution: Record<string, number>;
}

export interface Problem {
  id: string;
  problem_code: string;
  severity: string;
  status: string;
  symptom: string;
  evidence: string | null;
  rule_key: string | null;
  root_cause_status: string;
  accounts_affected: number | null;
  detected_at: string;
  batch?: { batch_id: string } | null;
  workspace?: { name: string; code: string } | null;
}

export interface Paginated<T> {
  data: T[];
  // Laravel paginator fields
  current_page?: number;
  last_page?: number;
  total?: number;
  meta?: { total: number; per_page: number; current_page: number; last_page: number };
}

/* --- Instantly Email Accounts (live management view) --- */
export type Tone = "ok" | "warn" | "bad" | "info" | "neutral" | "accent";

export interface InstantlyWorkspaceRef {
  workspace_id: string;
  name: string | null;
  code: string | null;
  status: string;
  has_credentials: boolean;
}

export interface InstantlyTag {
  id: string;
  label: string;
}

export interface InstantlyAccountRow {
  email: string;
  first_name: string | null;
  last_name: string | null;
  provider_code: number | null;
  type_label: string;
  status: number | null;
  status_label: string;
  status_tone: Tone;
  warmup_status: number | null;
  daily_limit: number | null;
  warmup_score: number | null;
  tags: InstantlyTag[];
}

export interface InstantlyAccountDetail extends InstantlyAccountRow {
  warmup_status_label: string;
  warmup_status_tone: Tone;
  warmup_started_at: string | null;
  daily_limit_max: number | null;
  sending_gap: number | null;
  enable_slow_ramp: boolean | null;
  reply_to: string | null;
  inbox_placement_test_limit: number | null;
  tracking_domain_name: string | null;
  tracking_domain_status: string | null;
  warmup_limit: number | null;
  warmup_increment: string | null;
  warmup_reply_rate: number | null;
  warmup_limit_max: number | null;
}

export interface InstantlyListResponse {
  items: InstantlyAccountRow[];
  next: string | null;
}
