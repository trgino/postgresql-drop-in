<?php
/**
 * PostgreSQL acceptance gate (dev tool, not shipped).
 *
 * Translates the fixture statements and asks PostgreSQL to PREPARE each
 * one. "The translator did not throw" is not proof that PostgreSQL accepts
 * the SQL (docs/11 §3, audit D-13/D-44): this tool is the oracle.
 *
 * Usage:
 *   PGHOST=127.0.0.1 PGPORT=5432 PGUSER=postgres PGPASSWORD=secret \
 *   PGDATABASE=slonik_php php tools/pg-accept/run.php
 *
 * Exit code: 0 = every statement prepared, 1 = at least one failed.
 *
 * @package Slonik
 */

declare( strict_types=1 );

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

use Slonik\Schema\Catalog;
use Slonik\Sql\MinTranslator;

/**
 * The value comparison lives in compare.php (shared with the MySQL
 * oracle check): a date and a timestamp of the same moment are equal
 * (MySQL returns a DATE for DATE_ADD on a date, PostgreSQL a timestamp)
 * - the type difference is a documented deviation, the value is the same.
 */
require_once __DIR__ . '/compare.php';

$dir = __DIR__;

$host     = getenv( 'PGHOST' ) ? getenv( 'PGHOST' ) : '127.0.0.1';
$port     = getenv( 'PGPORT' ) ? getenv( 'PGPORT' ) : '5432';
$user     = getenv( 'PGUSER' ) ? getenv( 'PGUSER' ) : 'postgres';
$password = getenv( 'PGPASSWORD' ) ? getenv( 'PGPASSWORD' ) : '';
$database = getenv( 'PGDATABASE' ) ? getenv( 'PGDATABASE' ) : 'postgres';

try {
	$pdo = new PDO(
		'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $database,
		$user,
		$password,
		array(
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_STRINGIFY_FETCHES  => true,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		)
	);
} catch ( \Throwable $e ) {
	fwrite( STDERR, "cannot connect to PostgreSQL: " . $e->getMessage() . "\n" );
	exit( 1 );
}

/**
 * Fixture lines (one statement per line, comments removed).
 *
 * @param string $path Fixture path.
 * @return string[]
 */
function slonik_sql_lines( $path ) {
	$out   = array();
	$lines = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	foreach ( (array) $lines as $line ) {
		$line = trim( (string) $line );
		if ( '' === $line || 0 === strpos( $line, '--' ) ) {
			continue;
		}
		$out[] = $line;
	}
	return $out;
}

/**
 * Fixture statements split at semicolons (multi-line DDL).
 *
 * @param string $path Fixture path.
 * @return string[]
 */
function slonik_sql_chunks( $path ) {
	$chunks = array();
	$body   = implode( "\n", slonik_sql_lines( $path ) );
	// Split at semicolons that are not inside a dollar-quoted block
	// (a trigger function body contains semicolons).
	$parts  = array();
	$buffer = '';
	$quoted = false;
	$tag    = '';
	$length = strlen( $body );
	for ( $i = 0; $i < $length; $i++ ) {
		$char = $body[ $i ];
		if ( ! $quoted && '$' === $char && preg_match( '/^\$[A-Za-z_0-9]*\$/', substr( $body, $i ), $m ) ) {
			$quoted = true;
			$tag    = $m[0];
			$buffer .= $tag;
			$i      += strlen( $tag ) - 1;
			continue;
		}
		if ( $quoted && $tag === substr( $body, $i, strlen( $tag ) ) ) {
			$quoted = false;
			$buffer .= $tag;
			$i      += strlen( $tag ) - 1;
			continue;
		}
		if ( ';' === $char && ! $quoted ) {
			$parts[] = $buffer;
			$buffer  = '';
			continue;
		}
		$buffer .= $char;
	}
	$parts[] = $buffer;
	foreach ( $parts as $chunk ) {
		$chunk = trim( $chunk );
		if ( '' !== $chunk ) {
			$chunks[] = $chunk;
		}
	}
	return $chunks;
}

$schema = slonik_sql_chunks( $dir . '/schema.sql' );
foreach ( $schema as $sql ) {
	$pdo->exec( $sql );
}

// The runtime translates with the catalog in hand: it maps the MySQL
// column spelling to the stored one (P-08). Without it every mixed-case
// identifier would be quoted as written and miss the column.
$catalog = Catalog::load( $pdo, 'public' );
// The test database has no citext: the degraded mode is the honest one.
$options = array( 'ci_mode' => \Slonik\Schema\CiMode::DEGRADED );

