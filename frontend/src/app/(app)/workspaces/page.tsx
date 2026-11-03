"use client";

import { Badge, ErrorNote, PageHeader, Spinner, Table, Td, Th } from "@/components/ui";
import { useApi } from "@/lib/hooks";
import { dash, label, num, tone } from "@/lib/format";
import type { WorkspaceSummary } from "@/lib/types";

export default function WorkspacesPage() {
  const { data, error, loading } = useApi<{ data: WorkspaceSummary[] }>("/workspaces");

  return (
    <div>
      <PageHeader title="Workspaces" subtitle="All accessible workspaces. Expired workspaces are shown honestly — never counted as zero." />
      {error && <ErrorNote message={error} />}
      {loading ? (
        <Spinner />
      ) : (
        <Table>
          <thead>
            <tr>
              <Th>Workspace</Th><Th>Code</Th><Th>Status</Th><Th>Batches</Th>
              <Th>Total</Th><Th>Active</Th><Th>Error</Th><Th>Providers</Th><Th>Integration</Th>
            </tr>
          </thead>
          <tbody>
            {data?.data.map((w) => (
              <tr key={w.id} className="hover:bg-[var(--surface-2)]">
                <Td className="font-medium">{w.name}</Td>
                <Td className="text-[var(--text-muted)]">{w.code}</Td>
                <Td>
                  <Badge tone={w.counts_toward_totals ? "ok" : "warn"}>{w.status_display}</Badge>
                </Td>
                <Td className="tabular-nums">{w.batches_count}</Td>
                <Td className="tabular-nums">{w.total_accounts === null ? <span className="text-[var(--warn)]">Not verified</span> : num(w.total_accounts)}</Td>
                <Td className="tabular-nums">{dash(w.active_accounts === null ? null : num(w.active_accounts))}</Td>
                <Td className="tabular-nums">{w.error_accounts === null ? "—" : num(w.error_accounts)}</Td>
                <Td className="text-xs text-[var(--text-muted)]">
                  {Object.entries(w.provider_distribution || {}).map(([p, c]) => `${label(p)}: ${c}`).join(" · ") || "—"}
                </Td>
                <Td><Badge tone={tone(w.integration_status)}>{label(w.integration_status)}</Badge></Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
    </div>
  );
}
