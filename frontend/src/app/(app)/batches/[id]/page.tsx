"use client";

import { useParams } from "next/navigation";
import { useState } from "react";
import {
  Badge, Card, EmptyState, ErrorNote, PageHeader, Spinner, Table, Td, Th,
} from "@/components/ui";
import { useApi } from "@/lib/hooks";
import { date, dateTime, dash, label, num, pct, tone } from "@/lib/format";
import type { BatchRow } from "@/lib/types";
import { InstantlyPanel } from "@/components/instantly-panel";

interface Detail {
  header: BatchRow;
  lifecycle: {
    phase: string;
    phase_label: string;
    warmup: { status: string; start: string | null; end: string | null; days: number; elapsed: number | null; remaining: number | null };
    active: { active_start: string | null; active_age: number | null; lifecycle_days: number; replacement_due: string | null; days_until_replacement: number | null };
    readiness_checks_passed: boolean;
  };
  activity: {
    yesterday: Record<string, unknown> | null;
    last_7_days: Record<string, number | null>;
    last_30_days: Record<string, number | null>;
  };
  deliverability: Record<string, string>;
  domains: { id: string; name: string; spf_status: string | null; dkim_status: string | null; dmarc_status: string | null; mx_status: string | null; last_verified_at: string | null }[];
  account_exceptions: { id: string; type: string; severity: string; symptom: string; sample_dialog: string | null; status: string; detected_at: string; email_account?: { email: string; status: string } | null }[];
  problems: { id: string; problem_code: string; severity: string; status: string; symptom: string; detected_at: string }[];
  placement_tests: { id: string; test_date: string; scope: string; spam_pct: number | null; inbox_pct: number | null; provider: string | null; evidence: string | null }[];
  ramp_schedules: { id: string; week_no: number; daily_limit_per_account: number; approved: boolean }[];
  lifecycle_history: { id: string; phase: string; from_phase: string | null; entered_at: string; note: string | null }[];
  recommendations: { rule_key: string; detected: string; why: string; evidence: string | null; suggested_investigation: string; expected_impact: string | null; approval_required: boolean; is_ai_generated: boolean }[];
  rotation_plan: { status: string; overlap_start: string | null; overlap_end: string | null } | null;
}

const TABS = ["Overview", "Instantly", "Accounts", "Domains", "Lifecycle", "Daily Activity", "Deliverability", "Placement", "Problems", "Rotation", "History"];

function Metric({ k, v }: { k: string; v: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between border-b border-[var(--border)] py-1.5 text-sm last:border-0">
      <span className="text-[var(--text-muted)]">{k}</span>
      <span className="font-medium tabular-nums">{v}</span>
    </div>
  );
}

function ActivityBlock({ title, a }: { title: string; a: Record<string, number | null> }) {
  return (
    <Card className="p-4">
      <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-[var(--text-faint)]">{title}</h3>
      <Metric k="Sent" v={num(a.sent)} />
      <Metric k="Capacity" v={dash(a.capacity)} />
      <Metric k="Utilisation" v={pct(a.utilisation)} />
      <Metric k="Replies" v={num(a.replies)} />
      <Metric k="Bounce rate" v={pct(a.bounce_rate)} />
    </Card>
  );
}

