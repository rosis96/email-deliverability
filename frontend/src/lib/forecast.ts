/**
 * Email Infrastructure & Meeting Forecasting — pure calculation engine.
 *
 * Model (per workspace, per month):
 *   positive replies needed  = meeting target ÷ (positive-reply → meeting %)
 *   unique prospects needed  = positive replies ÷ positive reply rate
 *                              (reply rate is per unique prospect contacted)
 *   emails needed            = prospects × emails per prospect (sequence steps)
 *   active accounts needed   = emails ÷ (sending days × blended daily limit),
 *                              split across provider lines by mix %
 *   backup accounts needed   = active needed × backup %
 *   additional accounts      = max(0, needed − existing), per line, active & backup separately
 *   current monthly cost     = (active + backup + disconnected) × cost per account
 *                              (disconnected accounts are still being paid for)
 *   total monthly cost       = current cost + additional cost
 *   est. meetings booked     = planned sending capacity ÷ steps × reply rate × conversion
 */

export type ProviderKind = "google" | "microsoft" | "smtp" | "other";

export type ProviderLine = {
  id: string;
  name: string; // e.g. "Google – Zapmail"
  kind: ProviderKind;
  cost_per_account: number; // USD / account / month
  daily_limit: number; // cold emails / account / day
  mix_pct: number; // share of required sending accounts
  active: number;
  backup: number;
  disconnected: number;
};

export type PlanInputs = {
  providers: ProviderLine[];
  sending_days_per_month: number;
  meeting_target: number;
  reply_to_meeting_pct: number;
  emails_per_prospect: number;
  backup_pct: number;
  selected_reply_rate: number;
  reply_rates: number[];
  notes: string | null;
};

export const DEFAULT_REPLY_RATES = [0.1, 0.2, 0.3, 0.5, 0.7, 0.8, 1, 2, 3];

export const KIND_LABEL: Record<ProviderKind, string> = {
  google: "Google",
  microsoft: "Microsoft",
  smtp: "SMTP",
  other: "Other",
};

let seq = 0;
export function newLineId() {
  seq += 1;
  return `p${Date.now().toString(36)}${seq}`;
}

/** Starting point for a workspace with no saved plan. Prices are placeholders. */
export function defaultInputs(): PlanInputs {
  const line = (
    id: string,
    name: string,
    kind: ProviderKind,
    cost: number,
    mix: number,
  ): ProviderLine => ({
    id,
    name,
    kind,
    cost_per_account: cost,
    daily_limit: 15,
    mix_pct: mix,
    active: 0,
    backup: 0,
    disconnected: 0,
  });
  return {
    providers: [
      line("google-dfy", "Google – Instantly DFY", "google", 4.5, 30),
      line("google-zapmail", "Google – Zapmail", "google", 3, 20),
      line("google-peeker", "Google – Peeker (GWS)", "google", 3, 10),
      line("smtp-maildoso", "SMTP – Maildoso", "smtp", 1.5, 30),
      line("ms-zapmail", "Microsoft – Zapmail", "microsoft", 3, 10),
    ],
    sending_days_per_month: 22,
    meeting_target: 20,
    reply_to_meeting_pct: 40,
    emails_per_prospect: 3,
    backup_pct: 20,
    selected_reply_rate: 0.5,
    reply_rates: [...DEFAULT_REPLY_RATES],
    notes: null,
  };
}

/** Fill any missing keys of a saved plan with defaults (forward-compatible). */
export function normalizeInputs(saved: Partial<PlanInputs> | null | undefined): PlanInputs {
  const d = defaultInputs();
  if (!saved) return d;
  return {
    ...d,
    ...saved,
    providers: (saved.providers ?? d.providers).map((p) => ({ ...p })),
    reply_rates: saved.reply_rates?.length ? [...saved.reply_rates] : d.reply_rates,
    notes: saved.notes ?? null,
  };
}

const n = (v: unknown) => (typeof v === "number" && Number.isFinite(v) ? v : 0);

/* ------------------------------------------------------------------ */
/* 1. Existing infrastructure                                          */
/* ------------------------------------------------------------------ */

export type LineInfra = {
  id: string;
  name: string;
  kind: ProviderKind;
  total: number;
  active: number;
  backup: number;
  disconnected: number;
  cost_per_account: number;
  monthly_cost: number;
  daily_per_account: number;
  monthly_per_account: number;
  daily_capacity: number; // active accounts only
  monthly_capacity: number;
};

