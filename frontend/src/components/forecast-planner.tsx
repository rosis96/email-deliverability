"use client";

import { useMemo, useState, type ReactNode } from "react";
import { Badge, Button, Card, Select, Table, Td, Th } from "./ui";
import { Icon } from "./icons";
import { dateTime } from "@/lib/format";
import {
  KIND_LABEL,
  fmtInt,
  fmtNum,
  fmtPct,
  fmtUsd,
  infrastructure,
  newLineId,
  positiveRepliesNeeded,
  scenario,
  scenarios,
  validate,
  type LiveSnapshot,
  type PlanInputs,
  type ProviderKind,
  type ProviderLine,
} from "@/lib/forecast";

/* ------------------------------------------------------------------ */
/* Small inputs                                                        */
/* ------------------------------------------------------------------ */

const cellInput =
  "w-full rounded-md border border-[var(--border-strong)] bg-[var(--surface)] px-2 py-1 text-sm tabular-nums text-[var(--text)] outline-none focus:border-[var(--accent)] disabled:cursor-not-allowed disabled:opacity-60";

/** Numeric input that keeps the typed text ("0.", "") while reporting numbers. */
function NumInput({
  value,
  onChange,
  disabled,
  step = "any",
  min = 0,
  className = "",
  suffix,
}: {
  value: number;
  onChange: (v: number) => void;
  disabled?: boolean;
  step?: string;
  min?: number;
  className?: string;
  suffix?: string;
}) {
  const [prev, setPrev] = useState(value);
  const [text, setText] = useState(String(value));
  if (value !== prev) {
    setPrev(value);
    setText(String(value));
  }
  return (
    <div className={`relative ${className}`}>
      <input
        type="number"
        inputMode="decimal"
        step={step}
        min={min}
        disabled={disabled}
        value={text}
        onChange={(e) => {
          setText(e.target.value);
          const v = parseFloat(e.target.value);
          const next = Number.isFinite(v) ? v : 0;
          setPrev(next);
          onChange(next);
        }}
        className={`${cellInput} ${suffix ? "pr-7" : ""}`}
      />
      {suffix && (
        <span className="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-xs text-[var(--text-faint)]">
          {suffix}
        </span>
      )}
    </div>
  );
}

function Section({
  title,
  subtitle,
  actions,
  children,
}: {
  title: string;
  subtitle?: ReactNode;
  actions?: ReactNode;
  children: ReactNode;
}) {
  return (
    <section className="mb-8">
      <div className="mb-3 flex flex-wrap items-end justify-between gap-2">
        <div>
          <h2 className="text-base font-semibold">{title}</h2>
          {subtitle && <p className="mt-0.5 text-xs text-[var(--text-muted)]">{subtitle}</p>}
        </div>
        {actions}
      </div>
      {children}
    </section>
  );
}

function Metric({ label, value, hint, tone }: { label: string; value: ReactNode; hint?: ReactNode; tone?: "ok" | "warn" | "bad" }) {
  const color = tone === "bad" ? "text-[var(--bad)]" : tone === "warn" ? "text-[var(--warn)]" : tone === "ok" ? "text-[var(--ok)]" : "";
  return (
    <Card className="p-3.5">
      <div className="text-[11px] font-medium uppercase tracking-wide text-[var(--text-faint)]">{label}</div>
      <div className={`mt-1 text-xl font-semibold tabular-nums ${color}`}>{value}</div>
      {hint && <div className="mt-0.5 text-xs text-[var(--text-muted)]">{hint}</div>}
    </Card>
  );
}

/* ------------------------------------------------------------------ */
/* Planner                                                             */
/* ------------------------------------------------------------------ */

