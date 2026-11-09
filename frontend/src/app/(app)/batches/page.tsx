"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import { Badge, Dot, ErrorNote, LinkCell, PageHeader, Spinner, Table, Td, Th } from "@/components/ui";
import { Icon } from "@/components/icons";
import { useApi } from "@/lib/hooks";
import { dash, date, num, pct, tone, label } from "@/lib/format";
import type { BatchRow, Paginated, WorkspaceSummary } from "@/lib/types";

const PROVIDERS = [
  ["", "All providers"],
  ["smtp", "SMTP"],
  ["smtp_maildoso", "SMTP Maildoso"],
  ["google_dfy_gen2", "Google DFY Gen 2"],
  ["other", "Other"],
];
const PHASES = [
  ["", "All phases"],
  ["setup", "Setup"],
  ["warmup", "Warmup"],
  ["ramp", "Ramp"],
  ["active", "Active"],
  ["resting", "Resting"],
  ["retired", "Retired"],
];

function Select({
  value,
  onChange,
  options,
}: {
  value: string;
  onChange: (v: string) => void;
  options: (string[] | WorkspaceSummary)[];
}) {
  return (
    <select
      value={value}
      onChange={(e) => onChange(e.target.value)}
      className="rounded-lg border border-[var(--border-strong)] bg-[var(--surface)] px-3 py-1.5 text-sm outline-none focus:border-[var(--accent)]"
    >
      {options.map((o) =>
        Array.isArray(o) ? (
          <option key={o[0]} value={o[0]}>
            {o[1]}
          </option>
        ) : (
          <option key={o.id} value={o.code}>
            {o.name}
          </option>
        ),
      )}
    </select>
  );
}

