<?php
/**
 * `wp slonik translate|schema:check` commands.
 *
 * `translate` is a pure translator wrapper (unit goldens). `schema:check`
 * runs SchemaChecker over the live catalog (live-proven); `--repair`
 * executes only allowlisted setval statements.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Cli;

use PDO;
use Slonik\Health\PdoProbe;
use Slonik\Schema\Catalog;
use Slonik\Schema\SchemaChecker;
use Slonik\Sql\MinTranslator;
use Slonik\Sql\TranslationException;
use Throwable;

final class InspectCommand {

	/**
	 * @param string $mysql MySQL SQL.
	 * @param array  $assoc Assoc args (show-plan).
	 * @return array Array with code and out keys (no exit; entries exit).
	 */
	public static function translate( $mysql, $assoc ) {
		try {
			$plan = MinTranslator::translate( (string) $mysql );
		} catch ( TranslationException $e ) {
			return array( 'code' => 2, 'out' => 'error: ' . $e->getMessage() );
		}
		$steps = $plan->getSteps();
		if ( array() === $steps ) {
			return array( 'code' => 0, 'out' => '' );
		}
		$out = $steps[0]->getSql();
		if ( ! empty( $assoc['show-plan'] ) ) {
			$out .= "\nkind: " . $plan->getKind() . ' steps: ' . count( $steps );
		}
		return array( 'code' => 0, 'out' => $out );
	}

	/**
	 * WP-CLI entry: wp slonik translate "<mysql>" [--show-plan].
	 *
	 * @param array $args  Positional args (SQL first).
	 * @param array $assoc Assoc args.
	 */
	public static function translateEntry( $args, $assoc ) {
		$args = array_values( (array) $args );
		if ( ! isset( $args[0] ) || '' === (string) $args[0] ) {
			echo "usage: wp slonik translate \"<mysql>\" [--show-plan]\n";
			exit( 3 );
		}
		$res = self::translate( $args[0], (array) $assoc );
		echo $res['out'] . "\n";
		exit( $res['code'] );
	}

	/**
	 * WP-CLI entry: wp slonik schema:check [--repair].
	 *
	 * @param array $args  Positional args (unused).
	 * @param array $assoc Assoc args.
	 */
	public static function schemaCheck( $args, $assoc ) {
		unset( $args );
		$assoc  = (array) $assoc;
		$repair = ! empty( $assoc['repair'] );
		try {
			$pdo = self::connect();
		} catch ( Throwable $e ) {
			echo 'error: PG connect failed: ' . $e->getMessage() . "\n";
			exit( 1 );
		}
		if ( null === $pdo ) {
			echo "error: WPSLONIK_DB_* constants missing\n";
			exit( 1 );
		}
		try {
			$code = self::runSchemaCheck( $pdo, $repair );
		} catch ( Throwable $e ) {
			echo 'error: schema check failed: ' . $e->getMessage() . "\n";
			exit( 1 );
		}
		exit( $code );
	}

	/**
	 * @param PDO  $pdo PDO connection.
	 * @param bool $repair Whether to execute repairs.
	 * @return int Exit code.
	 */
	private static function runSchemaCheck( PDO $pdo, $repair ) {
		$schema  = self::schemaName();
		$catalog = Catalog::load( $pdo, $schema );
		$tables  = array();
		foreach ( self::tableNames( $pdo, $schema ) as $table ) {
			$columns  = $catalog->columns( $table );
			$identity = null;
			foreach ( $columns as $col ) {
				if ( $catalog->isIdentity( $table, $col ) ) {
					$identity = $col;
					break;
				}
			}
			$tables[] = array( 'table' => $table, 'columns' => $columns, 'identity' => $identity );
		}
		$checker = new SchemaChecker( new PdoProbe( $pdo ), $schema, $tables );
		$warn    = 0;
		foreach ( $checker->check() as $row ) {
			echo ( 'ok' === $row['status'] ? 'ok' : 'WARN' ) . ' ' . $row['id'] . ' ' . $row['message'] . "\n";
			if ( 'ok' !== $row['status'] ) {
				++$warn;
			}
		}
		if ( $repair ) {
			foreach ( $checker->repairs() as $sql ) {
				echo 'repair: ' . $sql . "\n";
				$checker->repairStatement( $sql );
			}
		}
		return $warn > 0 ? 1 : 0;
	}

	private static function schemaName() {
		return defined( 'WPSLONIK_DB_SCHEMA' ) && is_string( WPSLONIK_DB_SCHEMA ) && '' !== WPSLONIK_DB_SCHEMA ? WPSLONIK_DB_SCHEMA : 'public';
	}

	/**
	 * @return PDO|null Null when credentials are absent.
	 */
	private static function connect() {
		if ( ! defined( 'WPSLONIK_DB_HOST' ) || ! defined( 'WPSLONIK_DB_USER' ) || ! defined( 'WPSLONIK_DB_NAME' ) ) {
			return null;
		}
		list( $host, $port ) = HealthCommand::splitHost( (string) constant( 'WPSLONIK_DB_HOST' ) );
		$pass = defined( 'WPSLONIK_DB_PASSWORD' ) ? (string) constant( 'WPSLONIK_DB_PASSWORD' ) : '';
		return new PDO(
			'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . (string) constant( 'WPSLONIK_DB_NAME' ),
			(string) constant( 'WPSLONIK_DB_USER' ),
			$pass,
			array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION )
		);
	}

	/**
	 * @param PDO    $pdo PDO connection.
	 * @param string $schema Schema name.
	 * @return string[]
	 */
	private static function tableNames( PDO $pdo, $schema ) {
		$stmt = $pdo->query(
			"SELECT table_name FROM information_schema.tables WHERE table_schema = '" . str_replace( "'", "''", $schema ) . "' AND table_type = 'BASE TABLE' ORDER BY 1"
		);
		if ( false === $stmt ) {
			return array();
		}
		$names = $stmt->fetchAll( PDO::FETCH_COLUMN );
		return is_array( $names ) ? array_values( $names ) : array();
	}
}
