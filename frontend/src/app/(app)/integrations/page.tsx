"use client";

import { useState } from "react";
import { Badge, Button, Card, ErrorNote, PageHeader, Spinner } from "@/components/ui";
import { useApi } from "@/lib/hooks";
import { api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { dateTime, label, num, tone } from "@/lib/format";
import type { WorkspaceSummary } from "@/lib/types";

interface Conn {
  id: string;
  provider: string;
  workspace: string | null;
  workspace_id: string | null;
  status: string;
  status_label: string;
  last_success_at: string | null;
  last_error: string | null;
  observed: { total: number; active: number; error: number; paused: number } | null;
  campaigns_count: number | null;
  workspace_name_remote: string | null;
  imported_accounts: number | null;
  unmapped_accounts?: number | null;
  has_credentials: boolean;
}

export default function IntegrationsPage() {
  const { user } = useAuth();
  const canManage = user?.role === "super_admin" || user?.role === "deliverability_manager";
  const { data, error, loading, reload } = useApi<{ data: Conn[] }>("/integrations");
  const { data: wsData } = useApi<{ data: WorkspaceSummary[] }>("/workspaces");

  const [wsId, setWsId] = useState("");
  const [key, setKey] = useState("");
  const [busy, setBusy] = useState<string | null>(null);
  const [msg, setMsg] = useState<string | null>(null);

  const connect = async () => {
    if (!wsId || !key) return;
    setBusy("connect");
    setMsg(null);
    try {
      const r = await api.post<{ message: string }>("/integrations/connect", {
        provider: "instantly",
        workspace_id: wsId,
        api_key: key,
      });
      setMsg(r.message);
      setKey("");
      reload();
    } catch (e) {
      setMsg(e instanceof Error ? e.message : "Connect failed");
    } finally {
      setBusy(null);
    }
  };

  const sync = async (id: string) => {
    setBusy(id);
    setMsg(null);
    try {
      const r = await api.post<{ message: string }>(`/integrations/${id}/sync`);
      setMsg(r.message);
      reload();
    } catch (e) {
      setMsg(e instanceof Error ? e.message : "Sync failed");
    } finally {
      setBusy(null);
    }
  };

  return (
    <div>
      <PageHeader
        title="Integrations"
        subtitle="Read-only provider adapters. API keys are stored encrypted server-side and never sent to the browser. Each sync maps mailboxes to batches by provider, refreshes batch counts and daily activity, and re-runs problem detection."
      />

      {msg && <div className="mb-4 rounded-lg border border-[var(--border)] bg-[var(--surface-2)] px-4 py-2 text-sm">{msg}</div>}

      {canManage && (
        <Card className="mb-6 p-5">
          <h2 className="mb-1 text-sm font-semibold">Connect an Instantly workspace</h2>
          <p className="mb-4 text-xs text-[var(--text-muted)]">
            A v2 API key is scoped to one workspace. Create it in Instantly → Settings → Integrations → API Keys.
          </p>
          <div className="flex flex-wrap items-end gap-3">
            <div>
              <label className="mb-1 block text-xs font-medium text-[var(--text-muted)]">Workspace</label>
              <select
                value={wsId}
                onChange={(e) => setWsId(e.target.value)}
                className="rounded-lg border border-[var(--border-strong)] bg-[var(--surface)] px-3 py-1.5 text-sm outline-none focus:border-[var(--accent)]"
              >
                <option value="">Select…</option>
                {wsData?.data.filter((w) => w.counts_toward_totals).map((w) => (
                  <option key={w.id} value={w.id}>{w.name}</option>
                ))}
              </select>
            </div>
            <div className="flex-1 min-w-[260px]">
              <label className="mb-1 block text-xs font-medium text-[var(--text-muted)]">v2 API key</label>
              <input
                type="password"
                value={key}
                onChange={(e) => setKey(e.target.value)}
                placeholder="Paste key (stored encrypted)"
                className="w-full rounded-lg border border-[var(--border-strong)] bg-[var(--surface)] px-3 py-1.5 text-sm outline-none focus:border-[var(--accent)]"
              />
            </div>
            <Button variant="primary" onClick={connect} disabled={!wsId || !key || busy === "connect"}>
              {busy === "connect" ? "Connecting…" : "Connect & verify"}
            </Button>
          </div>
        </Card>
      )}

      {error && <ErrorNote message={error} />}
      {loading ? (
        <Spinner />
      ) : (
        <div className="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-3">
          {[...(data?.data ?? [])]
            // Stable order (connected workspaces A–Z, then unlinked) so cards don't jump after a sync.
            .sort((a, b) => (a.workspace ?? "~").localeCompare(b.workspace ?? "~") || a.provider.localeCompare(b.provider))
            .map((c) => (
            <Card key={c.id} className="p-4">
              <div className="flex items-center justify-between">
                <span className="font-medium">
                  {label(c.provider)}
                  <span className="text-[var(--text-muted)]">
                    {" · "}
                    {c.workspace ?? c.workspace_name_remote ?? "(unlinked workspace)"}
                  </span>
                </span>
                {c.status === "not_configured" && c.has_credentials && !c.last_error ? (
                  <Badge tone="neutral">Key stored</Badge>
                ) : (
                  <Badge tone={tone(c.status)}>{c.status_label}</Badge>
                )}
              </div>

              {c.observed ? (
                <div className="mt-3 grid grid-cols-3 gap-2 text-center">
                  <div className="rounded-lg bg-[var(--surface-2)] py-2">
                    <div className="text-lg font-semibold tabular-nums">{num(c.observed.total)}</div>
                    <div className="text-[10px] uppercase text-[var(--text-faint)]">accounts</div>
                  </div>
                  <div className="rounded-lg bg-[var(--surface-2)] py-2">
                    <div className="text-lg font-semibold tabular-nums text-[var(--ok)]">{num(c.observed.active)}</div>
                    <div className="text-[10px] uppercase text-[var(--text-faint)]">active</div>
                  </div>
                  <div className="rounded-lg bg-[var(--surface-2)] py-2">
                    <div className="text-lg font-semibold tabular-nums text-[var(--bad)]">{num(c.observed.error)}</div>
                    <div className="text-[10px] uppercase text-[var(--text-faint)]">error</div>
                  </div>
                </div>
              ) : (
                <div className="mt-3 text-xs text-[var(--text-muted)]">
                  {c.last_success_at
                    ? "Connected — last sync returned no account data."
                    : c.has_credentials
                      ? "Key stored — run a sync to pull data."
                      : "Not configured."}
                </div>
              )}

              {c.imported_accounts != null && c.imported_accounts > 0 && (
                (c.unmapped_accounts ?? 0) > 0 ? (
                  <div className="mt-2 text-xs text-[var(--warn)]">
                    {num(c.unmapped_accounts ?? 0)} of {num(c.imported_accounts)} accounts not yet mapped to a batch — run Sync now
                  </div>
                ) : (
                  <div className="mt-2 text-xs text-[var(--ok)]">
                    {num(c.imported_accounts)} accounts mapped to batches
                  </div>
                )
              )}
              {c.campaigns_count != null && (
                <div className="mt-1 text-xs text-[var(--text-muted)]">{num(c.campaigns_count)} campaigns</div>
              )}

              <div className="mt-2 text-xs text-[var(--text-muted)]">Last sync: {dateTime(c.last_success_at)}</div>
              {c.last_error && <div className="mt-1 text-xs text-[var(--bad)]">{c.last_error}</div>}

              {canManage && c.provider === "instantly" && c.has_credentials && (
                <div className="mt-3">
                  <Button size="sm" onClick={() => sync(c.id)} disabled={busy === c.id}>
                    {busy === c.id ? "Syncing…" : "Sync now"}
                  </Button>
                </div>
              )}
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
