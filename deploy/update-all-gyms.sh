#!/bin/bash
# update-all-gyms.sh
#
# Pulls the latest code into every gym install under /var/www/*.<BASE_DOMAIN>/
# and reruns `composer install --no-dev`. Skips non-git folders.
#
# Run after pushing a fix to GitHub. Each install pulls its own tracked branch
# (e.g. main, or a per-gym branch like bhatti) and must have no conflicting
# local changes — otherwise the pull aborts.
#
# Installs are owned by www-data, but this script runs as root, so git is run
# as the owner (sudo -u www-data) to avoid "detected dubious ownership in
# repository" aborting every pull.
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
  # Run git as the checkout owner (www-data); pulls the install's own tracked
  # branch via its configured upstream, not necessarily main.
  if ! sudo -u www-data git -C "$dir" pull --ff-only; then
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
