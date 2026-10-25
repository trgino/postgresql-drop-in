<?php
/**
 * Differential runner: same fixture, MySQL original vs translated PG.
 * Usage: php tools/diff/run.php [--mysql-host=..] [--pg-host=..]
 * Env: DIFF_MYSQL_PASS (default pass123), DIFF_PG_PASS (default pgpass123).
 * Exit: 0 all (expected) green, 1 unexpected mismatch/error, 2 infra failure.
 *
 * PHP 7.4 compatible. No regex on SQL (first-keyword stats only).
 *
 * @package Slonik
 */

declare( strict_types=1 );

require_once __DIR__ . '/lib.php';
require_once dirname( __DIR__, 2 ) . '/src/Sql/MysqlParser.php';
require_once dirname( __DIR__, 2 ) . '/src/Sql/TranslationException.php';
require_once dirname( __DIR__, 2 ) . '/src/Sql/PlanStep.php';
require_once dirname( __DIR__, 2 ) . '/src/Sql/ExecutionPlan.php';
require_once dirname( __DIR__, 2 ) . '/src/Sql/PgQuote.php';
require_once dirname( __DIR__, 2 ) . '/src/Sql/MinTranslator.php';
require_once dirname( __DIR__, 2 ) . '/src/Dialect/Pg/MinEmitter.php';
require_once dirname( __DIR__, 2 ) . '/src/Dialect/Pg/Semantics.php';
require_once dirname( __DIR__, 2 ) . '/src/Schema/Catalog.php';
require_once dirname( __DIR__, 2 ) . '/src/Schema/MinDdl.php';
require_once dirname( __DIR__, 2 ) . '/src/Schema/MinShow.php';

use Slonik\Schema\Catalog;
use Slonik\Schema\MinDdl;
use Slonik\Sql\MinTranslator;

$options = getopt( '', array( 'mysql-host::', 'pg-host::' ) );
$my_host = isset( $options['mysql-host'] ) ? $options['mysql-host'] : '127.0.0.1:3307';
$pg_host = isset( $options['pg-host'] ) ? $options['pg-host'] : '127.0.0.1:5433';
$my_pass = getenv( 'DIFF_MYSQL_PASS' );
$my_pass = is_string( $my_pass ) && '' !== $my_pass ? $my_pass : 'pass123';
$pg_pass = getenv( 'DIFF_PG_PASS' );
$pg_pass = is_string( $pg_pass ) && '' !== $pg_pass ? $pg_pass : 'pgpass123';

function slonik_diff_split_host( $hostport, $defport ) {
	$parts = explode( ':', (string) $hostport, 2 );
	return array( $parts[0], isset( $parts[1] ) ? (int) $parts[1] : $defport );
}

function slonik_diff_statements( $file ) {
	$text = file_get_contents( $file );
	if ( ! is_string( $text ) ) {
		return array();
	}
	$out = array();
	foreach ( explode( ";\n", $text ) as $chunk ) {
		$chunk = trim( $chunk );
		if ( '' !== $chunk ) {
			$out[] = $chunk;
		}
	}
	return $out;
}

list( $my_h, $my_p ) = slonik_diff_split_host( $my_host, 3306 );
list( $pg_h, $pg_p ) = slonik_diff_split_host( $pg_host, 5432 );

$mysqli = new mysqli( $my_h, 'root', $my_pass, '', $my_p );
if ( $mysqli->connect_error ) {
	fwrite( STDERR, "MySQL connect failed: {$mysqli->connect_error}\n" );
	exit( 2 );
}
$mysqli->query( 'CREATE DATABASE IF NOT EXISTS diffdb' );
$mysqli->select_db( 'diffdb' );
$mysqli->set_charset( 'utf8mb4' );
$mysqli->query( "SET SESSION sql_mode=''" );

try {
	$pg_admin = new PDO(
		"pgsql:host={$pg_h};port={$pg_p};dbname=postgres",
		'postgres',
		$pg_pass,
		array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION )
	);
	try {
		$pg_admin->exec( 'CREATE DATABASE diffdb' );
	} catch ( PDOException $e ) {
		if ( false === strpos( $e->getMessage(), 'already exists' ) ) {
			throw $e;
		}
	}
	$pdo = new PDO(
		"pgsql:host={$pg_h};port={$pg_p};dbname=diffdb",
		'postgres',
		$pg_pass,
		array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION )
	);
} catch ( PDOException $e ) {
	fwrite( STDERR, "PG connect failed: {$e->getMessage()}\n" );
	exit( 2 );
}

