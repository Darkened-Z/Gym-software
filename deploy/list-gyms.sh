#!/bin/bash
# list-gyms.sh
#
# Show every gym install on this VPS: URL, install date, DB size, SSL status.
#
# Usage:  sudo list-gyms.sh

set -euo pipefail

[ -f /etc/gym-deploy.conf ] && source /etc/gym-deploy.conf
: "${BASE_DOMAIN:?BASE_DOMAIN not set}"

printf "%-40s  %-10s  %-12s  %-8s  %s\n" "URL" "INSTALLED" "DB SIZE" "SSL" "DB NAME"
printf "%-40s  %-10s  %-12s  %-8s  %s\n" "---" "---------" "-------" "---" "-------"

shopt -s nullglob
COUNT=0
for dir in /var/www/*."${BASE_DOMAIN}"/; do
  [ -d "$dir" ] || continue
  FQDN="$(basename "${dir%/}")"
  SLUG="${FQDN%.${BASE_DOMAIN}}"
  DB_NAME="gym_${SLUG//-/_}"

  INSTALLED="$(date -d "$(stat -c %y "$dir")" +%F 2>/dev/null || echo "?")"

  DB_SIZE="$(mysql -N -B -e "
    SELECT IFNULL(ROUND(SUM(data_length + index_length) / 1024 / 1024, 1), 0)
    FROM information_schema.tables
    WHERE table_schema = '${DB_NAME}'
  " 2>/dev/null) MB"

  SSL="no"
  [ -f "/etc/letsencrypt/live/${FQDN}/fullchain.pem" ] && SSL="yes"

  printf "%-40s  %-10s  %-12s  %-8s  %s\n" \
    "https://${FQDN}" "$INSTALLED" "$DB_SIZE" "$SSL" "$DB_NAME"
  COUNT=$((COUNT + 1))
done

echo ""
echo "Total: ${COUNT} gym(s)"
