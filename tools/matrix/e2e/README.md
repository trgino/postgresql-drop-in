# WooCommerce e2e oracle

A development-only oracle: it runs a subset of WooCommerce's own
Playwright e2e suite against the matrix site — first on the MySQL source
the baseline), then again after the PostgreSQL cutover — and the two
reports are diffed to expose PostgreSQL-specific regressions.

Like the rest of `tools/`, this is not shipped with the plugin.

## Prerequisites

Docker with network access at **build** time: the `e2e` image checks out
the WooCommerce monorepo at `WOOCOMMERCE_REF` (default `11.2.1`) and
installs Playwright Chromium.

```sh
docker compose -f tools/matrix/docker-compose.yml build web e2e
```

## The site

`web` serves the `wpfiles` volume on host port 8081; the Playwright run
opens `http://web`.

Baseline (MySQL, no migration/cutover):

```sh
docker compose -f tools/matrix/docker-compose.yml run --rm \
	-e E2E_BASELINE=1 -e MATRIX_PLUGINS=woocommerce wpcli \
	sh /matrix/tools/matrix/run.sh
```

PostgreSQL (install + migration + cutover): the same command without
`E2E_BASELINE`.

The runner expects these accounts on the site (run.sh creates them):
`admin/admin` and `customer/password`.

## Running the oracle

```sh
# MySQL truth
docker compose -f tools/matrix/docker-compose.yml run --rm \
	-e E2E_LABEL=mysql e2e bash /matrix/tools/matrix/e2e/run-e2e.sh

# PostgreSQL
docker compose -f tools/matrix/docker-compose.yml run --rm \
	-e E2E_LABEL=pg e2e bash /matrix/tools/matrix/e2e/run-e2e.sh
```

The runner exits with Playwright's own exit code: reds are data here.
Read each red against the MySQL baseline before calling anything a
PostgreSQL gap:

- green on both runs — no case,
- MySQL red — environment/flaky of the subset itself (exclude list),
- MySQL green + PostgreSQL red — a **PG case** (docs/14 entry).

## Subset and reports

`tests.list` holds the Playwright path filters (v1: `tests/basic`,
`tests/cart`, `tests/shop`); the config's setup chain (`install wc`,
`global authentication`, `site setup`) runs through its project
dependencies regardless of the filters. Reports land under
`tools/matrix/out/`:

- `e2e-<label>.json` — Playwright JSON (`.stats` has the counters),
- `e2e-<label>.log` — console mirror.

## Vendored helpers (test-plugins)

The suite assumes a wp-env-provisioned site (see the kit's
`.wp-env.e2e.json` and `bin/test-env-setup.sh`). Replicated in the
matrix, both under `test-plugins/` and activated by `run.sh` on every
fresh install:

- `woocommerce-e2e-test-helper` — copied verbatim from the checkout at
  `WOOCOMMERCE_REF` (`tests/e2e/test-plugins/`); it provides the
  `e2e-feature-flags`, `e2e-options`, `e2e-environment`, `e2e-theme`
  REST routes the setup and utils use.
- `basic-auth` — copied verbatim from
  https://github.com/WP-API/Basic-Auth (same plugin the wp-env config
  pins); it lets the REST fixtures authenticate with the plain admin
  credentials.

`run.sh` mirrors the fixture provisioning: `customer/password` user
same names/birthdate as the kit, theme `twentytwentythree`, permalink
structure `/%postname%/`, blog name `WooCommerce Core E2E Test Suite`.

## Notes
- The version pair must not drift: wpcli installs the plugin zip at
  `WOOCOMMERCE_REF` (wordpress.org), the e2e image checks out the same
  tag. Bump `WOOCOMMERCE_REF`, then rebuild the e2e image and rerun.
- Env vars win over `tests/e2e/.env` (dotenv does not override existing
  environment variables), so the runner exports `BASE_URL`,
  `ADMIN_USER`, `ADMIN_PASSWORD`, `CUSTOMER_USER`, `CUSTOMER_PASSWORD`
  directly — the `test:e2e:*` scripts of the kit would overwrite those,
  and are not used here.
- No spec in the v1 subset references wp-env (`grep -rn "wp-env"` over
  `tests/basic`, `tests/cart`, `tests/shop` is clean); any spec added
  later that depends on it is an exclude-list case.