export default function BatchesPage() {
  const [search, setSearch] = useState("");
  const [workspace, setWorkspace] = useState("");
  const [provider, setProvider] = useState("");
  const [phase, setPhase] = useState("");
  const [sort, setSort] = useState("batch_id");
  const [dir, setDir] = useState<"asc" | "desc">("asc");

  const { data: wsData } = useApi<{ data: WorkspaceSummary[] }>("/workspaces");

  const query = useMemo(() => {
    const p = new URLSearchParams({ sort, dir, per_page: "100" });
    if (search) p.set("search", search);
    if (workspace) p.set("workspace", workspace);
    if (provider) p.set("provider", provider);
    if (phase) p.set("phase", phase);
    return `/dashboard/batches?${p.toString()}`;
  }, [search, workspace, provider, phase, sort, dir]);

  const { data, error, loading } = useApi<Paginated<BatchRow>>(query);

  const setSortCol = (col: string) => {
    if (sort === col) setDir((d) => (d === "asc" ? "desc" : "asc"));
    else {
      setSort(col);
      setDir("asc");
    }
  };

  const SortTh = ({ col, children }: { col: string; children: React.ReactNode }) => (
    <Th className="cursor-pointer select-none" >
      <span className="inline-flex items-center gap-1" onClick={() => setSortCol(col)}>
        {children}
        {sort === col && <span>{dir === "asc" ? "▲" : "▼"}</span>}
      </span>
    </Th>
  );

  const rows = data?.data ?? [];
  const unassigned = useApi<{ data: { count: number }[] }>("/unassigned-accounts");
  const unassignedTotal = (unassigned.data?.data ?? []).reduce((n, w) => n + w.count, 0);

  return (
    <div>
      <PageHeader
        title="Batch Management"
        subtitle="One row per batch — the primary unit of management. Individual mailboxes appear only inside batch details."
        actions={
          <>
            <Link
              href="/batches/unassigned"
              className="rounded-lg border border-[var(--border-strong)] px-3 py-1.5 text-sm hover:bg-[var(--surface-2)]"
            >
              Unassigned mailboxes{unassignedTotal ? ` (${num(unassignedTotal)})` : ""}
            </Link>
            <Link
              href="/batches/new"
              className="rounded-lg bg-[var(--accent)] px-3 py-1.5 text-sm text-[var(--accent-fg)] hover:opacity-90"
            >
              New batch
            </Link>
          </>
        }
      />

      <div className="mb-4 flex flex-wrap items-center gap-2">
        <div className="relative">
          <Icon
            name="search"
            size={16}
            className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[var(--text-faint)]"
          />
          <input
            placeholder="Search batch, workspace, domain, email…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="w-72 rounded-lg border border-[var(--border-strong)] bg-[var(--surface)] py-1.5 pl-9 pr-3 text-sm outline-none focus:border-[var(--accent)]"
          />
        </div>
        <Select
          value={workspace}
          onChange={setWorkspace}
          options={[["", "All workspaces"], ...(wsData?.data ?? [])]}
        />
        <Select value={provider} onChange={setProvider} options={PROVIDERS} />
        <Select value={phase} onChange={setPhase} options={PHASES} />
        <span className="ml-auto text-xs text-[var(--text-muted)]">{rows.length} batches</span>
      </div>

      {error && <ErrorNote message={error} />}
      {loading ? (
        <Spinner />
      ) : (
        <Table>
          <thead>
            <tr>
              <SortTh col="batch_id">Batch</SortTh>
              <Th>Workspace</Th>
              <Th>Provider</Th>
              <Th>Domains</Th>
              <SortTh col="total_accounts">Total</SortTh>
              <SortTh col="active_accounts">Active</SortTh>
              <Th>Err</Th>
              <SortTh col="error_pct">Err %</SortTh>
              <Th>Phase</Th>
              <Th>Warmup</Th>
              <Th>Warm end</Th>
              <Th>Active start</Th>
              <SortTh col="replacement_due">Repl. due</SortTh>
              <Th>Limit</Th>
              <Th>Y&apos;day</Th>
              <Th>Capacity</Th>
              <SortTh col="utilisation">Util</SortTh>
              <Th>7d</Th>
              <Th>30d</Th>
              <Th>Reply%</Th>
              <SortTh col="bounce_rate">Bounce%</SortTh>
              <Th>Placement</Th>
              <Th>Rot.</Th>
              <Th>Probs</Th>
              <Th>Next action</Th>
            </tr>
          </thead>
          <tbody>
            {rows.map((b) => (
              <tr key={b.id} className="hover:bg-[var(--surface-2)]">
                <Td><LinkCell href={`/batches/${b.id}`}>{b.batch_id}</LinkCell></Td>
                <Td className="text-[var(--text-muted)]">{b.workspace_code}</Td>
                <Td><Badge tone="neutral">{label(b.provider)}</Badge></Td>
                <Td className="tabular-nums">{b.domains_count}</Td>
                <Td className="tabular-nums">{num(b.total_accounts)}</Td>
                <Td className="tabular-nums">{num(b.active_accounts)}</Td>
                <Td className="tabular-nums">{b.error_accounts}</Td>
                <Td className="tabular-nums">
                  <span className={b.error_pct >= 10 ? "font-semibold text-[var(--bad)]" : ""}>{pct(b.error_pct)}</span>
                </Td>
                <Td><Badge tone={tone(b.lifecycle_phase)}>{b.lifecycle_phase_label}</Badge></Td>
                <Td><Badge tone={tone(b.warmup_status)}>{label(b.warmup_status)}</Badge></Td>
                <Td className="text-[var(--text-muted)]">{date(b.warmup_end)}</Td>
                <Td className="text-[var(--text-muted)]">{date(b.active_start)}</Td>
                <Td className="text-[var(--text-muted)]">{date(b.replacement_due)}</Td>
                <Td className="tabular-nums">{dash(b.daily_limit_per_account)}</Td>
                <Td className="tabular-nums">{dash(b.yesterday_sent)}</Td>
                <Td className="tabular-nums">{dash(b.daily_capacity)}</Td>
                <Td className="tabular-nums">{pct(b.utilisation)}</Td>
                <Td className="tabular-nums">{num(b.sent_7d)}</Td>
                <Td className="tabular-nums">{num(b.sent_30d)}</Td>
                <Td className="tabular-nums">{pct(b.reply_rate)}</Td>
                <Td className="tabular-nums">{pct(b.bounce_rate)}</Td>
                <Td><Badge tone={tone(b.inbox_placement)}>{label(b.inbox_placement)}</Badge></Td>
                <Td>{b.rotation_flag ? <Dot tone="warn" /> : <Dot tone="ok" />}</Td>
                <Td className="tabular-nums">{b.open_problems > 0 ? <Badge tone="bad">{b.open_problems}</Badge> : "0"}</Td>
                <Td className="max-w-[260px] truncate text-xs text-[var(--text-muted)]" >{b.next_action}</Td>
              </tr>
            ))}
            {rows.length === 0 && (
              <tr>
                <Td className="py-8 text-center text-[var(--text-muted)]">No batches match the filters.</Td>
              </tr>
            )}
          </tbody>
        </Table>
      )}
    </div>
  );
}
