"use client";

import { useMemo, useState } from "react";
import { ForecastOverview } from "@/components/forecast-overview";
import { ForecastPlanner } from "@/components/forecast-planner";
import { Badge, ErrorNote, PageHeader, Select, Spinner } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { useApi } from "@/lib/hooks";
import { normalizeInputs, type PlanInputs, type PlanRow } from "@/lib/forecast";

const OVERVIEW = "__overview";

export default function InfrastructurePage() {
  const { isViewer } = useAuth();
  const { data, error, loading, reload } = useApi<{ data: PlanRow[] }>("/infrastructure/plans");
  const rows = useMemo(() => data?.data ?? [], [data]);

  const [selected, setSelected] = useState<string>(OVERVIEW);
  const [drafts, setDrafts] = useState<Record<string, PlanInputs>>({});
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState<{ tone: "ok" | "bad"; text: string } | null>(null);

  const row = rows.find((r) => r.workspace_id === selected) ?? null;
  const saved = useMemo(() => (row ? normalizeInputs(row.inputs) : null), [row]);
  const draft = row ? (drafts[row.workspace_id] ?? saved!) : null;
  const dirtyIds = Object.keys(drafts).filter((id) => {
    const r = rows.find((x) => x.workspace_id === id);
    return r && JSON.stringify(drafts[id]) !== JSON.stringify(normalizeInputs(r.inputs));
  });

  const save = async () => {
    if (!row || !draft) return;
    setSaving(true);
    setNotice(null);
    try {
      const res = await api.put<{ message: string }>(`/infrastructure/plans/${row.workspace_id}`, { inputs: draft });
      setNotice({ tone: "ok", text: res.message });
      setDrafts((d) => {
        const next = { ...d };
        delete next[row.workspace_id];
        return next;
      });
      reload();
    } catch (e) {
      setNotice({ tone: "bad", text: e instanceof ApiError ? e.message : "Could not save the plan." });
    } finally {
      setSaving(false);
    }
  };

  return (
    <div>
      <PageHeader
        title="Infrastructure & Forecasting"
        subtitle="Email infrastructure, sending capacity and the accounts, prospects and spend each workspace needs to hit its meeting target."
        actions={
          <Select
            className="w-72"
            value={selected}
            onChange={(e) => {
              setSelected(e.target.value);
              setNotice(null);
            }}
          >
            <option value={OVERVIEW}>All workspaces — overview</option>
            {rows.map((r) => (
              <option key={r.workspace_id} value={r.workspace_id}>
                {r.name}
                {dirtyIds.includes(r.workspace_id) ? " • unsaved" : r.inputs ? "" : " (no plan)"}
              </option>
            ))}
          </Select>
        }
      />

      {error && <ErrorNote message={error} />}
      {notice && (
        <div className="mb-4">
          {notice.tone === "bad" ? <ErrorNote message={notice.text} /> : <Badge tone="ok">{notice.text}</Badge>}
        </div>
      )}
      {loading && !data ? (
        <Spinner />
      ) : selected === OVERVIEW || !row || !draft || !saved ? (
        <>
          {dirtyIds.length > 0 && (
            <p className="mb-3 text-xs text-[var(--warn)]">
              {dirtyIds.length} workspace(s) have unsaved changes — the overview uses saved plans only.
            </p>
          )}
          <ForecastOverview rows={rows} onOpen={setSelected} />
        </>
      ) : (
        <ForecastPlanner
          key={row.workspace_id}
          name={row.name}
          draft={draft}
          saved={saved}
          hasSavedPlan={!!row.inputs}
          live={row.live}
          updatedAt={row.updated_at}
          updatedBy={row.updated_by}
          canEdit={!isViewer}
          saving={saving}
          onChange={(next) => setDrafts((d) => ({ ...d, [row.workspace_id]: next }))}
          onSave={save}
          onReset={() =>
            setDrafts((d) => {
              const next = { ...d };
              delete next[row.workspace_id];
              return next;
            })
          }
        />
      )}
    </div>
  );
}