export default function BatchDetailPage() {
  const params = useParams<{ id: string }>();
  const { data, error, loading, reload } = useApi<{ data: Detail }>(`/batches/${params.id}`);
  const [tab, setTab] = useState("Overview");

  if (loading && !data) return <Spinner />;
  if (error) return <ErrorNote message={error} />;
  if (!data) return <EmptyState title="Batch not found" />;

  const d = data.data;
  const h = d.header;

  return (
    <div>
      <PageHeader
        title={h.batch_id}
        subtitle={`${h.workspace} · ${label(h.provider)} · ${h.lifecycle_phase_label}`}
        actions={
          <>
            <Badge tone={h.error_pct >= 10 ? "bad" : "ok"}>Err {pct(h.error_pct)}</Badge>
            <Badge tone={tone(h.inbox_placement)}>Placement: {label(h.inbox_placement)}</Badge>
          </>
        }
      />

      <div className="mb-5 rounded-lg border border-[var(--border)] bg-[var(--surface-2)] px-4 py-2 text-sm">
        <span className="text-[var(--text-faint)]">Recommended action: </span>
        <span className="font-medium">{h.next_action}</span>
      </div>

      <div className="mb-5 flex flex-wrap gap-1 border-b border-[var(--border)]">
        {TABS.map((t) => (
          <button
            key={t}
            onClick={() => setTab(t)}
            className={`-mb-px border-b-2 px-3 py-2 text-sm ${
              tab === t
                ? "border-[var(--accent)] font-medium text-[var(--text)]"
                : "border-transparent text-[var(--text-muted)] hover:text-[var(--text)]"
            }`}
          >
            {t}
          </button>
        ))}
      </div>

      {tab === "Overview" && (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
          <Card className="p-4">
            <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-[var(--text-faint)]">Accounts</h3>
            <Metric k="Total" v={num(h.total_accounts)} />
            <Metric k="Active" v={num(h.active_accounts)} />
            <Metric k="Error" v={num(h.error_accounts)} />
            <Metric k="Error %" v={pct(h.error_pct)} />
            <Metric k="Domains" v={h.domains_count} />
          </Card>
          <Card className="p-4">
            <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-[var(--text-faint)]">Health dimensions</h3>
            <Metric k="Infrastructure" v={<Badge tone={tone(d.deliverability.infrastructure)}>{label(d.deliverability.infrastructure)}</Badge>} />
            <Metric k="Connectivity" v={<Badge tone={tone(d.deliverability.account_connectivity)}>{label(d.deliverability.account_connectivity)}</Badge>} />
            <Metric k="Reputation" v={<Badge tone={tone(d.deliverability.sender_reputation)}>{label(d.deliverability.sender_reputation)}</Badge>} />
            <Metric k="Inbox placement" v={<Badge tone={tone(d.deliverability.inbox_placement)}>{label(d.deliverability.inbox_placement)}</Badge>} />
          </Card>
          <Card className="p-4">
            <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-[var(--text-faint)]">Lifecycle</h3>
            <Metric k="Phase" v={d.lifecycle.phase_label} />
            <Metric k="Warmup" v={<Badge tone={tone(d.lifecycle.warmup.status)}>{label(d.lifecycle.warmup.status)}</Badge>} />
            <Metric k="Warmup end" v={date(d.lifecycle.warmup.end)} />
            <Metric k="Replacement due" v={date(d.lifecycle.active.replacement_due)} />
            <Metric k="Readiness checks" v={d.lifecycle.readiness_checks_passed ? "Passed" : "Not recorded"} />
          </Card>
          <div className="md:col-span-2 lg:col-span-3 grid grid-cols-1 gap-4 md:grid-cols-3">
            <ActivityBlock title="Last 7 days" a={d.activity.last_7_days} />
            <ActivityBlock title="Last 30 days" a={d.activity.last_30_days} />
            <Card className="p-4">
              <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-[var(--text-faint)]">Open incidents</h3>
              <div className="text-3xl font-semibold">{d.problems.filter((p) => !["resolved", "closed"].includes(p.status)).length}</div>
              <p className="mt-1 text-xs text-[var(--text-muted)]">See the Problems tab for detail.</p>
            </Card>
          </div>
          {d.recommendations.length > 0 && (
            <div className="md:col-span-2 lg:col-span-3">
              <h3 className="mb-2 mt-2 text-sm font-semibold">Recommendations</h3>
              <div className="space-y-2">
                {d.recommendations.map((r, i) => (
                  <Card key={i} className="p-4">
                    <div className="flex items-center gap-2">
                      <Badge tone="info">{label(r.rule_key)}</Badge>
                      {r.approval_required && <Badge tone="warn">Approval required</Badge>}
                      <Badge tone="neutral">{r.is_ai_generated ? "AI suggestion" : "Rule-based"}</Badge>
                    </div>
                    <p className="mt-2 text-sm font-medium">{r.detected}</p>
                    <p className="mt-1 text-sm text-[var(--text-muted)]">{r.why}</p>
                    <p className="mt-1 text-sm"><span className="text-[var(--text-faint)]">Investigate: </span>{r.suggested_investigation}</p>
                    {r.evidence && <p className="mt-1 text-xs text-[var(--text-faint)]">Evidence: {r.evidence}</p>}
                  </Card>
                ))}
              </div>
            </div>
          )}
        </div>
      )}

      {tab === "Instantly" && <InstantlyPanel batch={h} onChanged={reload} />}

      {tab === "Accounts" && (
        <AccountsTab exceptions={d.account_exceptions} />
      )}

      {tab === "Domains" && (
        <Table>
          <thead><tr><Th>Domain</Th><Th>SPF</Th><Th>DKIM</Th><Th>DMARC</Th><Th>MX</Th><Th>Last verified</Th></tr></thead>
          <tbody>
            {d.domains.map((dm) => (
              <tr key={dm.id}>
                <Td className="font-medium">{dm.name}</Td>
                <Td><Badge tone={tone(dm.spf_status ?? "unknown")}>{label(dm.spf_status ?? "unknown")}</Badge></Td>
                <Td><Badge tone={tone(dm.dkim_status ?? "unknown")}>{label(dm.dkim_status ?? "unknown")}</Badge></Td>
                <Td><Badge tone={tone(dm.dmarc_status ?? "unknown")}>{label(dm.dmarc_status ?? "unknown")}</Badge></Td>
                <Td><Badge tone={tone(dm.mx_status ?? "unknown")}>{label(dm.mx_status ?? "unknown")}</Badge></Td>
                <Td className="text-[var(--text-muted)]">{date(dm.last_verified_at)}</Td>
              </tr>
            ))}
            {d.domains.length === 0 && <tr><Td className="py-6 text-center text-[var(--text-muted)]">No domains recorded.</Td></tr>}
          </tbody>
        </Table>
      )}

      {tab === "Lifecycle" && (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <Card className="p-4">
            <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-[var(--text-faint)]">Warmup</h3>
            <Metric k="Status" v={<Badge tone={tone(d.lifecycle.warmup.status)}>{label(d.lifecycle.warmup.status)}</Badge>} />
            <Metric k="Start" v={date(d.lifecycle.warmup.start)} />
            <Metric k="End" v={date(d.lifecycle.warmup.end)} />
            <Metric k="Days" v={d.lifecycle.warmup.days} />
            <Metric k="Elapsed" v={dash(d.lifecycle.warmup.elapsed)} />
            <Metric k="Remaining" v={dash(d.lifecycle.warmup.remaining)} />
          </Card>
          <Card className="p-4">
            <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-[var(--text-faint)]">Active lifecycle</h3>
            <Metric k="Active start" v={date(d.lifecycle.active.active_start)} />
            <Metric k="Active age (days)" v={dash(d.lifecycle.active.active_age)} />
            <Metric k="Lifecycle days" v={d.lifecycle.active.lifecycle_days} />
            <Metric k="Replacement due" v={date(d.lifecycle.active.replacement_due)} />
            <Metric k="Days until replacement" v={dash(d.lifecycle.active.days_until_replacement)} />
          </Card>
          <Card className="p-4 md:col-span-2">
            <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-[var(--text-faint)]">Ramp schedule (editable per batch — not universally safe limits)</h3>
            <div className="flex flex-wrap gap-2">
              {d.ramp_schedules.map((r) => (
                <div key={r.id} className="rounded-lg border border-[var(--border)] px-3 py-2 text-sm">
                  <div className="text-xs text-[var(--text-faint)]">Week {r.week_no}</div>
                  <div className="font-medium">{r.daily_limit_per_account}/acct/day</div>
                  <Badge tone={r.approved ? "ok" : "neutral"}>{r.approved ? "Approved" : "Draft"}</Badge>
                </div>
              ))}
            </div>
          </Card>
        </div>
      )}

      {tab === "Daily Activity" && (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <ActivityBlock title="Last 7 days" a={d.activity.last_7_days} />
          <ActivityBlock title="Last 30 days" a={d.activity.last_30_days} />
          <p className="md:col-span-2 text-sm text-[var(--text-muted)]">
            Record or import daily figures from the Daily Activity section.
          </p>
        </div>
      )}

      {tab === "Deliverability" && (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <Card className="p-4">
            <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-[var(--text-faint)]">Four independent dimensions</h3>
            <Metric k="Infrastructure" v={<Badge tone={tone(d.deliverability.infrastructure)}>{label(d.deliverability.infrastructure)}</Badge>} />
            <Metric k="Account connectivity" v={<Badge tone={tone(d.deliverability.account_connectivity)}>{label(d.deliverability.account_connectivity)}</Badge>} />
            <Metric k="Sender reputation" v={<Badge tone={tone(d.deliverability.sender_reputation)}>{label(d.deliverability.sender_reputation)}</Badge>} />
            <Metric k="Inbox placement" v={<Badge tone={tone(d.deliverability.inbox_placement)}>{label(d.deliverability.inbox_placement)}</Badge>} />
          </Card>
          <Card className="p-4">
            <p className="text-sm text-[var(--text-muted)]">
              Account health is tracked separately from inbox placement. A perfect warmup score never implies good placement, and workspace-level placement is not copied here.
            </p>
          </Card>
        </div>
      )}

      {tab === "Placement" && (
        <Table>
          <thead><tr><Th>Date</Th><Th>Scope</Th><Th>Provider</Th><Th>Inbox %</Th><Th>Spam %</Th><Th>Evidence</Th></tr></thead>
          <tbody>
            {d.placement_tests.map((p) => (
              <tr key={p.id}>
                <Td>{date(p.test_date)}</Td>
                <Td><Badge tone="neutral">{label(p.scope)}</Badge></Td>
                <Td>{dash(p.provider)}</Td>
                <Td className="tabular-nums">{pct(p.inbox_pct)}</Td>
                <Td className="tabular-nums">{pct(p.spam_pct)}</Td>
                <Td className="max-w-[320px] truncate text-xs text-[var(--text-muted)]">{dash(p.evidence)}</Td>
              </tr>
            ))}
            {d.placement_tests.length === 0 && <tr><Td className="py-6 text-center text-[var(--text-muted)]">No batch-scoped placement tests.</Td></tr>}
          </tbody>
        </Table>
      )}

      {tab === "Problems" && (
        <Table>
          <thead><tr><Th>Code</Th><Th>Severity</Th><Th>Status</Th><Th>Symptom</Th><Th>Detected</Th></tr></thead>
          <tbody>
            {d.problems.map((p) => (
              <tr key={p.id}>
                <Td className="font-medium">{p.problem_code}</Td>
                <Td><Badge tone={tone(p.severity)}>{label(p.severity)}</Badge></Td>
                <Td><Badge tone={tone(p.status)}>{label(p.status)}</Badge></Td>
                <Td className="max-w-[420px] truncate">{p.symptom}</Td>
                <Td className="text-[var(--text-muted)]">{dateTime(p.detected_at)}</Td>
              </tr>
            ))}
            {d.problems.length === 0 && <tr><Td className="py-6 text-center text-[var(--text-muted)]">No problems recorded.</Td></tr>}
          </tbody>
        </Table>
      )}

      {tab === "Rotation" && (
        <Card className="p-5">
          {d.rotation_plan ? (
            <div>
              <Metric k="Status" v={<Badge tone="info">{label(d.rotation_plan.status)}</Badge>} />
              <Metric k="Overlap start" v={date(d.rotation_plan.overlap_start)} />
              <Metric k="Overlap end" v={date(d.rotation_plan.overlap_end)} />
            </div>
          ) : (
            <EmptyState title="No rotation plan" hint="Create one from the Rotation Planner when this batch becomes a candidate." />
          )}
        </Card>
      )}

      {tab === "History" && (
        <div className="space-y-2">
          {d.lifecycle_history.map((e) => (
            <div key={e.id} className="flex items-center gap-3 rounded-lg border border-[var(--border)] bg-[var(--surface)] px-4 py-2 text-sm">
              <Badge tone={tone(e.phase)}>{label(e.phase)}</Badge>
              {e.from_phase && <span className="text-xs text-[var(--text-faint)]">from {label(e.from_phase)}</span>}
              <span className="ml-auto text-xs text-[var(--text-muted)]">{dateTime(e.entered_at)}</span>
              {e.note && <span className="text-xs text-[var(--text-muted)]">· {e.note}</span>}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

function AccountsTab({ exceptions }: { exceptions: Detail["account_exceptions"] }) {
  if (exceptions.length === 0) {
    return <EmptyState title="No account exceptions" hint="Only mailbox exceptions are shown here — not the full account list." />;
  }
  return (
    <Table>
      <thead><tr><Th>Mailbox</Th><Th>Type</Th><Th>Severity</Th><Th>Status</Th><Th>Symptom</Th><Th>Sample dialog</Th><Th>Detected</Th></tr></thead>
      <tbody>
        {exceptions.map((e) => (
          <tr key={e.id}>
            <Td className="font-medium">{e.email_account?.email ?? "—"}</Td>
            <Td><Badge tone="neutral">{label(e.type)}</Badge></Td>
            <Td><Badge tone={tone(e.severity)}>{label(e.severity)}</Badge></Td>
            <Td><Badge tone={tone(e.status)}>{label(e.status)}</Badge></Td>
            <Td className="max-w-[320px] truncate">{e.symptom}</Td>
            <Td className="max-w-[280px] truncate text-xs text-[var(--text-muted)]">{dash(e.sample_dialog)}</Td>
            <Td className="text-[var(--text-muted)]">{date(e.detected_at)}</Td>
          </tr>
        ))}
      </tbody>
    </Table>
  );
}
