# Assumptions, Unknowns & Build Deviations

## Honoured data rules
- No dates invented or backfilled. Missing warmup/active dates stay null →
  *Dates needed*. No active age or replacement due without a verified active
  start.
- Account creation date is **not** used as a warmup-start substitute.
- Workspace-level placement results are kept at workspace scope and never
  auto-copied to batches. Warmup score is never treated as inbox placement.
- Expired “My Organization Free Trial” workspace is never counted as zero.
- Seeded historical batches sit in `setup` (registered, awaiting verified
  dates), not `active`, despite connected accounts.
- The four deliverability dimensions (infrastructure, connectivity, reputation,
  placement) are tracked and displayed independently — no single blended score.

## Known discrepancies preserved
- Revcadence 408→400 (8 reconnecting accounts missing) → **PRB-0001**, open.
- Instantly header totals vs per-campaign sums differ in five workspaces →
  `application_settings.known_discrepancies`. Per-campaign sums are primary;
  header totals preserved with their labels.

## Integrations
- Instantly, Maildoso, Google Postmaster, Microsoft SNDS and MXToolbox are
  modelled as **adapters with connection state**, shown as *Not configured*
  until credentials are set server-side. No endpoints are invented and no
  integration is faked as connected. Live sync is left for the integration
  stage; CSV import + manual entry cover data entry meanwhile.
- API keys are read from server-side env only (`INSTANTLY_API_KEY`, etc.) and
  never returned to the browser.

## Environment constraints on this build host
- Host had PHP 8.0 and **no Composer**, so the Laravel backend is only runnable
  via Docker (PHP 8.3 image). All backend PHP was syntax-validated with `php -l`;
  migrations/seeders/tests execute in the container.
- Docker Desktop was not running during the build, so the backend was not
  executed here; the frontend was fully built and type-checked (`next build`).

## Deliberate deviations from the brief
- **UI component library:** a bespoke Tailwind component layer
  (`frontend/src/components/ui.tsx`) is used instead of the shadcn/ui generator,
  and inline SVG icons instead of `lucide-react`. Reason: the host npm
  dependency tree hit an arborist “Invalid Version” crash on any reinstall, so
  new packages could not be added reliably; the custom layer delivers the same
  Linear/Vercel-style UX with zero added dependencies and a verified build.
  Recharts was likewise replaced by lightweight CSS bars. These can be swapped
  for shadcn/Recharts once the npm tree is healthy.
- **Next build uses Turbopack** (`next build --turbopack`) because the scaffold
  wired Tailwind 4 through the Turbopack loader; this is required for CSS to be
  processed at build time.

## Scope status
Implemented end-to-end: foundation (DB, auth/RBAC, workspaces, batch registry,
audit seed, dashboard), lifecycle + daily activity (+CSV), deliverability
dimensions & detection, problems, recommendations, rotation, approvals, settings,
integrations view, audit logs, and all 15 UI sections.

Next stage (not yet built): live provider sync + reconciliation jobs, inline
DNS-check execution, richer reporting charts, and migrating the UI to
shadcn/Recharts.
