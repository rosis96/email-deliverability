"use client";

import { useMemo, useState } from "react";
import { Badge, Button, Card, EmptyState, ErrorNote, PageHeader, Select, Spinner, Table, Td, Th } from "@/components/ui";
import { api } from "@/lib/api";
import { useApi } from "@/lib/hooks";
import { useAuth } from "@/lib/auth";
import { label, num } from "@/lib/format";

interface Summary { workspace_id: string; name: string; code: string; count: number }
interface Mailbox { email: string; status: string | null; provider: string | null; daily_limit: number | null; warmup_on: boolean; tags: string[] }
interface BatchOpt { id: string; batch_id: string; provider_type: string; phase: string }

export default function UnassignedPage() {
  const { user } = useAuth();
  const canAssign = user?.role === "super_admin" || user?.role === "deliverability_manager";
  const summary = useApi<{ data: Summary[] }>("/unassigned-accounts");
  const [ws, setWs] = useState("");
  const wsId = ws || summary.data?.data[0]?.workspace_id || "";
  const list = useApi<{ data: Mailbox[]; batches: BatchOpt[] }>(wsId ? `/workspaces/${wsId}/unassigned-accounts` : null);

  const [selected, setSelected] = useState<Record<string, boolean>>({});
  const [target, setTarget] = useState("");
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);

  const rows = useMemo(() => list.data?.data ?? [], [list.data]);
  const chosen = rows.filter((r) => selected[r.email]).map((r) => r.email);

  const assign = async () => {
    if (!target || chosen.length === 0) return;
    setBusy(true);
    setMsg(null);
    try {
      const r = await api.post<{ message: string }>(`/batches/${target}/assign`, { emails: chosen });
      setMsg(r.message + " Progress is shown on the batch's Instantly tab.");
      setSelected({});
      list.reload();
      summary.reload();
    } catch (e) {
      setMsg(e instanceof Error ? e.message : "Assign failed");
    } finally {
      setBusy(false);
    }
  };

  return (
    <div>
      <PageHeader
        title="Unassigned mailboxes"
        subtitle="Synced from Instantly but not matched to a batch (no batch tag, no registered domain, and more than one possible batch). Assigning one tags it in Instantly with the batch ID so future syncs keep it there."
      />

      {summary.error && <ErrorNote message={summary.error} />}
      {summary.loading && !summary.data ? (
        <Spinner />
      ) : (summary.data?.data.length ?? 0) === 0 ? (
        <EmptyState title="Every synced mailbox is in a batch" hint="New mailboxes that can't be matched automatically will appear here." />
      ) : (
        <>
          <div className="mb-4 flex flex-wrap items-center gap-2">
            <Select value={wsId} onChange={(e) => { setWs(e.target.value); setSelected({}); }} className="w-auto">
              {summary.data!.data.map((w) => (
                <option key={w.workspace_id} value={w.workspace_id}>
                  {w.name} ({num(w.count)})
                </option>
              ))}
            </Select>
            <span className="text-xs text-[var(--text-muted)]">{num(chosen.length)} selected</span>
            <div className="ml-auto flex items-center gap-2">
              <Select value={target} onChange={(e) => setTarget(e.target.value)} className="w-auto">
                <option value="">Assign to batch…</option>
                {(list.data?.batches ?? []).map((b) => (
                  <option key={b.id} value={b.id}>
                    {b.batch_id} · {label(b.provider_type)} · {label(b.phase)}
                  </option>
                ))}
              </Select>
              <Button variant="primary" onClick={assign} disabled={!canAssign || busy || !target || chosen.length === 0}>
                {busy ? "Assigning…" : "Assign & tag in Instantly"}
              </Button>
            </div>
          </div>

          {!canAssign && <p className="mb-3 text-xs text-[var(--warn)]">Only managers and admins can assign mailboxes.</p>}
          {msg && <div className="mb-4 rounded-lg border border-[var(--border)] bg-[var(--surface-2)] px-4 py-2 text-sm">{msg}</div>}
          {list.error && <ErrorNote message={list.error} />}

          {list.loading && !list.data ? (
            <Spinner />
          ) : rows.length === 0 ? (
            <EmptyState title="Nothing unassigned in this workspace" />
          ) : (
            <Card>
              <Table>
                <thead>
                  <tr>
                    <Th>
                      <input
                        type="checkbox"
                        checked={chosen.length === rows.length}
                        onChange={(e) => setSelected(e.target.checked ? Object.fromEntries(rows.map((r) => [r.email, true])) : {})}
                      />
                    </Th>
                    <Th>Email</Th>
                    <Th>Provider</Th>
                    <Th>Status</Th>
                    <Th>Daily limit</Th>
                    <Th>Warmup</Th>
                    <Th>Instantly tags</Th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((m) => (
                    <tr key={m.email}>
                      <Td>
                        <input
                          type="checkbox"
                          checked={!!selected[m.email]}
                          onChange={(e) => setSelected((s) => ({ ...s, [m.email]: e.target.checked }))}
                        />
                      </Td>
                      <Td className="font-medium">{m.email}</Td>
                      <Td>{label(m.provider ?? "unknown")}</Td>
                      <Td><Badge tone={m.status === "active" ? "ok" : "neutral"}>{label(m.status ?? "unknown")}</Badge></Td>
                      <Td className="tabular-nums">{m.daily_limit ?? "—"}</Td>
                      <Td>{m.warmup_on ? "On" : "Off"}</Td>
                      <Td className="text-xs">{m.tags.join(", ") || "—"}</Td>
                    </tr>
                  ))}
                </tbody>
              </Table>
            </Card>
          )}
        </>
      )}
    </div>
  );
}
