#!/bin/bash
# update-all-gyms.sh
#
# Pulls the latest code into every gym install under /var/www/*.<BASE_DOMAIN>/
# and reruns `composer install --no-dev`. Skips non-git folders.
#
# Run after pushing a fix to GitHub. Each install must be on the same branch
# (default: main) and have no local changes — otherwise the pull aborts.
#
# Usage:  sudo update-all-gyms.sh

set -euo pipefail

[ -f /etc/gym-deploy.conf ] && source /etc/gym-deploy.conf
: "${BASE_DOMAIN:?BASE_DOMAIN not set}"

FAIL=0
UPDATED=0
SKIPPED=0

for dir in /var/www/*."${BASE_DOMAIN}"/; do
  [ -d "$dir" ] || continue
  if [ ! -d "${dir}/.git" ]; then
    echo "⚠ Skipping ${dir} (not a git checkout)"
    SKIPPED=$((SKIPPED + 1))
    continue
  fi

  echo "→ Updating ${dir}"
  if ! git -C "$dir" pull --ff-only; then
    echo "  ✗ git pull failed — skipping composer install"
    FAIL=$((FAIL + 1))
    continue
  fi

  if [ -f "${dir}/composer.json" ]; then
    ( cd "$dir" && composer install --no-dev --optimize-autoloader --no-interaction ) || {
      echo "  ✗ composer install failed"
      FAIL=$((FAIL + 1))
      continue
    }
  fi

  chown -R www-data:www-data "$dir"
  UPDATED=$((UPDATED + 1))
done

echo ""
echo "✓ Updated: ${UPDATED}    Skipped: ${SKIPPED}    Failed: ${FAIL}"
exit "$FAIL"
