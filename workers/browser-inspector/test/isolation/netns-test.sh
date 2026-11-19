#!/usr/bin/env bash
# Behavioural test of isolation/inspector-isolation.nft in a throwaway network topology. Run INSIDE a privileged container
# with no network (see run-netns-test.sh): it creates namespaces, bridges and listeners and touches nothing outside it.
#
#   H  the "host": bridges br-inspector / br-relaylink / br-relay, its own LAN address 192.168.1.111, Docker-style and
#      Tailscale-style chains at priority 0 (FORWARD policy DROP, accept for br-* and for oifname tailscale0)
#   W  the worker (on br-inspector and br-relaylink)      R  the relay (on br-relay and br-relaylink)
#   X  "the internet" and every blocked range             M  the Mac (192.168.1.114)       T  a tailnet peer (100.64.1.5)
set -u
RULES=${RULES:-/rules/inspector-isolation.nft}
HERE=$(cd "$(dirname "$0")" && pwd)
PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); printf '  ok    %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  FAIL  %s  (got %s, wanted %s)\n' "$1" "$2" "$3"; }
probe(){ local ns=$1; shift; ip netns exec "$ns" python3 "$HERE/probe.py" "$@"; }
# expect NS DESCRIPTION WANT HOST PORT [SOURCE]
expect(){ local ns=$1 desc=$2 want=$3; shift 3; local got; got=$(probe "$ns" "$1" "$2" "${TMO:-1.2}" "${3:-}"); [ "$got" = "$want" ] && ok "$desc" || bad "$desc" "$got" "$want"; }
not_connected(){ local ns=$1 desc=$2; shift 2; local got; got=$(probe "$ns" "$1" "$2" "${TMO:-1.2}" "${3:-}"); [ "$got" != connected ] && ok "$desc ($got)" || bad "$desc" "$got" "anything but connected"; }

sysctl -qw net.ipv4.ip_forward=1 net.ipv6.conf.all.forwarding=1
# Linux rate-limits the ICMP errors a reject sends (a burst of 6, then one a second, per peer), so back-to-back probes would
# see "timeout" for some refusals. A timeout is still not a connection, but this test wants deterministic answers, so it is
# switched off in this private namespace. The rate-limited behaviour is exercised separately below.
sysctl -qw net.ipv4.icmp_ratelimit=0
# The policy must hold whether or not br_netfilter makes bridged traffic visible to netfilter (it varies by host).
BRNF=${BRNF:-1}
echo "br_netfilter bridge-nf-call-iptables=$BRNF"
[ -e /proc/sys/net/bridge/bridge-nf-call-iptables ] && sysctl -qw net.bridge.bridge-nf-call-iptables=$BRNF || true
for n in W R X M T; do ip netns add $n; ip netns exec $n ip link set lo up; done
link(){ # link HOSTIF NS NSIF  (veth pair, host end up, namespace end up)
  ip link add "$1" type veth peer name "$3" netns "$2"; ip link set "$1" up; ip netns exec "$2" ip link set "$3" up; }

# --- the host's bridges (names as in compose.yaml) and its own LAN address
for b in br-inspector br-relaylink br-relay; do ip link add $b type bridge; ip link set $b up; done
ip addr add 172.29.60.1/24 dev br-inspector; ip addr add 172.29.61.1/24 dev br-relaylink; ip addr add 172.29.62.1/24 dev br-relay
ip -6 addr add fd00:60::1/64 dev br-inspector nodad
ip link add lan0 type dummy; ip link set lan0 up; ip addr add 192.168.1.111/24 dev lan0

