"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { MailboxOverview } from "@/components/mailbox-overview";
import { BulkBar, type BulkSelection } from "@/components/bulk-bar";
import {
  Badge,
  Button,
  Card,
  Drawer,
  EmptyState,
  ErrorNote,
  Field,
  Input,
  PageHeader,
  Select,
  Spinner,
  Table,
  Tabs,
  Td,
  Th,
  Toggle,
} from "@/components/ui";
import { Icon } from "@/components/icons";
import { api, ApiError } from "@/lib/api";
import { num } from "@/lib/format";
import { useAuth } from "@/lib/auth";
import type {
  InstantlyAccountDetail,
  InstantlyAccountRow,
  InstantlyListResponse,
  InstantlyTag,
  InstantlyWorkspaceRef,
  Tone,
} from "@/lib/types";

const STATUS_OPTIONS: { value: string; label: string }[] = [
  { value: "", label: "All statuses" },
  { value: "1", label: "Active" },
  { value: "2", label: "Paused" },
  { value: "-1", label: "Connection error" },
  { value: "-3", label: "Sending error" },
  { value: "-2", label: "Soft bounce error" },
  { value: "3", label: "Maintenance" },
];

const INCREMENT_OPTIONS = [
  { value: "disabled", label: "Disabled (full limit day one)" },
  { value: "0", label: "0" },
  { value: "1", label: "1 (suggested)" },
  { value: "2", label: "2" },
  { value: "3", label: "3" },
  { value: "4", label: "4 (max)" },
];

type FormState = {
  daily_limit: string;
  sending_gap: string;
  enable_slow_ramp: boolean;
  reply_to: string;
  inbox_placement_test_limit: string;
  tracking_domain_name: string;
  first_name: string;
  last_name: string;
  warmup_limit: string;
  warmup_increment: string;
  warmup_reply_rate: string;
};

function initForm(d: InstantlyAccountDetail): FormState {
  return {
    daily_limit: d.daily_limit?.toString() ?? "",
    sending_gap: d.sending_gap?.toString() ?? "",
    enable_slow_ramp: !!d.enable_slow_ramp,
    reply_to: d.reply_to ?? "",
    inbox_placement_test_limit: d.inbox_placement_test_limit?.toString() ?? "",
    tracking_domain_name: d.tracking_domain_name ?? "",
    first_name: d.first_name ?? "",
    last_name: d.last_name ?? "",
    warmup_limit: d.warmup_limit?.toString() ?? "",
    warmup_increment: d.warmup_increment ?? "",
    warmup_reply_rate: d.warmup_reply_rate?.toString() ?? "",
  };
}

const toNum = (s: string): number | null => (s.trim() === "" ? null : Number(s));
const toStr = (s: string): string | null => (s.trim() === "" ? null : s.trim());

