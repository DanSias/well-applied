#!/usr/bin/env bash
# Tests relay/nginx.conf, UNMODIFIED, in the real nginx image. Container A owns a network-less namespace holding a fake
# Well Applied at 192.168.1.114:8000; container B (the relay, run as the compose file runs it: non-root, read-only, no
# capabilities) joins that namespace. Needs: docker, the scratch image from test/isolation/image, and the nginx image.
set -euo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
ROOT=$(cd "$HERE/../.." && pwd)
NGINX_IMAGE=${NGINX_IMAGE:-nginxinc/nginx-unprivileged:1.27-alpine}
A=relaytest-ns-$$; B=relaytest-nginx-$$
cleanup() { docker rm -f "$A" "$B" >/dev/null 2>&1 || true; }
trap cleanup EXIT

docker run -d --name "$A" --network none --cap-add NET_ADMIN -v "$HERE:/t:ro" -v "$ROOT/relay:/relay:ro" isolation-nft-test sleep 600 >/dev/null
docker exec "$A" sh -c 'ip link set lo up && ip addr add 192.168.1.114/32 dev lo'
docker run --rm --network none --read-only --tmpfs /tmp --cap-drop ALL --security-opt no-new-privileges:true \
  -v "$ROOT/relay/nginx.conf:/etc/nginx/nginx.conf:ro" "$NGINX_IMAGE" nginx -t 2>&1 | grep -E "syntax|test" 
docker run -d --name "$B" --network "container:$A" --read-only --tmpfs /tmp --cap-drop ALL --security-opt no-new-privileges:true \
  -v "$ROOT/relay/nginx.conf:/etc/nginx/nginx.conf:ro" "$NGINX_IMAGE" >/dev/null
sleep 1.5
docker exec "$A" python3 /t/relay-test.py
