"use client";

import { useRef, useState } from "react";
import { Badge, Button, Card, ErrorNote, PageHeader, Spinner, Table, Td, Th } from "@/components/ui";
import { Icon } from "@/components/icons";
import { useApi } from "@/lib/hooks";
import { api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { date, dash, num, pct } from "@/lib/format";
import type { BatchRow, Paginated } from "@/lib/types";

interface ActivityRow {
  id: string;
  activity_date: string;
  sent: number;
  replies: number;
  bounces: number;
  capacity: number | null;
  utilisation: number | null;
  bounce_rate: number | null;
  source: string;
}

export default function ActivityPage() {
  const { can } = useAuth();
  const { data: batches } = useApi<Paginated<BatchRow>>("/dashboard/batches?per_page=100");
  const [batchId, setBatchId] = useState("");
  const activity = useApi<{ data: ActivityRow[] }>(batchId ? `/batches/${batchId}/activity` : null);
  const fileRef = useRef<HTMLInputElement>(null);
  const [msg, setMsg] = useState<string | null>(null);
  const [form, setForm] = useState({ activity_date: "", sent: "", active_accounts: "", daily_limit_per_account: "", replies: "", bounces: "" });
  const canWrite = can("activity.write");

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!batchId) return;
    setMsg(null);
    try {
      await api.post(`/batches/${batchId}/activity`, {
        activity_date: form.activity_date,
        sent: Number(form.sent || 0),
        active_accounts: form.active_accounts ? Number(form.active_accounts) : null,
        daily_limit_per_account: form.daily_limit_per_account ? Number(form.daily_limit_per_account) : null,
        replies: Number(form.replies || 0),
        bounces: Number(form.bounces || 0),
      });
      setMsg("Saved.");
      activity.reload();
    } catch (err) {
      setMsg(err instanceof Error ? err.message : "Save failed");
    }
  };

  const upload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setMsg(null);
    const fd = new FormData();
    fd.append("file", file);
    try {
      const res = await api.upload<{ processed: number; errors: string[] }>("/activity/import", fd);
      setMsg(`Imported ${res.processed} row(s)${res.errors.length ? `, ${res.errors.length} error(s)` : ""}.`);
      activity.reload();
    } catch (err) {
      setMsg(err instanceof Error ? err.message : "Import failed");
    } finally {
      if (fileRef.current) fileRef.current.value = "";
    }
  };

  const field = (name: keyof typeof form, labelText: string, type = "number") => (
    <div>
      <label className="mb-1 block text-xs font-medium text-[var(--text-muted)]">{labelText}</label>
      <input
        type={type}
        value={form[name]}
        onChange={(e) => setForm((f) => ({ ...f, [name]: e.target.value }))}
        className="w-full rounded-lg border border-[var(--border-strong)] bg-[var(--surface)] px-3 py-1.5 text-sm outline-none focus:border-[var(--accent)]"
      />
    </div>
  );

  return (
    <div>
      <PageHeader
        title="Daily Activity"
        subtitle="One record per batch per day. Rates are derived; workspace totals are never split across batches by guessing."
        actions={
          canWrite ? (
            <>
              <input ref={fileRef} type="file" accept=".csv" onChange={upload} className="hidden" />
              <Button onClick={() => fileRef.current?.click()}>
                <Icon name="upload" size={16} /> Import CSV
              </Button>
            </>
          ) : undefined
        }
      />

      <div className="mb-4 flex items-center gap-3">
        <select
          value={batchId}
          onChange={(e) => setBatchId(e.target.value)}
          className="rounded-lg border border-[var(--border-strong)] bg-[var(--surface)] px-3 py-1.5 text-sm outline-none focus:border-[var(--accent)]"
        >
          <option value="">Select a batch…</option>
          {batches?.data.map((b) => (
            <option key={b.id} value={b.id}>{b.batch_id} — {b.workspace_code}</option>
          ))}
        </select>
        {msg && <span className="text-sm text-[var(--text-muted)]">{msg}</span>}
      </div>

      {!batchId ? (
        <Card className="p-8 text-center text-sm text-[var(--text-muted)]">Select a batch to view and record daily activity.</Card>
      ) : (
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
          {canWrite && (
            <Card className="p-5">
              <h3 className="mb-4 text-sm font-semibold">Record a day</h3>
              <form onSubmit={submit} className="space-y-3">
                {field("activity_date", "Date", "date")}
                {field("sent", "Sent")}
                {field("active_accounts", "Active accounts")}
                {field("daily_limit_per_account", "Limit / account")}
                {field("replies", "Replies")}
                {field("bounces", "Bounces")}
                <Button type="submit" variant="primary" className="w-full">Save day</Button>
              </form>
            </Card>
          )}

          <div className={canWrite ? "lg:col-span-2" : "lg:col-span-3"}>
            {activity.loading ? (
              <Spinner />
            ) : activity.error ? (
              <ErrorNote message={activity.error} />
            ) : (
              <Table>
                <thead><tr><Th>Date</Th><Th>Sent</Th><Th>Capacity</Th><Th>Util</Th><Th>Replies</Th><Th>Bounces</Th><Th>Bounce%</Th><Th>Source</Th></tr></thead>
                <tbody>
                  {activity.data?.data.map((a) => (
                    <tr key={a.id}>
                      <Td>{date(a.activity_date)}</Td>
                      <Td className="tabular-nums">{num(a.sent)}</Td>
                      <Td className="tabular-nums">{dash(a.capacity)}</Td>
                      <Td className="tabular-nums">{pct(a.utilisation)}</Td>
                      <Td className="tabular-nums">{num(a.replies)}</Td>
                      <Td className="tabular-nums">{num(a.bounces)}</Td>
                      <Td className="tabular-nums">{pct(a.bounce_rate)}</Td>
                      <Td><Badge tone="neutral">{a.source}</Badge></Td>
                    </tr>
                  ))}
                  {(!activity.data || activity.data.data.length === 0) && (
                    <tr><Td className="py-8 text-center text-[var(--text-muted)]">No activity recorded for this batch yet.</Td></tr>
                  )}
                </tbody>
              </Table>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