$statements = slonik_sql_lines( $dir . '/statements.sql' );
$failed     = 0;
$checked    = 0;
foreach ( $statements as $line ) {
	++$checked;
	try {
		$plan = MinTranslator::translate( $line, $catalog, $options );
		$sql  = '';
		foreach ( $plan->getSteps() as $step ) {
			$sql .= $step->getSql() . '; ';
		}
		if ( \Slonik\Sql\ExecutionPlan::KIND_DDL === $plan->getKind() ) {
			// PostgreSQL cannot PREPARE DDL: run it.
			$pdo->exec( rtrim( $sql, '; ' ) );
			echo "ok   (ddl) " . rtrim( $sql, '; ' ) . "\n";
			continue;
		}
		// PostgreSQL PREPARE does not know PDO's `?` markers: rewrite them
		// positionally first (D-55 probes are the first parameterized
		// fixture lines; run.php keeps a naive rule, no '?' in literals).
		$n = 0;
		$sql = preg_replace_callback(
			'/\?/',
			function () use ( &$n ) {
				return '$' . ( ++ $n );
			},
			$sql
		);
		$pdo->exec( 'PREPARE slonik_accept_' . $checked . ' AS ' . $sql );
		$pdo->exec( 'DEALLOCATE slonik_accept_' . $checked );
		echo "ok   " . $sql . "\n";
	} catch ( \Throwable $e ) {
		++$failed;
		echo "FAIL " . $line . "\n     " . $e->getMessage() . "\n";
	}
}

printf( "\n%d statement(s) checked, %d failed\n", $checked, $failed );