export type Infra = {
  lines: LineInfra[];
  total: number;
  active: number;
  backup: number;
  disconnected: number;
  monthly_cost: number;
  avg_cost_per_account: number;
  daily_capacity: number;
  monthly_capacity: number;
  by_kind: Record<ProviderKind, number>;
};

export function infrastructure(p: PlanInputs): Infra {
  const days = n(p.sending_days_per_month);
  const lines = p.providers.map<LineInfra>((l) => {
    const active = n(l.active);
    const backup = n(l.backup);
    const disconnected = n(l.disconnected);
    const total = active + backup + disconnected;
    const daily = n(l.daily_limit);
    return {
      id: l.id,
      name: l.name,
      kind: l.kind,
      total,
      active,
      backup,
      disconnected,
      cost_per_account: n(l.cost_per_account),
      monthly_cost: total * n(l.cost_per_account),
      daily_per_account: daily,
      monthly_per_account: daily * days,
      daily_capacity: active * daily,
      monthly_capacity: active * daily * days,
    };
  });
  const sum = (k: keyof LineInfra) => lines.reduce((a, l) => a + (l[k] as number), 0);
  const by_kind: Record<ProviderKind, number> = { google: 0, microsoft: 0, smtp: 0, other: 0 };
  lines.forEach((l) => (by_kind[l.kind] += l.total));
  const total = sum("total");
  const monthly_cost = sum("monthly_cost");
  return {
    lines,
    total,
    active: sum("active"),
    backup: sum("backup"),
    disconnected: sum("disconnected"),
    monthly_cost,
    avg_cost_per_account: total ? monthly_cost / total : 0,
    daily_capacity: sum("daily_capacity"),
    monthly_capacity: sum("monthly_capacity"),
    by_kind,
  };
}

/* ------------------------------------------------------------------ */
/* 2. Meeting forecast basics                                          */
/* ------------------------------------------------------------------ */

export function positiveRepliesNeeded(p: PlanInputs): number {
  const conv = n(p.reply_to_meeting_pct) / 100;
  return conv > 0 ? Math.ceil(n(p.meeting_target) / conv) : 0;
}

/* ------------------------------------------------------------------ */
/* 3. Reply-rate scenarios                                             */
/* ------------------------------------------------------------------ */

export type LineScenario = {
  id: string;
  name: string;
  required_active: number;
  required_backup: number;
  additional_active: number;
  additional_backup: number;
  additional: number;
  additional_cost: number;
};

export type Scenario = {
  reply_rate: number; // %
  positive_replies: number;
  prospects: number;
  emails: number;
  daily_emails: number;
  lines: LineScenario[];
  required_active: number;
  required_backup: number;
  required_total: number;
  additional_active: number;
  additional_backup: number;
  additional: number;
  additional_cost: number;
  total_cost: number;
  meetings_current: number; // with today's active capacity
  meetings_planned: number; // once the plan is built out
  cost_per_meeting: number | null;
  coverage_pct: number; // current capacity ÷ emails needed
  feasible: boolean; // false when the mix/limits can't produce any capacity
};

