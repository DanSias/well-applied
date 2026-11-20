# Browser inspector network isolation

The inspector follows Apply links to employer sites, so untrusted pages run JavaScript in its Chromium. The worker's own URL
checks are not enough (DNS rebinding, redirects of subresources, a compromised browser). This directory makes the **host**
enforce what the worker's containers can reach, and makes the worker unable to run without that enforcement.

## Architecture

```
                         ┌──────────── AI box (Ubuntu, Docker 29, iptables-nft, Tailscale) ────────────┐
 internet  ◄── 80/443 ── │ br-inspector ── browser-inspector ──┐                                          │
 (public only)           │   172.29.60.0/24                    │ br-relaylink (internal, no gateway)      │
                         │                                     └──── api-relay ── br-relay ── 100.84.185.81:8000 (tailscale0)
                         │  nftables table inet inspector_isolation @ priority -10 (host-enforced)        │   (Well Applied, the Mac)
                         │  systemd: policy unit ▸ guard unit ▸ worker unit                               │
                         └────────────────────────────────────────────────────────────────────────────────┘
```

| Bridge | Who | May reach |
|---|---|---|
| `br-inspector` | the worker (Chromium) | **public** addresses, TCP 80/443, UDP 443, DNS to 9.9.9.9 / 1.1.1.1. Not: any private, loopback, link-local (cloud metadata), CGNAT (**all of Tailscale**), documentation, multicast or reserved range; not the AI box; not IPv6. |
| `br-relaylink` | worker ⇄ relay | each other (layer 2). Forwards nothing. |
| `br-relay` | the relay | `100.84.185.81:8000` (Well Applied, the Mac's **Tailscale** address) and nothing else, and only when the packet leaves through `tailscale0`: if Tailscale is down the route falls back to the LAN or default gateway, which the policy refuses. |

Anything arriving from these bridges and addressed to **the AI box itself** (its LAN and tailnet addresses, Docker's bridge
gateways, SSH, Ollama, Open WebUI) is refused at the input hook. UFW is inactive on this host, so INPUT would otherwise be open.

The relay (`relay/nginx.conf`) exposes exactly `POST /api/worker/agent-runs/claim` and `POST /api/worker/agent-runs/<n>/result`
and requires a bearer token to be present; the web UI is unreachable through it. Because Chromium shares the relay's link
(Chromium and the worker are one container), the relay is also what limits what a page could do if it rebound a name onto it.

### Why a table of its own at priority -10 (verified on the AI box)

On the AI box `FORWARD` is `ts-forward → DOCKER-USER → DOCKER-FORWARD`, policy DROP, all at priority 0, and Tailscale's
`ts-forward` **ACCEPTs everything forwarded out of tailscale0** before `DOCKER-USER` is ever reached. A rule in `DOCKER-USER`
therefore could not stop a container reaching the tailnet. This table's base chains run at **-10**, ahead of every one of
those. Netfilter evaluates every base chain on a hook in priority order, so:

* a `reject` here is final, whatever an accept at priority 0 would say;
* an `accept` here only means "this table has no objection". It does **not** skip the later chains: Docker's policy DROP and
  its own rules still decide. The table can only ever remove access, never grant it.

`test/isolation/netns-test.sh` reproduces that exact hook layout (a Tailscale-style accept and a Docker-style policy DROP at
priority 0) and checks both statements, in both `br_netfilter` modes.

## Fail-closed startup: four independent layers

1. **The policy exists before Docker does.** `inspector-isolation.service` loads it atomically (`Before=docker.service`; a
   drop-in makes Docker `Wants=` it, never `Requires=`, so a firewall error cannot take Ollama or Open WebUI down).
2. **Only systemd starts the worker, and only after verifying the policy.** `well-applied-browser-inspector.service` has
   `Requires=` on the policy and its guard, and `ExecStartPre=+inspector-isolation verify`, which refuses to start unless the
   table loaded in the kernel is exactly what was applied (file hash, table hash, both base chains at priority -10). Compose's
   `restart: "no"` stops Docker starting the containers on its own. `Restart=on-failure` goes back through `verify`.
3. **A host guard watches continuously.** `inspector-isolation-guard.service` runs `nft monitor` (reacts to any change within
   about 0.1 s) plus a 3 s timer. The moment the table is deleted, edited, or the ruleset flushed, it **kills every container
   carrying the label `com.well-applied.isolation=required`** (however it was started, including by hand), stops the worker
   unit, then restores the policy. The worker stays stopped until you start it. This is independent of the worker's code.
4. **The worker checks itself.** At start and before every claim it needs the canary connections to be refused with *host
   unreachable* (not "connection refused", not a timeout), its capabilities to be empty, `no_new_privs` set, and not to be
   root; otherwise it exits (status 70) without claiming work. This is supplemental evidence, not proof: a compromised worker
   could lie, and rules cannot be proven from inside. Layers 1 to 3 are the enforcement.

Stopping the policy unit stops the guard (`BindsTo=`), which stops the worker (`Requires=`), in that order.

**Limits.** Between a rule being deleted and the guard reacting there is a window of milliseconds (measured: 0.09 to 0.18 s).
Nothing here defends against root on the AI box. IPv6 is dropped for these bridges and Docker's networks here have it off.

## Chromium's own sandbox

**Found while validating on the AI box (Docker 29.8.1, Ubuntu 26.04):** Chromium's sandbox (each renderer in its own user, pid and
network namespaces behind a seccomp filter) was **never on** in the original deployment, and the old documentation was wrong to
say it was. Two separate reasons:

