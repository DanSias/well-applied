#!/bin/sh
# Regenerates the committed sample reports. Offline. Pass the inspector commit the reports should name.
set -e
cd "$(dirname "$0")/../.."
commit="${1:-$(git rev-parse HEAD)}"
for c in generic-off generic-on; do node eval/src/cli.js run --config "$c" --out "eval/reports/sample/$c" --inspector-commit "$commit"; done
node eval/src/cli.js compare eval/reports/sample/generic-off/report.json eval/reports/sample/generic-on/report.json --out eval/reports/sample/compare-off-vs-on