export function scenario(p: PlanInputs, replyRatePct: number, infra = infrastructure(p)): Scenario {
  const days = n(p.sending_days_per_month);
  const steps = Math.max(1, n(p.emails_per_prospect));
  const conv = n(p.reply_to_meeting_pct) / 100;
  const rate = n(replyRatePct) / 100;
  const backupPct = n(p.backup_pct) / 100;

  const positive_replies = positiveRepliesNeeded(p);
  const prospects = rate > 0 ? Math.ceil(positive_replies / rate) : 0;
  const emails = prospects * steps;

  const mixTotal = p.providers.reduce((a, l) => a + Math.max(0, n(l.mix_pct)), 0);
  const weights = p.providers.map((l) => (mixTotal > 0 ? Math.max(0, n(l.mix_pct)) / mixTotal : 0));
  // Emails one "blended" account sends per month.
  const blended = p.providers.reduce((a, l, i) => a + weights[i] * n(l.daily_limit) * days, 0);
  const feasible = emails === 0 || blended > 0;
  const accountsNeeded = blended > 0 ? emails / blended : 0;

  const lines = p.providers.map<LineScenario>((l, i) => {
    const required_active = weights[i] > 0 ? Math.ceil(accountsNeeded * weights[i] - 1e-9) : 0;
    const required_backup = Math.ceil(required_active * backupPct - 1e-9);
    const additional_active = Math.max(0, required_active - n(l.active));
    const additional_backup = Math.max(0, required_backup - n(l.backup));
    const additional = additional_active + additional_backup;
    return {
      id: l.id,
      name: l.name,
      required_active,
      required_backup,
      additional_active,
      additional_backup,
      additional,
      additional_cost: additional * n(l.cost_per_account),
    };
  });

  const sum = (k: keyof LineScenario) => lines.reduce((a, l) => a + (l[k] as number), 0);
  const additional_cost = sum("additional_cost");
  const total_cost = infra.monthly_cost + additional_cost;

  // Planned sending capacity = per line, the larger of today's active and required active.
  const plannedCapacity = p.providers.reduce(
    (a, l, i) => a + Math.max(n(l.active), lines[i].required_active) * n(l.daily_limit) * days,
    0,
  );
  const meetings = (capacity: number) => (capacity / steps) * rate * conv;
  const meetings_planned = meetings(plannedCapacity);

  return {
    reply_rate: replyRatePct,
    positive_replies,
    prospects,
    emails,
    daily_emails: days > 0 ? Math.ceil(emails / days) : 0,
    lines,
    required_active: sum("required_active"),
    required_backup: sum("required_backup"),
    required_total: sum("required_active") + sum("required_backup"),
    additional_active: sum("additional_active"),
    additional_backup: sum("additional_backup"),
    additional: sum("additional"),
    additional_cost,
    total_cost,
    meetings_current: meetings(infra.monthly_capacity),
    meetings_planned,
    cost_per_meeting: meetings_planned > 0 ? total_cost / meetings_planned : null,
    coverage_pct: emails > 0 ? (infra.monthly_capacity / emails) * 100 : 100,
    feasible,
  };
}

export function scenarios(p: PlanInputs): Scenario[] {
  const infra = infrastructure(p);
  return [...p.reply_rates].sort((a, b) => a - b).map((r) => scenario(p, r, infra));
}

/** Problems that make the numbers untrustworthy — shown above the planner. */
export function validate(p: PlanInputs): string[] {
  const issues: string[] = [];
  const mix = p.providers.reduce((a, l) => a + n(l.mix_pct), 0);
  if (p.providers.length === 0) issues.push("Add at least one provider line.");
  else if (Math.abs(mix - 100) > 0.5) issues.push(`Provider mix adds up to ${round(mix, 1)}% — it must total 100%.`);
  if (p.providers.some((l) => n(l.mix_pct) > 0 && n(l.daily_limit) <= 0))
    issues.push("A provider with a mix share has a daily limit of 0, so it can't send anything.");
  if (n(p.reply_to_meeting_pct) <= 0) issues.push("Positive reply → meeting conversion must be above 0%.");
  if (n(p.emails_per_prospect) < 1) issues.push("Emails per prospect must be at least 1.");
  if (n(p.sending_days_per_month) < 1) issues.push("Sending days per month must be at least 1.");
  if (p.providers.some((l) => !l.name.trim())) issues.push("Every provider line needs a name.");
  return issues;
}

export function round(v: number, dp = 0) {
  const f = 10 ** dp;
  return Math.round(v * f) / f;
}

/* ------------------------------------------------------------------ */
/* Formatting                                                          */
/* ------------------------------------------------------------------ */

export const fmtInt = (v: number) => Math.round(v).toLocaleString();
export const fmtUsd = (v: number | null, dp = 0) =>
  v === null || !Number.isFinite(v)
    ? "—"
    : v.toLocaleString(undefined, { style: "currency", currency: "USD", minimumFractionDigits: dp, maximumFractionDigits: dp });
export const fmtNum = (v: number, dp = 1) =>
  v.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: dp });
export const fmtPct = (v: number) => `${fmtNum(v, 2)}%`;

/* ------------------------------------------------------------------ */
/* API shapes                                                          */
/* ------------------------------------------------------------------ */

export type LiveSnapshot = {
  total: number;
  active: number;
  paused: number;
  error: number;
  other: number;
  daily_capacity: number;
  by_provider: Record<string, number>;
  synced_at: string | null;
};

export type PlanRow = {
  workspace_id: string;
  name: string;
  code: string;
  status: string;
  counts_toward_totals: boolean;
  inputs: PlanInputs | null;
  updated_at: string | null;
  updated_by: string | null;
  live: LiveSnapshot | null;
};
