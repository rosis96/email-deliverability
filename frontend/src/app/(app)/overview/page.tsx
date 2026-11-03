"use client";

import { Badge, Card, EmptyState, ErrorNote, PageHeader, Spinner, StatCard } from "@/components/ui";
import { useApi } from "@/lib/hooks";
import { label, num } from "@/lib/format";
import type { Overview } from "@/lib/types";

export default function OverviewPage() {
  const { data, error, loading } = useApi<Overview>("/dashboard/overview");

  if (loading) return <Spinner />;
  if (error) return <ErrorNote message={error} />;
  if (!data) return <EmptyState title="No data" />;

  const t = data.totals;
  const ops = data.operations;

  return (
    <div>
      <PageHeader
        title="Command Centre"
        subtitle="Batch-level deliverability across all accessible workspaces. Totals derive only from verified records."
      />

      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <StatCard label="Verified accounts" value={num(t.verified_accounts)} />
        <StatCard label="Connected" value={num(t.connected_accounts)} tone="ok" />
        <StatCard label="In error" value={num(t.accounts_in_error)} tone={t.accounts_in_error > 0 ? "bad" : "ok"} />
        <StatCard label="Total batches" value={num(t.total_batches)} />
      </div>

      <div className="mt-3 grid grid-cols-2 gap-3 md:grid-cols-4">
        <StatCard label="Rotation candidates" value={num(ops.rotation_candidates)} tone={ops.rotation_candidates > 0 ? "warn" : "ok"} />
        <StatCard label="Open problems" value={num(ops.open_problems)} tone={ops.open_problems > 0 ? "bad" : "ok"} />
        <StatCard label="Replacements ready" value={num(ops.replacement_batches_ready)} />
        <StatCard label="Due ≤ 30 days" value={num(ops.replacements_due_30d)} tone={ops.replacements_due_30d > 0 ? "warn" : "ok"} />
      </div>

      <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <Card className="p-5">
          <h2 className="mb-4 text-sm font-semibold">Lifecycle phase distribution</h2>
          <div className="space-y-2">
            {Object.entries(data.phases).map(([phase, count]) => (
              <div key={phase} className="flex items-center justify-between text-sm">
                <Badge tone="neutral">{label(phase)}</Badge>
                <span className="tabular-nums font-medium">{count}</span>
              </div>
            ))}
          </div>
        </Card>

        <Card className="p-5">
          <h2 className="mb-1 text-sm font-semibold">Excluded workspaces</h2>
          <p className="mb-4 text-xs text-[var(--text-muted)]">
            Expired or unverified workspaces are shown explicitly and never counted as zero.
          </p>
          {data.excluded_workspaces.length === 0 ? (
            <p className="text-sm text-[var(--text-muted)]">None.</p>
          ) : (
            <div className="space-y-3">
              {data.excluded_workspaces.map((w) => (
                <div key={w.code} className="rounded-lg border border-dashed border-[var(--warn)] bg-[var(--warn-bg)] p-3">
                  <div className="text-sm font-medium">{w.name}</div>
                  <div className="mt-0.5 text-xs text-[var(--warn)]">{w.display}</div>
                </div>
              ))}
            </div>
          )}
        </Card>
      </div>
    </div>
  );
}
