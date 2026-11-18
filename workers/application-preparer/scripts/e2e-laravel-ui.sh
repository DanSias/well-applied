#!/usr/bin/env bash
# Local FULL-UI fixture test: the real Laravel app and Opportunities UI, the real agent and the real engine, against a
# LOCAL fixture form. Synthetic data only; no live site; nothing is submitted. A disposable database and storage
# directory are used, so your development database and storage are never touched. It keeps running until Ctrl-C.
#
#   workers/application-preparer/scripts/e2e-laravel-ui.sh
#   then open the URL it prints, go to the Application preparation section, and click "Prepare application".
#
# Needs: PHP + dev dependencies, Node >= 22.18, Playwright's Chromium, and the front-end assets: either `npm run dev`
# running (public/hot exists) or a built `public/build` (npm run build).
# Options: AGENT=0 to start no agent (the attempt then stays queued, which is how to try Cancel);
#          FIXTURE_PAGE=/lever-form.html FIXTURE_ATS=lever for the Lever fixture; E2E_PORT=8765.
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
WORKER="$(cd "$HERE/.." && pwd)"
APP="$(cd "$WORKER/../.." && pwd)"
E2E="$(mktemp -d "${TMPDIR:-/tmp}/wa-ui.XXXXXX")"
PORT="${E2E_PORT:-8765}"
PAGE="${FIXTURE_PAGE:-/greenhouse-form.html}"
ATS="${FIXTURE_ATS:-greenhouse}"
PIDS=()

cleanup() {
    for pid in "${PIDS[@]:-}"; do [ -n "$pid" ] && kill "$pid" 2>/dev/null || true; done
    echo; echo "(stopped. Disposable files are in $E2E; remove with: rm -rf $E2E)"
}
trap cleanup EXIT INT TERM

if [ ! -e "$APP/public/hot" ] && [ ! -e "$APP/public/build/manifest.json" ]; then
    echo "No front-end assets: start 'npm run dev' (or run 'npm run build') first." >&2
    exit 1
fi

mkdir -p "$E2E/storage/framework/"{cache/data,sessions,views,testing} "$E2E/storage/logs" "$E2E/storage/app/private" "$E2E/agent-work"
touch "$E2E/db.sqlite"
export DB_CONNECTION=sqlite DB_DATABASE="$E2E/db.sqlite" LARAVEL_STORAGE_PATH="$E2E/storage"
export APP_ENV=local CACHE_STORE=file SESSION_DRIVER=file QUEUE_CONNECTION=sync
export PREPARER_WORKER_TOKEN="e2e-preparer-token" BROWSER_WORKER_TOKEN="e2e-inspector-token" PREPARER_ALLOW_LOCAL_FIXTURE=true

cd "$APP"
echo "== migrate (disposable database)"
php artisan migrate --force --no-interaction </dev/null >/dev/null

echo "== local fixture form server"
(cd "$WORKER" && node test/e2e-serve-fixtures.ts "$E2E/origin.txt") &
PIDS+=($!)
for _ in $(seq 1 50); do [ -s "$E2E/origin.txt" ] && break; sleep 0.2; done
ORIGIN="$(cat "$E2E/origin.txt")"

echo "== seed synthetic data"
FIXTURE_ORIGIN="$ORIGIN" FIXTURE_PAGE="$PAGE" FIXTURE_PDF="$WORKER/fixtures/sample-resume.pdf" \
    php artisan tinker --execute="require base_path('tests/Support/e2e/seed-preparation.php');" </dev/null

echo "== Laravel on 127.0.0.1:$PORT"
(cd "$APP/public" && exec php -d variables_order=EGPCS -S "127.0.0.1:$PORT" "$APP/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php") >"$E2E/serve.log" 2>&1 &
PIDS+=($!)
for _ in $(seq 1 50); do curl -s -o /dev/null "http://127.0.0.1:$PORT/up" && break; sleep 0.2; done

if [ "${AGENT:-1}" != "0" ]; then
    echo "== preparation agent (polling every 2 s, real engine, local fixture only)"
    (cd "$WORKER" && CAREER_TOOLKIT_URL="http://127.0.0.1:$PORT" PREPARER_ALLOW_LOCAL_FIXTURE=1 PREPARER_FIXTURE_ATS="$ATS" \
        PREPARER_POLL_INTERVAL_MS=2000 PREPARER_WORK_DIR="$E2E/agent-work" node src/agent.ts) >"$E2E/agent.log" 2>&1 &
    PIDS+=($!)
else
    echo "== no agent (AGENT=0): an attempt will stay queued until you start one yourself:"
    echo "   cd $WORKER && CAREER_TOOLKIT_URL=http://127.0.0.1:$PORT PREPARER_WORKER_TOKEN=$PREPARER_WORKER_TOKEN PREPARER_ALLOW_LOCAL_FIXTURE=1 PREPARER_FIXTURE_ATS=$ATS PREPARER_WORK_DIR=$E2E/agent-work node src/agent.ts --once"
fi

echo
echo "Ready:  http://127.0.0.1:$PORT/opportunities/1"
echo "Logs:   $E2E/serve.log  $E2E/agent.log"
echo "Press Ctrl-C to stop everything."
wait
