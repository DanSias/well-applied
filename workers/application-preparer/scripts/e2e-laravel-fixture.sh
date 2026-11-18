#!/usr/bin/env bash
# Local END-TO-END fixture test: the real Laravel backend, the real agent and the real preparation engine, against a
# LOCAL fixture form. Synthetic data only; no live site; nothing is submitted. It uses a disposable database and a
# separate storage directory, so your development database and storage are never touched.
#
#   workers/application-preparer/scripts/e2e-laravel-fixture.sh            # greenhouse fixture
#   FIXTURE_PAGE=/lever-form.html FIXTURE_ATS=lever workers/application-preparer/scripts/e2e-laravel-fixture.sh
#
# Needs: PHP + composer deps (dev), Node >= 22.18, Playwright's Chromium. Takes about a minute.
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
WORKER="$(cd "$HERE/.." && pwd)"
APP="$(cd "$WORKER/../.." && pwd)"
E2E="$(mktemp -d "${TMPDIR:-/tmp}/wa-e2e.XXXXXX")"
PORT="${E2E_PORT:-8765}"
PAGE="${FIXTURE_PAGE:-/greenhouse-form.html}"
ATS="${FIXTURE_ATS:-greenhouse}"
PIDS=()

cleanup() {
    for pid in "${PIDS[@]:-}"; do [ -n "$pid" ] && kill "$pid" 2>/dev/null || true; done
    echo "(disposable files left in $E2E; remove with: rm -rf $E2E)"
}
trap cleanup EXIT

# Everything Laravel writes goes under $E2E: a disposable database and storage directory.
mkdir -p "$E2E/storage/framework/"{cache/data,sessions,views,testing} "$E2E/storage/logs" "$E2E/storage/app/private" "$E2E/agent-work"
touch "$E2E/db.sqlite"
export DB_CONNECTION=sqlite DB_DATABASE="$E2E/db.sqlite" LARAVEL_STORAGE_PATH="$E2E/storage"
export APP_ENV=local CACHE_STORE=file SESSION_DRIVER=file QUEUE_CONNECTION=sync
export PREPARER_WORKER_TOKEN="e2e-preparer-token" BROWSER_WORKER_TOKEN="e2e-inspector-token" PREPARER_ALLOW_LOCAL_FIXTURE=true

cd "$APP"
echo "== migrate (disposable database)"
php artisan migrate --force --no-interaction </dev/null >/dev/null

echo "== start the local fixture form server"
(cd "$WORKER" && node test/e2e-serve-fixtures.ts "$E2E/origin.txt") &
PIDS+=($!)
for _ in $(seq 1 50); do [ -s "$E2E/origin.txt" ] && break; sleep 0.2; done
ORIGIN="$(cat "$E2E/origin.txt")"

echo "== seed synthetic data (job points at $ORIGIN$PAGE)"
FIXTURE_ORIGIN="$ORIGIN" FIXTURE_PAGE="$PAGE" FIXTURE_PDF="$WORKER/fixtures/sample-resume.pdf" \
    php artisan tinker --execute="require base_path('tests/Support/e2e/seed-preparation.php');" </dev/null

echo "== start Laravel on 127.0.0.1:$PORT"
# php's built-in server directly (not `artisan serve`, which does not forward every environment variable, such as the
# disposable storage path). variables_order lets Laravel read LARAVEL_STORAGE_PATH.
(cd "$APP/public" && exec php -d variables_order=EGPCS -S "127.0.0.1:$PORT" "$APP/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php") >"$E2E/serve.log" 2>&1 &
PIDS+=($!)
for _ in $(seq 1 50); do curl -s -o /dev/null "http://127.0.0.1:$PORT/up" && break; sleep 0.2; done

echo "== dispatch a preparation (the same code the Prepare button will call)"
php artisan tinker --execute='$r = app(\App\Support\ApplicationPreparation\DispatchApplicationPreparation::class)->dispatch(\App\Models\JobPosting::firstOrFail()); echo "workflow_run={$r["workflowRun"]->id} created=".json_encode($r["created"])."\n";' </dev/null

echo "== run the agent once (real engine, headless, local fixture only)"
(cd "$WORKER" && CAREER_TOOLKIT_URL="http://127.0.0.1:$PORT" PREPARER_ALLOW_LOCAL_FIXTURE=1 PREPARER_FIXTURE_ATS="$ATS" \
    PREPARER_WORK_DIR="$E2E/agent-work" node src/agent.ts --once)

echo "== what Laravel recorded"
php artisan tinker --execute='
$run = \App\Models\AgentRun::where("agent_type","application_preparer")->latest("id")->firstOrFail();
$a = app(\App\Support\ApplicationPreparation\PreparationArtifacts::class);
echo "agent_run status:   ".$run->status->value."\n";
echo "workflow status:    ".$run->workflowStep->workflowRun->status->value."\n";
echo "report outcome:     ".($run->result_summary["outcome"] ?? "-")."\n";
echo "reviewable:         ".json_encode($run->result_summary["reviewable"] ?? null)."\n";
echo "integrity flags:    ".json_encode($run->result_summary["integrity_flags"] ?? null)."\n";
echo "screenshots:        ".json_encode($run->result_summary["screenshots"] ?? null)."\n";
echo "upload:             ".json_encode($run->result_summary["upload"] ?? null)."\n";
echo "counts:             ".json_encode($run->result_summary["counts"] ?? null)."\n";
echo "applicant keys sent: ".implode(",", $run->input_summary["applicant_keys"])."\n";
echo "omitted keys:       ".json_encode($run->input_summary["omitted_keys"])."\n";
echo "stored screenshots: ".count($a->screenshotNames($run))."\n";
echo "report readable:    ".json_encode($a->readReport($run) !== null)."\n";
echo "submissions:        ".\App\Models\Submission::count()."\n";
' </dev/null
echo "== local agent work directory after delivery (should be empty):"
ls -A "$E2E/agent-work" | sed 's/^/   /' || true
echo "== done"
