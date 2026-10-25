#!/bin/sh
# The live half of the plugin compatibility matrix (docs/11 §8.2).
# Runs inside the wordpress:cli container.
#
# 1. WordPress on MySQL (the source).
# 2. The Tier-1 plugins installed and activated.
# 3. The migration to PostgreSQL (the target).
# 4. The drop-in: the site runs on PostgreSQL.
# 5. A smoke scenario per plugin, then the compat log.
set -eu

WP="php -d memory_limit=1G /usr/local/bin/wp --allow-root --path=/var/www/html"
: "${WP_VERSION:=latest}"
PLUGINS="${MATRIX_PLUGINS:-woocommerce}"
# e2e oracle (K-55): the site URL the Playwright run will open; smoke-only
# runs keep the default too, WP-CLI resolves names fine.
WP_URL="${WP_URL:-http://web}"
# The pinned WooCommerce release (the plugin zip on install; the e2e image
# checks out the same tag - the version pair must not drift).
WOOCOMMERCE_REF="${WOOCOMMERCE_REF:-11.2.1}"

mkdir -p /out
if [ ! -f /var/www/html/wp-load.php ]; then
	$WP core download --version="$WP_VERSION" --force
fi

# The plugin under test: the repository itself.
if [ ! -L /var/www/html/wp-content/plugins/postgresql-drop-in ]; then
	rm -rf /var/www/html/wp-content/plugins/postgresql-drop-in
	ln -s /matrix /var/www/html/wp-content/plugins/postgresql-drop-in
fi

rm -f /var/www/html/wp-config.php /var/www/html/wp-content/db.php
$WP config create --dbname=wp --dbuser=root --dbpass=root --dbhost=db --skip-check
$WP config set WP_DEBUG false --raw

# The db guard: wp db * must not run against a PostgreSQL site (docs/09 §6).
printf 'require: /matrix/wp-cli-guard.php\napache_modules:\n  - mod_rewrite\n' > /var/www/html/wp-cli.yml

# The target (PostgreSQL) and the migration settings.
# No --raw: the values must be quoted, the WP-CLI evaluates wp-config.php.
$WP config set WPSLONIK_DB_HOST pgtarget
$WP config set WPSLONIK_DB_PORT 5432
$WP config set WPSLONIK_DB_NAME m6mig
$WP config set WPSLONIK_DB_USER postgres
$WP config set WPSLONIK_DB_PASSWORD postgres
$WP config set WPSLONIK_DB_SCHEMA public
$WP config set WPSLONIK_MIGRATE_BATCH_ROWS 1000
$WP config set WPSLONIK_MIGRATE_VERIFY count
# The target has to be resettable for a clean migration run.
$WP config set WPSLONIK_MIGRATE_DROP_TARGET true --raw

# A fresh source database (the volume may carry an older install).
$WP db reset --yes 2>/dev/null || true
$WP core install --url="$WP_URL" --title=Matrix --admin_user=admin \
	--admin_password=admin --admin_email=admin@example.com --skip-email

# The Playwright auth fixture also logs in a customer account (K-55);
# the credentials match the e2e kit's test-data defaults exactly (their
# test-env-setup.sh provisions the same pair).
$WP user create customer customer@woocommercecoree2etestsuite.com \
	--user_pass=password --role=customer \
	--first_name=Jane --last_name=Smith \
	--user_registered="2022-01-01 12:23:45" || echo "   customer creation failed"

# The plugin under test must be active: WP-CLI only loads active plugins,
# and the CLI registers itself while the plugin loads.
$WP plugin activate postgresql-drop-in || echo "   activation failed: postgresql-drop-in"

echo "== installing the plugins"
for slug in $PLUGINS; do
	echo "-- $slug"
	if [ woocommerce = "$slug" ]; then
		$WP plugin install "$slug" --activate --version="$WOOCOMMERCE_REF" || echo "   install/activate failed: $slug"
	else
		$WP plugin install "$slug" --activate || echo "   install/activate failed: $slug"
	fi
