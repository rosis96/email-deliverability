export function dash(value: unknown, suffix = ""): string {
  if (value === null || value === undefined || value === "") return "—";
  return `${value}${suffix}`;
}

export function pct(value: number | null | undefined): string {
  if (value === null || value === undefined) return "—";
  return `${value}%`;
}

export function num(value: number | null | undefined): string {
  if (value === null || value === undefined) return "—";
  return value.toLocaleString();
}

export function date(value: string | null | undefined): string {
  if (!value) return "—";
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return String(value);
  return d.toLocaleDateString(undefined, { year: "numeric", month: "short", day: "numeric" });
}

export function dateTime(value: string | null | undefined): string {
  if (!value) return "—";
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return String(value);
  return d.toLocaleString(undefined, { dateStyle: "medium", timeStyle: "short" });
}

/** Map a status-ish token to a semantic tone used by the Badge component. */
export function tone(
  kind: string,
): "ok" | "warn" | "bad" | "info" | "neutral" {
  const map: Record<string, "ok" | "warn" | "bad" | "info" | "neutral"> = {
    // placement
    good: "ok",
    poor: "bad",
    not_tested: "neutral",
    // warmup
    complete: "ok",
    warming_up: "info",
    dates_needed: "warn",
    // health
    healthy: "ok",
    verified: "ok",
    degraded: "warn",
    monitor: "warn",
    disconnected: "bad",
    failed: "bad",
    critical: "bad",
    insufficient_data: "neutral",
    unknown: "neutral",
    // severities
    low: "neutral",
    medium: "warn",
    high: "bad",
    // phases
    setup: "neutral",
    warmup: "info",
    ramp: "info",
    active: "ok",
    resting: "warn",
    retired: "neutral",
    // problem/approval statuses
    open: "bad",
    investigating: "warn",
    in_progress: "info",
    waiting_approval: "warn",
    waiting_provider: "warn",
    resolved: "ok",
    closed: "neutral",
    pending: "warn",
    approved: "ok",
    rejected: "bad",
    executed: "ok",
    connected: "ok",
    not_configured: "neutral",
    sync_failed: "bad",
    permission_denied: "bad",
  };
  return map[kind] ?? "neutral";
}

export function label(token: string | null | undefined): string {
  if (!token) return "—";
  return token
    .split("_")
    .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
    .join(" ");
}
