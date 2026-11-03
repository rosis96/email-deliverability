"use client";

import { Badge, Card, EmptyState, ErrorNote, PageHeader, Spinner, Table, Td, Th } from "@/components/ui";
import { useApi } from "@/lib/hooks";
import { date, label, pct } from "@/lib/format";

interface Candidate {
  id: string;
  batch_id: string;
  workspace: string | null;
  phase: string;
  error_pct: number;
  replacement_due: string | null;
  reasons: string[];
}
interface Plan {
  id: string;
  status: string;
  overlap_start: string | null;
  overlap_end: string | null;
  retirement_date: string | null;
  current_batch?: { batch_id: string } | null;
  replacement_batch?: { batch_id: string } | null;
  workspace?: { name: string } | null;
}

export default function RotationPage() {
  const candidates = useApi<{ data: Candidate[] }>("/rotation/candidates");
  const plans = useApi<{ data: Plan[] }>("/rotation/plans");

  return (
    <div>
      <PageHeader title="Rotation Planner" subtitle="Rotation is always a recommendation. The platform never pauses or deletes accounts automatically." />

      <h2 className="mb-3 text-sm font-semibold">Rotation candidates</h2>
      {candidates.error && <ErrorNote message={candidates.error} />}
      {candidates.loading ? (
        <Spinner />
      ) : candidates.data && candidates.data.data.length > 0 ? (
        <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
          {candidates.data.data.map((c) => (
            <Card key={c.id} className="p-4">
              <div className="flex items-center justify-between">
                <span className="font-medium">{c.batch_id}</span>
                <Badge tone="warn">{label(c.phase)}</Badge>
              </div>
              <div className="mt-1 text-xs text-[var(--text-muted)]">
                {c.workspace} · Err {pct(c.error_pct)} · Repl. due {date(c.replacement_due)}
              </div>
              <ul className="mt-2 list-disc space-y-1 pl-4 text-sm text-[var(--text-muted)]">
                {c.reasons.map((r, i) => <li key={i}>{r}</li>)}
              </ul>
            </Card>
          ))}
        </div>
      ) : (
        <EmptyState title="No rotation candidates" hint="Batches appear here when detection thresholds or replacement windows are met." />
      )}

      <h2 className="mb-3 mt-8 text-sm font-semibold">Replacement plans</h2>
      {plans.loading ? (
        <Spinner />
      ) : plans.data && plans.data.data.length > 0 ? (
        <Table>
          <thead><tr><Th>Workspace</Th><Th>Current</Th><Th>Replacement</Th><Th>Status</Th><Th>Overlap</Th><Th>Retire</Th></tr></thead>
          <tbody>
            {plans.data.data.map((p) => (
              <tr key={p.id}>
                <Td>{p.workspace?.name ?? "—"}</Td>
                <Td className="font-medium">{p.current_batch?.batch_id ?? "—"}</Td>
                <Td>{p.replacement_batch?.batch_id ?? "—"}</Td>
                <Td><Badge tone="info">{label(p.status)}</Badge></Td>
                <Td className="text-[var(--text-muted)]">{date(p.overlap_start)} → {date(p.overlap_end)}</Td>
                <Td className="text-[var(--text-muted)]">{date(p.retirement_date)}</Td>
              </tr>
            ))}
          </tbody>
        </Table>
      ) : (
        <EmptyState title="No replacement plans yet" />
      )}
    </div>
  );
}