export function ForecastPlanner({
  name,
  draft,
  saved,
  hasSavedPlan,
  live,
  updatedAt,
  updatedBy,
  canEdit,
  saving,
  onChange,
  onSave,
  onReset,
}: {
  name: string;
  draft: PlanInputs;
  saved: PlanInputs;
  hasSavedPlan: boolean;
  live: LiveSnapshot | null;
  updatedAt: string | null;
  updatedBy: string | null;
  canEdit: boolean;
  saving: boolean;
  onChange: (next: PlanInputs) => void;
  onSave: () => void;
  onReset: () => void;
}) {
  const p = draft;
  const ro = !canEdit;
  const dirty = useMemo(() => JSON.stringify(draft) !== JSON.stringify(saved), [draft, saved]);
  const issues = useMemo(() => validate(p), [p]);
  const infra = useMemo(() => infrastructure(p), [p]);
  const rows = useMemo(() => scenarios(p), [p]);
  const sel = useMemo(() => scenario(p, p.selected_reply_rate, infra), [p, infra]);
  const savedSel = useMemo(() => scenario(saved, saved.selected_reply_rate), [saved]);
  const replies = positiveRepliesNeeded(p);
  const [newRate, setNewRate] = useState("");

  const set = <K extends keyof PlanInputs>(k: K, v: PlanInputs[K]) => onChange({ ...p, [k]: v });
  const setLine = (id: string, patch: Partial<ProviderLine>) =>
    set("providers", p.providers.map((l) => (l.id === id ? { ...l, ...patch } : l)));
  const removeLine = (id: string) => set("providers", p.providers.filter((l) => l.id !== id));
  const addLine = () =>
    set("providers", [
      ...p.providers,
      { id: newLineId(), name: "New provider", kind: "google", cost_per_account: 0, daily_limit: 15, mix_pct: 0, active: 0, backup: 0, disconnected: 0 },
    ]);

  const mixTotal = p.providers.reduce((a, l) => a + (l.mix_pct || 0), 0);
  const mixByKind = p.providers.reduce<Record<ProviderKind, number>>(
    (a, l) => ({ ...a, [l.kind]: a[l.kind] + (l.mix_pct || 0) }),
    { google: 0, microsoft: 0, smtp: 0, other: 0 },
  );
  /** Scale every Google line so Google totals `pct`, other lines share the rest proportionally. */
  const setGoogleShare = (pct: number) => {
    const g = p.providers.filter((l) => l.kind === "google");
    const o = p.providers.filter((l) => l.kind !== "google");
    if (!g.length || !o.length) return;
    const gSum = g.reduce((a, l) => a + l.mix_pct, 0);
    const oSum = o.reduce((a, l) => a + l.mix_pct, 0);
    const scale = (lines: ProviderLine[], sum: number, target: number) =>
      new Map(lines.map((l) => [l.id, sum > 0 ? (l.mix_pct / sum) * target : target / lines.length]));
    const gm = scale(g, gSum, pct);
    const om = scale(o, oSum, 100 - pct);
    set(
      "providers",
      p.providers.map((l) => ({ ...l, mix_pct: Math.round(((gm.get(l.id) ?? om.get(l.id)) || 0) * 10) / 10 })),
    );
  };

  const addRate = () => {
    const v = parseFloat(newRate);
    if (!Number.isFinite(v) || v <= 0 || v > 100 || p.reply_rates.includes(v)) return;
    set("reply_rates", [...p.reply_rates, v].sort((a, b) => a - b));
    setNewRate("");
  };

  const delta = (a: number, b: number, money = false) => {
    const d = a - b;
    if (Math.abs(d) < 0.005) return <span className="text-[var(--text-faint)]">no change</span>;
    const txt = money ? fmtUsd(Math.abs(d)) : fmtInt(Math.abs(d));
    return <span className={d > 0 ? "text-[var(--bad)]" : "text-[var(--ok)]"}>{d > 0 ? "+" : "−"}{txt}</span>;
  };

  return (
    <div>
      {/* Save bar */}
      <div className="sticky top-14 z-[5] -mx-6 mb-6 flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] bg-[var(--bg)] px-6 py-3 backdrop-blur">
        <div className="flex flex-wrap items-center gap-2 text-sm">
          <span className="font-semibold">{name}</span>
          {!hasSavedPlan && <Badge tone="warn">No saved plan — showing defaults</Badge>}
          {dirty && <Badge tone="info">Unsaved changes</Badge>}
          {updatedAt && (
            <span className="text-xs text-[var(--text-faint)]">
              Saved {dateTime(updatedAt)}
              {updatedBy ? ` by ${updatedBy}` : ""}
            </span>
          )}
          {ro && <Badge>Read-only</Badge>}
        </div>
        {canEdit && (
          <div className="flex items-center gap-2">
            <Button variant="ghost" size="sm" onClick={onReset} disabled={!dirty || saving}>
              Discard changes
            </Button>
            <Button variant="primary" size="sm" onClick={onSave} disabled={(!dirty && hasSavedPlan) || saving || issues.length > 0}>
              {saving ? "Saving…" : "Save plan"}
            </Button>
          </div>
        )}
      </div>

      {issues.length > 0 && (
        <div className="mb-6 rounded-lg border border-[var(--warn)] bg-[var(--warn-bg)] px-4 py-3 text-sm text-[var(--warn)]">
          {issues.map((i) => (
            <div key={i}>• {i}</div>
          ))}
        </div>
      )}

      {/* Draft vs saved comparison */}
      {dirty && hasSavedPlan && (
        <Card className="mb-6 p-4">
          <div className="mb-2 text-xs font-semibold uppercase tracking-wide text-[var(--text-faint)]">
            This draft vs the saved plan (at the selected reply rate)
          </div>
          <div className="grid grid-cols-2 gap-3 text-sm md:grid-cols-5">
            <div>Accounts required<div className="font-semibold tabular-nums">{fmtInt(savedSel.required_total)} → {fmtInt(sel.required_total)} {delta(sel.required_total, savedSel.required_total)}</div></div>
            <div>Additional accounts<div className="font-semibold tabular-nums">{fmtInt(savedSel.additional)} → {fmtInt(sel.additional)} {delta(sel.additional, savedSel.additional)}</div></div>
            <div>Total monthly cost<div className="font-semibold tabular-nums">{fmtUsd(savedSel.total_cost)} → {fmtUsd(sel.total_cost)} {delta(sel.total_cost, savedSel.total_cost, true)}</div></div>
            <div>Cost per meeting<div className="font-semibold tabular-nums">{fmtUsd(savedSel.cost_per_meeting)} → {fmtUsd(sel.cost_per_meeting)}</div></div>
            <div>Prospects / month<div className="font-semibold tabular-nums">{fmtInt(savedSel.prospects)} → {fmtInt(sel.prospects)} {delta(sel.prospects, savedSel.prospects)}</div></div>
          </div>
        </Card>
      )}

      {/* 1. Infrastructure */}
      <Section
        title="1. Email infrastructure"
        subtitle="What this workspace runs today. Backups are warmed accounts held in reserve (not sending). Cost counts every account you pay for, including disconnected ones."
        actions={canEdit && <Button size="sm" onClick={addLine}><Icon name="plus" size={14} /> Add provider / vendor</Button>}
      >
        <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
          <Metric label="Total accounts" value={fmtInt(infra.total)} hint={`${fmtInt(infra.active)} active · ${fmtInt(infra.backup)} backup · ${fmtInt(infra.disconnected)} disconnected`} />
          <Metric label="Monthly cost" value={fmtUsd(infra.monthly_cost)} hint={`avg ${fmtUsd(infra.avg_cost_per_account, 2)} / account`} />
          <Metric label="Daily capacity" value={fmtInt(infra.daily_capacity)} hint="emails / day, active accounts" />
          <Metric label="Monthly capacity" value={fmtInt(infra.monthly_capacity)} hint={`${p.sending_days_per_month} sending days`} />
          <Metric label="Google / MS / SMTP" value={`${fmtInt(infra.by_kind.google)} / ${fmtInt(infra.by_kind.microsoft)} / ${fmtInt(infra.by_kind.smtp + infra.by_kind.other)}`} hint="accounts by provider type" />
          <Metric
            label="Instantly (last sync)"
            value={live ? fmtInt(live.total) : "—"}
            hint={
              live
                ? `${fmtInt(live.active)} active · ${fmtInt(live.paused)} paused · ${fmtInt(live.error)} error`
                : "No synced accounts for this workspace"
            }
          />
        </div>

        <Table>
          <thead>
            <tr>
              <Th>Provider / vendor</Th>
              <Th>Type</Th>
              <Th className="text-right">$ / account / mo</Th>
              <Th className="text-right">Daily limit / acct</Th>
              <Th className="text-right">Active</Th>
              <Th className="text-right">Backup</Th>
              <Th className="text-right">Disconnected</Th>
              <Th className="text-right">Total</Th>
              <Th className="text-right">Monthly cost</Th>
              <Th className="text-right">Monthly / acct</Th>
              <Th className="text-right">Daily cap</Th>
              <Th className="text-right">Monthly cap</Th>
              {canEdit && <Th />}
            </tr>
          </thead>
          <tbody>
            {p.providers.map((l, i) => {
              const li = infra.lines[i];
              return (
                <tr key={l.id}>
                  <Td className="min-w-[200px]">
                    <input className={cellInput} value={l.name} disabled={ro} onChange={(e) => setLine(l.id, { name: e.target.value })} />
                  </Td>
                  <Td className="min-w-[120px]">
                    <select className={cellInput} value={l.kind} disabled={ro} onChange={(e) => setLine(l.id, { kind: e.target.value as ProviderKind })}>
                      {(Object.keys(KIND_LABEL) as ProviderKind[]).map((k) => (
                        <option key={k} value={k}>{KIND_LABEL[k]}</option>
                      ))}
                    </select>
                  </Td>
                  <Td className="w-28"><NumInput value={l.cost_per_account} disabled={ro} onChange={(v) => setLine(l.id, { cost_per_account: v })} /></Td>
                  <Td className="w-24"><NumInput value={l.daily_limit} step="1" disabled={ro} onChange={(v) => setLine(l.id, { daily_limit: v })} /></Td>
                  <Td className="w-24"><NumInput value={l.active} step="1" disabled={ro} onChange={(v) => setLine(l.id, { active: Math.round(v) })} /></Td>
                  <Td className="w-24"><NumInput value={l.backup} step="1" disabled={ro} onChange={(v) => setLine(l.id, { backup: Math.round(v) })} /></Td>
                  <Td className="w-24"><NumInput value={l.disconnected} step="1" disabled={ro} onChange={(v) => setLine(l.id, { disconnected: Math.round(v) })} /></Td>
                  <Td className="text-right tabular-nums font-medium">{fmtInt(li.total)}</Td>
                  <Td className="text-right tabular-nums">{fmtUsd(li.monthly_cost)}</Td>
                  <Td className="text-right tabular-nums">{fmtInt(li.monthly_per_account)}</Td>
                  <Td className="text-right tabular-nums">{fmtInt(li.daily_capacity)}</Td>
                  <Td className="text-right tabular-nums">{fmtInt(li.monthly_capacity)}</Td>
                  {canEdit && (
                    <Td>
                      <button title="Remove line" onClick={() => removeLine(l.id)} className="rounded p-1 text-[var(--text-faint)] hover:bg-[var(--bad-bg)] hover:text-[var(--bad)]">
                        <Icon name="x" size={14} />
                      </button>
                    </Td>
                  )}
                </tr>
              );
            })}
            <tr className="bg-[var(--surface-2)] font-semibold">
              <Td>Total</Td>
              <Td />
              <Td className="text-right tabular-nums">{fmtUsd(infra.avg_cost_per_account, 2)} avg</Td>
              <Td />
              <Td className="text-right tabular-nums">{fmtInt(infra.active)}</Td>
              <Td className="text-right tabular-nums">{fmtInt(infra.backup)}</Td>
              <Td className="text-right tabular-nums">{fmtInt(infra.disconnected)}</Td>
              <Td className="text-right tabular-nums">{fmtInt(infra.total)}</Td>
              <Td className="text-right tabular-nums">{fmtUsd(infra.monthly_cost)}</Td>
              <Td />
              <Td className="text-right tabular-nums">{fmtInt(infra.daily_capacity)}</Td>
              <Td className="text-right tabular-nums">{fmtInt(infra.monthly_capacity)}</Td>
              {canEdit && <Td />}
            </tr>
          </tbody>
        </Table>
        {live && Object.keys(live.by_provider).length > 0 && (
          <p className="mt-2 text-xs text-[var(--text-faint)]">
            Instantly reference by provider:{" "}
            {Object.entries(live.by_provider).map(([k, v]) => `${k.replace("_", " ")} ${fmtInt(v)}`).join(" · ")}
            {" "}— Instantly can&apos;t tell vendors apart (Zapmail vs Peeker vs DFY), so counts above are maintained here.
          </p>
        )}
      </Section>

      {/* 2. Meeting forecast */}
      <Section title="2. Meeting forecast" subtitle="Targets and funnel assumptions. Positive reply rate is measured per unique prospect contacted.">
        <div className="grid gap-4 lg:grid-cols-[minmax(0,420px)_1fr]">
          <Card className="grid grid-cols-2 gap-3 p-4">
            <LabeledNum label="Qualified meetings / month" value={p.meeting_target} step="1" disabled={ro} onChange={(v) => set("meeting_target", v)} />
            <LabeledNum label="Positive reply → meeting" suffix="%" value={p.reply_to_meeting_pct} disabled={ro} onChange={(v) => set("reply_to_meeting_pct", v)} />
            <LabeledNum label="Emails per prospect (sequence)" value={p.emails_per_prospect} step="1" min={1} disabled={ro} onChange={(v) => set("emails_per_prospect", v)} />
            <LabeledNum label="Sending days / month" value={p.sending_days_per_month} step="1" min={1} disabled={ro} onChange={(v) => set("sending_days_per_month", v)} />
            <LabeledNum label="Backup capacity" suffix="%" value={p.backup_pct} disabled={ro} onChange={(v) => set("backup_pct", v)} />
            <label className="block">
              <div className="mb-1 text-xs font-medium text-[var(--text-muted)]">Planning reply rate</div>
              <Select value={String(p.selected_reply_rate)} disabled={ro} onChange={(e) => set("selected_reply_rate", parseFloat(e.target.value))}>
                {[...new Set([...p.reply_rates, p.selected_reply_rate])].sort((a, b) => a - b).map((r) => (
                  <option key={r} value={r}>{fmtPct(r)}</option>
                ))}
              </Select>
            </label>
          </Card>
          <div className="grid grid-cols-2 gap-3 md:grid-cols-3">
            <Metric label="Positive replies needed" value={fmtInt(replies)} hint={`${fmtInt(p.meeting_target)} meetings ÷ ${fmtNum(p.reply_to_meeting_pct)}%`} />
            <Metric label="Unique prospects / month" value={fmtInt(sel.prospects)} hint={`at ${fmtPct(p.selected_reply_rate)} positive reply rate`} />
            <Metric label="Emails / month" value={fmtInt(sel.emails)} hint={`${fmtInt(sel.daily_emails)} / sending day`} />
            <Metric label="Accounts required" value={fmtInt(sel.required_total)} hint={`${fmtInt(sel.required_active)} active + ${fmtInt(sel.required_backup)} backup`} />
            <Metric
              label="Shortfall"
              value={sel.additional > 0 ? `+${fmtInt(sel.additional)}` : "None"}
              tone={sel.additional > 0 ? "bad" : "ok"}
              hint={sel.additional > 0 ? `${fmtUsd(sel.additional_cost)} / mo more` : "Current infrastructure covers it"}
            />
            <Metric
              label="Capacity coverage"
              value={`${fmtNum(sel.coverage_pct, 0)}%`}
              tone={sel.coverage_pct >= 100 ? "ok" : sel.coverage_pct >= 70 ? "warn" : "bad"}
              hint={`≈ ${fmtNum(sel.meetings_current)} meetings from today's capacity`}
            />
          </div>
        </div>
      </Section>

      {/* 3. Infrastructure planning */}
      <Section
        title="3. Infrastructure planning"
        subtitle={`How new sending accounts are split across providers, at the planning reply rate (${fmtPct(p.selected_reply_rate)}). Daily limits and prices are edited in section 1.`}
      >
        <Card className="mb-3 flex flex-wrap items-center gap-x-6 gap-y-3 p-4 text-sm">
          <div>
            Mix by type:{" "}
            <span className="font-medium">
              Google {fmtNum(mixByKind.google)}% · Microsoft {fmtNum(mixByKind.microsoft)}% · SMTP/other {fmtNum(mixByKind.smtp + mixByKind.other)}%
            </span>
          </div>
          {canEdit && p.providers.some((l) => l.kind === "google") && p.providers.some((l) => l.kind !== "google") && (
            <label className="flex items-center gap-3">
              <span className="text-xs text-[var(--text-muted)]">Google share</span>
              <input
                type="range"
                min={0}
                max={100}
                step={5}
                value={Math.round(mixByKind.google)}
                onChange={(e) => setGoogleShare(parseFloat(e.target.value))}
                className="w-48 accent-[var(--accent)]"
              />
              <span className="w-10 tabular-nums">{Math.round(mixByKind.google)}%</span>
            </label>
          )}
          <div className={Math.abs(mixTotal - 100) > 0.5 ? "font-medium text-[var(--bad)]" : "text-[var(--text-muted)]"}>
            Total mix: {fmtNum(mixTotal)}%
          </div>
        </Card>
        <Table>
          <thead>
            <tr>
              <Th>Provider / vendor</Th>
              <Th className="text-right">Mix %</Th>
              <Th className="text-right">Required active</Th>
              <Th className="text-right">Required backup</Th>
              <Th className="text-right">Have (active / backup)</Th>
              <Th className="text-right">Additional</Th>
              <Th className="text-right">Additional cost / mo</Th>
            </tr>
          </thead>
          <tbody>
            {p.providers.map((l, i) => {
              const s = sel.lines[i];
              return (
                <tr key={l.id}>
                  <Td>
                    {l.name} <span className="text-xs text-[var(--text-faint)]">{KIND_LABEL[l.kind]}</span>
                  </Td>
                  <Td className="w-28"><NumInput value={l.mix_pct} suffix="%" disabled={ro} onChange={(v) => setLine(l.id, { mix_pct: v })} /></Td>
                  <Td className="text-right tabular-nums">{fmtInt(s.required_active)}</Td>
                  <Td className="text-right tabular-nums">{fmtInt(s.required_backup)}</Td>
                  <Td className="text-right tabular-nums text-[var(--text-muted)]">{fmtInt(l.active)} / {fmtInt(l.backup)}</Td>
                  <Td className={`text-right tabular-nums font-medium ${s.additional > 0 ? "text-[var(--bad)]" : ""}`}>{s.additional > 0 ? `+${fmtInt(s.additional)}` : "0"}</Td>
                  <Td className="text-right tabular-nums">{fmtUsd(s.additional_cost)}</Td>
                </tr>
              );
            })}
            <tr className="bg-[var(--surface-2)] font-semibold">
              <Td>Total</Td>
              <Td className="text-right tabular-nums">{fmtNum(mixTotal)}%</Td>
              <Td className="text-right tabular-nums">{fmtInt(sel.required_active)}</Td>
              <Td className="text-right tabular-nums">{fmtInt(sel.required_backup)}</Td>
              <Td className="text-right tabular-nums">{fmtInt(infra.active)} / {fmtInt(infra.backup)}</Td>
              <Td className="text-right tabular-nums">{sel.additional > 0 ? `+${fmtInt(sel.additional)}` : "0"}</Td>
              <Td className="text-right tabular-nums">{fmtUsd(sel.additional_cost)}</Td>
            </tr>
          </tbody>
        </Table>
      </Section>

      {/* 4. Scenarios */}
      <Section
        title="4. Positive reply rate scenarios"
        subtitle="Everything needed to hit the meeting target at each reply rate. Click a row to make it the planning rate."
        actions={
          canEdit && (
            <div className="flex flex-wrap items-center gap-1.5">
              {p.reply_rates.map((r) => (
                <span key={r} className="inline-flex items-center gap-1 rounded-md bg-[var(--surface-2)] px-2 py-0.5 text-xs">
                  {fmtPct(r)}
                  {p.reply_rates.length > 1 && (
                    <button
                      title="Remove rate"
                      onClick={() => {
                        const next = p.reply_rates.filter((x) => x !== r);
                        onChange({ ...p, reply_rates: next, selected_reply_rate: p.selected_reply_rate === r ? next[0] : p.selected_reply_rate });
                      }}
                      className="text-[var(--text-faint)] hover:text-[var(--bad)]"
                    >
                      <Icon name="x" size={11} />
                    </button>
                  )}
                </span>
              ))}
              <input
                value={newRate}
                onChange={(e) => setNewRate(e.target.value)}
                onKeyDown={(e) => e.key === "Enter" && addRate()}
                placeholder="add %"
                className={`${cellInput} !w-20`}
              />
              <Button size="sm" onClick={addRate}>Add</Button>
            </div>
          )
        }
      >
        <Table>
          <thead>
            <tr>
              <Th>Reply rate</Th>
              <Th className="text-right">Unique prospects</Th>
              <Th className="text-right">Emails / month</Th>
              <Th className="text-right">Emails / day</Th>
              {p.providers.map((l) => (
                <Th key={l.id} className="text-right">
                  <span title={l.name}>{l.name.length > 18 ? l.name.slice(0, 17) + "…" : l.name}</span>
                </Th>
              ))}
              <Th className="text-right">Accounts req.</Th>
              <Th className="text-right">Backup req.</Th>
              <Th className="text-right">Additional</Th>
              <Th className="text-right">Additional cost</Th>
              <Th className="text-right">Total cost / mo</Th>
              <Th className="text-right">Meetings (today)</Th>
              <Th className="text-right">Meetings (planned)</Th>
              <Th className="text-right">Cost / meeting</Th>
            </tr>
          </thead>
          <tbody>
            {rows.map((s) => {
              const active = s.reply_rate === p.selected_reply_rate;
              return (
                <tr
                  key={s.reply_rate}
                  onClick={() => !ro && set("selected_reply_rate", s.reply_rate)}
                  className={`${ro ? "" : "cursor-pointer"} ${active ? "bg-[var(--info-bg)]" : "hover:bg-[var(--surface-2)]"}`}
                >
                  <Td className="font-semibold">{fmtPct(s.reply_rate)} {active && <Badge tone="info">planning</Badge>}</Td>
                  <Td className="text-right tabular-nums">{fmtInt(s.prospects)}</Td>
                  <Td className="text-right tabular-nums">{fmtInt(s.emails)}</Td>
                  <Td className="text-right tabular-nums">{fmtInt(s.daily_emails)}</Td>
                  {s.lines.map((ls) => (
                    <Td key={ls.id} className="text-right tabular-nums">
                      {fmtInt(ls.required_active + ls.required_backup)}
                      {ls.additional > 0 && <span className="ml-1 text-xs text-[var(--bad)]">+{fmtInt(ls.additional)}</span>}
                    </Td>
                  ))}
                  <Td className="text-right tabular-nums font-medium">{fmtInt(s.required_active)}</Td>
                  <Td className="text-right tabular-nums">{fmtInt(s.required_backup)}</Td>
                  <Td className={`text-right tabular-nums font-medium ${s.additional > 0 ? "text-[var(--bad)]" : "text-[var(--ok)]"}`}>
                    {s.additional > 0 ? `+${fmtInt(s.additional)}` : "0"}
                  </Td>
                  <Td className="text-right tabular-nums">{fmtUsd(s.additional_cost)}</Td>
                  <Td className="text-right tabular-nums font-medium">{fmtUsd(s.total_cost)}</Td>
                  <Td className="text-right tabular-nums">{fmtNum(s.meetings_current)}</Td>
                  <Td className="text-right tabular-nums">{fmtNum(s.meetings_planned)}</Td>
                  <Td className="text-right tabular-nums">{fmtUsd(s.cost_per_meeting, 2)}</Td>
                </tr>
              );
            })}
          </tbody>
        </Table>
        <p className="mt-2 text-xs text-[var(--text-faint)]">
          Provider columns show accounts required (active + backup) with the shortfall in red. &quot;Meetings (today)&quot; is what current active
          capacity would book at that rate; &quot;Meetings (planned)&quot; is after adding the required accounts. Cost per meeting = total
          monthly infrastructure cost ÷ planned meetings.
        </p>
      </Section>

      <Section title="Notes">
        <textarea
          value={p.notes ?? ""}
          disabled={ro}
          onChange={(e) => set("notes", e.target.value || null)}
          rows={3}
          placeholder="Assumptions, vendor quotes, decisions…"
          className={`${cellInput} min-h-[80px]`}
        />
      </Section>
    </div>
  );
}

function LabeledNum({
  label,
  value,
  onChange,
  disabled,
  suffix,
  step,
  min,
}: {
  label: string;
  value: number;
  onChange: (v: number) => void;
  disabled?: boolean;
  suffix?: string;
  step?: string;
  min?: number;
}) {
  return (
    <label className="block">
      <div className="mb-1 text-xs font-medium text-[var(--text-muted)]">{label}</div>
      <NumInput value={value} onChange={onChange} disabled={disabled} suffix={suffix} step={step} min={min} />
    </label>
  );
}
