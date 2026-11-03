"use client";

import { Badge, ErrorNote, LinkCell, PageHeader, Spinner, Table, Td, Th } from "@/components/ui";
import { useApi } from "@/lib/hooks";
import { num, pct } from "@/lib/format";
import type { BatchRow, Paginated } from "@/lib/types";

export default function AccountHealthPage() {
  const { data, error, loading } = useApi<Paginated<BatchRow>>("/dashboard/batches?sort=error_pct&dir=desc&per_page=100");

  return (
    <div>
      <PageHeader title="Account Health" subtitle="Mailbox connectivity by batch. Account health is tracked separately from inbox placement." />
      {error && <ErrorNote message={error} />}
      {loading ? (
        <Spinner />
      ) : (
        <Table>
          <thead><tr><Th>Batch</Th><Th>Workspace</Th><Th>Total</Th><Th>Active</Th><Th>Error</Th><Th>Error %</Th><Th>Open problems</Th></tr></thead>
          <tbody>
            {data?.data.map((b) => (
              <tr key={b.id} className="hover:bg-[var(--surface-2)]">
                <Td><LinkCell href={`/batches/${b.id}`}>{b.batch_id}</LinkCell></Td>
                <Td className="text-[var(--text-muted)]">{b.workspace_code}</Td>
                <Td className="tabular-nums">{num(b.total_accounts)}</Td>
                <Td className="tabular-nums">{num(b.active_accounts)}</Td>
                <Td className="tabular-nums">{b.error_accounts}</Td>
                <Td className="tabular-nums"><Badge tone={b.error_pct >= 10 ? "bad" : b.error_pct > 0 ? "warn" : "ok"}>{pct(b.error_pct)}</Badge></Td>
                <Td>{b.open_problems > 0 ? <Badge tone="bad">{b.open_problems}</Badge> : "0"}</Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
    </div>
  );
}
