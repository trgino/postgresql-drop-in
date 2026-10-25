# PostgreSQL Drop-in

PostgreSQL support for WordPress.

> **Status: beta.** The translation runtime, health checks, WP-CLI commands,
> the compatibility scanner and the MySQL → PostgreSQL migration tool exist and are exercised by the test
> suite and by a PostgreSQL acceptance gate in CI. The plugin is not on wordpress.org yet and is not
> recommended for production sites yet — see [Known limitations](#known-limitations).

## What it does

WordPress only speaks MySQL. PostgreSQL Drop-in lets a WordPress site run on **PostgreSQL 16+** by installing a
`wp-content/db.php` drop-in that replaces `$wpdb` with a subclass. The subclass translates each MySQL
statement to PostgreSQL:

```
MySQL SQL → lexer → parser → AST → rewriter → PostgreSQL emitter → PDO (pdo_pgsql) → PostgreSQL
```

Translation works on a parsed syntax tree, not on regular expressions. Plugins and themes that use the
`$wpdb` API are in scope. Code that opens its own `mysqli`/PDO connections is not.

A MySQL (or MariaDB) site is migrated with WP-CLI:

```bash
wp slonik migrate --dry-run     # plan only, writes nothing
wp slonik migrate --rehearse    # full rehearsal in one transaction, rolled back
wp slonik migrate run            # copy the data
wp slonik migrate verify         # row count + checksum comparison
```

## Known limitations

- **Case-insensitive equality.** MySQL compares text case-insensitively by default; PostgreSQL does not.
  `WHERE user_login = 'Admin'` therefore behaves differently than in MySQL unless case-sensitivity mode is
  configured. Set `WPSLONIK_CI_MODE` to `citext` (requires the `citext` PostgreSQL extension and superuser
  to install it) or `degraded` (uses `lower()` on comparisons, no extension needed, but cannot use indexes).
  See the admin panel → Case sensitivity section for the mode, its effect and the `CREATE EXTENSION citext;`
  setup steps.
- **Unsupported MySQL constructs are rejected, not translated.** They fail with an explicit error instead
  of reaching PostgreSQL with a different meaning:
  - **Functions without a PostgreSQL equivalent:** `SHA1()`, `SHA2()`, `SUBSTRING_INDEX()`.
  - **Syntax without a PostgreSQL equivalent:** `UPDATE`/`DELETE` with `LIMIT`, multi-table
    `UPDATE`/`DELETE` (and a multi-table `DELETE` across different tables), `@@` system variables,
    `CREATE TABLE … AS SELECT`, `CREATE TABLE … LIKE`, complex or chained division (`a/2/2`).
  - **Statements outside the supported subset:** `SHOW CREATE TABLE`, `SHOW TABLE STATUS`,
    `LOCK`/`UNLOCK`, `OPTIMIZE`, `ANALYZE`.
    `DROP TABLE IF EXISTS` and `TRUNCATE TABLE` are translated. `ALTER TABLE` (single-action and
    multi-action) is translated; `SHOW VARIABLES` is emulated for a fixed set of variables.
    `INSERT IGNORE` becomes `ON CONFLICT DO NOTHING`: the unique conflict is covered, the other
    MySQL errors keep failing.
  `GROUP_CONCAT()` becomes `string_agg()`; the `SEPARATOR` and `ORDER BY` forms are not supported.

  `DATABASE()` and `VERSION()` are translated: `DATABASE()` becomes `current_database()` and `VERSION()`
  becomes `VERSION`.

  The static scan (`wp slonik compat <path>`) reports every occurrence with the rule id `R-062`, so a plugin
  that uses one of them is known before it runs.
- **Column types follow one rule.** A MySQL type with a PostgreSQL equivalent keeps it; otherwise the encompassing
  upper type is used: the text family (`tinytext` … `longtext`) becomes `text`, the binary family becomes `bytea`, and the
  integers widen to the next one (`tinyint` → `smallint`). `enum`/`set` become `text` with a `CHECK` for the value list.
  The upper type always keeps the comparison operators, so a `longtext` column stays comparable and searchable.
- **DDL coverage.** `CREATE TABLE` (with `IF NOT EXISTS`/`TEMPORARY`), `DROP TABLE IF EXISTS`
  and `TRUNCATE TABLE` are translated. `ALTER TABLE` (single-action and multi-action) is
  translated; `SHOW CREATE TABLE`, `SHOW TABLE STATUS`, `LOCK`/`UNLOCK`, `OPTIMIZE` and
  `ANALYZE` are not. `SHOW VARIABLES` is emulated for a fixed set of variables.
- **Multisite is not supported.**

## Plugin compatibility

A plugin matrix installs the plugins below on PostgreSQL 16 through the drop-in, activates them, exercises
them and reads every database error from the compatibility log.

| Plugin | Result | Notes |
|--------|--------|-------|
| Query Monitor | Compatible | Verified on WordPress 7.1.3, PostgreSQL 16.0.15, PHP 8.3 |
| Akismet | Compatible | Verified |
| Classic Editor | Compatible | Verified |
| WP Super Cache | Compatible | Verified |
| All-in-One WP Migration | Compatible | Verified |
| WP Mail SMTP | Compatible | Verified (`DATABASE()` is translated) |
| Contact Form 7 | Compatible | Verified |
| WooCommerce | Not compatible yet | The site runs, but the plugin's own installer and updater use `ALTER TABLE` and `INFORMATION_SCHEMA`, which the translation does not support yet |
| Wordfence | Not compatible yet | The site runs, but the plugin uses `SHOW VARIABLES` and compares binary values that PostgreSQL needs an explicit cast for |

The binary family (`binary`, `varbinary`, the `blob` types) is copied byte for byte into `bytea`.
The migration removes the NUL bytes from text values - PostgreSQL text cannot store them - and reports how
many were removed for every table. A column whose payload carries bytes a text column cannot hold is
mapped to `bytea` instead: the Action Scheduler `schedule` column (a serialized PHP object with NUL bytes
in the property names) is such a column, and its bytes are kept byte for byte.

## Requirements

| Component | Version |
|-----------|---------|
| PHP | 7.4+ with `pdo_pgsql` (`mysqli` for the migration command) |
| MySQL / MariaDB (source) | MySQL 5.5.5+ (WordPress' own minimum) or MariaDB 10.x+. Verified on MySQL 5.7, MySQL 8.4, MariaDB 10.11 and MariaDB 12.3 |
| WordPress | 6.4+ (single site; multisite is not supported in v1) |
| PostgreSQL | 16+ |
| Hosting | VPS, Docker or managed cloud PostgreSQL. Shared hosting is not a target. |

## Design principles

- **Opt-in.** Activating the plugin never changes the database. PostgreSQL mode is enabled only by an explicit
  `define( 'WPSLONIK_ENABLED', true );` in `wp-config.php`.
- **Transparent.** Health checks (admin screen and WP-CLI) say what is missing and how to fix it. Errors
  explain what failed and what to do next.
- **Strict.** No silent data truncation. MySQL zero dates map to a documented sentinel.
- **Private.** No telemetry. Compatibility logs redact literal values.
- **Migration by WP-CLI.** A MySQL → PostgreSQL migration command with `--dry-run` and `--rehearse` modes.

## Development

```bash
composer install
composer ci     # PHPCS (PHP 7.4 compatibility) + PHPStan + PHPUnit
```

Code must run on PHP 7.4 as well as current PHP 8.x releases. CI lints on PHP 7.4–8.4 and runs the quality
gates on 7.4 and 8.3.

Corpus capture records the real SQL WordPress sends to MySQL. It needs Docker; see
[tools/corpus/README.md](tools/corpus/README.md).

## License

[GPL-2.0-or-later](LICENSE).

PostgreSQL is a registered trademark of the PostgreSQL Community Association of Canada. WordPress is a
registered trademark of the WordPress Foundation. This project is not affiliated with or endorsed by either.
