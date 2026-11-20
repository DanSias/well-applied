#!/usr/bin/env bash
# The host controller against the REAL nft in a private network namespace (run inside the privileged test container; see
# run-guard-integration-test.sh). docker and systemctl are stand-ins that record what the guard asks of them. Proves:
#  - the fingerprint is stable: verify passes repeatedly on the real kernel's listing,
#  - a deleted table, an edited table and a full `flush ruleset` are each noticed and undone,
#  - the guard reacts to an nftables CHANGE EVENT immediately, not only on its timer (the timer is set to 60s here).
set -u
HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT=/script/bin/inspector-isolation
export INSPECTOR_ISOLATION_RULES=/script/inspector-isolation.nft
export INSPECTOR_ISOLATION_STATE=/tmp/iso-state
export INSPECTOR_GUARD_INTERVAL=60
export SANDBOX=/tmp/sandbox; mkdir -p $SANDBOX/bin
cat >$SANDBOX/bin/docker <<'D'
#!/usr/bin/env bash
case "$1" in ps) cat "$SANDBOX/containers" 2>/dev/null ;; kill) shift; echo "$(date +%s.%N) kill $*" >>"$SANDBOX/docker.log"; : >"$SANDBOX/containers" ;; esac
D
cat >$SANDBOX/bin/systemctl <<'D'
#!/usr/bin/env bash
echo "$(date +%s.%N) $*" >>"$SANDBOX/systemctl.log"
D
chmod +x $SANDBOX/bin/*; export PATH=$SANDBOX/bin:$PATH
PASS=0; FAIL=0
ok(){ PASS=$((PASS+1)); echo "  ok    $1"; }; bad(){ FAIL=$((FAIL+1)); echo "  FAIL  $1"; }
intact(){ bash $SCRIPT verify 2>/dev/null; }
wait_intact(){ for _ in $(seq 1 40); do intact && return 0; sleep 0.1; done; return 1; }

bash $SCRIPT apply 2>&1 | sed 's/^/   /'
intact && ok "verify passes right after apply (real kernel listing)" || bad "verify after apply"
for i in 1 2 3; do intact || { bad "verify stable on repeat $i"; break; }; done; ok "verify is stable across repeated listings"

echo victim >$SANDBOX/containers
bash $SCRIPT guard 2>$SANDBOX/guard.err & GUARD=$!
sleep 1.0

t0=$(date +%s.%N); nft delete table inet inspector_isolation
wait_intact && ok "deleted table: restored" || bad "deleted table: not restored"
t1=$(date +%s.%N)
grep -q "kill victim" $SANDBOX/docker.log 2>/dev/null && ok "deleted table: labelled container killed" || bad "container not killed"
grep -q "stop --no-block well-applied-browser-inspector.service" $SANDBOX/systemctl.log 2>/dev/null && ok "deleted table: worker unit stopped" || bad "unit not stopped"
python3 - <<PY
import re
t0=$t0; t1=$t1
log=open("$SANDBOX/docker.log").read().split()
kill_at=float(log[0])
print(f"   container killed {kill_at-t0:.2f}s after the table was deleted")
open("$SANDBOX/fast","w").write("1" if kill_at-t0 < 5 else "0")
PY
[ "$(cat $SANDBOX/fast)" = 1 ] && ok "the kill came within 5 s of the deletion, long before the 60 s timer" || bad "reaction was slow"

: >$SANDBOX/docker.log; echo victim2 >$SANDBOX/containers
nft add rule inet inspector_isolation forward accept
wait_intact && ok "edited table (an added accept rule): restored" || bad "edited table not restored"
grep -q "kill victim2" $SANDBOX/docker.log && ok "edited table: container killed" || bad "edit: not killed"
nft list table inet inspector_isolation | grep -c "forward accept$" | grep -q '^0$' && ok "the added rule is gone" || bad "added rule survived"

: >$SANDBOX/docker.log; echo victim3 >$SANDBOX/containers
nft flush ruleset
wait_intact && ok "flush ruleset: policy restored" || bad "flush ruleset: not restored"
grep -q "kill victim3" $SANDBOX/docker.log && ok "flush ruleset: container killed" || bad "flush: not killed"

: >$SANDBOX/docker.log; echo bystander >$SANDBOX/containers; sleep 1.5
[ ! -s $SANDBOX/docker.log ] && ok "an intact policy causes no further action" || bad "spurious containment"

kill $GUARD 2>/dev/null; wait $GUARD 2>/dev/null
bash $SCRIPT remove 2>/dev/null; nft list table inet inspector_isolation >/dev/null 2>&1 && bad "remove left the table" || ok "remove deletes the table"
echo; echo "RESULT: $PASS passed, $FAIL failed"; [ $FAIL -eq 0 ]