# --- worker W: br-inspector (egress) + br-relaylink (internal link)
link w0 W w1; ip link set w0 master br-inspector; ip netns exec W ip addr add 172.29.60.3/24 dev w1; ip netns exec W ip -6 addr add fd00:60::3/64 dev w1 nodad
ip netns exec W ip route add default via 172.29.60.1; ip netns exec W ip -6 route add default via fd00:60::1
link wl0 W wl1; ip link set wl0 master br-relaylink; ip netns exec W ip addr add 172.29.61.3/24 dev wl1
# --- relay R: br-relay (to Well Applied) + br-relaylink
link r0 R r1; ip link set r0 master br-relay; ip netns exec R ip addr add 172.29.62.2/24 dev r1; ip netns exec R ip route add default via 172.29.62.1
link rl0 R rl1; ip link set rl0 master br-relaylink; ip netns exec R ip addr add 172.29.61.2/24 dev rl1
# --- X: the internet and every blocked range, all as loopback addresses reached through H
link hx0 X hx1; ip addr add 10.99.1.1/30 dev hx0; ip netns exec X ip addr add 10.99.1.2/30 dev hx1; ip netns exec X ip route add default via 10.99.1.1
ip -6 addr add fd00:99::1/64 dev hx0 nodad; ip netns exec X ip -6 addr add fd00:99::2/64 dev hx1 nodad; ip netns exec X ip -6 route add default via fd00:99::1
PUBLIC="8.8.8.8 1.1.1.1 93.184.216.34"
BLOCKED="10.255.255.254 169.254.169.254 100.127.255.254 192.168.255.254 172.31.255.254"
for a in $PUBLIC $BLOCKED; do ip netns exec X ip addr add $a/32 dev lo; ip route add $a/32 via 10.99.1.2; done
ip netns exec X ip -6 addr add fd00:ee::1/128 dev lo nodad; ip -6 route add fd00:ee::1/128 via fd00:99::2
# --- M: the Mac. T: a tailnet peer behind the interface named tailscale0
link hm0 M hm1; ip addr add 10.99.2.1/30 dev hm0; ip netns exec M ip addr add 10.99.2.2/30 dev hm1; ip netns exec M ip route add default via 10.99.2.1
ip netns exec M ip addr add 192.168.1.114/32 dev lo; ip route add 192.168.1.114/32 via 10.99.2.2
link tailscale0 T t1; ip addr add 10.99.3.1/30 dev tailscale0; ip netns exec T ip addr add 10.99.3.2/30 dev t1; ip netns exec T ip route add default via 10.99.3.1
ip netns exec T ip addr add 100.64.1.5/32 dev lo; ip route add 100.64.0.0/10 via 10.99.3.2 dev tailscale0
# --- stand-in services
ip netns exec X python3 "$HERE/listen.py" 80 443 53 8080 22 >/dev/null 2>&1 &
ip netns exec M python3 "$HERE/listen.py" 8000 >/dev/null 2>&1 &
ip netns exec T python3 "$HERE/listen.py" 80 >/dev/null 2>&1 &
ip netns exec R python3 "$HERE/listen.py" 8081 >/dev/null 2>&1 &
ip netns exec W python3 "$HERE/listen.py" 7777 >/dev/null 2>&1 &
python3 "$HERE/listen.py" 22 11434 3000 >/dev/null 2>&1 &     # services on the host itself
sleep 0.5

# --- what is already on the real AI box at priority 0: Docker's FORWARD policy DROP with accepts for its bridges, and
#     Tailscale's ts-forward, which runs FIRST and ACCEPTs anything forwarded out of tailscale0
nft add table ip simfilter
nft 'add chain ip simfilter FORWARD { type filter hook forward priority 0; policy drop; }'
nft add rule ip simfilter FORWARD oifname '"tailscale0"' accept
nft add rule ip simfilter FORWARD iifname '"br-*"' accept
nft add rule ip simfilter FORWARD ct state established,related accept
nft 'add chain ip simfilter INPUT { type filter hook input priority 0; policy accept; }'
nft add table ip6 simfilter6
nft 'add chain ip6 simfilter6 FORWARD { type filter hook forward priority 0; policy accept; }'

