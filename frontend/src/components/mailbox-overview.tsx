"use client";

import { useState } from "react";
import { Badge, Card, ErrorNote, Spinner } from "@/components/ui";
import { useApi } from "@/lib/hooks";
import { dateTime, num } from "@/lib/format";

interface Stats {
  total: number;
  active: number;
  paused: number;
  error: number;
  other: number;
  warmup_on: number;
  avg_warmup_score: number | null;
  daily_capacity: number;
  error_pct: number;
  health: "ok" | "warn" | "bad" | "empty";
}
interface TagRow extends Stats {
  tag_id: string | null;
  label: string;
}
interface WsSummary {
  workspace_id: string;
  name: string;
  code: string;
  last_synced_at: string | null;
  totals: Stats;
  tags: TagRow[];
}

const healthBadge = (h: Stats["health"]) =>
  h === "ok" ? <Badge tone="ok">Healthy</Badge> : h === "bad" ? <Badge tone="bad">Problem</Badge> : h === "warn" ? <Badge tone="warn">Check</Badge> : <Badge tone="neutral">Empty</Badge>;

/** Every workspace and tag at a glance; click a row to open it in the list below. */
export function MailboxOverview({ onPick }: { onPick: (workspaceId: string, tagId: string | null) => void }) {
  const { data, error, loading } = useApi<{ data: WsSummary[] }>("/mailboxes/summary");
  const [open, setOpen] = useState<Record<string, boolean>>({});

  if (loading && !data) return <Spinner />;
  if (error) return <ErrorNote message={error} />;
  const rows = data?.data ?? [];

  const grand = rows.reduce(
    (g, w) => ({ total: g.total + w.totals.total, active: g.active + w.totals.active, paused: g.paused + w.totals.paused, error: g.error + w.totals.error }),
    { total: 0, active: 0, paused: 0, error: 0 },
  );

  return (
    <Card className="mb-6 p-0">
      <div className="flex flex-wrap items-center gap-x-6 gap-y-1 border-b border-[var(--border)] px-4 py-3 text-sm">
        <span className="font-semibold">All mailboxes</span>
        <span>{num(grand.total)} total</span>
        <span className="text-[var(--ok)]">{num(grand.active)} active</span>
        <span className="text-[var(--text-muted)]">{num(grand.paused)} paused</span>
        <span className="text-[var(--bad)]">{num(grand.error)} in error</span>
        <span className="ml-auto text-xs text-[var(--text-faint)]">From the latest hourly sync · click a row to manage it</span>
      </div>
      <div className="overflow-x-auto">
        <table className="w-full border-collapse text-sm">
          <thead>
            <tr className="text-left text-[11px] uppercase tracking-wide text-[var(--text-faint)]">
              <th className="px-4 py-2 font-medium">Workspace / batch</th>
              <th className="px-3 py-2 text-right font-medium">Mailboxes</th>
              <th className="px-3 py-2 text-right font-medium">Active</th>
              <th className="px-3 py-2 text-right font-medium">Paused</th>
              <th className="px-3 py-2 text-right font-medium">Error</th>
              <th className="px-3 py-2 text-right font-medium">Warmup on</th>
              <th className="px-3 py-2 text-right font-medium">Avg warmup</th>
              <th className="px-3 py-2 text-right font-medium">Daily capacity</th>
              <th className="px-3 py-2 font-medium">Health</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((w) => (
              <WsRows key={w.workspace_id} w={w} open={!!open[w.workspace_id]} toggle={() => setOpen((o) => ({ ...o, [w.workspace_id]: !o[w.workspace_id] }))} onPick={onPick} />
            ))}
          </tbody>
        </table>
      </div>
    </Card>
  );
}

function Cells({ s }: { s: Stats }) {
  return (
    <>
      <td className="px-3 py-2 text-right tabular-nums">{num(s.total)}</td>
      <td className="px-3 py-2 text-right tabular-nums text-[var(--ok)]">{num(s.active)}</td>
      <td className="px-3 py-2 text-right tabular-nums">{s.paused ? num(s.paused) : "—"}</td>
      <td className={`px-3 py-2 text-right tabular-nums ${s.error ? "font-medium text-[var(--bad)]" : ""}`}>{s.error ? `${num(s.error)} (${s.error_pct}%)` : "—"}</td>
      <td className="px-3 py-2 text-right tabular-nums">{num(s.warmup_on)}/{num(s.total)}</td>
      <td className="px-3 py-2 text-right tabular-nums">{s.avg_warmup_score != null ? `${s.avg_warmup_score}%` : "—"}</td>
      <td className="px-3 py-2 text-right tabular-nums">{num(s.daily_capacity)}/day</td>
      <td className="px-3 py-2">{healthBadge(s.health)}</td>
    </>
  );
}

function WsRows({ w, open, toggle, onPick }: { w: WsSummary; open: boolean; toggle: () => void; onPick: (ws: string, tag: string | null) => void }) {
  return (
    <>
      <tr className="cursor-pointer border-t border-[var(--border)] bg-[var(--surface-2)] hover:bg-[var(--surface-2)]" onClick={toggle}>
        <td className="px-4 py-2 font-medium">
          <span className="mr-2 inline-block w-3 text-[var(--text-faint)]">{open ? "▾" : "▸"}</span>
          {w.name} <span className="text-xs text-[var(--text-faint)]">({w.code}) · synced {dateTime(w.last_synced_at)}</span>
        </td>
        <Cells s={w.totals} />
      </tr>
      {open &&
        w.tags.map((t) => (
          <tr
            key={(t.tag_id ?? "none") + w.workspace_id}
            className="cursor-pointer border-t border-[var(--border)] hover:bg-[var(--surface-2)]"
            onClick={() => onPick(w.workspace_id, t.tag_id)}
            title="Open these mailboxes below"
          >
            <td className="py-2 pl-11 pr-4">{t.label}</td>
            <Cells s={t} />
          </tr>
        ))}
    </>
  );
}
