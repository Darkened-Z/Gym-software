#!/bin/bash
# deprovision-gym.sh <slug>
#
# Removes a gym install — but first backs up its DB and files to
# /var/backups/gym-deploy/. Safe to undo a provision.
#
# Usage:  sudo deprovision-gym.sh ironhouse

set -euo pipefail

[ -f /etc/gym-deploy.conf ] && source /etc/gym-deploy.conf
: "${BASE_DOMAIN:?BASE_DOMAIN not set}"

if [ $# -ne 1 ]; then
  echo "Usage: $0 <slug>" >&2
  exit 1
fi

SLUG="$1"
FQDN="${SLUG}.${BASE_DOMAIN}"
INSTALL_DIR="/var/www/${FQDN}"
DB_NAME="gym_${SLUG//-/_}"
DB_USER="$DB_NAME"
BACKUP_DIR="/var/backups/gym-deploy"
DATE="$(date +%Y%m%d-%H%M%S)"

if [ ! -d "$INSTALL_DIR" ]; then
  echo "ERROR: ${INSTALL_DIR} does not exist." >&2
  exit 1
fi

mkdir -p "$BACKUP_DIR"

echo "→ Backing up database..."
mysqldump "$DB_NAME" | gzip > "${BACKUP_DIR}/${DB_NAME}-${DATE}.sql.gz"

echo "→ Backing up files (excluding vendor/)..."
tar --exclude='vendor' -czf "${BACKUP_DIR}/${FQDN}-files-${DATE}.tar.gz" \
    -C "$(dirname "$INSTALL_DIR")" "$(basename "$INSTALL_DIR")"

echo "→ Removing nginx vhost..."
rm -f "/etc/nginx/sites-enabled/${FQDN}.conf" "/etc/nginx/sites-available/${FQDN}.conf"
nginx -t && systemctl reload nginx

echo "→ Revoking SSL cert (best effort)..."
certbot delete --cert-name "$FQDN" --non-interactive 2>/dev/null || true

echo "→ Dropping database and user..."
mysql <<SQL
DROP DATABASE IF EXISTS \`${DB_NAME}\`;
DROP USER IF EXISTS '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

echo "→ Removing files..."
rm -rf "$INSTALL_DIR"

echo "→ Removing credentials file..."
rm -f "/etc/gym-deploy/credentials/${FQDN}.txt"

echo ""
echo "✓ Removed ${FQDN}"
echo "  DB backup:    ${BACKUP_DIR}/${DB_NAME}-${DATE}.sql.gz"
echo "  Files backup: ${BACKUP_DIR}/${FQDN}-files-${DATE}.tar.gz"
