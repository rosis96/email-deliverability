"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { Button, Card, ErrorNote, Field, Input, PageHeader, Select, Toggle } from "@/components/ui";
import { api } from "@/lib/api";
import { useApi } from "@/lib/hooks";
import type { WorkspaceSummary } from "@/lib/types";

const PROVIDERS = [
  ["smtp", "SMTP"],
  ["smtp_maildoso", "SMTP Maildoso"],
  ["google_dfy_gen2", "Google DFY Gen 2"],
  ["other", "Other"],
];

export default function NewBatchPage() {
  const router = useRouter();
  const { data: wsData } = useApi<{ data: WorkspaceSummary[] }>("/workspaces");
  const [f, setF] = useState({
    workspace_id: "",
    provider_type: "smtp",
    batch_id: "",
    planned_account_count: "",
    warmup_start: "",
    active_daily_limit: "",
    domains: "",
    notes: "",
  });
  const [tag, setTag] = useState(true);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF((s) => ({ ...s, [k]: e.target.value }));

  const submit = async () => {
    setBusy(true);
    setErr(null);
    try {
      const r = await api.post<{ data: { header: { id: string } }; message: string | null }>("/batches", {
        ...f,
        batch_id: f.batch_id.trim().toUpperCase(),
        planned_account_count: Number(f.planned_account_count),
        active_daily_limit: Number(f.active_daily_limit),
        domains: f.domains.split(/[\s,]+/).map((d) => d.trim().toLowerCase()).filter(Boolean),
        create_instantly_tag: tag,
      });
      if (r.message) window.alert(r.message);
      router.push(`/batches/${r.data.header.id}`);
    } catch (e) {
      setErr(e instanceof Error ? e.message : "Could not create batch");
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="max-w-2xl">
      <PageHeader
        title="New batch"
        subtitle="Register a batch of mailboxes. Its mailboxes are matched on sync by Instantly tag (= batch ID) or by the domains you list here."
      />
      <Card className="space-y-4 p-5">
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <Field label="Workspace">
            <Select value={f.workspace_id} onChange={set("workspace_id")}>
              <option value="">Select…</option>
              {wsData?.data.filter((w) => w.counts_toward_totals).map((w) => (
                <option key={w.id} value={w.id}>{w.name} ({w.code})</option>
              ))}
            </Select>
          </Field>
          <Field label="Provider">
            <Select value={f.provider_type} onChange={set("provider_type")}>
              {PROVIDERS.map(([v, l]) => (
                <option key={v} value={v}>{l}</option>
              ))}
            </Select>
          </Field>
          <Field label="Batch ID" hint="WORKSPACE-TYPE-NUMBER, e.g. REV-SMTP-02. Permanent.">
            <Input value={f.batch_id} onChange={set("batch_id")} placeholder="REV-SMTP-02" />
          </Field>
          <Field label="Planned mailboxes">
            <Input type="number" min={1} value={f.planned_account_count} onChange={set("planned_account_count")} />
          </Field>
          <Field label="Warmup start">
            <Input type="date" value={f.warmup_start} onChange={set("warmup_start")} />
          </Field>
          <Field label="Active daily limit per mailbox" hint="Applied in Instantly when the batch goes Active.">
            <Input type="number" min={1} value={f.active_daily_limit} onChange={set("active_daily_limit")} />
          </Field>
        </div>
        <Field label="Domains" hint="One per line or comma-separated. New mailboxes on these domains join this batch automatically.">
          <textarea
            value={f.domains}
            onChange={set("domains")}
            rows={4}
            className="w-full rounded-lg border border-[var(--border-strong)] bg-[var(--surface)] px-3 py-1.5 text-sm outline-none focus:border-[var(--accent)]"
          />
        </Field>
        <Field label="Notes">
          <Input value={f.notes} onChange={set("notes")} />
        </Field>
        <div className="flex items-center gap-3 text-sm">
          <Toggle checked={tag} onChange={setTag} />
          <span>Create tag “{f.batch_id.trim().toUpperCase() || "BATCH-ID"}” in Instantly</span>
        </div>
        {err && <ErrorNote message={err} />}
        <div className="flex gap-2">
          <Button variant="primary" onClick={submit} disabled={busy || !f.workspace_id || !f.batch_id}>
            {busy ? "Creating…" : "Create batch"}
          </Button>
          <Button variant="ghost" onClick={() => router.push("/batches")}>Cancel</Button>
        </div>
      </Card>
    </div>
  );
}
