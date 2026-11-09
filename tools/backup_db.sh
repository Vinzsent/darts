#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────
# DARTS database backup — run this ON THE LIVE SERVER (Ubuntu).
#
# Setup (one time, as the deploy user):
#   1) Create ~/.my.cnf with the MySQL credentials and lock it down:
#        [client]
#        user=darts_user
#        password='YOUR_REAL_PASSWORD'
#      chmod 600 ~/.my.cnf
#   2) chmod +x ~/darts/tools/backup_db.sh   (adjust DARTS_DIR below)
#
# Recommended cron (daily 02:00 + keeps 14 days):
#   0 2 * * * /var/www/html/darts/tools/backup_db.sh >> /var/www/html/darts/tools/backup.log 2>&1
#
# Also run it manually right before every deployment.
# ─────────────────────────────────────────────────────────────────────────

set -u

DB_NAME="darts"
BACKUP_DIR="${HOME}/backups"
KEEP_DAYS=14
STAMP="$(date +%Y%m%d_%H%M%S)"
TARGET="${BACKUP_DIR}/${DB_NAME}_${STAMP}.sql.gz"

mkdir -p "${BACKUP_DIR}"

# Credentials come from ~/.my.cnf (never hardcode the password here).
if ! mysqldump --single-transaction --quick --routines --triggers \
       "${DB_NAME}" | gzip > "${TARGET}"; then
    echo "[backup_db] FAILED at ${STAMP}" >&2
    rm -f "${TARGET}"
    exit 1
fi

echo "[backup_db] wrote ${TARGET} ($(du -h "${TARGET}" | cut -f1))"

# Prune backups older than KEEP_DAYS
find "${BACKUP_DIR}" -name "${DB_NAME}_*.sql.gz" -type f -mtime "+${KEEP_DAYS}" -delete
echo "[backup_db] pruned backups older than ${KEEP_DAYS} days"
