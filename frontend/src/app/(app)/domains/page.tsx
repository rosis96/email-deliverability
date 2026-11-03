"use client";

import { ErrorNote, LinkCell, PageHeader, Spinner, Table, Td, Th } from "@/components/ui";
import { useApi } from "@/lib/hooks";
import { label } from "@/lib/format";
import type { BatchRow, Paginated } from "@/lib/types";

export default function DomainsPage() {
  const { data, error, loading } = useApi<Paginated<BatchRow>>("/dashboard/batches?per_page=100");

  return (
    <div>
      <PageHeader title="Domain & DNS" subtitle="Domains belong to a batch. Open a batch to inspect per-domain SPF / DKIM / DMARC / MX status and DNS check history." />
      {error && <ErrorNote message={error} />}
      {loading ? (
        <Spinner />
      ) : (
        <Table>
          <thead><tr><Th>Batch</Th><Th>Workspace</Th><Th>Provider</Th><Th>Domain family</Th><Th>Domains</Th><Th /></tr></thead>
          <tbody>
            {data?.data.map((b) => (
              <tr key={b.id} className="hover:bg-[var(--surface-2)]">
                <Td className="font-medium">{b.batch_id}</Td>
                <Td className="text-[var(--text-muted)]">{b.workspace_code}</Td>
                <Td>{label(b.provider)}</Td>
                <Td className="text-[var(--text-muted)]">—</Td>
                <Td className="tabular-nums">{b.domains_count}</Td>
                <Td><LinkCell href={`/batches/${b.id}`}>Inspect DNS →</LinkCell></Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
    </div>
  );
}
