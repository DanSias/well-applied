# Browser inspector network isolation

The inspector follows Apply links to employer sites, so untrusted pages run JavaScript in its Chromium. The worker's own URL
checks are not enough (DNS rebinding, redirects of subresources, a compromised browser). This directory makes the **host**
enforce what the worker's containers can reach, and makes the worker unable to run without that enforcement.

## Architecture

```
                         ┌──────────── AI box (Ubuntu, Docker 29, iptables-nft, Tailscale) ────────────┐
 internet  ◄── 80/443 ── │ br-inspector ── browser-inspector ──┐                                          │
 (public only)           │   172.29.60.0/24                    │ br-relaylink (internal, no gateway)      │
                         │                                     └──── api-relay ── br-relay ── 192.168.1.114:8000
                         │  nftables table inet inspector_isolation @ priority -10 (host-enforced)        │   (Well Applied, the Mac)
                         │  systemd: policy unit ▸ guard unit ▸ worker unit                               │
                         └────────────────────────────────────────────────────────────────────────────────┘
```

| Bridge | Who | May reach |
|---|---|---|
| `br-inspector` | the worker (Chromium) | **public** addresses, TCP 80/443, UDP 443, DNS to 9.9.9.9 / 1.1.1.1. Not: any private, loopback, link-local (cloud metadata), CGNAT (**all of Tailscale**), documentation, multicast or reserved range; not the AI box; not IPv6. |
| `br-relaylink` | worker ⇄ relay | each other (layer 2). Forwards nothing. |
| `br-relay` | the relay | `192.168.1.114:8000` (Well Applied) and nothing else. |

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
2. **Only systemd starts the worker, and only after verifying the policy.** `career-toolkit-browser-worker.service` has
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

## Files

| File | Installed as |
|---|---|
| `compose.yaml` | in the worker directory |
| `relay/nginx.conf` | `<worker dir>/relay/nginx.conf` |
| `isolation/inspector-isolation.nft` | `/etc/inspector-isolation/inspector-isolation.nft` |
| `isolation/bin/inspector-isolation` | `/usr/local/sbin/inspector-isolation` |
| `isolation/inspector-isolation.service`, `…-guard.service` | `/etc/systemd/system/` |
| `isolation/career-toolkit-browser-worker.service` | `/etc/systemd/system/` (rendered for the user and directory) |
| `isolation/docker.service.d-inspector-isolation.conf` | `/etc/systemd/system/docker.service.d/inspector-isolation.conf` |
| `isolation/install.sh`, `rollback.sh` | run from the repository copy |
| `isolation/validate-hardening.sh`, `validate-network.sh` | run on the AI box |

## Assumptions to confirm before deploying

* **`192.168.1.114` (the Mac) stays that address.** Reserve it in your router's DHCP. It appears in the policy, the relay and
  the canaries (a test fails if they disagree). To change it: `sudo systemctl stop career-toolkit-browser-worker`, edit all
  three, copy the policy to `/etc/inspector-isolation/`, `sudo inspector-isolation apply`, then start the worker. (Stop the
  worker first: while the table and its recorded fingerprint disagree, the guard rightly treats the policy as lost.)
* DNS is **9.9.9.9 / 1.1.1.1**, not the router, so internal names are never resolved for the worker.
* Only **TCP 80/443 and UDP 443** leave the worker. An application site on another port will fail.
* Well Applied listens on `0.0.0.0:8000` (`SERVER_HOST=0.0.0.0`) and the Mac accepts the AI box's address (as it does today).

## Deployment sequence

Nothing on the AI box is changed by merging this. Steps 3 to 8 are the cutover; the inspector is **offline from step 4 to
step 8** (a few minutes). Ollama, Open WebUI, Tailscale, the Application Preparer and the NAS are never touched.

On the Mac (the repository):

1. `rsync -az --delete --exclude node_modules --exclude .env workers/browser-inspector/ daniel@ai-box:~/career-toolkit-browser-worker-next/`

On the AI box:

2. **Build alongside the running worker** (it is not affected), and keep the old image:
   ```sh
   docker tag career-toolkit-browser-worker:local career-toolkit-browser-worker:pre-isolation
   cd ~/career-toolkit-browser-worker-next
   docker build -t career-toolkit-browser-worker:next .
   docker tag career-toolkit-browser-worker:next career-toolkit-browser-worker:local   # the running container keeps its own image
   isolation/validate-hardening.sh career-toolkit-browser-worker:local                  # Chromium under the exact hardening, no network
   docker pull nginxinc/nginx-unprivileged:1.27-alpine
   ```
   Stop here if `validate-hardening.sh` fails; see "If Chromium will not start hardened".
