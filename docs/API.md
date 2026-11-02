# API Reference

Base URL: `http://localhost:8000/api`
Auth: `Authorization: Bearer <token>` (from `POST /login`). All responses JSON.

## Auth
| Method | Path | Notes |
|--------|------|-------|
| POST | `/login` | `{email,password}` → `{token,user}` |
| GET  | `/me` | current user + permissions |
| POST | `/logout` | revoke current token |

## Dashboard (read)
| GET | `/dashboard/overview` | command-centre totals, phases, operations, excluded workspaces |
| GET | `/dashboard/batches` | batch rows. Query: `search, workspace, provider, phase, status, rotation_only, problems_only, health, sort, dir, page, per_page` |

## Workspaces (read)
| GET | `/workspaces` | summaries (expired → null counts, not zero) |
| GET | `/workspaces/{workspace}` | workspace + its batch rows + placement tests |

## Batches
| GET | `/batches` | all batch rows |
| GET | `/batches/{batch}` | full detail (header, lifecycle, activity, deliverability, domains, exceptions, problems, placement, ramp, history, recommendations, rotation plan) |
| POST | `/batches` | create (operator+). Body: `batch_id, workspace_id, provider_type, domain_family?, planned_account_count, domains[], warmup_start, active_daily_limit, warmup_days?, lifecycle_days?, batch_owner?, notes?` |
| PATCH | `/batches/{batch}` | update (no `batch_id` change) |
| POST | `/batches/{batch}/transition` | `{phase, note?, active_start?}` with guardrails |
| DELETE | `/batches/{batch}` | **409** — batches are never hard-deleted |

## Daily activity
| GET | `/batches/{batch}/activity` | rows + derived metrics + 7/30-day summary |
| POST | `/batches/{batch}/activity` | upsert one day (unique batch+date) |
| POST | `/activity/import` | multipart CSV import (`file`) |

CSV columns: `batch_id,activity_date,active_accounts,connected_accounts,sent,daily_limit_per_account,replies,positive_replies,bounces,hard_bounces,soft_bounces,rejections,warmup_emails,campaign_status,notes`

## Problems & recommendations
| GET | `/problems` | query: `status(open|…), batch_id, severity, per_page` |
| GET | `/problems/{problem}` | detail + recommendations |
| POST | `/problems` | create (operator+) |
| PATCH | `/problems/{problem}` | update status/root-cause/assignment |
| POST | `/batches/{batch}/detect` | run rules engine, persist new problems |

## Rotation
| GET | `/rotation/candidates` | batches flagged, with reasons |
| GET | `/rotation/plans` | replacement plans |
| POST | `/rotation/plans` | create plan |
| PATCH | `/rotation/plans/{rotationPlan}` | advance status / dates |

## Placement, integrations, settings, audit
| GET | `/placement-tests` | query: `workspace_id, batch_id` |
| POST | `/placement-tests` | create (scope auto: workspace if no batch_id) |
| GET | `/integrations` | connection states (no secrets) |
| GET | `/integrations/{integration}/logs` | sync logs |
| GET | `/settings` | thresholds + defaults |
| PUT | `/settings` | update (manager/admin) |
| GET | `/audit-logs` | query: `action, entity_type, per_page` |

## Approvals (safety gate)
| GET | `/approvals` | query: `status` |
| POST | `/approvals` | request (operator+) |
| POST | `/approvals/{approval}/approve` | manager/admin |
| POST | `/approvals/{approval}/reject` | manager/admin |
| POST | `/approvals/{approval}/execute` | `{execution_log}` — records manual execution only |

### Role matrix
- **viewer** — all GETs only.
- **operator** — + batch/activity/problem/rotation/placement writes, request approvals.
- **deliverability_manager / super_admin** — + approve/reject, settings update.
