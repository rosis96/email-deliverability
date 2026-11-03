"use client";

import { Badge, ErrorNote, LinkCell, PageHeader, Spinner, Table, Td, Th } from "@/components/ui";
import { useApi } from "@/lib/hooks";
import { label, pct, tone } from "@/lib/format";
import type { BatchRow, Paginated } from "@/lib/types";

export default function DeliverabilityPage() {
  const { data, error, loading } = useApi<Paginated<BatchRow>>("/dashboard/batches?per_page=100");

  return (
    <div>
      <PageHeader
        title="Deliverability"
        subtitle="Four independent dimensions — infrastructure, connectivity, reputation and inbox placement — never collapsed into one misleading score."
      />
      {error && <ErrorNote message={error} />}
      {loading ? (
        <Spinner />
      ) : (
        <Table>
          <thead><tr><Th>Batch</Th><Th>Workspace</Th><Th>Error %</Th><Th>Bounce %</Th><Th>Reply %</Th><Th>Inbox placement</Th><Th>Open problems</Th></tr></thead>
          <tbody>
            {data?.data.map((b) => (
              <tr key={b.id} className="hover:bg-[var(--surface-2)]">
                <Td><LinkCell href={`/batches/${b.id}`}>{b.batch_id}</LinkCell></Td>
                <Td className="text-[var(--text-muted)]">{b.workspace_code}</Td>
                <Td className="tabular-nums">{pct(b.error_pct)}</Td>
                <Td className="tabular-nums">{pct(b.bounce_rate)}</Td>
                <Td className="tabular-nums">{pct(b.reply_rate)}</Td>
                <Td><Badge tone={tone(b.inbox_placement)}>{label(b.inbox_placement)}</Badge></Td>
                <Td>{b.open_problems > 0 ? <Badge tone="bad">{b.open_problems}</Badge> : "0"}</Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
    </div>
  );
}
