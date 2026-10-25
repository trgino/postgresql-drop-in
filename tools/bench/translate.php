<?php
/**
 * M7.4 benchmark harness: translation cost + plan-cache hit rate.
 *
 * Runs every statement of a corpus through MinTranslator::translate()
 * with and without the plan cache, reporting p50/p99 microseconds per
 * successful call and the effective speedup of caching repeated statements.
 *
 * Statements that fail to translate are counted but not timed (they are
 * out of scope for the minimal translator, not a benchmark regression).
 *
 * Output (text): one summary line plus per-verb breakdown.
 *
 * Usage: php tools/bench/translate.php <corpus.jsonl> [--repeats=<n>]
 *   corpus.jsonl   JSONL lines, each with an "sql" field (tools/corpus/out/corpus-v0.jsonl)
 *   --repeats      how many times to re-run the whole corpus (default 3)
 *
 * This is a dev-only tool; it never rewrites SQL and never touches PostgreSQL.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

if ( $argc < 2 ) {
	fwrite( STDERR, "Usage: php translate.php <corpus.jsonl> [--repeats=<n>]\n" );
	exit( 2 );
}

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Sql/MysqlParser.php';
\Slonik\Sql\MysqlParser::load();

use Slonik\Cache\ArrayCache;
use Slonik\Sql\ExecutionPlan;
use Slonik\Sql\MinTranslator;

$corpus = $argv[1];
$repeats = 3;
foreach ( array_slice( $argv, 2 ) as $arg ) {
	if ( 0 === strpos( $arg, '--repeats=' ) ) {
		$repeats = max( 1, (int) substr( $arg, 10 ) );
	}
}

$fh = fopen( $corpus, 'rb' );
if ( false === $fh ) {
	fwrite( STDERR, "Cannot open $corpus\n" );
	exit( 1 );
}

$sqls = array();
while ( false !== ( $line = fgets( $fh ) ) ) {
	$line = trim( $line );
	if ( '' === $line ) {
		continue;
	}
	$rec = json_decode( $line, true );
	if ( ! is_array( $rec ) || ! isset( $rec['sql'] ) || '' === $rec['sql'] ) {
		continue;
	}
	$sqls[] = (string) $rec['sql'];
}
fclose( $fh );

if ( array() === $sqls ) {
	fwrite( STDERR, "No statements found in $corpus\n" );
	exit( 1 );
}

$unique_count = count( array_unique( $sqls ) );

// Verb of a statement (first keyword).
$verb_of = function ( $sql ) {
	if ( preg_match( '/^\s*(?:\/\*.*?\*\/\s*)*([A-Za-z]+)/s', $sql, $m ) ) {
		return strtoupper( $m[1] );
	}
	return 'OTHER';
};

$failures   = 0;
$successful = 0;

// ---- uncached -----------------------------------------------------------
$uncached_by_sql = array(); // sql => list of microseconds
foreach ( range( 1, $repeats ) as $r ) {
	foreach ( $sqls as $sql ) {
		$t0 = hrtime( true );
		try {
			MinTranslator::translate( $sql, null, array( 'parametrize' => true ) );
			++$successful;
		} catch ( \Throwable $e ) {
			++$failures;
			continue;
		}
		$dt = ( hrtime( true ) - $t0 ) / 1000; // microseconds
		$uncached_by_sql[ $sql ][] = $dt;
	}
}

// ---- cached -------------------------------------------------------------
$cache      = new ArrayCache();
$cached_by_sql = array(); // sql => list of microseconds
$cache_hits = 0;
$cache_miss = 0;
foreach ( range( 1, $repeats ) as $r ) {
	foreach ( $sqls as $sql ) {
		$key   = 'tr:' . hash( 'sha256', $sql . "\0" . '' . "\0" . '' );
		$cached = $cache->get( $key );
		$t0    = hrtime( true );
		if ( null !== $cached ) {
			++$cache_hits;
			ExecutionPlan::fromArray( $cached );
		} else {
			++$cache_miss;
			try {
				$plan = MinTranslator::translate( $sql, null, array( 'parametrize' => true ) );
			} catch ( \Throwable $e ) {
				++$failures;
				continue;
			}
			$cache->set( $key, $plan->toArray(), 0 );
		}
		$cached_by_sql[ $sql ][] = ( hrtime( true ) - $t0 ) / 1000; // microseconds
	}
}

$flatten = function ( array $by_sql ) {
	$out = array();
	foreach ( $by_sql as $vals ) {
		foreach ( $vals as $v ) {
			$out[] = $v;
		}
	}
	return $out;
};

$pct = function ( array $values, $p ) {
	if ( array() === $values ) {
		return 0.0;
	}
	sort( $values );
	$n   = count( $values );
	$idx = (int) ceil( ( $p / 100.0 ) * $n ) - 1;
	if ( $idx < 0 ) {
		$idx = 0;
	}
	if ( $idx >= $n ) {
		$idx = $n - 1;
	}
	return (float) $values[ $idx ];
};

$uncached_all = $flatten( $uncached_by_sql );
$cached_all   = $flatten( $cached_by_sql );

$unc_p50 = $pct( $uncached_all, 50 );
$unc_p99 = $pct( $uncached_all, 99 );
$cac_p50 = $pct( $cached_all, 50 );
$cac_p99 = $pct( $cached_all, 99 );

$unc_total = array_sum( $uncached_all );
$cac_total = array_sum( $cached_all );
$speedup   = $unc_total > 0 ? $unc_total / max( $cac_total, 0.001 ) : 0;

echo "== M7.4 translation benchmark ==\n";
echo "statements: " . count( $sqls ) . " total / $unique_count unique\n";
echo "repeats:    $repeats\n";
echo "cache:      off vs on (ArrayCache, per-request)\n\n";
printf( "uncached (no plan cache): p50=%.2f µs  p99=%.2f µs  total=%.2f ms\n", $unc_p50, $unc_p99, $unc_total / 1000.0 );
printf( "cached   (plan cache):    p50=%.2f µs  p99=%.2f µs  total=%.2f ms\n", $cac_p50, $cac_p99, $cac_total / 1000.0 );
printf( "cache hits=%d  misses=%d  hit-rate=%.1f%%\n", $cache_hits, $cache_miss, ( $cache_hits + $cache_miss ) > 0 ? 100.0 * $cache_hits / ( $cache_hits + $cache_miss ) : 0 );
printf( "speedup (cached vs uncached): %.2fx\n", $speedup );
printf( "translated=%d  failed=%d\n\n", $successful, $failures );

// per-verb breakdown (uncached p50/p99)
$by_verb = array();
foreach ( $uncached_by_sql as $sql => $vals ) {
	$v = $verb_of( $sql );
	if ( ! isset( $by_verb[ $v ] ) ) {
		$by_verb[ $v ] = array();
	}
	foreach ( $vals as $val ) {
		$by_verb[ $v ][] = $val;
	}
}
arsort( $by_verb );
echo "per-verb (uncached p50/p99 µs, count):\n";
foreach ( $by_verb as $v => $vals ) {
	sort( $vals );
	$n = count( $vals );
	$p50 = $vals[ max( 0, (int) ceil( 0.5 * $n ) - 1 ) ];
	$p99 = $vals[ max( 0, (int) ceil( 0.99 * $n ) - 1 ) ];
	printf( "  %-12s p50=%8.2f  p99=%8.2f  n=%d\n", $v, $p50 / 1000.0, $p99 / 1000.0, $n );
}

exit( $failures > 0 ? 1 : 0 );
