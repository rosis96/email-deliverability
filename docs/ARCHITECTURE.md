# Architecture

## Principles

- **Batch-first.** Aggregations and the main dashboard are keyed on batches.
- **Logic in services, not controllers/UI.** `backend/app/Services` holds all
  calculations; controllers orchestrate and shape responses; the frontend
  renders.
- **Reproducible maths.** Derived values (warmup end, days remaining, capacity,
  utilisation, rates, replacement due) are computed on read from stored source
  fields — never stored, never backfilled.
- **Honest unknowns.** Missing data stays `null` and renders as *Dates needed /
  Not tested / Not verified*.

## Backend services

| Service | Responsibility |
|---------|----------------|
| `LifecycleService` | Warmup/active/replacement date maths; UTC-safe integer day diffs. Enforces: missing warmup → `dates_needed`; missing active start → no replacement due; 21 days ≠ ready. |
| `MetricsService` | Per-day derived metrics + 7/30-day rolling aggregation. Division-by-zero → `null`. |
| `SettingsService` | Resolves thresholds/defaults (application_settings override → config). |
| `ProblemDetectionService` | Configurable rules engine → investigation *findings* (no actions). |
| `RecommendationService` | Deterministic playbook mapping findings → recommendations (never AI-invented root causes). |
| `RotationService` | Rotation candidacy + reasons, reusing detection findings. |
| `DashboardService` | Builds batch dashboard rows and the command-centre overview; excludes expired workspaces from totals explicitly. |
| `AuditLogger` | Writes `audit_logs` for operational changes. |

## Lifecycle engine (phases)

`setup → warmup → ramp → active → resting → retired`

- Phase is **operator-controlled** and never auto-advanced past warmup.
- `POST /batches/{id}/transition` guards:
  - `ramp` requires warmup **complete** AND `readiness_checks_passed`.
  - `active` requires a verified `active_start` (provided or already set).
- Formulas:
  - `warmup_end = warmup_start + warmup_days` (default 21)
  - `replacement_due = active_start + lifecycle_days` (default 90)

## Detection rules (configurable thresholds)

Error % ≥ 10 · error accounts ≥ 10 · placement spam % ≥ 20 (batch-scoped only) ·
persistently elevated bounce rate · open SMTP-auth / IMAP exceptions ·
SPF/DKIM/DMARC failures · over-sending vs capacity · sustained under-utilisation
· warmup complete but readiness missing · replacement due ≤ 30 days.

Findings are triggers, not actions. `POST /batches/{id}/detect` persists new
findings as `batch_problems` (deduplicated by open `rule_key`) with attached
recommendations.

## Auth & RBAC

Sanctum bearer tokens. Roles: `super_admin`, `deliverability_manager`,
`operator`, `viewer`. Route groups gate writes (non-viewer) and decisions
(manager/admin). `super_admin` implicitly holds every permission.

## Frontend

Next.js App Router. A client `AuthProvider` loads `/me`; the `(app)` route group
guards the session and renders the collapsible-sidebar shell. Pages fetch the
API with a bearer token via a thin `api` client + `useApi` hook. Theme tokens
(light/dark) are CSS variables in `globals.css`.

## Containers

`db` (Postgres) · `backend` (php-fpm, entrypoint migrates+seeds) ·
`nginx` (serves `backend/public`, proxies php-fpm) · `queue` (queue worker) ·
`scheduler` (`schedule:work`) · `frontend` (Next dev server).