$mysqli->query( 'DROP TABLE IF EXISTS diff_items' );
$pdo->exec( 'DROP TABLE IF EXISTS "diff_items"' );

foreach ( slonik_diff_statements( __DIR__ . '/fixture.sql' ) as $statement ) {
	if ( 0 === stripos( ltrim( $statement ), 'CREATE TABLE' ) ) {
		if ( ! $mysqli->query( $statement ) ) {
			fwrite( STDERR, "MySQL fixture failed: {$mysqli->error}\n" );
			exit( 2 );
		}
		foreach ( MinDdl::translate( $statement ) as $pg_statement ) {
			$pdo->exec( $pg_statement );
		}
		continue;
	}
	if ( ! $mysqli->query( $statement ) ) {
		fwrite( STDERR, "MySQL fixture failed: {$mysqli->error}\n" );
		exit( 2 );
	}
	$plan  = MinTranslator::translate( $statement, Catalog::load( $pdo, 'public' ) );
	$steps = $plan->getSteps();
	if ( 1 !== count( $steps ) ) {
		fwrite( STDERR, "Fixture INSERT produced multi-step plan\n" );
		exit( 2 );
	}
	$pdo->exec( $steps[0]->getSql() );
}
$pdo->exec( 'SELECT setval(pg_get_serial_sequence(\'"diff_items"\', \'id\'), COALESCE((SELECT MAX("id") FROM "diff_items"), 0))' );

$catalog = Catalog::load( $pdo, 'public' );
$green   = 0;
$bad     = 0;
$fh      = fopen( __DIR__ . '/queries.jsonl', 'rb' );
if ( false === $fh ) {
	fwrite( STDERR, "Cannot open queries.jsonl\n" );
	exit( 2 );
}
while ( false !== ( $line = fgets( $fh ) ) ) {
	$entry = json_decode( trim( $line ), true );
	if ( ! is_array( $entry ) || ! isset( $entry['id'], $entry['sql'] ) ) {
		continue;
	}
	$sql     = (string) $entry['sql'];
	$ordered = ! empty( $entry['ordered'] );
	$verdict = array( 'id' => $entry['id'], 'rules' => isset( $entry['rules'] ) ? $entry['rules'] : array() );
	try {
		$my_res = $mysqli->query( $sql );
		if ( false === $my_res ) {
			throw new RuntimeException( 'mysql error: ' . $mysqli->error );
		}
		$my_rows = $my_res->fetch_all( MYSQLI_ASSOC );
		$plan    = MinTranslator::translate( $sql, $catalog );
		$steps   = $plan->getSteps();
		if ( 1 !== count( $steps ) ) {
			throw new RuntimeException( 'multi-step plan in differential scope' );
		}
		$verdict['pg_sql'] = $steps[0]->getSql();
		$pg_stmt           = $pdo->query( $steps[0]->getSql() );
		$pg_rows           = $pg_stmt->fetchAll( PDO::FETCH_ASSOC );
		$match             = slonik_diff_shapes_equal( $my_rows, $pg_rows, $ordered );
		$verdict['verdict'] = $match ? 'match' : 'mismatch';
		if ( ! $match ) {
			$verdict['mysql_rows'] = slonik_diff_normalize( $my_rows );
			$verdict['pg_rows']    = slonik_diff_normalize( $pg_rows );
		}
	} catch ( Throwable $e ) {
		$verdict['verdict'] = 'error';
		$verdict['error']   = $e->getMessage();
	}
	$expected = isset( $entry['expect'] ) ? $entry['expect'] : 'match';
	$ok       = ( 'mismatch' === $expected && 'mismatch' === $verdict['verdict'] )
		|| ( 'match' === $expected && 'match' === $verdict['verdict'] );
	if ( $ok ) {
		++$green;
	} else {
		++$bad;
	}
	$verdict['expected'] = $expected;
	$verdict['ok']       = $ok;
	echo json_encode( $verdict, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
}
fclose( $fh );
echo "differential: {$green} ok, {$bad} unexpected\n";
exit( $bad > 0 ? 1 : 0 );
