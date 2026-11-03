"use client";

import { Badge, ErrorNote, PageHeader, Spinner, Table, Td, Th } from "@/components/ui";
import { useApi } from "@/lib/hooks";
import { dateTime, label } from "@/lib/format";
import type { Paginated } from "@/lib/types";

interface Log {
  id: string;
  action: string;
  entity_type: string | null;
  entity_id: string | null;
  entity_label: string | null;
  created_at: string;
  user?: { name: string } | null;
}

export default function AuditPage() {
  const { data, error, loading } = useApi<Paginated<Log>>("/audit-logs?per_page=100");

  return (
    <div>
      <PageHeader title="Audit Logs" subtitle="Every operational change is recorded for traceability." />
      {error && <ErrorNote message={error} />}
      {loading ? (
        <Spinner />
      ) : (
        <Table>
          <thead><tr><Th>When</Th><Th>User</Th><Th>Action</Th><Th>Entity</Th></tr></thead>
          <tbody>
            {data?.data.map((l) => (
              <tr key={l.id}>
                <Td className="text-[var(--text-muted)]">{dateTime(l.created_at)}</Td>
                <Td>{l.user?.name ?? "system"}</Td>
                <Td><Badge tone="neutral">{label(l.action.replace(/\./g, "_"))}</Badge></Td>
                <Td className="text-xs text-[var(--text-muted)]">{l.entity_label ?? l.entity_type ?? "—"}</Td>
              </tr>
            ))}
            {(!data || data.data.length === 0) && <tr><Td className="py-8 text-center text-[var(--text-muted)]">No audit entries yet.</Td></tr>}
          </tbody>
        </Table>
      )}
    </div>
  );
}
