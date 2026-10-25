<?php
/**
 * Corpus statistics / dedupe (dev tool). Statistics only: it classifies by the
 * statement's first keyword and never rewrites SQL.
 *
 * Usage: php tools/corpus/stats.php <raw.jsonl> [--dedupe=<out.jsonl>]
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

if ( $argc < 2 ) {
	fwrite( STDERR, "Usage: php stats.php <raw.jsonl> [--dedupe=<out.jsonl>]\n" );
	exit( 2 );
}

$in     = $argv[1];
$dedupe = null;
foreach ( array_slice( $argv, 2 ) as $arg ) {
	if ( 0 === strpos( $arg, '--dedupe=' ) ) {
		$dedupe = substr( $arg, 9 );
	}
}

$fh = fopen( $in, 'rb' );
if ( false === $fh ) {
	fwrite( STDERR, "Cannot open $in\n" );
	exit( 1 );
}

$total   = 0;
$errors  = 0;
$seen    = array();
$by_verb = array();
$by_lbl  = array();
$err_ex  = array();
$out     = $dedupe ? fopen( $dedupe, 'wb' ) : null;

while ( false !== ( $line = fgets( $fh ) ) ) {
	$rec = json_decode( $line, true );
	if ( ! is_array( $rec ) || ! isset( $rec['sql'] ) ) {
		continue;
	}
	++$total;
	$sql  = (string) $rec['sql'];
	$verb = 'OTHER';
	if ( preg_match( '/^\s*(?:\(\s*)*(?:\/\*.*?\*\/\s*)*([A-Za-z]+)/s', $sql, $m ) ) {
		$verb = strtoupper( $m[1] );
	}
	$label            = isset( $rec['label'] ) ? (string) $rec['label'] : '';
	$by_verb[ $verb ] = ( $by_verb[ $verb ] ?? 0 ) + 1;
	$by_lbl[ $label ] = ( $by_lbl[ $label ] ?? 0 ) + 1;
	if ( empty( $rec['ok'] ) ) {
		++$errors;
		if ( count( $err_ex ) < 15 ) {
			$err_ex[] = ( $rec['err'] ?? '' ) . '  <=  ' . substr( $sql, 0, 120 );
		}
	}
	$key = md5( $sql );
	if ( ! isset( $seen[ $key ] ) ) {
		$seen[ $key ] = true;
		if ( $out ) {
			fwrite( $out, json_encode( $rec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE ) . "\n" );
		}
	}
}
fclose( $fh );
if ( $out ) {
	fclose( $out );
}

arsort( $by_verb );
arsort( $by_lbl );
echo "total statements : $total\n";
echo 'distinct SQL     : ' . count( $seen ) . "\n";
echo "failed on MySQL  : $errors (expected for a few synthetic probes)\n\n";
echo "by verb:\n";
foreach ( $by_verb as $k => $v ) {
	printf( "  %-12s %d\n", $k, $v );
}
echo "\nby label:\n";
foreach ( $by_lbl as $k => $v ) {
	printf( "  %-28s %d\n", '' === $k ? '(none)' : $k, $v );
}
if ( $err_ex ) {
	echo "\nfailure samples:\n  " . implode( "\n  ", $err_ex ) . "\n";
}
if ( $dedupe ) {
	echo "\ndeduped corpus written to $dedupe\n";
}
