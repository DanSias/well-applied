#!/usr/bin/env bash
# Runs guard-integration-test.sh in the same kind of throwaway, network-less, privileged container as the netns test.
set -euo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
ROOT=$(cd "$HERE/../.." && pwd)
docker run --rm --privileged --network none -v "$ROOT/isolation:/script:ro" -v "$HERE:/t:ro" isolation-nft-test bash /t/guard-integration-test.sh
