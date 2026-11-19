#!/usr/bin/env bash
# Runs netns-test.sh against the repository's policy in a privileged, network-less, throwaway container. The container is a
# test bed only: nothing it creates exists outside it. Needs Docker and a scratch image with nftables/iproute2/python3:
#   docker build -t isolation-nft-test test/isolation/image
set -euo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
ROOT=$(cd "$HERE/../.." && pwd)
docker run --rm --privileged --network none \
  -v "$ROOT/isolation:/rules:ro" -v "$HERE:/t:ro" isolation-nft-test bash /t/netns-test.sh
