# tools/bench — performance benchmark tools

Dev-only (stays in the repo, not shipped). Needs no PostgreSQL; measures
translation cost purely in PHP.

## translate.php

Runs every corpus expression through `MinTranslator::translate()` in two
ways and reports p50/p99 microseconds:

- **uncached**: parse + emit from scratch on every call
- **cached**: decode-only after a plan-cache hit with `ArrayCache`

```
php tools/bench/translate.php tools/corpus/out/corpus-v0.jsonl --repeats=5
```

Example output (2026-10-25, php74, corpus-v0 = 1901 unique expressions):

```
uncached (no plan cache): p50=626.50 µs  p99=3668.10 µs  total=8131.52 ms
cached   (plan cache):    p50=1.60 µs    p99=2839.20 µs  total=1781.65 ms
cache hits=7024  misses=2481  hit-rate=73.9%
speedup (cached vs uncached): 4.56x
translated=8780  failed=1450
```

`failed` = minimal-translator out-of-scope expressions (fail-closed:
ODKU/REPLACE needing catalog, `REGEXP`, `@@`, multi-table DELETE …). Not
a benchmark result; a translator limitation.

### V-04 performance budget numerical target

Measured baseline (single PHP process, no PG connection):

| Metric | Value (baseline) | Target |
|--------|------------------|--------|
| Single translate p50 | 626 µs | < 1 ms |
| Single translate p99 | 3.7 ms | < 10 ms |
| Repeated query (cache hit) p50 | 1.6 µs | < 10 µs |
| Plan-cache hit rate | 73.9% (repeated flow) | ≥ 70% |

Targets calibrated from the first measurement; in production (PDO
connection + PG exec) extra latency is added, so these limits cover the
translation layer only.