done
$WP plugin list --format=csv || true

# e2e oracle site provisioning (K-55): the kit assumes its wp-env
# environment - basic auth over REST, the e2e option/feature helper, the
# default test theme, pretty permalinks and the fixture blog name
# (tests/e2e/bin/test-env-setup.sh parity). The two helpers are vendored
# under /matrix/tools/matrix/e2e/test-plugins.
if [ -d /matrix/tools/matrix/e2e/test-plugins ]; then
	for plug in woocommerce-e2e-test-helper basic-auth; do
		cp -a "/matrix/tools/matrix/e2e/test-plugins/$plug" /var/www/html/wp-content/plugins/
		$WP plugin activate "$plug" || echo "   e2e helper activation failed: $plug"
	done
	$WP theme install twentytwentythree --activate || echo "   theme install failed: twentytwentythree"
	$WP rewrite structure "/%postname%/" --hard || echo "   rewrite structure failed"
	$WP option update blogname "WooCommerce Core E2E Test Suite" || true
fi

if [ -n "${E2E_BASELINE:-}" ]; then
	# The e2e baseline (K-55): MySQL stays the source of truth, the site
	# serves from MySQL so the oracle gets the MySQL truth first.
	echo "== e2e baseline (MySQL, no migration/cutover)"
else
echo "== migration preflight"
$WP slonik migrate reset-target --yes || true
$WP slonik migrate preflight || true
echo "== migration run"
$WP slonik migrate run --yes || true
$WP slonik migrate status || true
$WP slonik migrate verify || true

echo "== switching the site to PostgreSQL"
# The compatibility log is a file in the container: every run starts with
# an empty one, so the counts belong to this run only.
rm -f /var/www/html/wp-content/wpslonik/compat-log.jsonl
$WP slonik install-dropin --force || true
$WP config set WPSLONIK_ENABLED true --raw
$WP plugin deactivate --all 2>/dev/null || true
$WP plugin activate postgresql-drop-in || true
# The e2e oracle helpers get deactivated with everything else; bring
# them back so the oracle run has its REST shims (K-55).
for plug in woocommerce-e2e-test-helper basic-auth; do
	$WP plugin activate "$plug" || true
done
$WP db query "SELECT 1" || true

echo "== byte fidelity (the raw column bytes have to survive the migration)"
php /matrix/tools/matrix/fidelity.php || echo "   byte fidelity check failed"

echo "== smoke per plugin"
for slug in $PLUGINS; do
	echo "-- $slug"
	$WP plugin activate "$slug" || echo "   activate failed on PostgreSQL: $slug"
	$WP eval "echo 'site up: ' . get_option( 'blogname' ) . PHP_EOL;" || true
	$WP eval "global \$wpdb; echo 'posts: ' . \$wpdb->get_var( 'SELECT COUNT(*) FROM ' . \$wpdb->posts ) . PHP_EOL;" || true
	$WP eval "global \$wpdb; echo 'wc sessions: ' . \$wpdb->get_var( 'SELECT COUNT(*) FROM ' . \$wpdb->prefix . 'woocommerce_sessions' ) . PHP_EOL;" || true
	# The scheduled actions are serialized PHP objects with NUL bytes: the
	# probe proves that the deserializer gets the bytes back (D-74).
	$WP action-scheduler data-store 2>&1 | head -3 || echo "   action-scheduler data-store failed"
	$WP eval "global \$wpdb; \$r = \$wpdb->get_row( 'SELECT action_id, schedule FROM ' . \$wpdb->prefix . 'actionscheduler_actions ORDER BY action_id LIMIT 1' ); \$o = \$r ? unserialize( \$r->schedule ) : null; echo 'schedule probe: ' . ( is_object( \$o ) ? get_class( \$o ) : 'FAIL' ) . PHP_EOL;" || echo "   schedule probe failed"
done

echo "== compat log"
$WP slonik compat-log list || true
$WP slonik health || true
fi

echo "== done"
