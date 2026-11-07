#!/bin/bash
# Recurring cron + search indexing worker (Phase 22, D-12 / KNOW-01).
#
# Infrastructure, not app config (CLAUDE.md: the entrypoint owns "Solr/vector
# indexing"). docker/entrypoint.sh forks this in the background just before
# `exec apache2-foreground` unless WORKER_ENABLED=false.
#
# Each pass runs:
#   drush cron:run search_api_cron --force   index tracked items now; ultimate_cron
#                                           schedules search_api_cron every 15 min
#   drush cron                               every other due job (queues, mail,
#                                           file/system cleanup)
#
# Env:
#   DRUSH                  required; the full drush command string (may carry
#                          -r/--uri options). Empty -> exit 2.
#   WORKER_INTERVAL        seconds between passes (default 60)
#   WORKER_MAX_ITERATIONS  passes before exiting 0 (default 0 = run forever)
#   WORKER_RUN_AS          user drush runs as (default www-data); empty runs
#                          drush directly as the current user (tests)
#
# Logging: one line per drush call with a UTC timestamp, the command and its
# exit code. Drush output bodies are discarded on purpose: they can include
# members-only content titles and container logs are widely readable (T-22-12).

# A failing drush call must never end the loop.
set +e

DRUSH="${DRUSH:-}"
WORKER_INTERVAL="${WORKER_INTERVAL:-60}"
WORKER_MAX_ITERATIONS="${WORKER_MAX_ITERATIONS:-0}"
WORKER_RUN_AS="${WORKER_RUN_AS-www-data}"

log() {
  echo "[worker] $(date -u +%Y-%m-%dT%H:%M:%SZ) $*"
}

if [ -z "$DRUSH" ]; then
  log "DRUSH is not set; worker not started"
  exit 2
fi

# run_drush <drush args...>: run one drush command, log only its exit code.
run_drush() {
  if [ -n "$WORKER_RUN_AS" ]; then
    # www-data's login shell is nologin, so name bash explicitly. `su` without
    # `-` keeps the container env that settings.php reads with getenv().
    su -s /bin/bash "$WORKER_RUN_AS" -c "HOME=/tmp $DRUSH $*" >/dev/null 2>&1
  else
    # $DRUSH is deliberately unquoted: it is a command plus its options.
    HOME="${HOME:-/tmp}" $DRUSH "$@" >/dev/null 2>&1
  fi
  local rc=$?
  log "drush $* exit=$rc"
  return $rc
}

log "started (interval=${WORKER_INTERVAL}s, max_iterations=${WORKER_MAX_ITERATIONS}, run_as=${WORKER_RUN_AS:-<current user>})"

# Clear locks left by a container killed mid-run: ultimate_cron's
# launcher.lock_timeout is 3600 s and --force still respects locks.
run_drush cron:unlock --all

iteration=0
while true; do
  iteration=$((iteration + 1))
  run_drush cron:run search_api_cron --force
  run_drush cron

  if [ "$WORKER_MAX_ITERATIONS" -gt 0 ] && [ "$iteration" -ge "$WORKER_MAX_ITERATIONS" ]; then
    log "stopping after ${iteration} iteration(s)"
    exit 0
  fi
  sleep "$WORKER_INTERVAL"
done