// Golden results: MySQL 8.4 is the oracle, PostgreSQL must agree.
$results   = file( $dir . '/results.sql', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
$compared  = 0;
$mismatches = 0;
foreach ( (array) $results as $line ) {
	$line = trim( (string) $line );
	if ( '' === $line || 0 === strpos( $line, '--' ) || false === strpos( $line, '|||' ) ) {
		continue;
	}
	$parts    = explode( '|||', $line, 2 );
	$sql      = trim( $parts[0] );
	$expected = trim( $parts[1] );
	try {
		$plan = MinTranslator::translate( $sql, $catalog, $options );
		$pg   = '';
		foreach ( $plan->getSteps() as $step ) {
			$pg .= $step->getSql();
		}
		$row    = $pdo->query( $pg )->fetch( PDO::FETCH_NUM );
		$actual = ( null === $row || ! array_key_exists( 0, $row ) || null === $row[0] ) ? 'NULL' : (string) $row[0];
		++$compared;
		if ( ! slonik_same_value( $actual, $expected ) ) {
			++$mismatches;
			echo "MISMATCH " . $sql . "\n  mysql: " . $expected . "\n  pgsql: " . $actual . "\n";
		}
	} catch ( \Throwable $e ) {
		++$mismatches;
		++$failed;
		echo "FAIL    " . $sql . "\n     " . $e->getMessage() . "\n";
	}
}
printf( "%d golden result(s) compared, %d mismatch(es)\n", $compared, $mismatches );

// Row counts: the same statements, compared by the number of rows.
$counts = file( $dir . '/counts.sql', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
$counted = 0;
foreach ( (array) $counts as $line ) {
	$line = trim( (string) $line );
	if ( '' === $line || 0 === strpos( $line, '--' ) || false === strpos( $line, '|||' ) ) {
		continue;
	}
	$parts    = explode( '|||', $line, 2 );
	$sql      = trim( $parts[0] );
	$expected = (int) trim( $parts[1] );
	try {
		$plan = MinTranslator::translate( $sql, $catalog, $options );
		$pg   = '';
		foreach ( $plan->getSteps() as $step ) {
			$pg .= $step->getSql();
		}
		$rows  = $pdo->query( $pg )->fetchAll( PDO::FETCH_NUM );
		$count = count( $rows );
		++$counted;
		if ( $count !== $expected ) {
			++$mismatches;
			++$failed;
			echo "ROWCOUNT " . $sql . "\n  mysql: " . $expected . " rows\n  pgsql: " . $count . " rows\n";
		}
	} catch ( \Throwable $e ) {
		++$mismatches;
		++$failed;
		echo "FAIL    " . $sql . "\n     " . $e->getMessage() . "\n";
	}
}
printf( "%d row count(s) compared, %d mismatch(es)\n", $counted, $mismatches );

// Copy fidelity: the COPY path must land NULLs as NULL and the text
// values byte for byte (audit D-62: PDO::pgsqlCopyFromArray mangled the
// PostgreSQL "\N" marker, every NULL became the string "N").
$copy_failures = 0;
$copy_table    = 'slonik_accept_copy';
try {
	$pdo->exec( 'DROP TABLE IF EXISTS public.' . $copy_table );
	$pdo->exec( 'CREATE TABLE public.' . $copy_table . ' (id int, v text)' );
	$cases = array(
		null,
		'N',
		'a\\b',
		'\\leading',
		"a\tb\nc",
		'',
		'héllo wörld ✓',
	);
	$lines = array();
	$id    = 0;
	foreach ( $cases as $value ) {
		$lines[] = (string) $id . "\t" . \Slonik\Migrate\CopyBatch::cell( $value );
		++$id;
	}
	// The real copy path (PgTarget::copy), not a raw PDO call: the NULL
	// marker is part of that contract.
	$target = new \Slonik\Migrate\PgTarget( $pdo );
	$target->copy( 'public.' . $copy_table, $lines );
	$rows = $pdo->query( 'SELECT id, v FROM public.' . $copy_table . ' ORDER BY id' )->fetchAll( PDO::FETCH_NUM );	if ( count( $rows ) !== count( $cases ) ) {
		++$copy_failures;
		echo "COPY    row count: expected " . count( $cases ) . ', got ' . count( $rows ) . "\n";
	}
	$by_id = array();
	foreach ( $rows as $row ) {
		$by_id[ (int) $row[0] ] = $row[1];
	}
	$id = 0;
	foreach ( $cases as $expected ) {
		$actual = array_key_exists( $id, $by_id ) ? $by_id[ $id ] : '__missing__';
		if ( $expected !== $actual ) {
			++$copy_failures;
			echo "COPY    id " . $id . ': expected ' . var_export( $expected, true )
				. ', got ' . var_export( $actual, true ) . "\n";
		}
		++$id;
	}
} catch ( \Throwable $e ) {
	++$copy_failures;
	echo "FAIL    copy fidelity: " . $e->getMessage() . "\n";
}
try {
	$pdo->exec( 'DROP TABLE IF EXISTS public.' . $copy_table );
} catch ( \Throwable $e ) {
	// The table may not exist; the check above already reported it.
}
printf( "%d copy case(s) checked, %d failure(s)\n", count( $cases ), $copy_failures );
$failed += $copy_failures;

// A bytea column: the value goes in PostgreSQL's hex input format
// ("\x…"); the raw bytes would be refused ("invalid input syntax for
// type bytea" - audit D-68).
$bin_table = 'slonik_accept_copy_bin';
try {
	$pdo->exec( 'DROP TABLE IF EXISTS public.' . $bin_table );
	$pdo->exec( 'CREATE TABLE public.' . $bin_table . ' (id int, v bytea)' );
	$raw    = "\x00\x01\x02\xff";
	$hex    = \Slonik\Migrate\RowTransform::value( $raw, array( 'type' => 'varbinary' ) )['value'];
	$lines  = array( "0\t" . \Slonik\Migrate\CopyBatch::cell( $hex ) );
	$target = new \Slonik\Migrate\PgTarget( $pdo );
	$target->copy( 'public.' . $bin_table, $lines );
	$pdo->exec( 'SET bytea_output TO hex' );
	$got = $pdo->query( 'SELECT v FROM public.' . $bin_table )->fetchColumn();
	// PDO's pgsql driver returns a bytea column as a stream: the raw
	// bytes are what has to match.
	$bytes = is_resource( $got ) ? stream_get_contents( $got ) : (string) $got;
	if ( $raw !== $bytes ) {
		++$copy_failures;
		echo "COPY    bytea: expected " . bin2hex( $raw ) . ', got ' . bin2hex( $bytes ) . "\n";
	}
	$pdo->exec( 'DROP TABLE IF EXISTS public.' . $bin_table );
} catch ( \Throwable $e ) {
	++$copy_failures;
	echo "FAIL    copy fidelity (bytea): " . $e->getMessage() . "\n";
}
$failed += $copy_failures;

if ( $mismatches > 0 ) {
	$failed += $mismatches;
}

exit( $failed > 0 ? 1 : 0 );
