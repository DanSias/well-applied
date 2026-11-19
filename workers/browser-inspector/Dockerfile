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

# The base image's own non-root `pwuser` runs Chromium with its
# sandbox enabled — no --privileged, no --cap-add=SYS_ADMIN, and no
# --no-sandbox flag are required. Confirmed directly against this
# exact image/user combination on the real deployment target (Ubuntu
# Server, ai-box) during the investigation PoC.
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