1. Playwright launches Chromium with `--no-sandbox` unless `chromiumSandbox: true`. "Chromium launches" proves nothing.
2. With `chromiumSandbox: true`, Chromium **refuses to start** under Docker's default seccomp profile, with no other
   hardening applied at all ("Chromium sandboxing failed!"). Docker's default allows `clone`/`unshare`/`setns` only to a container
   holding `CAP_SYS_ADMIN`, and `chroot` only with `CAP_SYS_CHROOT`. It is not `cap_drop`, `read_only` or `no-new-privileges`
   (each tried alone), and AppArmor's user-namespace restriction on this host is not the blocker (`seccomp=unconfined` alone was
   enough).

The isolated deployment turns the sandbox on (`BROWSER_WORKER_CHROMIUM_SANDBOX=1`) and gives the container a seccomp profile,
`chromium-seccomp.json`, that is **Docker's default with exactly four syscalls (`clone`, `unshare`, `setns`, `chroot`) allowed to
unprivileged processes**. Every other rule stays (`mount`, `bpf`, module loading, `reboot`, `keyctl` ... remain blocked or
capability-gated). It is **not** `seccomp=unconfined`. `make-chromium-seccomp.py` derives it reproducibly from upstream's
`default.json`; `test/chromium-sandbox-config.test.js` pins its properties.

`validate-hardening.sh` proves the sandbox is **in force**, not merely that Chromium starts: no process has `--no-sandbox`, the
renderer lives in a different user and pid namespace from the browser, behind its own seccomp filter, with no capabilities. It also
runs two negative controls (Playwright's default launch is unsandboxed; Docker's default seccomp cannot start the sandbox).

**The trade-off, stated plainly.** The profile lets a process inside the container create user namespaces of its own, which enlarges
the kernel attack surface reachable from a compromised process (a bug in kernel namespace code). The container still has no
capabilities, `no-new-privileges`, a read-only root, the network policy above and resource limits. The alternative is a browser
with no sandbox at all, loading untrusted pages. Docker's built-in profile can change between releases and this copy does not follow
it: re-run `make-chromium-seccomp.py` against the new `default.json` when upgrading Docker, and diff. To go back to the old
behaviour, remove the `seccomp=` line and `BROWSER_WORKER_CHROMIUM_SANDBOX` from `compose.yaml` (the worker's start-up check then
still requires a seccomp filter to be installed).

## Files

