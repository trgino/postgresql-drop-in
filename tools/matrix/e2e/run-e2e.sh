#!/usr/bin/env bash
# The WooCommerce e2e oracle runner (K-55): runs the spec paths from
# /matrix/tools/matrix/e2e/tests.list against the site at $BASE_URL and
# writes the Playwright JSON report to /out/e2e-$E2E_LABEL.json.
#
# Usage (from the host):
#   docker compose -f tools/matrix/docker-compose.yml run --rm \
#     -e E2E_LABEL=mysql e2e bash /matrix/tools/matrix/e2e/run-e2e.sh
set -eo pipefail

: "${E2E_LABEL:=run}"
BASE_URL="${BASE_URL:-http://web}"
# The credentials match the accounts run.sh creates on the site.
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-admin}"
CUSTOMER_USER="${CUSTOMER_USER:-customer}"
CUSTOMER_PASSWORD="${CUSTOMER_PASSWORD:-password}"
LIST=/matrix/tools/matrix/e2e/tests.list
OUT=/out/e2e-"$E2E_LABEL".json
LOG=/out/e2e-"$E2E_LABEL".log

cd /wc-src/plugins/woocommerce
export BASE_URL ADMIN_USER ADMIN_PASSWORD CUSTOMER_USER CUSTOMER_PASSWORD

# The official runner's trick (run-tests-with-env.sh): resolve the
# @woocommerce/* workspace packages to their TypeScript source and let
# Playwright transpile them on the fly; nothing has to be pre-built.
export NODE_OPTIONS="${NODE_OPTIONS:-} --conditions=wc-source"

# Reds are data here (the audit diffs this report against the MySQL
# baseline), so the Playwright exit code is passed through unchanged at
# the end. The config's reporters already include a JSON report
# (test-results-<timestamp>.json): the newest one is copied to the stable
# /out path the audit reads; the console ('list') output mirrors into the
# log. CLI --reporter override cannot carry the built-in json reporter's
# output file (require() semantics), so reporters stay config-driven.
set -- $(tr '\n' ' ' < "$LIST")
set +e
pnpm exec playwright test --config=tests/e2e/playwright.config.ts "$@" 2>&1 | tee "$LOG"
STATUS=${PIPESTATUS[0]}
set -e
RESULTS=/wc-src/plugins/woocommerce/tests/e2e/test-results
JSON=$(ls -1t "$RESULTS"/test-results-*.json 2>/dev/null | head -1 || true)
if [ -n "$JSON" ]; then
	cp "$JSON" "$OUT"
fi
jq -c '.stats' "$OUT" || true
echo "== e2e $E2E_LABEL done rc=$STATUS"
exit "$STATUS"
