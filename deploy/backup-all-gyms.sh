#!/bin/bash
# backup-all-gyms.sh
#
# Daily backup of every gym DB on this VPS. Run by cron.
# Keeps the last 7 days, deletes anything older.
#
# Cron setup (run as root):
#   sudo crontab -e
#   # Daily at 3:15am
#   15 3 * * * /usr/local/bin/backup-all-gyms.sh >> /var/log/gym-backup.log 2>&1

set -euo pipefail

[ -f /etc/gym-deploy.conf ] && source /etc/gym-deploy.conf
: "${BASE_DOMAIN:?BASE_DOMAIN not set}"

BACKUP_DIR="/var/backups/gym-deploy/daily"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-7}"
DATE="$(date +%Y%m%d-%H%M%S)"

mkdir -p "$BACKUP_DIR"

echo "[$(date -Iseconds)] Starting backup of all gym databases..."

# Loop every gym DB
COUNT=0
FAIL=0
for db in $(mysql -N -B -e "SHOW DATABASES LIKE 'gym\\_%'"); do
  OUT="${BACKUP_DIR}/${db}-${DATE}.sql.gz"
  if mysqldump --single-transaction --quick "$db" | gzip > "$OUT"; then
    SIZE=$(du -h "$OUT" | cut -f1)
    echo "  ✓ ${db}  ${SIZE}"
    COUNT=$((COUNT + 1))
  else
    echo "  ✗ ${db}  FAILED"
    rm -f "$OUT"
    FAIL=$((FAIL + 1))
  fi
done

# Retention sweep
echo "Pruning backups older than ${RETENTION_DAYS} days..."
find "$BACKUP_DIR" -name 'gym_*.sql.gz' -mtime "+${RETENTION_DAYS}" -delete -print | sed 's/^/  removed: /'

echo "[$(date -Iseconds)] Done. Backed up: ${COUNT}    Failed: ${FAIL}"
exit "$FAIL"
