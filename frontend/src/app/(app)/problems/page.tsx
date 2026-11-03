"use client";

import { useState } from "react";
import { Badge, ErrorNote, PageHeader, Spinner, Table, Td, Th } from "@/components/ui";
import { useApi } from "@/lib/hooks";
import { dateTime, label, tone } from "@/lib/format";
import type { Paginated, Problem } from "@/lib/types";

export default function ProblemsPage() {
  const [status, setStatus] = useState("open");
  const { data, error, loading } = useApi<Paginated<Problem>>(`/problems?status=${status}&per_page=100`);

  return (
    <div>
      <PageHeader title="Problems & Incidents" subtitle="Batch-level incidents from the detection rules engine. Investigation triggers — not automatic actions." />
      <div className="mb-4 flex gap-2">
        {["open", "resolved", "closed"].map((s) => (
          <button
            key={s}
            onClick={() => setStatus(s)}
            className={`rounded-lg px-3 py-1.5 text-sm ${status === s ? "bg-[var(--accent)] text-[var(--accent-fg)]" : "border border-[var(--border-strong)] hover:bg-[var(--surface-2)]"}`}
          >
            {label(s)}
          </button>
        ))}
      </div>
      {error && <ErrorNote message={error} />}
      {loading ? (
        <Spinner />
      ) : (
        <Table>
          <thead>
            <tr><Th>Code</Th><Th>Workspace</Th><Th>Batch</Th><Th>Severity</Th><Th>Status</Th><Th>Rule</Th><Th>Symptom</Th><Th>Root cause</Th><Th>Detected</Th></tr>
          </thead>
          <tbody>
            {data?.data.map((p) => (
              <tr key={p.id} className="hover:bg-[var(--surface-2)]">
                <Td className="font-medium">{p.problem_code}</Td>
                <Td className="text-[var(--text-muted)]">{p.workspace?.code ?? "—"}</Td>
                <Td>{p.batch?.batch_id ?? "—"}</Td>
                <Td><Badge tone={tone(p.severity)}>{label(p.severity)}</Badge></Td>
                <Td><Badge tone={tone(p.status)}>{label(p.status)}</Badge></Td>
                <Td className="text-xs text-[var(--text-muted)]">{p.rule_key ? label(p.rule_key) : "—"}</Td>
                <Td className="max-w-[360px] truncate">{p.symptom}</Td>
                <Td><Badge tone="neutral">{label(p.root_cause_status)}</Badge></Td>
                <Td className="text-[var(--text-muted)]">{dateTime(p.detected_at)}</Td>
              </tr>
            ))}
            {(!data || data.data.length === 0) && <tr><Td className="py-8 text-center text-[var(--text-muted)]">No problems.</Td></tr>}
          </tbody>
        </Table>
      )}
    </div>
  );
}
