#!/usr/bin/env bash
#
# One bounded capacity run: a fresh, isolated universe at N AI accounts, measured
# the same way the 10-account grand pilot was. Runs the exact grand-test protocol
# (fresh DB, real registration path, 1000x speeds, hybrid drivers, 5s session
# interval) against its OWN database — never the holy `ogamex-grand`, never the
# dev/test database.
#
# Usage:
#   bash local-docker-dev/capacity-run.sh <players> [window_seconds] [keep] [mode]
#
#   players         number of AI accounts (2 / 5 / 10 for the comparison)
#   window_seconds  how long the scheduler+worker run before the report (default 480)
#   keep            if the third arg is "keep", the stack and DB stay up for a re-read
#   mode            "hybrid" (default) or "native" — the cognition mode and the DB name
#
# Output: a JSON report at storage/capacity/ogamex-cap[-native]-<players>.json plus a human
# summary on stdout. The database is created here and is dropped at the end unless "keep" is given.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PLAYERS="${1:?usage: capacity-run.sh <players> [window_seconds] [keep] [mode]}"
WINDOW="${2:-480}"
KEEP="${3:-}"
MODE="${4:-hybrid}"
DB="ogamex-cap-${PLAYERS}"
PREFIX="ogamex_cap${PLAYERS}"
HORIZON="ogamex_cap${PLAYERS}_horizon:"
PORT="$((9100 + PLAYERS))"
if [ "$MODE" = "native" ]; then
    DB="ogamex-cap-native-${PLAYERS}"
    PREFIX="ogamex_capn${PLAYERS}"
    HORIZON="ogamex_capn${PLAYERS}_horizon:"
    PORT="$((9200 + PLAYERS))"
fi
COMPOSE=(docker compose -f "$ROOT/local-docker-dev/docker-compose.capacity.yml")
export CAP_DB="$DB" CAP_PREFIX="$PREFIX" CAP_HORIZON="$HORIZON" CAP_PORT="$PORT" CAP_MODE="$MODE"

OUT_DIR="$ROOT/storage/capacity"
mkdir -p "$OUT_DIR"
REPORT_JSON="$OUT_DIR/$DB.json"

say() { printf '\n[capacity %s] %s\n' "$PLAYERS" "$*"; }

mysql_exec() {
    MYSQL_PWD="" mysql -h 127.0.0.1 -P 3306 -u root --batch --skip-column-names -e "$1"
}

exec_app() {
    "${COMPOSE[@]}" exec -T ogamex-app "$@"
}

# `docker compose exec` succeeds the moment the container starts, well before the
# entrypoint finishes `composer install` + `migrate`. The host entrypoint applies
# the host's own migrations AND the enabled module's migrations (nWidart registers
# the module path), so the migration rows are the readiness signal: wait for the
# full host+module count before touching the database.
wait_for_app() {
    local total
    total=$(($(ls "$ROOT/database/migrations"/*.php 2>/dev/null | wc -l) + $(ls "$ROOT/Modules/AI/database/migrations"/*.php 2>/dev/null | wc -l)))
    say "waiting for the full migration to finish ($total rows)"
    for _ in $(seq 1 240); do
        if [ "$(mysql_exec "SELECT COUNT(*) FROM \`$DB\`.migrations" 2>/dev/null || echo 0)" -ge "$total" ]; then
            return 0
        fi
        sleep 2
    done
    say "migration never completed in time"
    return 1
}

say "universe: DB=$DB port=$PORT window=${WINDOW}s players=$PLAYERS"

# 1. A fresh database, created only if it does not already exist.
mysql_exec "CREATE DATABASE IF NOT EXISTS \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

# 2. Bring up only the app container (it migrates the host schema on start).
say "starting app container (migrates host schema)"
"${COMPOSE[@]}" up -d ogamex-app >/dev/null
wait_for_app

# 3. One human first account and the speed rows (1000x), then the N AI accounts
#    through the real registration path. The prepare script bootstraps Laravel,
#    which a bare `php -r` in the container does not. No module migrate here:
#    the host entrypoint already applied the module's migrations.
exec_app php /var/www/local-docker-dev/capacity-prepare.php "$PLAYERS"
exec_app php artisan ai:seed-grand-test --players="$PLAYERS" --confirm

# 4. Scheduler + queue worker up; the universe now runs on its own.
say "starting scheduler and queue worker"
"${COMPOSE[@]}" --profile queue up -d >/dev/null

# 5. The bounded window.
say "running for ${WINDOW}s"
sleep "$WINDOW"

# 6. Collect the report (its own machine-readable shape) and the worker's resident memory.
say "collecting the pilot report"
exec_app php artisan ai:pilot-report --json > "$REPORT_JSON" 2>/tmp/capacity-report-err.txt || {
    cat /tmp/capacity-report-err.txt >&2
    say "report failed"
}
printf '  report bytes: %s\n' "$(wc -c < "$REPORT_JSON")"

say "collecting worker memory (queue worker)"
docker stats --no-stream --format '{{.Name}} {{.MemUsage}}' \
    | grep -E "capacity.*queue" || true

say "done — report at $REPORT_JSON"

if [ "$KEEP" != "keep" ]; then
    say "stopping the stack (DB kept: $DB)"
    "${COMPOSE[@]}" --profile queue down >/dev/null 2>&1 || true
else
    say "keeping the stack and DB up ($DB) for a re-read"
fi
