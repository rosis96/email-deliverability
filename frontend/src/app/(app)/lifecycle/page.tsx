"use client";

import { Badge, ErrorNote, LinkCell, PageHeader, Spinner, Table, Td, Th } from "@/components/ui";
import { useApi } from "@/lib/hooks";
import { date, dash, label, tone } from "@/lib/format";
import type { BatchRow, Paginated } from "@/lib/types";

export default function LifecyclePage() {
  const { data, error, loading } = useApi<Paginated<BatchRow>>("/dashboard/batches?sort=replacement_due&dir=asc&per_page=100");

  return (
    <div>
      <PageHeader title="Warmup & Lifecycle" subtitle="Dates are only shown when verified. Missing warmup/active dates show as 'Dates needed' — never invented." />
      {error && <ErrorNote message={error} />}
      {loading ? (
        <Spinner />
      ) : (
        <Table>
          <thead><tr><Th>Batch</Th><Th>Phase</Th><Th>Warmup</Th><Th>Start</Th><Th>End</Th><Th>Days left</Th><Th>Active start</Th><Th>Active age</Th><Th>Replacement due</Th><Th>Days to repl.</Th></tr></thead>
          <tbody>
            {data?.data.map((b) => (
              <tr key={b.id} className="hover:bg-[var(--surface-2)]">
                <Td><LinkCell href={`/batches/${b.id}`}>{b.batch_id}</LinkCell></Td>
                <Td><Badge tone={tone(b.lifecycle_phase)}>{b.lifecycle_phase_label}</Badge></Td>
                <Td><Badge tone={tone(b.warmup_status)}>{label(b.warmup_status)}</Badge></Td>
                <Td className="text-[var(--text-muted)]">{date(b.warmup_start)}</Td>
                <Td className="text-[var(--text-muted)]">{date(b.warmup_end)}</Td>
                <Td className="tabular-nums">{dash(b.warmup_days_remaining)}</Td>
                <Td className="text-[var(--text-muted)]">{date(b.active_start)}</Td>
                <Td className="tabular-nums">{dash(b.active_age)}</Td>
                <Td className="text-[var(--text-muted)]">{date(b.replacement_due)}</Td>
                <Td className="tabular-nums">{dash(b.days_until_replacement)}</Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
    </div>
  );
}
