# Corpus capture (development tool)

Records every SQL statement WordPress sends through `wpdb` on a plain MySQL install. The result is the
translation/test corpus (real SQL used as both target and regression set).

```
docker compose -f tools/corpus/docker-compose.yml run --rm wpcli sh /corpus/run.sh
php tools/corpus/stats.php tools/corpus/out/corpus-raw.jsonl --dedupe=tools/corpus/out/corpus-v0.jsonl
```

| File | Purpose |
|------|---------|
| `capture-db.php` | `wpdb` subclass drop-in; overrides `query()` (the private `_do_query()` cannot be hooked) and appends JSONL |
| `flows/core-flows.php` | Core flows: fixtures, `WP_Query` variants, users, comments, terms, options, meta, cron, menus |
| `flows/synthetic-sql.php` | Plugin-style SQL core does not emit (ODKU, REPLACE, `SQL_CALC_FOUND_ROWS`, `SHOW`/`DESCRIBE`, DDL, locks…), label `synthetic` |
| `run.sh` | Container entry point: install WP with the drop-in active, run flows, cron, `update-db` |
| `stats.php` | Statistics and exact-SQL dedupe (classifies by first keyword only; never rewrites SQL) |

Environment: `WP_VERSION` (default `latest`), `SLONIK_CORPUS_SOURCE` (label, e.g. `plugin:foo@1.2`).
Output goes to `tools/corpus/out/` (git-ignored).

Record format (one JSON object per line): `v, seq, pid, ts, ms, src, label, ctx, ok, err, caller, sql`.

Not captured: `SET NAMES` / `SET SESSION sql_mode` (sent through mysqli directly by `wpdb`).
`ok:false` rows are kept on purpose (install-time probes, expected failures): they document real behaviour.
