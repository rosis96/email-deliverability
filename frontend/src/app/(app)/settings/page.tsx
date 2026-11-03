"use client";

import { useEffect, useState } from "react";
import { Button, Card, ErrorNote, PageHeader, Spinner } from "@/components/ui";
import { useApi } from "@/lib/hooks";
import { api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { label } from "@/lib/format";

interface SettingsResponse {
  thresholds: Record<string, number>;
  defaults: Record<string, number>;
  threshold_descriptions?: Record<string, string>;
}

export default function SettingsPage() {
  const { can } = useAuth();
  const { data, error, loading, reload } = useApi<SettingsResponse>("/settings");
  const [thresholds, setThresholds] = useState<Record<string, number>>({});
  const [saving, setSaving] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  const editable = can("settings.manage");

  useEffect(() => {
    if (data) setThresholds(data.thresholds);
  }, [data]);

  const save = async () => {
    setSaving(true);
    setMsg(null);
    try {
      await api.put("/settings", { thresholds });
      setMsg("Saved.");
      reload();
    } catch (e) {
      setMsg(e instanceof Error ? e.message : "Save failed");
    } finally {
      setSaving(false);
    }
  };

  if (loading) return <Spinner />;
  if (error) return <ErrorNote message={error} />;

  return (
    <div className="max-w-2xl">
      <PageHeader title="Settings" subtitle="Operational thresholds for the detection and rotation engines. These are configurable — not universally safe limits." />
      {msg && <div className="mb-4 text-sm text-[var(--text-muted)]">{msg}</div>}
      <Card className="p-5">
        <h2 className="mb-4 text-sm font-semibold">Detection thresholds</h2>
        <div className="space-y-3">
          {Object.entries(thresholds).map(([k, v]) => (
            <div key={k} className="flex items-center justify-between gap-4">
              <div>
                <div className="text-sm font-medium">{label(k)}</div>
                {data?.threshold_descriptions?.[k] && (
                  <div className="text-xs text-[var(--text-muted)]">{data.threshold_descriptions[k]}</div>
                )}
              </div>
              <input
                type="number"
                step="any"
                value={v}
                disabled={!editable}
                onChange={(e) => setThresholds((t) => ({ ...t, [k]: Number(e.target.value) }))}
                className="w-28 rounded-lg border border-[var(--border-strong)] bg-[var(--surface)] px-3 py-1.5 text-sm outline-none focus:border-[var(--accent)] disabled:opacity-60"
              />
            </div>
          ))}
        </div>
        {editable ? (
          <div className="mt-5">
            <Button variant="primary" onClick={save} disabled={saving}>
              {saving ? "Saving…" : "Save thresholds"}
            </Button>
          </div>
        ) : (
          <p className="mt-5 text-xs text-[var(--text-muted)]">Your role has read-only access to settings.</p>
        )}
      </Card>
    </div>
  );
}