sleep 2   # let IPv6 duplicate-address detection and neighbour discovery settle before the baseline
echo "== baseline: no isolation policy loaded (proves every probe below CAN succeed, so a later block means something)"
for t in "93.184.216.34 443" "10.255.255.254 80" "169.254.169.254 80" "100.127.255.254 80" "100.64.1.5 80" "192.168.1.114 8000" "192.168.1.111 11434" "172.29.60.1 22" "fd00:ee::1 443"; do
  set -- $t; expect W "worker reaches $1:$2 with no policy" connected "$1" "$2"
done
expect R "relay reaches the Mac with no policy" connected 192.168.1.114 8000
expect R "relay reaches the internet with no policy" connected 93.184.216.34 443

echo "== policy loaded"
nft -c -f "$RULES" && ok "policy passes nft -c" || bad "policy passes nft -c" fail ok
nft -f "$RULES" && ok "policy loads in one transaction" || bad "policy loads" fail ok
echo "-- worker (br-inspector): public web only"
for a in $PUBLIC; do expect W "worker -> $a:443 allowed" connected $a 443; expect W "worker -> $a:80 allowed" connected $a 80; done
expect W "worker -> 1.1.1.1:53 (the configured resolver) allowed" connected 1.1.1.1 53
expect W "worker -> 8.8.8.8:53 (any other resolver) refused" rejected 8.8.8.8 53
expect W "worker -> public :8080 refused (only 80/443)" rejected 93.184.216.34 8080
expect W "worker -> public :22 refused" rejected 93.184.216.34 22
echo "-- worker: every private range, with Docker's and Tailscale's accepts sitting at priority 0"
for a in $BLOCKED; do expect W "worker -> $a:80 refused fast" rejected $a 80; done
expect W "worker -> tailnet peer 100.64.1.5:80 (forwarded out of tailscale0) refused" rejected 100.64.1.5 80
expect W "worker -> Mac 192.168.1.114:8000 directly refused" rejected 192.168.1.114 8000
expect W "worker -> Mac closed port :9 refused by the policy, not by the Mac" rejected 192.168.1.114 9
echo "-- worker -> the host itself (INPUT is open on the real box: UFW is inactive)"
expect W "worker -> host LAN address :11434 refused" rejected 192.168.1.111 11434
expect W "worker -> its own gateway :22 refused" rejected 172.29.60.1 22
expect W "worker -> relay-link gateway :22 refused" rejected 172.29.61.1 22
expect W "worker -> the relay's gateway :22 refused" rejected 172.29.62.1 22
echo "-- worker <-> relay on the internal link (same bridge, not routed)"
expect W "worker -> relay 172.29.61.2:8081 allowed" connected 172.29.61.2 8081
echo "-- relay (br-relay): Well Applied and nothing else"
expect R "relay -> Mac :8000 allowed" connected 192.168.1.114 8000
expect R "relay -> Mac :9 refused" rejected 192.168.1.114 9
expect R "relay -> the internet refused" rejected 93.184.216.34 443
expect R "relay -> tailnet peer refused" rejected 100.64.1.5 80
expect R "relay -> host LAN address refused" rejected 192.168.1.111 11434
expect R "relay -> blocked range refused" rejected 10.255.255.254 80
expect R "relay -> worker over the relay link works (replies to the worker)" connected 172.29.61.3 7777
echo "-- a worker that tries to cheat"
# spoof the relay's address on the egress bridge: rules follow the interface, not the source address
ip netns exec W ip addr add 172.29.62.2/32 dev w1
# (the ICMP refusal goes to the FORGED source, so the worker never hears it: a timeout is the correct, blocked result)
not_connected W "worker spoofing the relay's source address cannot reach the Mac :8000" 192.168.1.114 8000 172.29.62.2
ip netns exec W ip addr del 172.29.62.2/32 dev w1
# route the Mac over the relay link: that bridge forwards nothing
ip netns exec W ip route add 192.168.1.114/32 via 172.29.61.1 dev wl1
expect W "worker routing the Mac over the relay link refused" rejected 192.168.1.114 8000
ip netns exec W ip route add 93.184.216.34/32 via 172.29.61.1 dev wl1 2>/dev/null
expect W "worker routing even a public address over the relay link refused" rejected 93.184.216.34 443
ip netns exec W ip route del 192.168.1.114/32; ip netns exec W ip route del 93.184.216.34/32
echo "-- IPv6"
TMO=1.0 not_connected W "worker -> IPv6 destination via the egress bridge" fd00:ee:: 443
TMO=1.0 not_connected W "worker -> IPv6 destination (host form)" fd00:ee::1 443

