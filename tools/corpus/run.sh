#!/bin/sh
# Runs inside the wordpress:cli container (see docker-compose.yml).
# Installs WordPress on MySQL with the capture drop-in and runs the flows.
set -eu

WP="php -d memory_limit=1G /usr/local/bin/wp --allow-root --path=/var/www/html"
: "${WP_VERSION:=latest}"

mkdir -p /out
rm -f /out/corpus-raw.jsonl

if [ ! -f /var/www/html/wp-load.php ]; then
	$WP core download --version="$WP_VERSION" --force
fi

rm -f /var/www/html/wp-config.php /var/www/html/wp-content/db.php
$WP config create --dbname=wp --dbuser=root --dbpass=root --dbhost=db --skip-check
$WP config set WP_DEBUG false --raw

# Drop-in goes in BEFORE install so the whole install is captured.
cp /corpus/capture-db.php /var/www/html/wp-content/db.php

# Fresh database.
$WP db reset --yes 2>/dev/null || true

export SLONIK_CORPUS_SOURCE="core@$($WP core version)"
$WP core install --url=http://localhost --title=Corpus --admin_user=admin \
	--admin_password=admin --admin_email=admin@example.com --skip-email

$WP eval-file /corpus/flows/core-flows.php
$WP eval-file /corpus/flows/synthetic-sql.php

# Cron + upgrade routines (touch option/transient/cron SQL paths).
$WP cron event run --due-now || true
$WP core update-db || true

echo "Captured $(wc -l < /out/corpus-raw.jsonl) statements."