3. **Cutover begins.** Stop the old worker with its own compose file, then swap directories (the token in `.env` carries over):
   ```sh
   cd ~/career-toolkit-browser-worker && docker compose down
   mv ~/career-toolkit-browser-worker ~/career-toolkit-browser-worker.pre-isolation
   mv ~/career-toolkit-browser-worker-next ~/career-toolkit-browser-worker
   cp ~/career-toolkit-browser-worker.pre-isolation/.env ~/career-toolkit-browser-worker/.env && chmod 600 ~/career-toolkit-browser-worker/.env
   ```
4. **Install the isolation (privileged).** It checks the policy with `nft -c` first, loads it atomically, starts the guard, and
   installs and enables (but does not start) the worker unit:
   ```sh
   cd ~/career-toolkit-browser-worker
   sudo isolation/install.sh --user daniel --dir /home/daniel/career-toolkit-browser-worker
   sudo /usr/local/sbin/inspector-isolation verify && echo intact
   sudo nft list table inet inspector_isolation | head -30
   ```
5. **Create the networks and containers without starting them, then test them (the worker is still stopped):**
   ```sh
   docker compose up --no-start
   isolation/validate-network.sh pre
   ```
6. **Start the worker (privileged, through systemd, the only supported way):**
   ```sh
   sudo systemctl start career-toolkit-browser-worker
   journalctl -u career-toolkit-browser-worker -n 40 --no-pager      # expect: "isolation self-check passed", then polling
   isolation/validate-network.sh post
   ```
7. Confirm the worker shows **online** in Well Applied (its presence badge), and `budget_s=90` in the first claim's log line.
8. **Fail-closed drill** (optional, interrupts only the inspector): `sudo nft delete table inet inspector_isolation` → within a
   second `systemctl status career-toolkit-browser-worker` shows it stopped, `docker ps` no longer lists it,
   `sudo /usr/local/sbin/inspector-isolation verify` passes again, and `journalctl -u inspector-isolation-guard` says
   `ISOLATION LOST`. Then `sudo systemctl start career-toolkit-browser-worker`.

The single, separately-authorised read-only Jobicy inspection comes after this.

### Privileged commands in total
`install.sh` (does: `nft -c -f`, `nft -f` via the unit, `systemctl daemon-reload`, `enable --now inspector-isolation`,
`enable --now inspector-isolation-guard`, `enable career-toolkit-browser-worker`), `systemctl start career-toolkit-browser-worker`,
`inspector-isolation verify`, `nft list table …`, `rollback.sh`.

### What installing does to the running system
It adds one nftables table. Its rules match three interface names that do not exist until step 5, so no existing packet
matches them; each packet entering the host's forward and input hooks does one extra interface-name comparison. It adds a
Docker drop-in that takes effect at Docker's **next** start (it is not restarted by this). No existing container, network,
firewall rule, Tailscale setting or Docker configuration is modified.

## Rollback

Fast path (isolation off, old worker back):
```sh
cd ~/career-toolkit-browser-worker
sudo isolation/rollback.sh --purge          # stops worker, guard and policy (in that order); removes units, drop-in, script, table
docker compose down                          # the new project's containers and networks
cd ~ && mv career-toolkit-browser-worker career-toolkit-browser-worker.isolated
mv career-toolkit-browser-worker.pre-isolation career-toolkit-browser-worker
docker tag career-toolkit-browser-worker:pre-isolation career-toolkit-browser-worker:local
cd ~/career-toolkit-browser-worker && docker compose up -d     # the original compose file and its restart policy
```
Then confirm the worker is online in Well Applied. `rollback.sh` is idempotent and removes nothing but what `install.sh` wrote.
To keep the policy but stop the worker: `sudo systemctl stop career-toolkit-browser-worker`.

## If Chromium will not start hardened
`validate-hardening.sh` runs the real Chromium tests under every option compose applies. Ubuntu 26.04 restricts unprivileged
user namespaces, and Chromium's sandbox uses them. If a test fails, change **one** option at a time and re-run: first
`read_only: true` (and widen the tmpfs mounts), then `no-new-privileges`. Do **not** drop `cap_drop: [ALL]`: it removes
`CAP_NET_RAW`, and the worker's start-up check (and the interface-based rules) assume it. Do not add `--no-sandbox`.

## Tests
| What | How | Run |
|---|---|---|
| nftables policy semantics (ordering vs Docker/Tailscale-style chains, every blocked range, host, Mac, relay-link, spoofing, IPv6, atomic reload) | privileged, network-less container with network namespaces | `docker build -t isolation-nft-test test/isolation/image && test/isolation/run-netns-test.sh` (set `BRNF=0/1`) |
| relay allow-list and normalisation | the real nginx image, fake Well Applied | `test/isolation/run-relay-test.sh` |
| guard against the real `nft` | same container | `test/isolation/run-guard-integration-test.sh` |
| controller, install/rollback, config consistency, worker self-check, redaction | `npm test` | |
| hardened container | `isolation/validate-hardening.sh` | on the AI box |
| live isolation | `isolation/validate-network.sh pre|post` | on the AI box |
