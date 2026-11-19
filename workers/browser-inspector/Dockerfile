# Production-shaped Browser Inspector worker image.
#
# Version pinning is deliberate and load-bearing: the base image tag and
# the playwright npm package version below MUST match exactly. Using
# `:latest` caused a real, reproduced version mismatch during the
# investigation PoC (the base image resolved to an older Chromium than
# the npm package expected, and the container failed to launch at all).
# See README.md "Version pinning rule".
FROM mcr.microsoft.com/playwright:v1.63.0-noble

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --omit=dev

COPY src ./src

# The base image's own non-root `pwuser` runs the worker. NOTE on Chromium's own sandbox: Playwright launches Chromium with
# --no-sandbox unless `chromiumSandbox: true` is passed, so for the original deployment Chromium ran WITHOUT its sandbox, and
# an earlier version of this comment wrongly said otherwise. With Docker's default seccomp profile the sandbox cannot start at
# all (it needs clone/unshare/setns/chroot for unprivileged processes). The isolated deployment (compose.yaml) enables it
# with BROWSER_WORKER_CHROMIUM_SANDBOX=1 and a seccomp profile that is Docker's default plus exactly those four syscalls;
# see isolation/README.md "Chromium's own sandbox" and isolation/validate-hardening.sh, which proves it is in force.
RUN chown -R pwuser:pwuser /app
USER pwuser

# Deployed with compose.yaml under isolation/ (dedicated networks, host-enforced nftables policy, systemd-controlled start): see
# isolation/README.md. The worker holds no credentials but its Well Applied token and must not be run on a default Docker
# network. With BROWSER_WORKER_REQUIRE_ISOLATION=1 (set by compose.yaml) it refuses to claim work unless its own self-check
# shows it is isolated and unprivileged (src/isolation-check.js).
#
# Default runtime is the Phase 3 poll loop — it runs continuously,
# claiming and reporting work against Well Applied. src/cli.js
# remains available for manual/dev one-shot invocation:
#   docker run --rm <image> node src/cli.js <url>
ENTRYPOINT ["node", "src/poll.js"]
