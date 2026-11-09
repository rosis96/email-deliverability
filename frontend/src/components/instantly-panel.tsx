"use client";

import { useEffect, useState } from "react";
import { Badge, Button, Card, EmptyState, ErrorNote, Input, Select, Spinner, Table, Td, Th } from "@/components/ui";
import { api } from "@/lib/api";
import { useApi } from "@/lib/hooks";
import { useAuth } from "@/lib/auth";
import { dateTime, label, num } from "@/lib/format";
import type { BatchRow } from "@/lib/types";

interface Mailbox {
  email: string;
  status: string | null;
  provider: string | null;
  daily_limit: number | null;
  warmup_on: boolean;
  warmup_score: number | null;
  tags: string[];
  confidence: string;
}

interface Preview {
  accounts?: number;
  from_accounts?: number;
  to_accounts?: number;
  actions: string[];
  campaigns: { id: string; name: string; accounts_in_campaign: number }[];
  warnings: string[];
}

interface Operation {
  id: string;
  type: string;
  params: Record<string, string> | null;
  status: string;
  total_steps: number;
  done_steps: number;
  failed_steps: number;
  log: string[] | null;
  error: string | null;
  created_at: string;
  user?: { name: string } | null;
}

const STAGES = [
  { value: "warmup", label: "Warmup — warmup on, out of campaigns" },
  { value: "ramp", label: "Ramp — warmup on, week-1 ramp limit, resume" },
  { value: "active", label: "Active — warmup on, active daily limit, resume" },
  { value: "resting", label: "Resting — out of campaigns, warmup on" },
  { value: "retired", label: "Retired — out of campaigns, pause all" },
];

const statusTone = (s: string) =>
  s === "completed" ? "ok" : s === "failed" ? "bad" : s === "completed_with_errors" ? "warn" : "info";

