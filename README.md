# RevCadence Deliverability OS

An internal operations platform for managing, monitoring, troubleshooting and
rotating cold-email infrastructure across multiple Instantly.ai workspaces and
Maildoso infrastructure. It replaces manual Google Sheets tracking and
workspace-by-workspace monitoring with one **batch-level** management system.

> **The batch is the primary unit of management.** One dashboard row = one batch.
> Individual mailboxes appear only inside batch details, exception lists and
> troubleshooting workflows.

---

## Stack

| Layer     | Technology |
|-----------|------------|
| Frontend  | Next.js 16 (App Router) · TypeScript · Tailwind CSS 4 |
| Backend   | Laravel 13 (PHP 8.3) · RESTful API · Sanctum token auth · Queue + Scheduler |
| Database  | PostgreSQL 16 (UUID keys, FK constraints, soft deletes) |
| Infra     | Docker Compose · Nginx · php-fpm |

Business logic (lifecycle maths, metrics, detection rules, recommendations,
rotation) lives in reusable backend **services** (`backend/app/Services`), not
in controllers or UI components. Every calculated value is reproducible from the
stored source records.

## Monorepo layout

```
/
├── backend/            Laravel API (services, models, migrations, seeders, tests)
├── frontend/           Next.js operator UI
├── docker/             Dockerfiles, nginx config, DB backup script
├── docs/               Architecture, API reference, schema, assumptions
├── docker-compose.yml
└── .env.example        Compose-level env (copy to .env)
```

---

## Quick start (Docker)

**Prerequisite:** Docker Desktop running. Nothing else is needed on the host —
PHP 8.3, Composer, Node and PostgreSQL all run inside containers.

```bash
cp .env.example .env            # compose + Postgres credentials
# backend/.env already exists (pre-configured for the db service)

docker compose up --build
```

On first boot the backend entrypoint waits for Postgres, installs Composer
dependencies, generates the app key, runs migrations and seeds the verified
audit snapshot.

| Service  | URL |
|----------|-----|
| Frontend | http://localhost:3000 |
| API      | http://localhost:8000/api |
| Health   | http://localhost:8000/up |

### Seeded dev logins (password: `password`)

| Email | Role | Can |
|-------|------|-----|
| admin@revcadence.local | Super Admin | everything |
| manager@revcadence.local | Deliverability Manager | approve actions, change settings |
| operator@revcadence.local | Operator | create/edit batches, activity, request approvals |
| viewer@revcadence.local | Viewer | read-only |

> Change these before any non-local deployment.

---

## Running without Docker (frontend only)

The host used for this build has PHP 8.0 and no Composer, so the **Laravel
backend can only run via Docker**. The frontend, however, runs natively:

```bash
cd frontend
npm install        # if node_modules is missing
npm run dev        # http://localhost:3000  (expects API at :8000)
```

---

## Verified audit data (seeded)

Seeded from the **October 30, 2026** audit as a labelled historical snapshot —
not live data. Expected totals across the 7 accessible workspaces:

- **1,564** accounts · **1,433** active · **131** errors · **11** batches

The expired **“My Organization Free Trial”** workspace is seeded with status
`expired` and **never counted as zero** — it displays *“Not verified — expired
plan blocks access.”*

Unknown fields (warmup start, active start, batch-level sending splits) are left
**null** and surface as *Dates needed / Not tested / Not verified*. Batches are
registered in the `setup` phase pending verified dates — none is marked `active`
merely because its accounts connect.

Known discrepancies preserved from the audit:
- Revcadence 408 (Oct 29) → 400 (Oct 30), 8 reconnecting accounts missing →
  logged as **PRB-0001** (unresolved).
- Instantly header totals vs per-campaign sums differ in five workspaces →
  recorded in `application_settings.known_discrepancies` (per-campaign sums are
  primary; header totals preserved separately).

---

## Tests

```bash
docker compose exec backend php artisan test
```

- **Unit** (`tests/Unit`) — lifecycle date maths and activity metrics; run with
  no database (fast). Covers: missing warmup → *Dates needed*; missing active
  start → no replacement due; warmup-complete ≠ ready; capacity / utilisation /
  reply / bounce rates; division-by-zero → null; rolling aggregation.
- **Feature** (`tests/Feature`) — audit totals = 1,564/1,433/131; expired
  workspace not zero; workspace placement not copied to batches; duplicate daily
  entry upserts; batches cannot be hard-deleted; ramp gated on readiness;
  approval permissions (viewer cannot request, operator cannot approve, manager
  can). Feature tests use in-memory SQLite (configured in `phpunit.xml`).

---

## Database backups

```bash
# One-off backup (writes to docker/db/backups/)
docker compose exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' \
  | gzip > docker/db/backups/revcadence-$(date +%F).sql.gz

# Restore
gunzip -c docker/db/backups/<file>.sql.gz \
  | docker compose exec -T db psql -U revcadence revcadence
```

A retention-aware script is provided at `docker/db/backup.sh`; schedule it via
host cron for automated daily backups. See `docs/DATABASE.md`.

---

## Safety model

The application is **read-only toward external platforms**. It never pauses
campaigns, deletes/reconnects accounts, changes limits or buys plans on its own.
Every external action is proposed, evidenced and gated through the **Approvals**
workflow with role-based permissions; execution is recorded, performed manually
by an operator.

Credentials (passwords, SMTP/IMAP secrets, API keys) are never stored in the
database or exposed to the browser. Provider API keys live only in server-side
environment variables.

---

## Documentation

- `docs/ARCHITECTURE.md` — services, lifecycle engine, detection rules.
- `docs/API.md` — endpoint reference.
- `docs/DATABASE.md` — schema and backup/restore.
- `docs/ASSUMPTIONS.md` — assumptions, unknowns, and build deviations.

See `docs/ASSUMPTIONS.md` for implementation notes (e.g. the UI uses a bespoke
Tailwind component layer rather than the shadcn generator, and the Next build
runs on Turbopack to process Tailwind 4).
