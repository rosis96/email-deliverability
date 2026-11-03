# Deploying RevCadence Deliverability OS to Railway

Three services in one Railway project: **Postgres** (managed plugin), **backend**
(Laravel 13 / PHP 8.4, Docker), **frontend** (Next.js, Docker). Both app services
build from a production `Dockerfile` committed in `backend/` and `frontend/`.

> Deploy order matters because the frontend bakes the backend URL in at build time,
> and the backend's CORS needs the frontend URL. Do it in the order below.

---

## 0. Prerequisites
- This repo pushed to GitHub (`origin`), including the deploy files added here.
- A Railway account with access to create a project from your GitHub repo.

## 1. Create the project + Postgres
1. Railway → **New Project** → **Deploy from GitHub repo** → pick `email-deliverability`.
   (If it first creates a single service, that's fine — we'll point it at `backend` next,
   and add the frontend as a second service.)
2. In the project, **New → Database → Add PostgreSQL**. Leave it; Railway exposes
   `PGHOST / PGPORT / PGDATABASE / PGUSER / PGPASSWORD` for reference.

## 2. Backend service
1. Open the service built from the repo (or **New → GitHub Repo** → same repo).
2. **Settings → Source → Root Directory = `backend`**. Railway will use `backend/Dockerfile`.
3. **Settings → Networking → Generate Domain** (note it, e.g. `https://revcadence-api.up.railway.app`).
4. **Variables** — add:

   ```
   APP_NAME=RevCadence Deliverability OS
   APP_ENV=production
   APP_DEBUG=false
   APP_KEY=base64:REPLACE_WITH_THE_KEY_FROM_CHAT
   APP_URL=https://<your-backend-domain>
   LOG_CHANNEL=stderr

   DB_CONNECTION=pgsql
   DB_HOST=${{Postgres.PGHOST}}
   DB_PORT=${{Postgres.PGPORT}}
   DB_DATABASE=${{Postgres.PGDATABASE}}
   DB_USERNAME=${{Postgres.PGUSER}}
   DB_PASSWORD=${{Postgres.PGPASSWORD}}

   SESSION_DRIVER=database
   CACHE_STORE=database
   QUEUE_CONNECTION=sync

   FRONTEND_URL=https://<your-frontend-domain>   # fill after step 3; redeploy
   BCRYPT_ROUNDS=12
   ```

   - `${{Postgres.PGHOST}}` etc. are Railway variable references — type them exactly;
     if your Postgres service isn't named "Postgres", use its name.
   - `APP_KEY`: use the value given in chat (or run `php artisan key:generate --show`).
   - Leave `FRONTEND_URL` as a placeholder for now; set it properly in step 4.
5. **Deploy.** First boot runs `migrate` + seeds the Oct-30 audit snapshot, roles, and
   the dev logins. Watch logs for `Starting php-fpm + nginx`. Health check is `/up`.
6. Verify: open `https://<backend-domain>/up` → should return a 200 "healthy" page.

## 3. Frontend service
1. **New → GitHub Repo** → same repo → this becomes a second service.
2. **Settings → Source → Root Directory = `frontend`** (uses `frontend/Dockerfile`).
3. **Variables** — add (this one is read at BUILD time):
   ```
   NEXT_PUBLIC_API_URL=https://<your-backend-domain>/api
   ```
   (note the `/api` suffix).
4. **Settings → Networking → Generate Domain** (e.g. `https://revcadence.up.railway.app`).
5. **Deploy.**

## 4. Wire CORS + redeploy backend
1. Back on the **backend** service, set `FRONTEND_URL=https://<your-frontend-domain>`
   (exact origin, no trailing slash) and `APP_URL=https://<your-backend-domain>`.
2. **Redeploy** the backend so CORS allows the frontend origin.

## 5. Post-deploy: sign in + reconnect Instantly
1. Open the frontend domain. Log in with a seeded account:
   **`admin@revcadence.local` / `password`** (change this immediately in a real setup).
2. Go to **Integrations** and **connect each of the 7 workspaces** with its Instantly
   API key (the keys from this machine do NOT transfer — they were encrypted with the
   local app key). For each: connect → then run sync / map so accounts + tags load.
3. Open **Email Accounts** → pick a workspace → you should see mailboxes, tags, and be
   able to edit settings (writes go straight to Instantly, logged in Audit Logs).

> For writes to succeed, each Instantly API key must have the `accounts:update`
> (or `all:all`) scope. If a save returns 403, regenerate that key in Instantly with
> write scope and reconnect the workspace.

---

## Notes / troubleshooting
- **Seeding only runs on a fresh DB.** The entrypoint checks the `users` table first,
  so redeploys never duplicate data. To reset: drop the Postgres volume (or run
  `php artisan migrate:fresh --seed` via a one-off), understanding it wipes everything.
- **CORS error in the browser** → `FRONTEND_URL` on the backend doesn't exactly match the
  frontend origin (scheme + host, no trailing slash). Fix and redeploy backend.
- **Frontend calls `localhost:8000`** → `NEXT_PUBLIC_API_URL` wasn't set at build time;
  set it and trigger a new frontend deploy (it's baked in at build, not runtime).
- **Backend unhealthy** → check logs. Common causes: DB vars not resolving (wrong
  Postgres service name in `${{...}}`), or `APP_KEY` missing.
- **Optional:** add a `queue` and `scheduler` worker service later (same repo/root,
  override start command to `php artisan queue:work` / `schedule:work`) if you move
  sync/detection to background jobs. Not required for the current manual-sync flow.
- **Local dev is unchanged** — `docker-compose.yml` and `docker/` still work for local,
  and running the host PHP/Next servers works as before.