| File | Installed as |
|---|---|
| `compose.yaml` | in the worker directory (`/opt/well-applied/browser-inspector/`) |
| `relay/nginx.conf` | `<worker dir>/relay/nginx.conf` |
| `isolation/inspector-isolation.nft` | `/etc/inspector-isolation/inspector-isolation.nft` |
| `isolation/bin/inspector-isolation` | `/usr/local/sbin/inspector-isolation` |
| `isolation/inspector-isolation.service`, `…-guard.service` | `/etc/systemd/system/` |
| `isolation/well-applied-browser-inspector.service` | `/etc/systemd/system/` (rendered for the user and directory) |
| `isolation/docker.service.d-inspector-isolation.conf` | `/etc/systemd/system/docker.service.d/inspector-isolation.conf` |
| `isolation/install.sh`, `rollback.sh` | run from the repository copy |
| `isolation/chromium-seccomp.json` (from `make-chromium-seccomp.py`) | referenced by `compose.yaml` as `./isolation/chromium-seccomp.json`; ships with the worker directory |
| `isolation/validate-hardening.sh`, `validate-network.sh`, `sandbox-probe.mjs` | run on the AI box |

## Assumptions to confirm before deploying

* **The Mac is `100.84.185.81` on the tailnet** (`daniels-macbook-air`). No DHCP reservation is needed: the Mac's LAN address no
  longer appears anywhere (the relay, the policy and the canaries use the Tailscale address, and a test fails if they disagree).
  A node keeps its Tailscale address until it is removed from the tailnet and re-added; then it gets a new one, the relay fails
  closed, and you update the three places. To change it: `sudo systemctl stop well-applied-browser-inspector`, edit
  `inspector-isolation.nft`, `relay/nginx.conf` and `compose.yaml` (canaries), copy the policy to `/etc/inspector-isolation/`,
  `sudo inspector-isolation apply`, then start the worker. (Stop the worker first: while the table and its recorded fingerprint
  disagree, the guard rightly treats the policy as lost.) Use the **IP, not the MagicDNS name**: the relay has no resolver by design.
* **Tailscale must be running on both machines** (the worker unit orders itself after `tailscaled` and waits up to 30 s for
  `tailscale0`, but never starts, stops or restarts it), and the tailnet ACL must allow `ai-box` to reach the Mac on TCP 8000 (it
  does today; no change is needed). Optional hardening: restrict that grant to `tcp:8000`. The ACL sees the AI box, not the relay,
  so the nftables policy is what limits which container may use the grant. If the Mac's Tailscale key expires, the Mac drops off
  the tailnet (its address is kept) until you re-authenticate; the Mac asleep is offline, exactly as it was on the LAN.
