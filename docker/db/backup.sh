#!/usr/bin/env bash
# Automated PostgreSQL backup for RevCadence Deliverability OS.
# Run from host:   docker compose exec db /backups/../backup.sh
# Or schedule via cron on the host:
#   0 2 * * *  cd /path/to/project && docker compose exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' | gzip > backups/revcadence-$(date +\%F).sql.gz
set -e

STAMP=$(date +%F-%H%M%S)
OUT="/backups/revcadence-${STAMP}.sql.gz"

echo "Creating backup -> ${OUT}"
pg_dump -U "${POSTGRES_USER}" "${POSTGRES_DB}" | gzip > "${OUT}"

# Retain last 14 backups.
ls -1t /backups/revcadence-*.sql.gz 2>/dev/null | tail -n +15 | xargs -r rm -f
echo "Backup complete. Current backups:"
ls -1t /backups/revcadence-*.sql.gz 2>/dev/null | head
