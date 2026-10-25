<?php
/**
 * The MySQL/MariaDB oracle check: the golden values in results.sql must
 * be the same on every supported MySQL-compatible version.
 *
 * The golden values were produced with MySQL 8.4 (the oracle). This
 * script proves that the other supported versions agree, so the
 * PostgreSQL parity is not a MySQL 8.4 accident.
 *
 * Usage:
 *   MYSQL_HOST=127.0.0.1 MYSQL_PORT=3306 MYSQL_USER=root \
 *   MYSQL_PASSWORD=root MYSQL_DATABASE=mysql \
 *   php tools/pg-accept/oracle.php
 *
 * Exit code: 0 = every statement agrees, 1 = at least one differs.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

require_once __DIR__ . '/compare.php';

/**
 * The constructs that do not exist on every version. They are reported
 * as skipped, never as a failure: the difference is a property of the
 * source server, not of the translation.
 */
const KNOWN_DIFFERENCES = array(
	'ANY_VALUE' => 'MariaDB has no ANY_VALUE aggregate (MySQL only); not used by WordPress core',
);

$host     = getenv( 'MYSQL_HOST' ) ? getenv( 'MYSQL_HOST' ) : '127.0.0.1';
$port     = getenv( 'MYSQL_PORT' ) ? (int) getenv( 'MYSQL_PORT' ) : 3306;
$user     = getenv( 'MYSQL_USER' ) ? getenv( 'MYSQL_USER' ) : 'root';
$password = getenv( 'MYSQL_PASSWORD' ) ? getenv( 'MYSQL_PASSWORD' ) : '';
$database = getenv( 'MYSQL_DATABASE' ) ? getenv( 'MYSQL_DATABASE' ) : 'mysql';

mysqli_report( MYSQLI_REPORT_OFF );
$link = @mysqli_connect( $host, $user, $password, $database, $port );
if ( ! $link ) {
	fwrite( STDERR, 'cannot connect to the MySQL-compatible server: ' . mysqli_connect_error() . "\n" );
	exit( 1 );
}
$version = (string) mysqli_get_server_info( $link );

$lines   = file( __DIR__ . '/results.sql', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
$queries = array();
foreach ( (array) $lines as $line ) {
	$line = trim( (string) $line );
	if ( '' === $line || 0 === strpos( $line, '--' ) || false === strpos( $line, '|||' ) ) {
		continue;
	}
	$parts    = explode( '|||', $line, 2 );
	$queries[] = array(
		'sql'      => trim( $parts[0] ),
		'expected' => trim( $parts[1] ),
	);
}

echo 'Oracle check — ' . $version . ' (' . count( $queries ) . " statements)\n";

$checked  = 0;
$mismatch = 0;
$skipped  = 0;
foreach ( $queries as $query ) {
	$sql = $query['sql'];
	foreach ( array_keys( KNOWN_DIFFERENCES ) as $name ) {
		if ( false !== stripos( $sql, $name . '(' ) ) {
			echo 'skip  ' . $sql . ' — ' . KNOWN_DIFFERENCES[ $name ] . "\n";
			++$skipped;
			continue 2;
		}
	}
	$result = @mysqli_query( $link, $sql );
	if ( ! $result ) {
		echo 'FAIL  ' . $sql . ' — ' . mysqli_error( $link ) . "\n";
		++$mismatch;
		continue;
	}
	$row    = mysqli_fetch_row( $result );
	$actual = ( ! $row || null === $row[0] ) ? 'NULL' : (string) $row[0];
	mysqli_free_result( $result );
	++$checked;
	if ( ! slonik_same_value( $actual, $query['expected'] ) ) {
		++$mismatch;
		echo 'DIFF  ' . $sql . "\n  expected: " . $query['expected'] . "\n  actual:   " . $actual . "\n";
	}
}
mysqli_close( $link );

printf(
	"%d statement(s) checked, %d mismatch(es), %d skipped\n",
	$checked,
	$mismatch,
	$skipped
);
exit( $mismatch > 0 ? 1 : 0 );
