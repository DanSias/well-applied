#!/usr/bin/env bash
# Proves the worker image still works with the hardening compose.yaml applies: every capability dropped, no-new-privileges,
# a read-only root filesystem, tmpfs for /tmp and the home directory, a pids/memory/cpu limit, and NO network at all (the
# tests serve their own local fixtures, so nothing is contacted). Run it wherever the image is built: on the AI box before
# deployment, because kernel and AppArmor settings differ between hosts (Ubuntu 26.04 restricts unprivileged user namespaces,
# which Chromium's sandbox uses).
#
#   isolation/validate-hardening.sh [IMAGE]        (default: career-toolkit-browser-worker:local)
#
# From the worker directory, so ./test is found. Exits non-zero on any failure.
set -euo pipefail
IMAGE=${1:-career-toolkit-browser-worker:local}
PWUSER_UID=$(docker run --rm --entrypoint id "$IMAGE" -u pwuser)
HARDEN=(--rm --network none --init --cap-drop ALL --security-opt no-new-privileges:true --read-only
        --tmpfs /tmp:size=512m,mode=1777 --tmpfs "/home/pwuser:size=256m,mode=0755,uid=$PWUSER_UID,gid=$PWUSER_UID"
        --shm-size 512m --memory 2g --cpus 2 --pids-limit 512)
fail=0
say() { printf '%s\n' "$*"; }

say "== the compose.yaml tmpfs home matches the image's pwuser uid"
grep -q "uid=$PWUSER_UID,gid=$PWUSER_UID" compose.yaml && say "  ok    uid $PWUSER_UID" || { say "  FAIL  compose.yaml tmpfs uid is not $PWUSER_UID"; fail=1; }

say "== privileges inside the hardened container"
docker run "${HARDEN[@]}" --entrypoint node "$IMAGE" -e '
  import("./src/isolation-check.js").then(async (m) => {
    const fs = await import("node:fs");
    const failures = m.processFailures(fs.readFileSync("/proc/self/status", "utf8"));
    console.log(failures.length ? "  FAIL  " + failures.join("; ") : "  ok    no capabilities, no_new_privs set, not root");
    process.exit(failures.length ? 1 : 0);
  });' || fail=1

say "== the root filesystem is read-only; tmp and home are writable"
docker run "${HARDEN[@]}" --entrypoint sh "$IMAGE" -c '
  if touch /app/should-fail 2>/dev/null; then echo "  FAIL  /app is writable"; exit 1; else echo "  ok    /app is read-only"; fi
  touch /tmp/x && touch "$HOME/x" && echo "  ok    /tmp and \$HOME are writable"' || fail=1

say "== Chromium and the worker under that hardening (local fixtures only, no network)"
docker run "${HARDEN[@]}" -v "$PWD/test:/app/test:ro" --entrypoint node "$IMAGE" --test \
  test/greenhouse-extractor.test.js test/page-kind.test.js test/apply-links.test.js test/navigation.test.js test/guard.test.js \
  test/isolation-check.test.js test/redact.test.js 2>&1 | grep -E "^ℹ (tests|pass|fail)|✖|not ok" || true
RESULT=$(docker run "${HARDEN[@]}" -v "$PWD/test:/app/test:ro" --entrypoint node "$IMAGE" --test \
  test/greenhouse-extractor.test.js test/page-kind.test.js test/apply-links.test.js test/navigation.test.js test/guard.test.js \
  test/isolation-check.test.js test/redact.test.js 2>&1 | grep -E "^ℹ fail" | awk '{print $3}')
[ "$RESULT" = "0" ] && say "  ok    all pass under the hardening" || { say "  FAIL  $RESULT test(s) failed under the hardening"; fail=1; }

say; [ "$fail" -eq 0 ] && say "HARDENING VALIDATION PASSED" || say "HARDENING VALIDATION FAILED"
exit "$fail"
