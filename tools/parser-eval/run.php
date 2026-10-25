<?php
/**
 * M1 parse-rate harness (dev only). No regex on SQL; delegates to upstream parser.
 * Usage: php tools/parser-eval/run.php <corpus.jsonl> [--limit=N]
 *
 * Env: M1_PARSER_SRC = upstream packages/mysql-parser/src directory.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

$snapshot = getenv( 'M1_PARSER_SRC' );
if ( ! is_string( $snapshot ) || '' === $snapshot ) {
	$snapshot = dirname( __DIR__, 2 ) . '/third-party/mysql-parser/src';
}
if ( ! is_string( $snapshot ) || '' === $snapshot ) {
	fwrite( STDERR, "Set M1_PARSER_SRC to upstream packages/mysql-parser/src\n" );
	exit( 3 );
}
$base = rtrim( $snapshot, '/\\' );
foreach ( array(
	$base . '/parser/class-wp-parser-token.php',
	$base . '/parser/class-wp-parser-node.php',
	$base . '/parser/class-wp-parser-grammar.php',
	$base . '/parser/class-wp-parser.php',
	$base . '/class-wp-mysql-token.php',
	$base . '/class-wp-mysql-lexer.php',
	$base . '/class-wp-mysql-parser-factory.php',
) as $f ) {
	if ( ! is_file( $f ) ) {
		fwrite( STDERR, "Missing parser file: $f\n" );
		exit( 3 );
	}
	require_once $f;
}

$in    = isset( $argv[1] ) ? (string) $argv[1] : '';
$limit = 0;
foreach ( array_slice( $argv, 2 ) as $a ) {
	if ( 0 === strpos( $a, '--limit=' ) ) {
		$limit = (int) substr( $a, 8 );
	}
}
if ( '' === $in ) {
	fwrite( STDERR, "Usage: php tools/parser-eval/run.php <corpus.jsonl> [--limit=N]\n" );
	exit( 2 );
}
$fh = fopen( $in, 'rb' );
if ( false === $fh ) {
	fwrite( STDERR, "Cannot open $in\n" );
	exit( 1 );
}
$total = 0;
$ok    = 0;
$fails = array();
$ms    = array();
$errk  = array();
$vbrk  = array();
$t0    = microtime( true );
while ( false !== ( $line = fgets( $fh ) ) ) {
	$rec = json_decode( $line, true );
	if ( ! is_array( $rec ) || ! isset( $rec['sql'] ) ) {
		continue;
	}
	if ( $limit > 0 && $total >= $limit ) {
		break;
	}
	++$total;
	$sql = (string) $rec['sql'];
	$s   = microtime( true );
	try {
		$lexer  = new WP_MySQL_Lexer( $sql );
		$tokens = $lexer->remaining_tokens();
		$parser = WP_MySQL_Parser_Factory::create_parser();
		$ast    = $parser->parse( $tokens );
		$ms[]   = ( microtime( true ) - $s ) * 1000.0;
		if ( null !== $ast ) {
			++$ok;
		} else {
			$fails[]          = substr( $sql, 0, 120 );
			$errk['null-ast'] = ( $errk['null-ast'] ?? 0 ) + 1;
			$verb             = 'OTHER';
			if ( preg_match( '/^\s*(?:\(\s*)*(?:\/\*.*?\*\/\s*)*([A-Za-z]+)/s', $sql, $m ) ) {
				$verb = strtoupper( $m[1] );
			}
			$vbrk[ $verb ] = ( $vbrk[ $verb ] ?? 0 ) + 1;
		}
	} catch ( Throwable $e ) {
		$ms[] = ( microtime( true ) - $s ) * 1000.0;
		$cls  = get_class( $e );
		if ( count( $fails ) < 50 ) {
			$fails[] = $cls . ': ' . substr( $sql, 0, 120 );
		}
		$errk[ $cls ] = ( $errk[ $cls ] ?? 0 ) + 1;
		$verb          = 'OTHER';
		if ( preg_match( '/^\s*(?:\(\s*)*(?:\/\*.*?\*\/\s*)*([A-Za-z]+)/s', $sql, $m ) ) {
			$verb = strtoupper( $m[1] );
		}
		$vbrk[ $verb ] = ( $vbrk[ $verb ] ?? 0 ) + 1;
	}
}
fclose( $fh );
$dt = microtime( true ) - $t0;
sort( $ms );
$nms = count( $ms );
$p50 = $nms > 0 ? round( $ms[ (int) ( $nms * 0.5 ) ], 3 ) : 0;
$p95 = $nms > 0 ? round( $ms[ (int) min( $nms - 1, $nms * 0.95 ) ], 3 ) : 0;
$sha = getenv( 'M1_SNAPSHOT_SHA' );
$out = json_encode(
	array(
		'total'         => $total,
		'parse_ok'      => $ok,
		'parse_fail'    => $total - $ok,
		'seconds'       => round( $dt, 3 ),
		'qps'           => $dt > 0 ? round( $total / $dt, 1 ) : 0,
		'php'           => PHP_VERSION,
		'p50_ms'        => $p50,
		'p95_ms'        => $p95,
		'snapshot_sha'  => is_string( $sha ) && '' !== $sha ? $sha : 'unknown',
		'error_classes' => $errk,
		'by_verb_fail'  => $vbrk,
		'failures'      => array_slice( $fails, 0, 15 ),
	),
	JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
);
if ( false === $out ) {
	fwrite( STDERR, "Failed to encode summary JSON\n" );
	exit( 1 );
}
echo $out . "\n";
