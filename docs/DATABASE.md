# Database

PostgreSQL 16. UUID primary keys on all domain tables (users/sessions/tokens
keep bigint ids). Foreign keys enforced; historical tables use soft deletes.

## Tables

| Table | Purpose / key constraints |
|-------|---------------------------|
| `users`, `roles`, `permissions`, `permission_role` | RBAC. `roles.name` unique. |
| `personal_access_tokens` | Sanctum tokens. |
| `workspaces` | `code` unique; `counts_toward_totals` (expired → false). |
| `batches` | `batch_id` unique & permanent; soft deletes; self-FK `replacement_batch_id`; source date fields nullable. |
| `domains` | unique(`workspace_id`,`name`); SPF/DKIM/DMARC/MX status. |
| `campaigns` | workspace/batch scoped; status. |
| `email_accounts` | unique(`source`,`external_id`); soft deletes; **no credential columns**. |
| `batch_lifecycle` | append-only phase-transition history. |
| `ramp_schedules` | unique(`batch_id`,`week_no`); editable per batch. |
| `daily_activity` | **unique(`batch_id`,`activity_date`)**; one row per batch per day. |
| `placement_tests` | `scope` workspace/batch; null `batch_id` = workspace-level. |
| `dns_checks`, `reputation_snapshots` | evidence-bearing health records. |
| `account_exceptions` | mailbox exceptions linked to batch. |
| `batch_problems` | `problem_code` unique; soft deletes; open-state constant. |
| `recommendations` | linked to problem/batch; `is_ai_generated` flag. |
| `rotation_plans` | current/replacement batch, overlap + retirement dates. |
| `approvals` | external-action gate; requested/approved/executed audit fields. |
| `integration_connections`, `integration_sync_logs` | adapter state (non-secret `config` json). |
| `imported_records` | raw import provenance for reconciliation. |
| `audit_logs` | operational change log. |
| `application_settings` | `key` unique json store (thresholds, known_discrepancies). |

All derived metrics are computed from these source rows — nothing pre-aggregated
is stored.

## Migrations & seeders

```bash
docker compose exec backend php artisan migrate         # apply
docker compose exec backend php artisan migrate:fresh --seed   # rebuild + seed
docker compose exec backend php artisan db:seed         # (re)seed only
```

Seeders (idempotent, `updateOrCreate`): `RolePermissionSeeder`, `UserSeeder`,
`WorkspaceSeeder`, `AuditBatchSeeder` (the Oct 30 2026 snapshot),
`IntegrationSeeder`.

## Backup & restore

```bash
# Backup (retention-aware helper)
docker compose exec db /backups/../backup.sh
# or ad-hoc:
docker compose exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' \
  | gzip > docker/db/backups/revcadence-$(date +%F).sql.gz

# Restore
gunzip -c docker/db/backups/<file>.sql.gz \
  | docker compose exec -T db psql -U revcadence revcadence
```

Automated daily backup (host cron):
```
0 2 * * *  cd /path/to/project && docker compose exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' | gzip > docker/db/backups/revcadence-$(date +\%F).sql.gz
```
