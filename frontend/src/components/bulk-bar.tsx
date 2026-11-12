"use client";

import { useEffect, useState } from "react";
import { Badge, Button, Card, Input, Select } from "@/components/ui";
import { api } from "@/lib/api";
import { label, num } from "@/lib/format";
import type { InstantlyTag } from "@/lib/types";

export type BulkSelection =
  | { scope: "emails"; emails: string[] }
  | { scope: "filter"; filter: { tag_ids?: string; status?: string; search?: string }; description: string };

interface Op {
  id: string;
  action: string;
  status: string;
  total: number;
  done: number;
  failed: number;
  log: string[] | null;
  error: string | null;
}

const ACTIONS: { value: string; label: string; input: "number" | "bool" | "increment" | "tag" | "none"; hint?: string }[] = [
  { value: "daily_limit", label: "Set daily sending limit", input: "number" },
  { value: "sending_gap", label: "Set sending gap (minutes)", input: "number" },
  { value: "enable_slow_ramp", label: "Slow ramp on/off", input: "bool" },
  { value: "warmup_on", label: "Turn warmup ON", input: "none" },
  { value: "warmup_off", label: "Turn warmup OFF", input: "none" },
  { value: "warmup_limit", label: "Set warmup daily limit", input: "number" },
  { value: "warmup_increment", label: "Set warmup daily increase", input: "increment" },
  { value: "warmup_reply_rate", label: "Set warmup reply rate %", input: "number" },
  { value: "pause", label: "Pause mailboxes", input: "none" },
  { value: "resume", label: "Resume mailboxes", input: "none" },
  { value: "add_tag", label: "Add to batch", input: "tag" },
  { value: "remove_tag", label: "Remove from batch", input: "tag" },
];

/** Apply one change to many mailboxes in Instantly (selection, whole tag, or filter). */
export function BulkBar({
  ws,
  selection,
  tags,
  onClear,
  onDone,
}: {
  ws: string;
  selection: BulkSelection;
  tags: InstantlyTag[];
  onClear: () => void;
  onDone: () => void;
}) {
  const [action, setAction] = useState("daily_limit");
  const [value, setValue] = useState("");
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  const [op, setOp] = useState<Op | null>(null);

  const def = ACTIONS.find((a) => a.value === action)!;
  const count = selection.scope === "emails" ? selection.emails.length : null;

  // Poll the running operation.
  useEffect(() => {
    if (!op || !["queued", "running"].includes(op.status)) return;
    const t = setInterval(async () => {
      try {
        const r = await api.get<{ data: Op }>(`/mailbox-operations/${op.id}`);
        setOp(r.data);
        if (!["queued", "running"].includes(r.data.status)) onDone();
      } catch {
        /* keep polling */
      }
    }, 3000);
    return () => clearInterval(t);
  }, [op, onDone]);

  const body = (dry: boolean) => ({
    action,
    value: def.input === "none" ? null : value,
    scope: selection.scope,
    emails: selection.scope === "emails" ? selection.emails : undefined,
    filter: selection.scope === "filter" ? selection.filter : undefined,
    dry_run: dry,
  });

  const apply = async () => {
    setBusy(true);
    setMsg(null);
    try {
      let n = count;
      if (selection.scope === "filter") {
        const d = await api.post<{ data: { count: number } }>(`/instantly/workspaces/${ws}/bulk`, body(true));
        n = d.data.count;
      }
      const shown = def.input === "none" ? "" : ` → ${def.input === "tag" ? tags.find((t) => t.id === value)?.label ?? value : value}`;
      if (!window.confirm(`${def.label}${shown}\n\nThis will change ${num(n ?? 0)} mailbox(es) directly in Instantly. Continue?`)) {
        setBusy(false);
        return;
      }
      const r = await api.post<{ message: string; data: Op }>(`/instantly/workspaces/${ws}/bulk`, body(false));
      setMsg(r.message);
      setOp(r.data);
    } catch (e) {
      setMsg(e instanceof Error ? e.message : "Bulk edit failed");
    } finally {
      setBusy(false);
    }
  };

  const running = op && ["queued", "running"].includes(op.status);

  return (
    <Card className="sticky top-2 z-10 mb-4 border-[var(--accent)] p-4">
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-sm font-medium">
          {selection.scope === "emails" ? `${num(count ?? 0)} selected` : selection.description}
        </span>
        <Select value={action} onChange={(e) => { setAction(e.target.value); setValue(""); }} className="w-auto">
          {ACTIONS.map((a) => (
            <option key={a.value} value={a.value}>{a.label}</option>
          ))}
        </Select>
        {def.input === "number" && (
          <Input type="number" min={0} value={value} onChange={(e) => setValue(e.target.value)} className="w-28" placeholder="Value" />
        )}
        {def.input === "bool" && (
          <Select value={value} onChange={(e) => setValue(e.target.value)} className="w-auto">
            <option value="">Choose…</option>
            <option value="true">On</option>
            <option value="false">Off</option>
          </Select>
        )}
        {def.input === "increment" && (
          <Select value={value} onChange={(e) => setValue(e.target.value)} className="w-auto">
            <option value="">Choose…</option>
            {["disabled", "0", "1", "2", "3", "4"].map((v) => (
              <option key={v} value={v}>{v === "disabled" ? "Disabled" : v}</option>
            ))}
          </Select>
        )}
        {def.input === "tag" && (
          <Select value={value} onChange={(e) => setValue(e.target.value)} className="w-auto">
            <option value="">Choose batch…</option>
            {tags.map((t) => (
              <option key={t.id} value={t.id}>{t.label}</option>
            ))}
          </Select>
        )}
        <Button variant="primary" onClick={apply} disabled={busy || !!running || (def.input !== "none" && value === "")}>
          {busy ? "Checking…" : "Apply to Instantly"}
        </Button>
        <Button variant="ghost" onClick={onClear}>Clear selection</Button>
      </div>

      {msg && <div className="mt-3 text-sm">{msg}</div>}
      {op && (
        <div className="mt-3">
          <div className="flex items-center gap-2 text-sm">
            <Badge tone={op.status === "completed" ? "ok" : op.status === "failed" ? "bad" : op.status === "completed_with_errors" ? "warn" : "info"}>
              {label(op.status)}
            </Badge>
            <span className="tabular-nums">{num(op.done)} / {num(op.total)} done{op.failed ? ` · ${num(op.failed)} failed` : ""}</span>
          </div>
          <div className="mt-2 h-1.5 w-full overflow-hidden rounded bg-[var(--surface-2)]">
            <div className="h-full bg-[var(--accent)] transition-all" style={{ width: `${op.total ? Math.round(((op.done + op.failed) / op.total) * 100) : 0}%` }} />
          </div>
          {(op.log?.length ?? 0) > 0 && (
            <pre className="mt-2 max-h-32 overflow-auto whitespace-pre-wrap text-xs text-[var(--text-muted)]">{op.log!.join("\n")}</pre>
          )}
        </div>
      )}
    </Card>
  );
}
