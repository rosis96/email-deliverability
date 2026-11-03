"use client";

import { Badge, ErrorNote, PageHeader, Spinner, Table, Td, Th } from "@/components/ui";
import { useApi } from "@/lib/hooks";
import { date, dash, label, pct, tone } from "@/lib/format";

interface Test {
  id: string;
  test_date: string;
  scope: string;
  provider: string | null;
  inbox_pct: number | null;
  spam_pct: number | null;
  missing_pct: number | null;
  evidence: string | null;
  workspace?: { name: string; code: string } | null;
  batch?: { batch_id: string } | null;
}

export default function PlacementTestsPage() {
  const { data, error, loading } = useApi<{ data: Test[] }>("/placement-tests");

  return (
    <div>
      <PageHeader title="Placement Tests" subtitle="Workspace-level results stay workspace-level. A result is never auto-mapped to a batch, and warmup score is never treated as placement." />
      {error && <ErrorNote message={error} />}
      {loading ? (
        <Spinner />
      ) : (
        <Table>
          <thead><tr><Th>Date</Th><Th>Workspace</Th><Th>Scope</Th><Th>Batch</Th><Th>Provider</Th><Th>Inbox %</Th><Th>Spam %</Th><Th>Missing %</Th><Th>Evidence</Th></tr></thead>
          <tbody>
            {data?.data.map((t) => (
              <tr key={t.id} className="hover:bg-[var(--surface-2)]">
                <Td>{date(t.test_date)}</Td>
                <Td className="font-medium">{t.workspace?.name ?? "—"}</Td>
                <Td><Badge tone="neutral">{label(t.scope)}</Badge></Td>
                <Td>{t.batch?.batch_id ?? <span className="text-[var(--text-faint)]">workspace-level</span>}</Td>
                <Td>{dash(t.provider)}</Td>
                <Td className="tabular-nums">{pct(t.inbox_pct)}</Td>
                <Td className="tabular-nums"><Badge tone={t.spam_pct !== null && t.spam_pct >= 20 ? "bad" : "neutral"}>{pct(t.spam_pct)}</Badge></Td>
                <Td className="tabular-nums">{pct(t.missing_pct)}</Td>
                <Td className="max-w-[320px] truncate text-xs text-[var(--text-muted)]">{dash(t.evidence)}</Td>
              </tr>
            ))}
            {(!data || data.data.length === 0) && <tr><Td className="py-8 text-center text-[var(--text-muted)]">No placement tests.</Td></tr>}
          </tbody>
        </Table>
      )}
    </div>
  );
}
