"use client";

import { useMemo } from "react";
import { Badge, Card, StatCard, Table, Td, Th } from "./ui";
import {
  fmtInt,
  fmtNum,
  fmtPct,
  fmtUsd,
  infrastructure,
  normalizeInputs,
  scenario,
  type PlanRow,
} from "@/lib/forecast";

/** Consolidated view across workspaces, each at its own planning reply rate. */
export function ForecastOverview({ rows, onOpen }: { rows: PlanRow[]; onOpen: (workspaceId: string) => void }) {
  const items = useMemo(
    () =>
      rows
        .filter((r) => r.counts_toward_totals || r.inputs)
        .map((r) => {
          if (!r.inputs) return { row: r, plan: null };
          const p = normalizeInputs(r.inputs);
          const infra = infrastructure(p);
          return { row: r, plan: { p, infra, s: scenario(p, p.selected_reply_rate, infra) } };
        }),
    [rows],
  );

  const planned = items.filter((i) => i.plan).map((i) => i.plan!);
  const t = planned.reduce(
    (a, { infra, s, p }) => ({
      accounts: a.accounts + infra.total,
      active: a.active + infra.active,
      backup: a.backup + infra.backup,
      disconnected: a.disconnected + infra.disconnected,
      cost: a.cost + infra.monthly_cost,
      daily: a.daily + infra.daily_capacity,
      monthly: a.monthly + infra.monthly_capacity,
      target: a.target + p.meeting_target,
      emails: a.emails + s.emails,
      prospects: a.prospects + s.prospects,
      required: a.required + s.required_total,
      additional: a.additional + s.additional,
      additionalCost: a.additionalCost + s.additional_cost,
      totalCost: a.totalCost + s.total_cost,
      meetingsToday: a.meetingsToday + s.meetings_current,
      short: a.short + (s.additional > 0 ? 1 : 0),
    }),
    { accounts: 0, active: 0, backup: 0, disconnected: 0, cost: 0, daily: 0, monthly: 0, target: 0, emails: 0, prospects: 0, required: 0, additional: 0, additionalCost: 0, totalCost: 0, meetingsToday: 0, short: 0 },
  );
  const missing = items.filter((i) => !i.plan).length;

  return (
    <div>
      <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        <StatCard label="Total accounts" value={fmtInt(t.accounts)} hint={`${fmtInt(t.active)} active · ${fmtInt(t.backup)} backup · ${fmtInt(t.disconnected)} disc.`} />
        <StatCard label="Monthly infra cost" value={fmtUsd(t.cost)} hint={`${fmtUsd(t.totalCost)} once shortfalls are filled`} />
        <StatCard label="Sending capacity" value={fmtInt(t.monthly)} hint={`${fmtInt(t.daily)} emails / day`} />
        <StatCard label="Meeting target" value={fmtInt(t.target)} hint={`≈ ${fmtNum(t.meetingsToday)} reachable with today's capacity`} tone={t.meetingsToday >= t.target ? "ok" : "warn"} />
        <StatCard label="Emails needed" value={fmtInt(t.emails)} hint={`${fmtInt(t.prospects)} unique prospects / month`} tone={t.monthly >= t.emails ? "ok" : "bad"} />
        <StatCard
          label="Shortfall"
          value={t.additional > 0 ? `+${fmtInt(t.additional)}` : "None"}
          hint={t.additional > 0 ? `${fmtUsd(t.additionalCost)} / mo across ${t.short} workspace(s)` : "All planned workspaces covered"}
          tone={t.additional > 0 ? "bad" : "ok"}
        />
      </div>

      {missing > 0 && (
        <Card className="mb-4 px-4 py-3 text-sm text-[var(--text-muted)]">
          {missing} workspace(s) have no plan yet and are left out of the totals — open one to set it up.
        </Card>
      )}

      <Table>
        <thead>
          <tr>
            <Th>Workspace</Th>
            <Th className="text-right">Accounts</Th>
            <Th className="text-right">Active / backup / disc.</Th>
            <Th className="text-right">Instantly (sync)</Th>
            <Th className="text-right">Monthly cost</Th>
            <Th className="text-right">Capacity / mo</Th>
            <Th className="text-right">Meeting target</Th>
            <Th className="text-right">Planning rate</Th>
            <Th className="text-right">Prospects / mo</Th>
            <Th className="text-right">Emails needed</Th>
            <Th className="text-right">Coverage</Th>
            <Th className="text-right">Shortfall</Th>
            <Th className="text-right">Extra cost</Th>
            <Th className="text-right">Cost / meeting</Th>
          </tr>
        </thead>
        <tbody>
          {items.map(({ row, plan }) => (
            <tr key={row.workspace_id} onClick={() => onOpen(row.workspace_id)} className="cursor-pointer hover:bg-[var(--surface-2)]">
              <Td className="font-medium text-[var(--accent)]">
                {row.name} <span className="text-xs text-[var(--text-faint)]">{row.code}</span>
              </Td>
              {plan ? (
                <>
                  <Td className="text-right tabular-nums font-medium">{fmtInt(plan.infra.total)}</Td>
                  <Td className="text-right tabular-nums text-[var(--text-muted)]">
                    {fmtInt(plan.infra.active)} / {fmtInt(plan.infra.backup)} / {fmtInt(plan.infra.disconnected)}
                  </Td>
                  <Td className="text-right tabular-nums text-[var(--text-muted)]">{row.live ? fmtInt(row.live.total) : "—"}</Td>
                  <Td className="text-right tabular-nums">{fmtUsd(plan.infra.monthly_cost)}</Td>
                  <Td className="text-right tabular-nums">{fmtInt(plan.infra.monthly_capacity)}</Td>
                  <Td className="text-right tabular-nums">{fmtInt(plan.p.meeting_target)}</Td>
                  <Td className="text-right tabular-nums">{fmtPct(plan.p.selected_reply_rate)}</Td>
                  <Td className="text-right tabular-nums">{fmtInt(plan.s.prospects)}</Td>
                  <Td className="text-right tabular-nums">{fmtInt(plan.s.emails)}</Td>
                  <Td className="text-right">
                    <Badge tone={plan.s.coverage_pct >= 100 ? "ok" : plan.s.coverage_pct >= 70 ? "warn" : "bad"}>
                      {fmtNum(plan.s.coverage_pct, 0)}%
                    </Badge>
                  </Td>
                  <Td className={`text-right tabular-nums font-medium ${plan.s.additional > 0 ? "text-[var(--bad)]" : "text-[var(--ok)]"}`}>
                    {plan.s.additional > 0 ? `+${fmtInt(plan.s.additional)}` : "0"}
                  </Td>
                  <Td className="text-right tabular-nums">{fmtUsd(plan.s.additional_cost)}</Td>
                  <Td className="text-right tabular-nums">{fmtUsd(plan.s.cost_per_meeting, 2)}</Td>
                </>
              ) : (
                <>
                  <Td className="text-right text-[var(--text-faint)]" />
                  <Td className="text-right text-[var(--text-faint)]" />
                  <Td className="text-right tabular-nums text-[var(--text-muted)]">{row.live ? fmtInt(row.live.total) : "—"}</Td>
                  <td colSpan={10} className="border-b border-[var(--border)] px-3 py-2 text-xs text-[var(--text-faint)]">
                    No plan yet — click to set up
                  </td>
                </>
              )}
            </tr>
          ))}
          {planned.length > 0 && (
            <tr className="bg-[var(--surface-2)] font-semibold">
              <Td>Total ({planned.length} planned)</Td>
              <Td className="text-right tabular-nums">{fmtInt(t.accounts)}</Td>
              <Td className="text-right tabular-nums">
                {fmtInt(t.active)} / {fmtInt(t.backup)} / {fmtInt(t.disconnected)}
              </Td>
              <Td />
              <Td className="text-right tabular-nums">{fmtUsd(t.cost)}</Td>
              <Td className="text-right tabular-nums">{fmtInt(t.monthly)}</Td>
              <Td className="text-right tabular-nums">{fmtInt(t.target)}</Td>
              <Td />
              <Td className="text-right tabular-nums">{fmtInt(t.prospects)}</Td>
              <Td className="text-right tabular-nums">{fmtInt(t.emails)}</Td>
              <Td className="text-right tabular-nums">{t.emails ? `${fmtNum((t.monthly / t.emails) * 100, 0)}%` : "—"}</Td>
              <Td className="text-right tabular-nums">{t.additional > 0 ? `+${fmtInt(t.additional)}` : "0"}</Td>
              <Td className="text-right tabular-nums">{fmtUsd(t.additionalCost)}</Td>
              <Td />
            </tr>
          )}
        </tbody>
      </Table>
      <p className="mt-2 text-xs text-[var(--text-faint)]">
        Each workspace is evaluated at its own planning reply rate. Coverage = today&apos;s monthly sending capacity ÷ emails needed to hit the
        meeting target. Note: coverage pools capacity, but shortfall is per provider line, so a workspace can show both.
      </p>
    </div>
  );
}