export default function EmailAccountsPage() {
  const { user } = useAuth();
  const canEdit = !!user && user.role !== "viewer";

  const [workspaces, setWorkspaces] = useState<InstantlyWorkspaceRef[]>([]);
  const [ws, setWs] = useState<string>("");
  const [wsLoading, setWsLoading] = useState(true);
  const [wsError, setWsError] = useState<string | null>(null);

  const [tags, setTags] = useState<InstantlyTag[]>([]);

  const [rows, setRows] = useState<InstantlyAccountRow[]>([]);
  const [cursor, setCursor] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);
  const [listError, setListError] = useState<string | null>(null);

  // filters
  const [search, setSearch] = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [tagFilter, setTagFilter] = useState("");
  const [typeFilter, setTypeFilter] = useState("");

  // drawer
  const [openEmail, setOpenEmail] = useState<string | null>(null);

  // bulk selection: explicit rows, or "everything matching the current filter"
  const [selected, setSelected] = useState<Record<string, boolean>>({});
  const [allMatching, setAllMatching] = useState(false);
  const [showOverview, setShowOverview] = useState(true);
  const [reloadKey, setReloadKey] = useState(0);
  const [overviewKey, setOverviewKey] = useState(0);
  const pendingTag = useRef<string | null>(null);

  // load workspaces once
  useEffect(() => {
    let cancelled = false;
    const run = async () => {
      try {
        const r = await api.get<{ data: InstantlyWorkspaceRef[] }>("/instantly/workspaces");
        if (cancelled) return;
        setWorkspaces(r.data);
        if (r.data.length) setWs((cur) => cur || r.data[0].workspace_id);
      } catch (e) {
        if (!cancelled) setWsError(e instanceof ApiError ? e.message : "Failed to load workspaces");
      } finally {
        if (!cancelled) setWsLoading(false);
      }
    };
    run();
    return () => {
      cancelled = true;
    };
  }, []);

  // debounce search
  useEffect(() => {
    const t = setTimeout(() => setDebouncedSearch(search), 400);
    return () => clearTimeout(t);
  }, [search]);

  // load tags when workspace changes
  useEffect(() => {
    if (!ws) return;
    let cancelled = false;
    const run = async () => {
      setTags([]);
      setTagFilter("");
      try {
        const r = await api.get<{ data: InstantlyTag[] }>(`/instantly/workspaces/${ws}/tags`);
        if (!cancelled) {
          setTags(r.data);
          if (pendingTag.current) {
            setTagFilter(pendingTag.current);
            pendingTag.current = null;
          }
        }
      } catch {
        if (!cancelled) setTags([]);
      }
    };
    run();
    return () => {
      cancelled = true;
    };
  }, [ws]);

  const buildQuery = useCallback(
    (startingAfter?: string | null) => {
      const p = new URLSearchParams({ limit: "100" });
      if (debouncedSearch) p.set("search", debouncedSearch);
      if (statusFilter) p.set("status", statusFilter);
      if (tagFilter) p.set("tag_ids", tagFilter);
      if (startingAfter) p.set("starting_after", startingAfter);
      return p.toString();
    },
    [debouncedSearch, statusFilter, tagFilter],
  );

  // (re)load first page when workspace or server-side filters change
  useEffect(() => {
    if (!ws) return;
    let cancelled = false;
    const run = async () => {
      setLoading(true);
      setListError(null);
      setRows([]);
      setCursor(null);
      setSelected({});
      setAllMatching(false);
      try {
        const r = await api.get<InstantlyListResponse>(
          `/instantly/workspaces/${ws}/accounts?${buildQuery()}`,
        );
        if (cancelled) return;
        setRows(r.items);
        setCursor(r.next);
      } catch (e) {
        if (!cancelled) setListError(e instanceof ApiError ? e.message : "Failed to load accounts");
      } finally {
        if (!cancelled) setLoading(false);
      }
    };
    run();
    return () => {
      cancelled = true;
    };
  }, [ws, buildQuery, reloadKey]);

  const loadMore = () => {
    if (!ws || !cursor) return;
    setLoadingMore(true);
    api
      .get<InstantlyListResponse>(`/instantly/workspaces/${ws}/accounts?${buildQuery(cursor)}`)
      .then((r) => {
        setRows((prev) => [...prev, ...r.items]);
        setCursor(r.next);
      })
      .catch((e) => setListError(e instanceof ApiError ? e.message : "Failed to load more"))
      .finally(() => setLoadingMore(false));
  };

  // client-side type filter (provider type is ambiguous to filter server-side)
  const typeOptions = useMemo(
    () => Array.from(new Set(rows.map((r) => r.type_label))).sort(),
    [rows],
  );
  const visibleRows = useMemo(
    () => (typeFilter ? rows.filter((r) => r.type_label === typeFilter) : rows),
    [rows, typeFilter],
  );

  const patchRow = useCallback((d: InstantlyAccountDetail) => {
    setRows((prev) =>
      prev.map((r) =>
        r.email === d.email
          ? {
              ...r,
              status: d.status,
              status_label: d.status_label,
              status_tone: d.status_tone,
              warmup_status: d.warmup_status,
              warmup_score: d.warmup_score,
              daily_limit: d.daily_limit,
              first_name: d.first_name,
              last_name: d.last_name,
            }
          : r,
      ),
    );
  }, []);

  const wsName = workspaces.find((w) => w.workspace_id === ws)?.name ?? "";

  const chosen = visibleRows.filter((r) => selected[r.email]).map((r) => r.email);
  const allVisibleChosen = visibleRows.length > 0 && chosen.length === visibleRows.length;
  const tagLabel = tags.find((t) => t.id === tagFilter)?.label;
  const statusLabel = STATUS_OPTIONS.find((o) => o.value === statusFilter)?.label;
  const selection: BulkSelection | null = allMatching
    ? {
        scope: "filter",
        filter: { tag_ids: tagFilter || undefined, status: statusFilter || undefined, search: debouncedSearch || undefined },
        description: `All mailboxes in ${wsName}${tagLabel ? ` · batch “${tagLabel}”` : ""}${statusFilter ? ` · ${statusLabel}` : ""}${debouncedSearch ? ` · matching “${debouncedSearch}”` : ""}`,
      }
    : chosen.length
      ? { scope: "emails", emails: chosen }
      : null;

  const pickFromOverview = (workspaceId: string, tagId: string | null) => {
    setStatusFilter("");
    setSearch("");
    if (workspaceId === ws) {
      setTagFilter(tagId ?? "");
    } else {
      pendingTag.current = tagId;
      setWs(workspaceId);
    }
    if (typeof window !== "undefined") window.scrollTo({ top: 420, behavior: "smooth" });
  };

  return (
    <div>
      <PageHeader
        title="Dashboard"
        subtitle="Filter mailboxes by workspace and batch, then select rows (or a whole batch) and apply one change to all of them in Instantly."
      />

      {wsError && <ErrorNote message={wsError} />}

      <div className="mb-2 flex justify-end">
        <Button size="sm" variant="ghost" onClick={() => setShowOverview((v) => !v)}>
          {showOverview ? "Hide overview" : "Show workspace / batch overview"}
        </Button>
      </div>
      {showOverview && <MailboxOverview key={overviewKey} onPick={pickFromOverview} />}

      {/* Toolbar */}
      <div className="mb-4 flex flex-wrap items-center gap-2">
        <Select
          value={ws}
          onChange={(e) => setWs(e.target.value)}
          className="w-auto min-w-[200px]"
        >
          {wsLoading ? (
            <option value="">Loading workspaces…</option>
          ) : workspaces.length === 0 ? (
            <option value="">No connected workspaces</option>
          ) : null}
          {workspaces.map((w) => (
            <option key={w.workspace_id} value={w.workspace_id}>
              {w.name} ({w.code})
            </option>
          ))}
        </Select>

        <div className="relative">
          <span className="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--text-faint)]">
            <Icon name="search" size={15} />
          </span>
          <Input
            placeholder="Search email…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="w-56 pl-8"
          />
        </div>

        <Select value={typeFilter} onChange={(e) => setTypeFilter(e.target.value)} className="w-auto">
          <option value="">All types</option>
          {typeOptions.map((t) => (
            <option key={t} value={t}>
              {t}
            </option>
          ))}
        </Select>

        <Select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)} className="w-auto">
          {STATUS_OPTIONS.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </Select>

        <Select value={tagFilter} onChange={(e) => setTagFilter(e.target.value)} className="w-auto">
          <option value="">All batches</option>
          {tags.map((t) => (
            <option key={t.id} value={t.id}>
              {t.label}
            </option>
          ))}
        </Select>

        <div className="ml-auto text-xs text-[var(--text-muted)]">
          {loading
            ? "Loading…"
            : typeFilter
              ? `${visibleRows.length} of ${rows.length} loaded`
              : `${rows.length} loaded${cursor ? " · more available" : ""}`}
        </div>
      </div>

      {listError && <ErrorNote message={listError} />}

      {canEdit && selection && (
        <BulkBar
          ws={ws}
          selection={selection}
          tags={tags}
          onClear={() => {
            // Refresh the list so it shows the new values from Instantly.
            setReloadKey((k) => k + 1);
          }}
          onDone={() => setOverviewKey((k) => k + 1)}
        />
      )}

      {canEdit && allVisibleChosen && !allMatching && (cursor || tagFilter || statusFilter) && (
        <div className="mb-3 rounded-lg bg-[var(--surface-2)] px-4 py-2 text-sm">
          All {num(visibleRows.length)} loaded mailboxes are selected.{" "}
          <button className="font-medium text-[var(--accent)] hover:underline" onClick={() => setAllMatching(true)}>
            Select every mailbox {tagLabel ? `in batch “${tagLabel}”` : "matching this filter"} in {wsName}
          </button>
        </div>
      )}

      {wsLoading || loading ? (
        <Spinner />
      ) : workspaces.length === 0 ? (
        <EmptyState
          title="No connected workspaces"
          hint="Connect an Instantly workspace under Integrations first."
        />
      ) : visibleRows.length === 0 ? (
        <EmptyState title="No accounts match" hint="Try a different workspace, tag, or filter." />
      ) : (
        <>
          <Table>
            <thead>
              <tr>
                {canEdit && (
                  <Th>
                    <input
                      type="checkbox"
                      aria-label="Select all loaded"
                      checked={allVisibleChosen || allMatching}
                      onChange={(e) => {
                        setAllMatching(false);
                        setSelected(e.target.checked ? Object.fromEntries(visibleRows.map((r) => [r.email, true])) : {});
                      }}
                    />
                  </Th>
                )}
                <Th>Email</Th>
                <Th>Batch</Th>
                <Th>Type</Th>
                <Th>Warmup</Th>
                <Th>Daily</Th>
                <Th>Status</Th>
              </tr>
            </thead>
            <tbody>
              {visibleRows.map((r) => (
                <tr
                  key={r.email}
                  onClick={() => setOpenEmail(r.email)}
                  className="cursor-pointer hover:bg-[var(--surface-2)]"
                >
                  {canEdit && (
                    <Td>
                      <input
                        type="checkbox"
                        aria-label={`Select ${r.email}`}
                        checked={allMatching || !!selected[r.email]}
                        onClick={(e) => e.stopPropagation()}
                        onChange={(e) => {
                          setAllMatching(false);
                          setSelected((s) => ({ ...s, [r.email]: e.target.checked }));
                        }}
                      />
                    </Td>
                  )}
                  <Td className="font-medium">{r.email}</Td>
                  <Td>
                    <div className="flex flex-wrap gap-1">
                      {r.tags.length === 0 ? (
                        <span className="text-[var(--text-faint)]">—</span>
                      ) : (
                        r.tags.map((t) => (
                          <Badge key={t.id} tone="info">
                            <Icon name="tag" size={11} />
                            {t.label}
                          </Badge>
                        ))
                      )}
                    </div>
                  </Td>
                  <Td className="text-[var(--text-muted)]">{r.type_label}</Td>
                  <Td className="tabular-nums text-[var(--text-muted)]">
                    {r.warmup_score != null ? `${r.warmup_score}%` : "—"}
                  </Td>
                  <Td className="tabular-nums text-[var(--text-muted)]">
                    {r.daily_limit ?? "—"}
                  </Td>
                  <Td>
                    <Badge tone={r.status_tone as Tone}>{r.status_label}</Badge>
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>

          {cursor && (
            <div className="mt-4 flex flex-col items-center gap-1.5">
              <Button onClick={loadMore} disabled={loadingMore}>
                {loadingMore ? "Loading…" : "Load more"}
              </Button>
              {typeFilter && (
                <span className="text-[11px] text-[var(--text-faint)]">
                  The type filter applies to loaded rows only — load more to include later pages.
                </span>
              )}
            </div>
          )}
        </>
      )}

      {openEmail && (
        <AccountDrawer
          ws={ws}
          wsName={wsName}
          email={openEmail}
          canEdit={canEdit}
          onClose={() => setOpenEmail(null)}
          onChanged={patchRow}
        />
      )}
    </div>
  );
}

/* -------------------------------------------------------------------------- */

function AccountDrawer({
  ws,
  wsName,
  email,
  canEdit,
  onClose,
  onChanged,
}: {
  ws: string;
  wsName: string;
  email: string;
  canEdit: boolean;
  onClose: () => void;
  onChanged: (d: InstantlyAccountDetail) => void;
}) {
  const [detail, setDetail] = useState<InstantlyAccountDetail | null>(null);
  const [form, setForm] = useState<FormState | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [tab, setTab] = useState("settings");
  const [saving, setSaving] = useState(false);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<{ tone: "ok" | "bad"; msg: string } | null>(null);

  const apply = useCallback(
    (d: InstantlyAccountDetail) => {
      setDetail(d);
      setForm(initForm(d));
      onChanged(d);
    },
    [onChanged],
  );

  const reload = useCallback(() => {
    setLoading(true);
    setError(null);
    api
      .get<{ data: InstantlyAccountDetail }>(
        `/instantly/workspaces/${ws}/account?email=${encodeURIComponent(email)}`,
      )
      .then((r) => apply(r.data))
      .catch((e) => setError(e instanceof ApiError ? e.message : "Failed to load account"))
      .finally(() => setLoading(false));
  }, [ws, email, apply]);

  useEffect(() => {
    let cancelled = false;
    const run = async () => {
      setLoading(true);
      setError(null);
      try {
        const r = await api.get<{ data: InstantlyAccountDetail }>(
          `/instantly/workspaces/${ws}/account?email=${encodeURIComponent(email)}`,
        );
        if (!cancelled) apply(r.data);
      } catch (e) {
        if (!cancelled) setError(e instanceof ApiError ? e.message : "Failed to load account");
      } finally {
        if (!cancelled) setLoading(false);
      }
    };
    run();
    return () => {
      cancelled = true;
    };
  }, [ws, email, apply]);

  const set = (k: keyof FormState, v: string | boolean) =>
    setForm((f) => (f ? { ...f, [k]: v } : f));

  const handleSave = async () => {
    if (!detail || !form) return;
    const payload: Record<string, unknown> = { email: detail.email };

    if (toNum(form.daily_limit) !== (detail.daily_limit ?? null))
      payload.daily_limit = toNum(form.daily_limit);
    if (toNum(form.sending_gap) !== (detail.sending_gap ?? null))
      payload.sending_gap = toNum(form.sending_gap);
    if (form.enable_slow_ramp !== !!detail.enable_slow_ramp)
      payload.enable_slow_ramp = form.enable_slow_ramp;
    if (toStr(form.reply_to) !== (detail.reply_to ?? null))
      payload.reply_to = toStr(form.reply_to);
    if (toNum(form.inbox_placement_test_limit) !== (detail.inbox_placement_test_limit ?? null))
      payload.inbox_placement_test_limit = toNum(form.inbox_placement_test_limit);
    if (toStr(form.tracking_domain_name) !== (detail.tracking_domain_name ?? null))
      payload.tracking_domain_name = toStr(form.tracking_domain_name);
    if (toStr(form.first_name) !== (detail.first_name ?? null))
      payload.first_name = toStr(form.first_name);
    if (toStr(form.last_name) !== (detail.last_name ?? null))
      payload.last_name = toStr(form.last_name);
    if (toNum(form.warmup_limit) !== (detail.warmup_limit ?? null))
      payload.warmup_limit = toNum(form.warmup_limit);
    if (form.warmup_increment !== "" && form.warmup_increment !== (detail.warmup_increment ?? ""))
      payload.warmup_increment = form.warmup_increment;
    if (toNum(form.warmup_reply_rate) !== (detail.warmup_reply_rate ?? null))
      payload.warmup_reply_rate = toNum(form.warmup_reply_rate);

    if (Object.keys(payload).length <= 1) {
      setNotice({ tone: "bad", msg: "No changes to save." });
      return;
    }

    setSaving(true);
    setNotice(null);
    try {
      const r = await api.patch<{ message: string; data: InstantlyAccountDetail }>(
        `/instantly/workspaces/${ws}/account`,
        payload,
      );
      apply(r.data);
      setNotice({ tone: "ok", msg: r.message });
    } catch (e) {
      setNotice({ tone: "bad", msg: e instanceof ApiError ? e.message : "Save failed" });
    } finally {
      setSaving(false);
    }
  };

  const doAction = async (
    path: string,
    body: Record<string, unknown>,
    optimistic?: () => void,
  ) => {
    setBusy(true);
    setNotice(null);
    try {
      const r = await api.post<{ message: string; data: unknown }>(
        `/instantly/workspaces/${ws}/account/${path}`,
        { email, ...body },
      );
      setNotice({ tone: "ok", msg: r.message });
      optimistic?.();
      if (path === "warmup") {
        // async job on Instantly's side — refresh shortly
        setTimeout(reload, 1800);
      } else {
        apply(r.data as InstantlyAccountDetail);
      }
    } catch (e) {
      setNotice({ tone: "bad", msg: e instanceof ApiError ? e.message : "Action failed" });
    } finally {
      setBusy(false);
    }
  };

  const paused = detail?.status === 2;
  const warmupOn = detail?.warmup_status === 1;

  const headerActions =
    canEdit && detail ? (
      <Button
        size="sm"
        variant={paused ? "primary" : "default"}
        disabled={busy}
        onClick={() => doAction(paused ? "resume" : "pause", {})}
      >
        <Icon name={paused ? "play" : "pause"} size={14} />
        {paused ? "Resume" : "Pause"}
      </Button>
    ) : null;

  return (
    <Drawer
      open
      onClose={onClose}
      title={email}
      subtitle={wsName}
      actions={headerActions}
      footer={
        canEdit && detail ? (
          <div className="flex items-center justify-between gap-3">
            <span className="text-[11px] text-[var(--text-faint)]">
              Writes directly to Instantly · logged to Audit Logs
            </span>
            <Button variant="primary" onClick={handleSave} disabled={saving}>
              {saving ? "Saving…" : "Save changes"}
            </Button>
          </div>
        ) : !canEdit ? (
          <span className="text-[11px] text-[var(--text-faint)]">
            Read-only — your role can view but not edit accounts.
          </span>
        ) : null
      }
    >
      {loading ? (
        <Spinner />
      ) : error ? (
        <ErrorNote message={error} />
      ) : detail && form ? (
        <div className="space-y-4">
          {notice && (
            <div
              className={`rounded-lg border px-3 py-2 text-sm ${
                notice.tone === "ok"
                  ? "border-[var(--ok)] bg-[var(--ok-bg)] text-[var(--ok)]"
                  : "border-[var(--bad)] bg-[var(--bad-bg)] text-[var(--bad)]"
              }`}
            >
              {notice.msg}
            </div>
          )}

          {/* status strip */}
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone={detail.status_tone as Tone}>{detail.status_label}</Badge>
            <Badge tone={detail.warmup_status_tone as Tone}>
              <Icon name="flame" size={11} /> Warmup {detail.warmup_status_label}
            </Badge>
            <Badge tone="neutral">{detail.type_label}</Badge>
            {detail.warmup_score != null && (
              <span className="text-xs text-[var(--text-muted)]">
                Warmup score <span className="font-medium text-[var(--text)]">{detail.warmup_score}%</span>
              </span>
            )}
          </div>

          <Tabs
            tabs={[
              { id: "settings", label: "Settings" },
              { id: "warmup", label: "Warmup" },
            ]}
            active={tab}
            onChange={setTab}
          />

          {tab === "settings" && (
            <div className="space-y-5">
              <Card className="space-y-4 p-4">
                <div className="text-sm font-semibold">Campaign settings</div>
                <div className="grid grid-cols-2 gap-4">
                  <Field label="Daily campaign limit" hint="Max campaign emails per day">
                    <Input
                      type="number"
                      min={0}
                      value={form.daily_limit}
                      disabled={!canEdit}
                      onChange={(e) => set("daily_limit", e.target.value)}
                    />
                  </Field>
                  <Field label="Minimum wait time" hint="Minutes between sends">
                    <Input
                      type="number"
                      min={0}
                      value={form.sending_gap}
                      disabled={!canEdit}
                      onChange={(e) => set("sending_gap", e.target.value)}
                    />
                  </Field>
                </div>
                <div className="flex items-center justify-between">
                  <div>
                    <div className="text-sm font-medium">Campaign slow ramp</div>
                    <div className="text-xs text-[var(--text-muted)]">
                      Gradually increase campaign emails per day
                    </div>
                  </div>
                  <Toggle
                    checked={form.enable_slow_ramp}
                    disabled={!canEdit}
                    onChange={(v) => set("enable_slow_ramp", v)}
                  />
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <Field label="Reply-to address" hint="Optional">
                    <Input
                      type="email"
                      placeholder="reply@example.com"
                      value={form.reply_to}
                      disabled={!canEdit}
                      onChange={(e) => set("reply_to", e.target.value)}
                    />
                  </Field>
                  <Field label="Daily inbox placement test limit">
                    <Input
                      type="number"
                      min={0}
                      value={form.inbox_placement_test_limit}
                      disabled={!canEdit}
                      onChange={(e) => set("inbox_placement_test_limit", e.target.value)}
                    />
                  </Field>
                </div>
                <Field
                  label="Custom tracking domain"
                  hint={
                    detail.tracking_domain_status
                      ? `Status: ${detail.tracking_domain_status}`
                      : "Leave blank to use the default"
                  }
                >
                  <Input
                    placeholder="track.example.com"
                    value={form.tracking_domain_name}
                    disabled={!canEdit}
                    onChange={(e) => set("tracking_domain_name", e.target.value)}
                  />
                </Field>
              </Card>

              <Card className="space-y-4 p-4">
                <div className="text-sm font-semibold">Sender identity</div>
                <div className="grid grid-cols-2 gap-4">
                  <Field label="First name">
                    <Input
                      value={form.first_name}
                      disabled={!canEdit}
                      onChange={(e) => set("first_name", e.target.value)}
                    />
                  </Field>
                  <Field label="Last name">
                    <Input
                      value={form.last_name}
                      disabled={!canEdit}
                      onChange={(e) => set("last_name", e.target.value)}
                    />
                  </Field>
                </div>
              </Card>
            </div>
          )}

          {tab === "warmup" && (
            <div className="space-y-5">
              <Card className="space-y-4 p-4">
                <div className="flex items-center justify-between">
                  <div>
                    <div className="text-sm font-semibold">Warmup</div>
                    <div className="text-xs text-[var(--text-muted)]">
                      {detail.warmup_started_at
                        ? `Started ${new Date(detail.warmup_started_at).toLocaleDateString()}`
                        : "Not started"}
                    </div>
                  </div>
                  <Toggle
                    checked={warmupOn}
                    disabled={!canEdit || busy}
                    onChange={(v) => doAction("warmup", { enabled: v })}
                  />
                </div>
                <p className="text-[11px] text-[var(--text-faint)]">
                  Toggling warmup is processed asynchronously by Instantly; the status refreshes a
                  moment after you switch it.
                </p>
              </Card>

              <Card className="space-y-4 p-4">
                <div className="text-sm font-semibold">Warmup settings</div>
                <div className="grid grid-cols-2 gap-4">
                  <Field label="Daily warmup limit" hint="Suggested 10, max 200">
                    <Input
                      type="number"
                      min={0}
                      value={form.warmup_limit}
                      disabled={!canEdit}
                      onChange={(e) => set("warmup_limit", e.target.value)}
                    />
                  </Field>
                  <Field label="Reply rate %" hint="Suggested 30">
                    <Input
                      type="number"
                      min={0}
                      max={100}
                      value={form.warmup_reply_rate}
                      disabled={!canEdit}
                      onChange={(e) => set("warmup_reply_rate", e.target.value)}
                    />
                  </Field>
                </div>
                <Field label="Increase per day" hint="Daily ramp increment">
                  <Select
                    value={form.warmup_increment}
                    disabled={!canEdit}
                    onChange={(e) => set("warmup_increment", e.target.value)}
                  >
                    <option value="">—</option>
                    {INCREMENT_OPTIONS.map((o) => (
                      <option key={o.value} value={o.value}>
                        {o.label}
                      </option>
                    ))}
                  </Select>
                </Field>
              </Card>
            </div>
          )}
        </div>
      ) : null}
    </Drawer>
  );
}
