# Differential runner (development tool)

Same fixture, two engines: each `queries.jsonl` entry runs once on MySQL
(original SQL via mysqli) and once on PostgreSQL (translated via
`Slonik\Sql\MinTranslator`, executed via PDO). Row sets are normalized
(stringify + order-insensitive unless `ordered`) and compared.

```
docker run -d --name slonik-mysql -e MYSQL_ROOT_PASSWORD=pass123 -p 3307:3306 mysql:8.4
docker exec slonik-pg16 psql -U postgres -c "CREATE DATABASE diffdb"
$env:DIFF_MYSQL_PASS="pass123"; $env:DIFF_PG_PASS="pgpass123"
php tools/diff/run.php --mysql-host=127.0.0.1:3307 --pg-host=127.0.0.1:5433
```

| Exit | Meaning |
|------|---------|
| 0 | every query matched (or mismatched exactly as `expect: mismatch` allow-lists) |
| 1 | unexpected mismatch or translation/execution error |
| 2 | infrastructure failure (connect, fixture load) |

| File | Purpose |
|------|---------|
| `lib.php` | Pure compare helpers (unit-tested, no DB) |
| `fixture.sql` | MySQL-dialect edge fixture; PG side loads it translated (dogfoods MinDdl) |
| `queries.jsonl` | `id`, `sql`, `ordered`, `rules`, optional `expect: mismatch` + `note` |
| `run.php` | Connections, fixture load, per-query verdicts |

Environment: `DIFF_MYSQL_PASS` (default `pass123`), `DIFF_PG_PASS` (default
`pgpass123`). Output goes to stdout (JSONL verdicts + summary); nothing is
committed. Fixture database on PG side: `diffdb` (kept separate from any
WordPress install).

Blind spot: the runner compares result sets, so a self-consistent
storage-level divergence on both sides (e.g. double-escaped backslashes
that read back equally) stays invisible. In particular the
`backslash-literal` entry cannot catch a doubling regression by
construction — only raw-PDO byte probes can. Storage bytes are asserted
separately via raw-PDO probes against a live WordPress install (backslash-literal episode).

