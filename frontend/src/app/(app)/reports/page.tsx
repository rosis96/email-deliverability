"use client";

import { Button, Card, ErrorNote, PageHeader, Spinner, StatCard } from "@/components/ui";
import { Icon } from "@/components/icons";
import { useApi } from "@/lib/hooks";
import { num } from "@/lib/format";
import type { BatchRow, Paginated } from "@/lib/types";

export default function ReportsPage() {
  const { data, error, loading } = useApi<Paginated<BatchRow>>("/dashboard/batches?per_page=100");
  const rows = data?.data ?? [];

  const total7 = rows.reduce((s, b) => s + (b.sent_7d || 0), 0);
  const total30 = rows.reduce((s, b) => s + (b.sent_30d || 0), 0);
  const maxSent = Math.max(1, ...rows.map((b) => b.sent_30d || 0));

  const exportCsv = () => {
    const headers = ["batch_id", "workspace", "provider", "total_accounts", "active_accounts", "error_pct", "sent_7d", "sent_30d", "bounce_rate", "inbox_placement"];
    const lines = [headers.join(",")];
    for (const b of rows) {
      lines.push([b.batch_id, b.workspace_code, b.provider, b.total_accounts, b.active_accounts, b.error_pct, b.sent_7d, b.sent_30d, b.bounce_rate ?? "", b.inbox_placement].join(","));
    }
    const blob = new Blob([lines.join("\n")], { type: "text/csv" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `revcadence-batches-${new Date().toISOString().slice(0, 10)}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  };

  return (
    <div>
      <PageHeader
        title="Reports & Analytics"
        subtitle="Supports decisions; it is not the primary purpose. Every figure states its source and aggregation level — workspace and batch totals are not combined."
        actions={<Button onClick={exportCsv}><Icon name="upload" size={16} /> Export CSV</Button>}
      />
      {error && <ErrorNote message={error} />}
      {loading ? (
        <Spinner />
      ) : (
        <>
          <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
            <StatCard label="Batches" value={num(rows.length)} />
            <StatCard label="Sent · 7 days" value={num(total7)} hint="Batch-level sum" />
            <StatCard label="Sent · 30 days" value={num(total30)} hint="Batch-level sum" />
            <StatCard label="Rotation flags" value={num(rows.filter((b) => b.rotation_flag).length)} />
          </div>

          <Card className="mt-6 p-5">
            <h2 className="mb-4 text-sm font-semibold">30-day sending by batch</h2>
            <div className="space-y-2">
              {rows.map((b) => (
                <div key={b.id} className="flex items-center gap-3 text-sm">
                  <span className="w-28 shrink-0 font-medium">{b.batch_id}</span>
                  <div className="h-4 flex-1 overflow-hidden rounded bg-[var(--surface-2)]">
                    <div className="h-full rounded bg-[var(--accent)]" style={{ width: `${((b.sent_30d || 0) / maxSent) * 100}%` }} />
                  </div>
                  <span className="w-20 shrink-0 text-right tabular-nums text-[var(--text-muted)]">{num(b.sent_30d)}</span>
                </div>
              ))}
            </div>
          </Card>
        </>
      )}
    </div>
  );
}
