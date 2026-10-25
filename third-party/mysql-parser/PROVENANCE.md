# Provenance: third-party/mysql-parser

Vendored MySQL lexer/parser runtime from the WordPress SQLite Database
Integration monorepo (`packages/mysql-parser`).

- Source: https://github.com/WordPress/sqlite-database-integration
- Snapshot: trunk @ `4d94f39b116c2637eb3b5bd7f28ca54350ba4788` (2026-10-14)
- Upstream license: GPL-2.0-or-later (`LICENSE`, byte-identical copy)
- Upstream `composer.json` requires: `php >= 7.2`, zero dependencies
- Vendored: the 8 files under upstream `src/` + `LICENSE`, byte-identical
  (verified with SHA256 at import time against upstream raw bytes, LF).
  Reference: upstream `LICENSE` raw SHA256
  `8177F97513213526DF2CF6184D8FF986C675AFB514D4E68A404010521B880643`
  (18092 bytes). Upstream headers kept as-is. This directory forces
  `eol=lf` (see root `.gitattributes`) so blobs stay LF-identical even
  on CRLF checkouts.
- Excluded on purpose: `data/` (70k-query dev corpus), `tests/`,
  `tools/`, `bin/` (grammar build pipeline), dev `composer.json`.
  Only the compiled runtime ships.
- Load entry: `Slonik\Sql\MysqlParser::load()` (`src/Sql/MysqlParser.php`)
  requires the class files in dependency order; `mysql-parse-table.php`
  is loaded lazily by `WP_MySQL_Parser_Factory`.
- Updating: re-copy `src/` + `LICENSE` from a new upstream commit,
  re-verify hashes, bump the snapshot line above, re-run
  `tools/parser-eval/run.php` against `tools/corpus/out/corpus-v0.jsonl`
  (must stay 1901/1901). Small gaps go upstream as PRs (K-36).
