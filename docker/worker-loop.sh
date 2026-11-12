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
#   PRIVATE_FILES_DIR      the private files folder (default /var/www/private,
#                          as settings.php sets it)
#
# Backup and restore: every drush call, the startup unlock included, runs
# while holding <private>/.localnodes/worker.lock, and none runs while the
# pause file <private>/.localnodes/backup-pause is in force. A backup or
# restore writes the pause, then takes the same lock, so a pass already
# running finishes first and no later pass writes while the site is copied
# or loaded. A pause with an end time in the past is ignored; one with no
# end time (a restore) or one that cannot be read keeps the worker idle.
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
PRIVATE_FILES_DIR="${PRIVATE_FILES_DIR:-/var/www/private}"
STATE_DIR="${PRIVATE_FILES_DIR%/}/.localnodes"
PAUSE_FILE="$STATE_DIR/backup-pause"
WORKER_LOCK="$STATE_DIR/worker.lock"

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
    # Each argument is shell-quoted so an empty one survives the -c string.
    local args
    args="$(printf '%q ' "$@")"
    # 9>&-: drush does not inherit the worker lock's descriptor.
    su -s /bin/bash "$WORKER_RUN_AS" -c "HOME=/tmp $DRUSH ${args% }" >/dev/null 2>&1 9>&-
  else
    # $DRUSH is deliberately unquoted: it is a command plus its options.
    HOME="${HOME:-/tmp}" $DRUSH "$@" >/dev/null 2>&1 9>&-
  fi
  local rc=$?
  log "drush $* exit=$rc"
  return $rc
}

# paused: true while the pause file is in force. The file is one JSON line
# written by the site's BackupPause class: {"token":"…","until":<n>|null,…}.
paused() {
  [ -e "$PAUSE_FILE" ] || [ -L "$PAUSE_FILE" ] || return 1
  local until
  until="$(grep -o '"until":[^,}]*' "$PAUSE_FILE" 2>/dev/null | head -n 1 | cut -d: -f2 | tr -d ' ')"
  case "$until" in
    ''|*[!0-9]*) return 0 ;;  # null (a restore) or unreadable: stay idle
  esac
  [ "$until" -gt "$(date +%s)" ]
}

# The lock folder and file belong to the user drush runs as, because the
# backup command opens the same lock from inside Drupal.
if [ ! -d "$STATE_DIR" ]; then
  if [ -n "$WORKER_RUN_AS" ]; then
    install -d -m 0700 -o "$WORKER_RUN_AS" -g "$WORKER_RUN_AS" "$STATE_DIR" 2>/dev/null
  else
    mkdir -p -m 0700 "$STATE_DIR" 2>/dev/null
  fi
fi
if ! exec 9>>"$WORKER_LOCK"; then
  log "cannot open $WORKER_LOCK; worker not started"
  exit 2
fi
if [ -n "$WORKER_RUN_AS" ]; then
  chown "$WORKER_RUN_AS:$WORKER_RUN_AS" "$WORKER_LOCK" 2>/dev/null
fi

log "started (interval=${WORKER_INTERVAL}s, max_iterations=${WORKER_MAX_ITERATIONS}, run_as=${WORKER_RUN_AS:-<current user>})"

# Clear locks left by a container killed mid-run: ultimate_cron's
# launcher.lock_timeout is 3600 s and --force still respects locks.
# ultimate_cron's cron:unlock requires a job name even with --all (without one
# it exits 1); an empty name selects every job. It runs on the first pass
# that is not paused, under the worker lock like every other call.
unlocked=0

iteration=0
while true; do
  iteration=$((iteration + 1))
  if ! flock 9; then
    log "cannot take the worker lock; pass skipped"
  elif paused; then
    log "site paused for a backup or restore; pass skipped"
  else
    if [ "$unlocked" -eq 0 ]; then
      run_drush cron:unlock '' --all
      unlocked=1
    fi
    run_drush cron:run search_api_cron --force
    run_drush cron
  fi
  flock -u 9
  # The export takes the shared backup/worker locks itself; never invoke it
  # while this shell holds worker.lock. Startup also retries expired cleanup.
  if ! paused; then
    run_drush localnodes:site-export-process
  fi

  if [ "$WORKER_MAX_ITERATIONS" -gt 0 ] && [ "$iteration" -ge "$WORKER_MAX_ITERATIONS" ]; then
    log "stopping after ${iteration} iteration(s)"
    exit 0
  fi
  sleep "$WORKER_INTERVAL"
done