/** Batch-level control of Instantly: mailboxes, tags, stage actions, rotation. */
export function InstantlyPanel({ batch, onChanged }: { batch: BatchRow; onChanged: () => void }) {
  const { user } = useAuth();
  const canApply = user?.role === "super_admin" || user?.role === "deliverability_manager";

  const mailboxes = useApi<{ data: Mailbox[] }>(`/batches/${batch.id}/mailboxes`);
  const ops = useApi<{ data: Operation[] }>(`/batches/${batch.id}/operations`);
  const allBatches = useApi<{ data: BatchRow[] }>("/batches");

  const [mode, setMode] = useState<"stage" | "rotate" | "tag_sync">("stage");
  const [phase, setPhase] = useState("resting");
  const [toBatch, setToBatch] = useState("");
  const [activeStart, setActiveStart] = useState("");
  const [preview, setPreview] = useState<Preview | null>(null);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  const [filter, setFilter] = useState("");

  const running = ops.data?.data.some((o) => o.status === "queued" || o.status === "running");
  const reloadOps = ops.reload;
  const reloadMailboxes = mailboxes.reload;

  // Poll while an operation is in progress.
  useEffect(() => {
    if (!running) return;
    const t = setInterval(() => {
      reloadOps();
      reloadMailboxes();
    }, 4000);
    return () => clearInterval(t);
  }, [running, reloadOps, reloadMailboxes]);

  const siblings = (allBatches.data?.data ?? []).filter(
    (b) => b.workspace_code === batch.workspace_code && b.id !== batch.id,
  );

  const body = () => ({
    type: mode,
    phase: mode === "stage" ? phase : undefined,
    to_batch_id: mode === "rotate" ? toBatch : undefined,
    active_start: mode === "stage" && phase === "active" && activeStart ? activeStart : undefined,
  });

  const runPreview = async () => {
    setBusy(true);
    setMsg(null);
    setPreview(null);
    try {
      const r = await api.post<{ data: Preview }>(`/batches/${batch.id}/instantly/preview`, body());
      setPreview(r.data);
    } catch (e) {
      setMsg(e instanceof Error ? e.message : "Preview failed");
    } finally {
      setBusy(false);
    }
  };

  const apply = async () => {
    if (!preview) return;
    if (!window.confirm("Apply these changes to Instantly now? This writes to live mailboxes and campaigns.")) return;
    setBusy(true);
    setMsg(null);
    try {
      const r = await api.post<{ message: string }>(`/batches/${batch.id}/instantly/apply`, body());
      setMsg(r.message);
      setPreview(null);
      ops.reload();
      onChanged();
    } catch (e) {
      setMsg(e instanceof Error ? e.message : "Apply failed");
    } finally {
      setBusy(false);
    }
  };

  const rows = (mailboxes.data?.data ?? []).filter((m) => !filter || m.email.includes(filter.toLowerCase()));
  const tagged = (mailboxes.data?.data ?? []).filter((m) => m.tags.some((t) => t.toUpperCase() === batch.batch_id)).length;
  const total = mailboxes.data?.data.length ?? 0;

  return (
    <div className="space-y-6">
      <Card className="p-5">
        <div className="mb-1 flex flex-wrap items-center justify-between gap-2">
          <h3 className="text-sm font-semibold">Apply to Instantly</h3>
          <span className="text-xs text-[var(--text-muted)]">
            {num(total)} mailbox(es) · {num(tagged)} tagged “{batch.batch_id}” in Instantly
          </span>
        </div>
        <p className="mb-4 text-xs text-[var(--text-muted)]">
          Preview first, then apply. Changes run in the background on every mailbox in this batch and are recorded in Audit Logs.
        </p>

        {!canApply && (
          <p className="mb-3 text-xs text-[var(--warn)]">Only managers and admins can apply batch-wide changes. You can still preview.</p>
        )}

        <div className="flex flex-wrap items-end gap-3">
          <Select value={mode} onChange={(e) => { setMode(e.target.value as typeof mode); setPreview(null); }} className="w-auto">
            <option value="stage">Change stage</option>
            <option value="rotate">Rotate to another batch</option>
            <option value="tag_sync">Tag all mailboxes with “{batch.batch_id}”</option>
          </Select>

          {mode === "stage" && (
            <Select value={phase} onChange={(e) => { setPhase(e.target.value); setPreview(null); }} className="w-auto">
              {STAGES.map((s) => (
                <option key={s.value} value={s.value}>{s.label}</option>
              ))}
            </Select>
          )}
          {mode === "stage" && phase === "active" && (
            <Input type="date" value={activeStart} onChange={(e) => setActiveStart(e.target.value)} className="w-auto" title="Active start date (required if not already set)" />
          )}
          {mode === "rotate" && (
            <Select value={toBatch} onChange={(e) => { setToBatch(e.target.value); setPreview(null); }} className="w-auto min-w-[200px]">
              <option value="">Replacement batch…</option>
              {siblings.map((b) => (
                <option key={b.id} value={b.id}>
                  {b.batch_id} · {label(b.provider)} · {b.lifecycle_phase_label} · {num(b.total_accounts)} mailboxes
                </option>
              ))}
            </Select>
          )}

          <Button onClick={runPreview} disabled={busy || (mode === "rotate" && !toBatch)}>
            {busy && !preview ? "Loading…" : "Preview"}
          </Button>
        </div>

        {msg && <div className="mt-4 rounded-lg border border-[var(--border)] bg-[var(--surface-2)] px-4 py-2 text-sm">{msg}</div>}

        {preview && (
          <div className="mt-4 rounded-lg border border-[var(--border)] p-4">
            <div className="mb-2 text-sm font-medium">
              This will affect{" "}
              {preview.accounts != null
                ? `${num(preview.accounts)} mailbox(es)`
                : `${num(preview.from_accounts ?? 0)} → ${num(preview.to_accounts ?? 0)} mailbox(es)`}
              :
            </div>
            <ul className="mb-3 list-disc space-y-1 pl-5 text-sm">
              {preview.actions.map((a) => (
                <li key={a}>{a}</li>
              ))}
            </ul>
            {preview.campaigns.length > 0 && (
              <div className="mb-3 text-sm">
                <div className="mb-1 text-xs font-semibold uppercase text-[var(--text-faint)]">Campaigns changed</div>
                {preview.campaigns.map((c) => (
                  <div key={c.id}>
                    {c.name} — {num(c.accounts_in_campaign)} of this batch&apos;s mailboxes
                  </div>
                ))}
              </div>
            )}
            {preview.warnings.map((w) => (
              <div key={w} className="mb-1 text-xs text-[var(--warn)]">⚠ {w}</div>
            ))}
            <div className="mt-3 flex gap-2">
              <Button variant="primary" onClick={apply} disabled={busy || !canApply || !!running}>
                {running ? "Another operation is running…" : "Apply to Instantly"}
              </Button>
              <Button variant="ghost" onClick={() => setPreview(null)}>Cancel</Button>
            </div>
          </div>
        )}
      </Card>

      <Card className="p-5">
        <h3 className="mb-3 text-sm font-semibold">Operations</h3>
        {ops.loading && !ops.data ? (
          <Spinner />
        ) : (ops.data?.data.length ?? 0) === 0 ? (
          <p className="text-sm text-[var(--text-muted)]">No batch operations yet.</p>
        ) : (
          <div className="space-y-3">
            {ops.data!.data.map((o) => (
              <details key={o.id} className="rounded-lg border border-[var(--border)] p-3" open={o.status === "running"}>
                <summary className="flex cursor-pointer flex-wrap items-center gap-2 text-sm">
                  <Badge tone={statusTone(o.status)}>{label(o.status)}</Badge>
                  <span className="font-medium">
                    {o.type === "stage" ? `Stage → ${label(o.params?.phase ?? "")}` : o.type === "rotate" ? "Rotation" : o.type === "assign" ? "Assign mailboxes" : "Tag sync"}
                  </span>
                  <span className="text-xs text-[var(--text-muted)]">
                    {dateTime(o.created_at)} · {o.user?.name ?? "system"} · {o.done_steps}/{o.total_steps || "?"} steps
                    {o.failed_steps ? ` · ${o.failed_steps} failed` : ""}
                  </span>
                </summary>
                {o.error && <div className="mt-2 text-xs text-[var(--bad)]">{o.error}</div>}
                <pre className="mt-2 max-h-48 overflow-auto whitespace-pre-wrap text-xs text-[var(--text-muted)]">
                  {(o.log ?? []).join("\n") || "Waiting to start…"}
                </pre>
              </details>
            ))}
          </div>
        )}
      </Card>

      <Card className="p-5">
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
          <h3 className="text-sm font-semibold">Mailboxes in this batch</h3>
          <Input placeholder="Filter email…" value={filter} onChange={(e) => setFilter(e.target.value)} className="w-56" />
        </div>
        {mailboxes.error && <ErrorNote message={mailboxes.error} />}
        {mailboxes.loading && !mailboxes.data ? (
          <Spinner />
        ) : rows.length === 0 ? (
          <EmptyState title="No mailboxes" hint="Sync the workspace, or assign mailboxes from Unassigned mailboxes." />
        ) : (
          <Table>
            <thead>
              <tr>
                <Th>Email</Th>
                <Th>Status</Th>
                <Th>Daily limit</Th>
                <Th>Warmup</Th>
                <Th>Tags</Th>
                <Th>Matched by</Th>
              </tr>
            </thead>
            <tbody>
              {rows.slice(0, 500).map((m) => (
                <tr key={m.email}>
                  <Td className="font-medium">{m.email}</Td>
                  <Td><Badge tone={m.status === "active" ? "ok" : m.status === "paused" ? "neutral" : "bad"}>{label(m.status ?? "unknown")}</Badge></Td>
                  <Td className="tabular-nums">{m.daily_limit ?? "—"}</Td>
                  <Td>{m.warmup_on ? `On${m.warmup_score != null ? ` · ${m.warmup_score}` : ""}` : "Off"}</Td>
                  <Td className="text-xs">{m.tags.join(", ") || "—"}</Td>
                  <Td className="text-xs text-[var(--text-muted)]">{m.confidence === "high" ? "Tag, domain or manual" : "Previous / provider"}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>
    </div>
  );
}
