#!/usr/bin/env bash
# Validates the isolation on the machine it is deployed on, using synthetic targets only: addresses nothing lives at,
# CLOSED ports (a refusal from the policy is distinguishable from a closed port's reset, see src/isolation-check.js), and
# one neutral public site. It never connects to an open internal service and never starts an inspection.
#
#   isolation/validate-network.sh pre     after install.sh and `docker compose up --no-start`, BEFORE the worker is started
#   isolation/validate-network.sh post    after `systemctl start well-applied-browser-inspector`
#
# "pre" runs throwaway containers (no ports, no volumes, removed on exit) on the same networks, with the same hardening,
# using the worker image. "post" executes inside the running worker and relay.
# NOTE: this script has been syntax-checked but not run against a live host; its first run is on the AI box.
set -uo pipefail
MODE=${1:-}
IMAGE=${IMAGE:-well-applied-browser-inspector:local}
PROJECT=${PROJECT:-well-applied-browser-inspector}
EGRESS=${PROJECT}_egress; LINK=${PROJECT}_relay-link
WORKER=well-applied-browser-inspector; RELAY=well-applied-api-relay
CANARIES="172.29.60.1:9,172.29.61.1:9,172.29.62.1:9,192.168.1.111:9,192.168.1.114:9"
pass=0; fail=0
ok()  { pass=$((pass+1)); echo "  ok    $1"; }
bad() { fail=$((fail+1)); echo "  FAIL  $1"; }
HARDEN=(--rm --cap-drop ALL --security-opt no-new-privileges:true --read-only --tmpfs /tmp --pids-limit 128 --memory 512m)

case "$MODE" in
pre)
  echo "== the networks exist with the expected bridges"
  for n in "$EGRESS:br-inspector" "$LINK:br-relaylink" "${PROJECT}_relay-out:br-relay"; do
    got=$(docker network inspect -f '{{index .Options "com.docker.network.bridge.name"}}' "${n%%:*}" 2>/dev/null || true)
    [ "$got" = "${n##*:}" ] && ok "${n%%:*} is ${n##*:}" || bad "${n%%:*}: bridge is '${got}', expected ${n##*:}"
  done
  echo "== the host policy is loaded and intact"
  sudo /usr/local/sbin/inspector-isolation verify && ok "policy intact" || bad "policy not intact"

  echo "== from the egress network: every canary refused fast, privileges dropped"
  docker run "${HARDEN[@]}" --network "$EGRESS" --dns 9.9.9.9 --dns 1.1.1.1 --user pwuser --entrypoint node "$IMAGE" -e '
    import("./src/isolation-check.js").then(async (m) => {
      const v = await m.checkIsolation({ extraTargets: process.argv[1].split(",") });
      for (const c of v.checks) console.log("   ", c.target, "->", c.outcome, c.code ?? "");
      console.log(v.ok ? "OK" : "FAILED: " + v.failures.join("; "));
      process.exit(v.ok ? 0 : 1);
    });' "$CANARIES" && ok "isolation self-check passes from the egress network" || bad "isolation self-check failed on the egress network"

  echo "== from the egress network: the public web works, a closed public port and IPv6 do not"
  docker run "${HARDEN[@]}" --network "$EGRESS" --dns 9.9.9.9 --dns 1.1.1.1 --user pwuser --entrypoint node "$IMAGE" -e '
    const net = require("node:net"), dns = require("node:dns").promises;
    const t = (h, p) => new Promise((r) => { const s = net.connect({ host: h, port: p, timeout: 4000 }); s.on("connect", () => { s.destroy(); r("connected"); }); s.on("timeout", () => { s.destroy(); r("timeout"); }); s.on("error", (e) => r(e.code)); });
    (async () => {
      const a = await dns.resolve4("example.com");
      console.log("   example.com ->", a.join(","));
      const results = { web: await t(a[0], 443), port8080: await t(a[0], 8080), v6: await t("fd00::1", 443) };
      console.log("   ", JSON.stringify(results));
      process.exit(results.web === "connected" && results.port8080 !== "connected" && results.v6 !== "connected" ? 0 : 1);
    })().catch((e) => { console.log("   error", e.code ?? e.message); process.exit(1); });' && ok "public web reachable; :8080 and IPv6 are not" || bad "public/port/IPv6 expectations failed"
  ;;

post)
  echo "== the worker is running isolated and passed its own check"
  docker ps --filter "name=^${WORKER}$" --filter status=running -q | grep -q . && ok "worker container is running" || bad "worker is not running"
  docker logs "$WORKER" 2>&1 | grep -q "isolation self-check passed" && ok "the worker's self-check passed at start" || bad "no passing self-check in the worker's log"
  docker logs "$WORKER" 2>&1 | grep -q "ISOLATION CHECK FAILED" && bad "the worker logged an isolation failure" || ok "no isolation failure logged"

  echo "== from inside the worker: only the relay's two endpoints exist, and auth is enforced"
  docker exec "$WORKER" node -e '
    const call = async (method, path, headers = {}) => { try { const r = await fetch("http://api-relay:8081" + path, { method, headers }); return r.status; } catch (e) { return "error " + (e.cause?.code ?? e.message); } };
    (async () => {
      const r = { claimNoToken: await call("POST", "/api/worker/agent-runs/claim"), claimGet: await call("GET", "/api/worker/agent-runs/claim", { authorization: "Bearer abcdefgh12345678" }), root: await call("POST", "/", { authorization: "Bearer abcdefgh12345678" }) };
      console.log("   ", JSON.stringify(r));
      process.exit(r.claimNoToken === 401 && r.claimGet === 405 && r.root === 404 ? 0 : 1);
    })();' && ok "relay answers 401 / 405 / 404 as designed" || bad "relay behaviour unexpected"

  echo "== the relay reaches Well Applied (a Laravel JSON answer proves the whole path)"
  docker exec "$WORKER" node -e '
    fetch("http://api-relay:8081/api/worker/agent-runs/claim", { method: "POST", headers: { accept: "application/json", authorization: "Bearer deliberately-wrong-token-0000" } })
      .then(async (r) => { const j = (r.headers.get("content-type") || "").includes("json"); console.log("    status", r.status, j ? "(JSON from Laravel)" : "(not JSON: the relay answered itself)"); process.exit(j && [401, 403].includes(r.status) ? 0 : 1); })
      .catch((e) => { console.log("    error", e.cause?.code ?? e.message); process.exit(1); });' && ok "claim with a wrong token is refused BY Well Applied" || bad "the relay did not reach Well Applied"

  echo "== the worker cannot reach Well Applied or the host directly (closed ports, so no service is touched)"
  docker exec "$WORKER" node -e '
    import("./src/isolation-check.js").then(async (m) => {
      const v = await m.checkIsolation({ extraTargets: process.argv[1].split(",") });
      console.log("   ", v.ok ? "all canaries refused" : v.failures.join("; "));
      process.exit(v.ok ? 0 : 1);
    });' "$CANARIES" && ok "canaries still refused from the running worker" || bad "a canary was not refused"
  ;;

*) echo "usage: validate-network.sh pre|post" >&2; exit 2 ;;
esac

echo; echo "RESULT: $pass passed, $fail failed"; [ "$fail" -eq 0 ]
