#!/usr/bin/env bash
# Proves the worker image still works with the hardening compose.yaml applies: every capability dropped, no-new-privileges,
# a read-only root filesystem, tmpfs for /tmp and the home directory, a pids/memory/cpu limit, and NO network at all (the
# tests serve their own local fixtures, so nothing is contacted). Run it wherever the image is built: on the AI box before
# deployment, because kernel and AppArmor settings differ between hosts (Ubuntu 26.04 restricts unprivileged user namespaces,
# which Chromium's sandbox uses).
#
#   isolation/validate-hardening.sh [IMAGE]        (default: well-applied-browser-inspector:local)
#
# From the worker directory, so ./test is found. Exits non-zero on any failure.
set -euo pipefail
IMAGE=${1:-well-applied-browser-inspector:local}
PWUSER_UID=$(docker run --rm --entrypoint id "$IMAGE" -u pwuser)
PROFILE="$PWD/isolation/chromium-seccomp.json"
[ -r "$PROFILE" ] || { echo "run from the worker directory (isolation/chromium-seccomp.json not found)" >&2; exit 2; }
HARDEN=(--rm --network none --init --cap-drop ALL --security-opt no-new-privileges:true --security-opt "seccomp=$PROFILE" -e BROWSER_WORKER_CHROMIUM_SANDBOX=1 --read-only
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

say "== Chromium's own sandbox is IN FORCE, not just running (the container's seccomp profile is the one compose.yaml uses)"
docker run "${HARDEN[@]}" -v "$PWD/isolation/sandbox-probe.mjs:/app/probe.mjs:ro" -e SANDBOX=true --entrypoint node "$IMAGE" /app/probe.mjs | tee /tmp/.sandbox-probe.$$ | python3 -c '
import json, sys
for line in sys.stdin:
    if line.startswith("{"):
        d = json.loads(line)
        kids = {c["type"]: c for c in d.get("children", [])}
        r, b = kids.get("renderer"), kids.get("browser")
        print("  launched:", d.get("launched"), "| --no-sandbox on any process:", d.get("noSandboxFlag"))
        if r and b:
            print("  renderer: user ns", r["userNs"][-5:], "(browser", b["userNs"][-5:] + ")", "| pid ns", r["pidNs"][-5:], "(browser", b["pidNs"][-5:] + ")", "| seccomp", r["seccomp"], "| caps", r["capEff"])
        print("  ok   " if d.get("sandboxActive") else "  FAIL ", "sandbox active" if d.get("sandboxActive") else "sandbox NOT active " + d.get("error", ""))
' || fail=1
grep -q '"sandboxActive":true' /tmp/.sandbox-probe.$$ || fail=1; rm -f /tmp/.sandbox-probe.$$

say "== negative control: Playwright's DEFAULT launch runs without the sandbox; Docker's DEFAULT seccomp cannot give it one"
DEFAULT_HARDEN=(--rm --network none --init --cap-drop ALL --security-opt no-new-privileges:true --read-only
        --tmpfs /tmp:size=512m,mode=1777 --tmpfs "/home/pwuser:size=256m,mode=0755,uid=$PWUSER_UID,gid=$PWUSER_UID" --shm-size 512m)
docker run "${DEFAULT_HARDEN[@]}" -v "$PWD/isolation/sandbox-probe.mjs:/app/probe.mjs:ro" -e SANDBOX=default --entrypoint node "$IMAGE" /app/probe.mjs | grep -q '"noSandboxFlag":true' \
  && say "  ok    default launch options run with --no-sandbox (so 'Chromium starts' proves nothing about the sandbox)" || say "  note  default launch did not use --no-sandbox"
if docker run "${DEFAULT_HARDEN[@]}" -v "$PWD/isolation/sandbox-probe.mjs:/app/probe.mjs:ro" -e SANDBOX=true --entrypoint node "$IMAGE" /app/probe.mjs >/dev/null 2>&1; then
  say "  note  the sandbox ALSO works under Docker's default seccomp on this host: the custom profile may not be needed here"
else
  say "  ok    with Docker's default seccomp the sandbox cannot start on this host (why isolation/chromium-seccomp.json exists)"
fi

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
