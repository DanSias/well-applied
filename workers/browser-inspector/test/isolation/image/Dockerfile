# Scratch test image for the isolation tests only (not deployed): a kernel-facing toolset to exercise the nftables policy.
FROM ubuntu:24.04
RUN apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends nftables iproute2 netcat-openbsd python3 iptables ca-certificates && rm -rf /var/lib/apt/lists/*
