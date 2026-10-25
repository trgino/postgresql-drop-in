<?php
/**
 * Raw byte fidelity check (dev tool, not shipped).
 *
 * A column whose target type is overridden to bytea (audit D-74) carries
 * bytes a text column cannot hold: the ActionScheduler `schedule` column
 * holds a serialized PHP object whose property names contain NUL bytes.
 * After the migration the raw bytes have to be identical to the source -
 * a hash comparison in the verifier proves the values match, this proves
 * the bytes themselves survived.
 *
 * Usage (inside the matrix container):
 *   php /matrix/tools/matrix/fidelity.php
 *
 * Exit code: 0 = the raw bytes match, 1 = a mismatch or an error.
 *
 * @package Slonik
 */

declare( strict_types=1 );

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

use Slonik\Schema\ColumnOverrides;

/** The tables the byte fidelity check covers (the overridden columns). */
$targets = array(
	array( 'table' => 'wp_actionscheduler_actions', 'column' => 'schedule' ),
);

$my_host = getenv( 'MYSQL_HOST' ) ? getenv( 'MYSQL_HOST' ) : 'db';
$my_port = getenv( 'MYSQL_PORT' ) ? (int) getenv( 'MYSQL_PORT' ) : 3306;
$my_user = getenv( 'MYSQL_USER' ) ? getenv( 'MYSQL_USER' ) : 'root';
$my_pass = getenv( 'MYSQL_PASSWORD' ) ? getenv( 'MYSQL_PASSWORD' ) : 'root';
$my_db   = getenv( 'MYSQL_DATABASE' ) ? getenv( 'MYSQL_DATABASE' ) : 'wp';

$pg_host = getenv( 'PGHOST' ) ? getenv( 'PGHOST' ) : 'pgtarget';
$pg_port = getenv( 'PGPORT' ) ? (int) getenv( 'PGPORT' ) : 5432;
$pg_user = getenv( 'PGUSER' ) ? getenv( 'PGUSER' ) : 'postgres';
$pg_pass = getenv( 'PGPASSWORD' ) ? getenv( 'PGPASSWORD' ) : 'postgres';
$pg_db   = getenv( 'PGDATABASE' ) ? getenv( 'PGDATABASE' ) : 'm6mig';

mysqli_report( MYSQLI_REPORT_OFF );
$mysql = @mysqli_connect( $my_host, $my_user, $my_pass, $my_db, $my_port );
if ( ! $mysql ) {
	fwrite( STDERR, 'cannot connect to MySQL: ' . mysqli_connect_error() . "\n" );
	exit( 1 );
}
try {
	$pdo = new PDO(
		'pgsql:host=' . $pg_host . ';port=' . $pg_port . ';dbname=' . $pg_db,
		$pg_user,
		$pg_pass,
		array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION )
	);
} catch ( Throwable $e ) {
	fwrite( STDERR, 'cannot connect to PostgreSQL: ' . $e->getMessage() . "\n" );
	exit( 1 );
}

$failed = 0;
foreach ( $targets as $target ) {
	$table  = (string) $target['table'];
	$column = (string) $target['column'];
	if ( ! ColumnOverrides::isBytea( $table, $column ) ) {
		fwrite( STDERR, "no bytea override for {$table}.{$column}\n" );
		++$failed;
		continue;
	}
	$source = array();
	$result = @$mysql->query( "SELECT action_id, HEX(`{$column}`) AS h FROM `{$table}` ORDER BY action_id" );
	if ( ! $result ) {
		fwrite( STDERR, "MySQL read failed for {$table}: " . $mysql->error . "\n" );
		++$failed;
		continue;
	}
	while ( $row = $result->fetch_assoc() ) {
		$source[ (string) $row['action_id'] ] = null === $row['h'] ? null : strtolower( (string) $row['h'] );
	}
	$target_rows = $pdo->query( 'SELECT action_id, encode("' . $column . '", \'hex\') AS h FROM "' . $table . '" ORDER BY action_id' )->fetchAll( PDO::FETCH_ASSOC );
	$copy        = array();
	foreach ( $target_rows as $row ) {
		$copy[ (string) $row['action_id'] ] = null === $row['h'] ? null : strtolower( (string) $row['h'] );
	}
	if ( count( $source ) > count( $copy ) ) {
		fwrite( STDERR, "{$table}: row count differs (source=" . count( $source ) . ', target=' . count( $copy ) . ")\n" );
		++$failed;
		continue;
	}
	$mismatch = 0;
	foreach ( $source as $id => $hex ) {
		// The target may have more rows (the site writes new actions
		// after the switch), but every migrated row has to be there
		// with the same bytes.
		if ( ! array_key_exists( $id, $copy ) || $copy[ $id ] !== $hex ) {
			++$mismatch;
			fwrite( STDERR, "{$table}: row {$id} differs (source=" . ( null === $hex ? 'NULL' : substr( $hex, 0, 32 ) ) . ', target=' . ( ! isset( $copy[ $id ] ) ? 'MISSING' : substr( (string) $copy[ $id ], 0, 32 ) ) . ")\n" );
		}
	}
	if ( $mismatch > 0 ) {
		++$failed;
		continue;
	}
	echo "ok   {$table}.{$column}: " . count( $source ) . " migrated row(s), raw bytes identical\n";
}

echo $failed > 0 ? "byte fidelity: FAILED\n" : "byte fidelity: ok\n";
exit( $failed > 0 ? 1 : 0 );
