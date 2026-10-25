# The WordPress CLI image ships PHP without pdo_pgsql. The migration tool
# and the drop-in need it (P-12), so the matrix image adds the extension.
#
# Build: docker compose -f tools/matrix/docker-compose.yml build wpcli
FROM wordpress:cli-php8.3 AS wpcli

# The image drops to www-data; the build needs root for apk and the
# extension build.
USER root

RUN apk add --no-cache libpq \
	&& apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev \
	&& docker-php-ext-install -j"$(nproc)" pdo_pgsql \
	&& apk del .build-deps

# The HTTP face of the matrix: same wpfiles volume the wpcli service
# populates. pdo_pgsql lets the site itself run on PostgreSQL through the
# drop-in after the cutover (K-55).
FROM wordpress:php8.3-apache AS web

USER root

RUN apt-get update \
	&& apt-get install -y --no-install-recommends libpq-dev \
	&& docker-php-ext-install -j"$(nproc)" pdo_pgsql \
	&& rm -rf /var/lib/apt/lists/*

# The WooCommerce e2e oracle image (K-55): the plugin's own Playwright
# kit checked out at a pinned tag. The monorepo pins Node ^24.15.0 and
# resolves its test imports from the workspace, so pnpm installs the
# whole workspace once, at build time.
FROM node:24-bookworm-slim AS e2e

USER root

RUN apt-get update \
	&& apt-get install -y --no-install-recommends git curl ca-certificates jq unzip \
	&& apt-get install -y --no-install-recommends php-cli php-mbstring php-xml php-curl php-zip php-intl php-bcmath composer \
	&& rm -rf /var/lib/apt/lists/* \
	&& corepack enable

WORKDIR /wc-src

ARG WOOCOMMERCE_REF=11.2.1
RUN git clone --depth 1 --branch "${WOOCOMMERCE_REF}" \
	    https://github.com/woocommerce/woocommerce.git . \
	&& pnpm install --frozen-lockfile \
	&& pnpm --filter ./plugins/woocommerce exec playwright install --with-deps chromium

WORKDIR /wc-src/plugins/woocommerce