* DNS is **9.9.9.9 / 1.1.1.1**, not the router, so internal names are never resolved for the worker.
* Only **TCP 80/443 and UDP 443** leave the worker. An application site on another port will fail.
* Well Applied listens on `0.0.0.0:8000` (`SERVER_HOST=0.0.0.0`), which includes the Mac's Tailscale interface, and the Mac's own firewall allows it (it did when checked; Tailscale's ShieldsUp is off).

## Layout and naming

| | Path / name |
|---|---|
| Active worker | `/opt/well-applied/browser-inspector/` (owner `daniel:daniel`, mode 0750; contains `.env`, mode 0600) |
| Backups | `/opt/well-applied/backups/` (owner `daniel:daniel`, mode 0700: it holds the old `.env`) |
| `/opt/well-applied` | `root:root`, 0755 (the worker cannot rewrite its own parent) |
| systemd unit | `well-applied-browser-inspector.service` |
| Compose project / containers | `well-applied-browser-inspector`: `well-applied-browser-inspector`, `well-applied-api-relay` |
| Image | `well-applied-browser-inspector:local` |
| Docker networks | `well-applied-browser-inspector_{egress,relay-link,relay-out}` (bridges `br-inspector`, `br-relaylink`, `br-relay`, unchanged) |
| **Unchanged on purpose** | `CAREER_TOOLKIT_URL`, `BROWSER_WORKER_TOKEN`, `BROWSER_WORKER_IDENTITY` (the worker's API contract and the existing `.env`), the `/api/worker/...` paths, the `com.well-applied.isolation=required` label, the `inspector-isolation*` units and `inspector_isolation` nft table |

Everything runs as `daniel`, never root: the unit has `User=daniel`, and inside the container Chromium runs as `pwuser` with no capabilities.
Only `/opt/well-applied` and the unit/policy files are created with sudo.

The **legacy** worker is `~/career-toolkit-browser-worker/` (Compose project and image `career-toolkit-browser-worker`, container
`career-toolkit-browser-worker`, network `career-toolkit-browser-worker_default`). The new project has different names for all of these, so the two
can never collide or share a network, and **the legacy directory and images are never modified or moved by the migration**: that is
the rollback. The staged `~/career-toolkit-browser-worker-next/` is superseded; it is archived, not deleted.

## Deployment sequence (migration from the legacy layout)

Nothing on the AI box is changed by merging this. The inspector is **offline only from step 6 to step 9**. Ollama, Open WebUI,
Tailscale, the Application Preparer and the NAS are never touched. `[Mac]` / `[AI box]` marks where a command runs; `sudo` is
needed only where shown.

1. **[AI box, sudo]** Create the layout. Nothing running is affected.
   ```sh
   sudo install -d -o root   -g root   -m 0755 /opt/well-applied
   sudo install -d -o daniel -g daniel -m 0750 /opt/well-applied/browser-inspector
   sudo install -d -o daniel -g daniel -m 0700 /opt/well-applied/backups
   ```
2. **[Mac]** Copy the worker files (from the repository, on `master` once merged). `--delete` is safe: the target is new.
   ```sh
   rsync -az --delete --exclude node_modules --exclude .env workers/browser-inspector/ daniel@ai-box:/opt/well-applied/browser-inspector/
   ```
3. **[AI box]** Back up the legacy deployment and carry the token over. `cp -a` keeps the legacy directory in place (it is the
   rollback); the running worker is not affected.
   ```sh
   STAMP=$(date +%Y%m%d-%H%M%S)
   cp -a ~/career-toolkit-browser-worker /opt/well-applied/backups/career-toolkit-browser-worker.legacy-$STAMP
   cp ~/career-toolkit-browser-worker/.env /opt/well-applied/browser-inspector/.env
   chmod 600 /opt/well-applied/browser-inspector/.env
   # the superseded staging directory (no secrets) is archived, not deleted:
   [ -d ~/career-toolkit-browser-worker-next ] && mv ~/career-toolkit-browser-worker-next /opt/well-applied/backups/career-toolkit-browser-worker-next.staged-$STAMP
   ```
4. **[AI box]** Make sure the rollback image tag exists (it must not be removed), then build the new image alongside the running worker:
   ```sh
   docker image inspect career-toolkit-browser-worker:pre-isolation >/dev/null 2>&1 || docker tag career-toolkit-browser-worker:local career-toolkit-browser-worker:pre-isolation
   cd /opt/well-applied/browser-inspector
   docker build -t well-applied-browser-inspector:local .
   isolation/validate-hardening.sh well-applied-browser-inspector:local     # Chromium under the exact hardening, no network
   docker pull nginxinc/nginx-unprivileged:1.27-alpine
   ```
   Stop here if `validate-hardening.sh` fails; see "If Chromium will not start hardened".
5. **[AI box]** Confirm what is about to be replaced: `docker ps` shows `career-toolkit-browser-worker` running, and
   `ls /opt/well-applied/backups` shows the backup.
6. **Cutover begins [AI box]**: stop the legacy worker with its own compose file. The legacy directory, image and `.env` stay as they are.
   ```sh
   cd ~/career-toolkit-browser-worker && docker compose down
   ```
7. **[AI box, sudo]** Install the isolation. It checks the policy with `nft -c` first, loads it atomically, starts the guard, and
   installs and enables (but does not start) the worker unit:
   ```sh
   cd /opt/well-applied/browser-inspector
   sudo isolation/install.sh --user daniel --dir /opt/well-applied/browser-inspector
   sudo /usr/local/sbin/inspector-isolation verify && echo intact
   sudo nft list table inet inspector_isolation | head -30
   ```
8. **[AI box]** Create the networks and containers without starting them, then test them (the worker is still stopped):
   ```sh
   cd /opt/well-applied/browser-inspector
   docker compose up --no-start
   isolation/validate-network.sh pre
   ```
   `pre` includes a throwaway container on the relay network that must reach `100.84.185.81:8000` (so the Mac must be online on the tailnet with Well Applied running) and be refused everywhere else.
9. **[AI box, sudo]** Start the worker (through systemd, the only supported way):
   ```sh
   sudo systemctl start well-applied-browser-inspector
   journalctl -u well-applied-browser-inspector -n 40 --no-pager      # expect: "isolation self-check passed", then polling
   isolation/validate-network.sh post
   ```
10. Confirm the worker shows **online** in Well Applied (its presence badge), and `budget_s=90` in the first claim's log line.
11. **Fail-closed drill** (optional, interrupts only the inspector): `sudo nft delete table inet inspector_isolation` → within a
    second `systemctl status well-applied-browser-inspector` shows it stopped, `docker ps` no longer lists it,
    `sudo /usr/local/sbin/inspector-isolation verify` passes again, and `journalctl -u inspector-isolation-guard` says
    `ISOLATION LOST`. Then `sudo systemctl start well-applied-browser-inspector`.
12. **Later, after you are satisfied (a separate decision):** the legacy directory and the `career-toolkit-browser-worker:*` images may
    be archived or removed. Until then they are the rollback.

The single, separately-authorised read-only Jobicy inspection comes after this.

### Privileged commands in total
`install.sh` (does: `nft -c -f`, `nft -f` via the unit, `systemctl daemon-reload`, `enable --now inspector-isolation`,
`enable --now inspector-isolation-guard`, `enable well-applied-browser-inspector`), `systemctl start well-applied-browser-inspector`,
`inspector-isolation verify`, `nft list table …`, `rollback.sh`.

### What installing does to the running system
It adds one nftables table. Its rules match three interface names that do not exist until step 8, so no existing packet
matches them; each packet entering the host's forward and input hooks does one extra interface-name comparison. It adds a
Docker drop-in that takes effect at Docker's **next** start (it is not restarted by this). No existing container, network,
firewall rule, Tailscale setting or Docker configuration is modified.

## Rollback

Fast path (isolation off, legacy worker back). Valid at any point after step 6, because the legacy directory and image are never modified:
```sh
cd /opt/well-applied/browser-inspector
sudo isolation/rollback.sh --purge          # stops worker, guard and policy (in that order); removes units, drop-in, script, table
docker compose down                          # the new project's containers and networks
cd ~/career-toolkit-browser-worker && docker compose up -d     # the legacy compose file, image (:local) and restart policy
```
`/opt/well-applied/` is left in place (it is inert once the unit is gone); remove it with `sudo rm -rf /opt/well-applied` only if you
want it gone. If the legacy directory was removed after step 12, restore it from
`/opt/well-applied/backups/career-toolkit-browser-worker.legacy-*` and `docker tag career-toolkit-browser-worker:pre-isolation career-toolkit-browser-worker:local`.
Then confirm the worker is online in Well Applied. `rollback.sh` is idempotent and removes nothing but what `install.sh` wrote.
To keep the policy but stop the worker: `sudo systemctl stop well-applied-browser-inspector`.

## If Chromium will not start hardened
`validate-hardening.sh` runs the real Chromium tests under every option compose applies. Ubuntu 26.04 restricts unprivileged
user namespaces, and Chromium's sandbox uses them. If a test fails, change **one** option at a time and re-run: first
`read_only: true` (and widen the tmpfs mounts), then `no-new-privileges`. Do **not** drop `cap_drop: [ALL]`: it removes
`CAP_NET_RAW`, and the worker's start-up check (and the interface-based rules) assume it. Do not add `--no-sandbox`, and do not
use `seccomp=unconfined`: use `chromium-seccomp.json` (see "Chromium's own sandbox"). The worker's self-check refuses to run
without a seccomp filter.

## Tests
| What | How | Run |
|---|---|---|
| nftables policy semantics (78 checks: ordering vs Docker/Tailscale-style chains, every blocked range, host, the Mac over a simulated tailscale0, wrong ports and neighbouring tailnet addresses, the wrong outgoing interface, relay-link, spoofing, IPv6, atomic reload) | privileged, network-less container with network namespaces | `docker build -t isolation-nft-test test/isolation/image && test/isolation/run-netns-test.sh` (set `BRNF=0/1`) |
| relay allow-list and normalisation | the real nginx image, fake Well Applied | `test/isolation/run-relay-test.sh` |
| guard against the real `nft` | same container | `test/isolation/run-guard-integration-test.sh` |
| controller, install/rollback, config consistency, worker self-check, redaction | `npm test` | |
| hardened container, with the sandbox verified IN FORCE | `isolation/validate-hardening.sh` | on the AI box (passed there: 104/104) |
| live isolation | `isolation/validate-network.sh pre|post` | on the AI box |