echo "== hook ordering"
# An accept in THIS table must not override a drop in a later (priority 0) chain.
nft add table ip laterdrop
nft 'add chain ip laterdrop FORWARD { type filter hook forward priority 0; policy accept; }'
nft add rule ip laterdrop FORWARD ip daddr 93.184.216.34 tcp dport 443 drop
TMO=1.0 not_connected W "an accept here does not override a later drop (allowed destination dropped downstream)" 93.184.216.34 443
expect W "other allowed destinations unaffected by that drop" connected 8.8.8.8 443
nft delete table ip laterdrop
expect W "and the destination works again once the later drop is gone" connected 93.184.216.34 443
# A reject here must not be undone by an accept at priority 0 (already shown above); and the hooks sit where we claim.
nft list hooks 2>/dev/null | grep -q 'chain inet inspector_isolation forward' && ok "hook list shows the isolation chains" || ok "hook list not supported by this nft (skipped)"
nft list table inet inspector_isolation | grep -q 'hook forward priority filter - 10' && ok "forward base chain is at priority -10" || bad "forward priority" "other" "-10"
nft list table inet inspector_isolation | grep -q 'hook input priority filter - 10' && ok "input base chain is at priority -10" || bad "input priority" "other" "-10"

echo "== atomic replacement: no window in which a blocked destination is reachable"
python3 - "$HERE" <<'PY' &
import subprocess, sys, time
# probe a blocked address from W continuously for 8 seconds
end = time.time() + 8; seen = {}; n = 0
while time.time() < end:
    out = subprocess.run(['ip','netns','exec','W','python3',sys.argv[1]+'/probe.py','10.255.255.254','80','0.5'],capture_output=True,text=True).stdout.strip()
    seen[out] = seen.get(out,0)+1; n += 1
open('/tmp/atomic.out','w').write(repr(seen))
PY
PROBER=$!
for i in $(seq 1 25); do nft -f "$RULES"; sleep 0.2; done
wait $PROBER
RES=$(cat /tmp/atomic.out); echo "   probes: $RES"
case "$RES" in *connected*) bad "no probe connected during 25 reloads" "$RES" "only rejected";; *rejected*) ok "no probe connected during 25 reloads ($RES)";; *) bad "prober ran" "$RES" "rejected";; esac

echo "== removal and re-application"
nft delete table inet inspector_isolation
expect W "with the table removed the worker reaches a blocked range again (so the test sees the difference)" connected 10.255.255.254 80
nft -f "$RULES"
expect W "re-applied: refused again" rejected 10.255.255.254 80

echo "== with Linux's default ICMP rate limit restored, refusals may arrive as timeouts but are never connections"
sysctl -qw net.ipv4.icmp_ratelimit=1000
LIMITED=$(for i in $(seq 1 20); do probe W 10.255.255.254 80 0.3; done | sort | uniq -c | tr '\n' ' ')
echo "   20 probes at the default rate limit: $LIMITED"
case "$LIMITED" in *connected*|*refused*) bad "rate-limited refusals never connect" "$LIMITED" "rejected/timeout only";; *) ok "rate-limited refusals never connect ($LIMITED)";; esac

echo; echo "RESULT: $PASS passed, $FAIL failed"; [ "$FAIL" -eq 0 ]
