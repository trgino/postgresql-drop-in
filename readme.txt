=== PostgreSQL Drop-in ===
Contributors: trgino
Tags: postgresql, database, drop-in, migration
Requires at least: 6.4
Tested up to: 7.1.3
Requires PHP: 7.4
Stable tag: 0.1.0-beta
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

PostgreSQL support for WordPress (in beta).

== Description ==

PostgreSQL Drop-in lets WordPress run on PostgreSQL 16+ by translating the MySQL SQL
produced by `wpdb` through a lexer, parser, AST and PostgreSQL emitter.

A MySQL or MariaDB site is migrated with WP-CLI: `wp slonik migrate --dry-run`,
`--rehearse`, `run` and `verify`.

This plugin is in beta and is not released yet. It is not recommended
for production sites yet. Known limitations: case-insensitive text equality
depends on the configured mode (citext or degraded), DDL support covers
CREATE TABLE, DROP TABLE IF EXISTS, TRUNCATE TABLE and ALTER TABLE, and
MySQL constructs without a PostgreSQL equivalent are rejected with an
explicit error instead of being translated.

The source database may be MySQL 5.5.5 or later (WordPress' own minimum) or
MariaDB 10.x or later. The golden values used to verify the translation are the
same on MySQL 5.7, MySQL 8.4, MariaDB 10.11 and MariaDB 12.3.

The constructs without a PostgreSQL equivalent are: the functions SHA1(),
SHA2() and SUBSTRING_INDEX(); the syntaxes
UPDATE/DELETE with LIMIT, multi-table UPDATE/DELETE, @@ system
variables, CREATE TABLE ... AS SELECT, CREATE TABLE ... LIKE and complex or
chained division; and the statements ALTER TABLE, SHOW CREATE TABLE,
SHOW VARIABLES, SHOW TABLE STATUS, LOCK/UNLOCK, OPTIMIZE and ANALYZE.
DROP TABLE IF EXISTS, TRUNCATE TABLE and INSERT IGNORE are translated
(INSERT IGNORE becomes ON CONFLICT DO NOTHING: the unique conflict is
covered, the other MySQL errors keep failing). GROUP_CONCAT() becomes
string_agg(); the SEPARATOR and ORDER BY forms are not supported. The static scan (wp slonik compat <path>) reports every occurrence
with the rule id R-062.

The functions DATABASE() and VERSION() are translated: DATABASE() becomes
current_database() and VERSION() becomes VERSION.

== Plugin compatibility ==

A plugin matrix installs the plugins below on PostgreSQL 16 through the
drop-in, activates them, exercises them and reads every database error from
the compatibility log.

Compatibility (beta): Query Monitor, Akismet, Classic Editor, WP Super Cache,
All-in-One WP Migration, WP Mail SMTP and Contact Form 7 activate and run on
PostgreSQL 16. WooCommerce and Wordfence activate and run, but their
installers/updaters may still fail on constructs not yet supported (e.g.
INFORMATION_SCHEMA probes, multi-table operations). Verify with
`wp slonik compat` before use.

Not compatible yet: multisite, reverse migration, shared hosting.

The binary family (`binary`, `varbinary`, the `blob` types) is copied byte for byte into
`bytea`. The migration removes the NUL bytes from text values - PostgreSQL text cannot
store them - and reports how many were removed for every table. A column whose payload
carries bytes a text column cannot hold is mapped to `bytea` instead: the Action
Scheduler `schedule` column (a serialized PHP object with NUL bytes in the property
names) is such a column, and its bytes are kept byte for byte.

PostgreSQL is a registered trademark of the PostgreSQL Community Association of Canada.
This project is not affiliated with or endorsed by the PostgreSQL project.

== Changelog ==

= 0.1.0-beta =
* Beta: translation runtime, health checks, WP-CLI commands,
  compatibility scanner, MySQL to PostgreSQL migration tool, translation
  plan cache, statement parameterization and IN-list normalization.

= 0.0.0 =
* Initial development snapshot: translation runtime, health checks, WP-CLI commands,
  compatibility scanner and the MySQL to PostgreSQL migration tool.
