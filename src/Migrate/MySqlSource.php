<?php
/**
 * MySQL source adapter for the migration (docs/15 §5.1, §9): raw mysqli is
 * out of the wpdb scope on purpose (NG-1 readers; the tool only SELECTs —
 * the source is never modified, docs/15 §1).
 *
 * The mysqli-driving methods are live-tested (T8 containers); the SQL
 * builders are pure and unit-tested here. Identifier values take the
 * the doubled-backtick rule, never mysqli_real_escape (identifiers).
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Migrate;

use mysqli;

final class MySqlSource implements CopySource {

	/** @var mysqli */
	private $cx;

	/** @var string Source database (default: the connection's database). */
	private $database;

	public function __construct( mysqli $cx, $database = '' ) {
		$this->cx       = $cx;
		$this->database = (string) $database;
	}

	/**
	 * Live connect from config values (outside wp; caller runs the CLI).
	 *
	 * @return self
	 */
	public static function connect( array $source ) {
		mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
		$cx = mysqli_init();
		if ( false === $cx ) {
			throw new \RuntimeException( 'mysqli_init failed' );
		}
		// Keep integers as strings: unsigned range checks and checksums rely
		// on canonical string values (docs/15 §6).
		$cx->options( MYSQLI_OPT_INT_AND_FLOAT_NATIVE, false );
		$ok = $cx->real_connect( $source['host'], $source['user'], $source['pass'], (string) ( $source['name'] ?? '' ), (int) ( $source['port'] ?? 3306 ) );
		if ( false === $ok ) {
			mysqli_close( $cx );
			throw new \RuntimeException( 'mysql source connect failed (redacted)' );
		}
		if ( true !== $cx->set_charset( 'utf8mb4' ) ) {
			mysqli_close( $cx );
			throw new \RuntimeException( 'source charset must be utf8mb4' );
		}
		return new self( $cx, (string) ( $source['name'] ?? '' ) );
	}

	/** Query one scalar. */
	public function value( $sql ) {
		$res = $this->cx->query( (string) $sql );
		if ( ! $res instanceof \mysqli_result ) {
			throw new \RuntimeException( 'mysql source query failed: ' . $this->cx->error );
		}
		$row = $res->fetch_row();
		mysqli_free_result( $res );
		return is_array( $row ) ? $row[0] : null;
	}

	/**
	 * Unbuffered stream of associative rows (docs §5.1 memory contract).
	 *
	 * @param string $sql SELECT assembled by QueryPlanner.
	 * @return iterable<int,array<string,?string>> Row values in natural order.
	 */
	public function stream( $sql ) {
		mysqli_real_query( $this->cx, (string) $sql );
		$res = mysqli_use_result( $this->cx );
		if ( false === $res ) {
			throw new \RuntimeException( 'mysql source stream failed: ' . $this->cx->error );
		}
		try {
			while ( ( $row = $res->fetch_assoc() ) !== null ) {
				yield $row;
			}
		} finally {
			mysqli_free_result( $res );
			// Drain the remaining result so the connection stays usable.
			while ( mysqli_more_results( $this->cx ) ) {
				mysqli_next_result( $this->cx );
			}
		}
	}

	/**
	 * Small associative fetch (bounded rows only).
	 *
	 * Keys are normalized to lowercase: information_schema column casing
	 * differs between servers (plain refs return uppercase on MySQL 8.4,
	 * aliases keep their written case), and downstream consumers read a
	 * single documented contract — lowercase meta keys.
	 */
	public function rows( $sql ): array {
		mysqli_real_query( $this->cx, (string) $sql );
		$res = mysqli_use_result( $this->cx );
		if ( false === $res ) {
			throw new \RuntimeException( 'mysql source query failed: ' . $this->cx->error );
		}
		$out = array();
		while ( ( $row = $res->fetch_assoc() ) !== null ) {
			$out[] = array_change_key_case( $row, CASE_LOWER );
		}
		mysqli_free_result( $res );
		while ( mysqli_more_results( $this->cx ) ) {
			mysqli_next_result( $this->cx );
		}
		return $out;
	}

	/** Execute a no-result statement (e.g. START TRANSACTION WITH CONSISTENT SNAPSHOT). */
	public function exec( $sql ) {
		mysqli_real_query( $this->cx, (string) $sql );
		while ( mysqli_more_results( $this->cx ) ) {
			mysqli_next_result( $this->cx );
		}
	}

	/** Quoted identifier (the doubled backtick rule). */
	public static function ident( $name ) {
		return '`' . str_replace( '`', '``', (string) $name ) . '`';
	}

	public function tables( $prefix, array $include, array $exclude ): array {
		$sql = self::tableListSql( $this->database, $prefix, $include, $exclude );
		return $this->rows( $sql );
	}

	public function columnsOf( $table ): array {
		return $this->rows( self::columnsSql( $this->database, $table ) );
	}

	/** @return array<int,string> Primary key column names in ordinal order ([] when none). */
	public function primaryKeys( $table ): array {
		$rows = $this->rows( self::keysSql( $this->database, $table, true ) );
		$cols = array();
		foreach ( $rows as $row ) {
			$cols[] = (string) $row['column_name'];
		}
		return $cols;
	}

	/** Objects standing in the way of v1 DDL: views/triggers/routines/events/FKs. */
	public function anomalies( $prefix ): array {
		$out = array();
		foreach ( self::anomalySqls( $this->database ) as $kind => $sql ) {
			foreach ( $this->rows( $sql ) as $row ) {
				$out[] = array( 'kind' => $kind, 'name' => (string) $row['name'], 'table' => (string) ( $row['tab'] ?? '' ) );
			}
		}
		return $out;
	}

	public function showCreate( $table ): string {
		mysqli_real_query( $this->cx, 'SHOW CREATE TABLE ' . self::ident( $table ) );
		$res = mysqli_use_result( $this->cx );
		if ( false === $res ) {
			throw new \RuntimeException( 'SHOW CREATE TABLE failed for ' . $table );
		}
		$row = $res->fetch_array( MYSQLI_NUM );
		mysqli_free_result( $res );
		while ( mysqli_more_results( $this->cx ) ) {
			mysqli_next_result( $this->cx );
		}
		if ( ! is_array( $row ) ) {
			throw new \RuntimeException( 'SHOW CREATE TABLE returned nothing for: ' . $table );
		}
		return (string) $row[1];
	}

	/** Approximate row/dimension stats from information_schema (single row). */
	public function statsOf( $table ): array {
		$rows = $this->rows( self::statsSql( $this->database, $table ) );
		return $rows[0] ?? array();
	}

	// ------------------------------------------------------------------
	// Pure SQL builders (unit-level here; executed live in T8).
	// ------------------------------------------------------------------

	public static function tableListSql( $database, $prefix, array $include, array $exclude ): string {
		// Scope: the prefix range plus explicitly included tables.
		$scope = array( 'table_name LIKE ' . self::sqlstr( (string) $prefix . '%' ) );
		if ( count( (array) $include ) > 0 ) {
			$scope[] = 'table_name IN (' . implode( ', ', array_map( array( self::class, 'sqlstr' ), (array) $include ) ) . ')';
		}
		$sql = 'SELECT table_name, engine, table_type, table_rows, data_length, table_collation'
			. ' FROM information_schema.tables'
			. ' WHERE table_schema = ' . self::sqlstr( (string) $database )
			. ' AND ( ' . implode( ' OR ', $scope ) . ' )';
		// Exclusions are AND-ed: OR-ed they would match every table.
		if ( count( (array) $exclude ) > 0 ) {
			$sql .= ' AND table_name NOT IN (' . implode( ', ', array_map( array( self::class, 'sqlstr' ), (array) $exclude ) ) . ')';
		}
		return $sql . ' ORDER BY table_name';
	}

	public static function columnsSql( $database, $table ): string {
		return 'SELECT table_name AS table_name, column_name, data_type, column_type,'
			. ' datetime_precision, character_set_name, collation_name, is_nullable, column_default, extra'
			. ' FROM information_schema.columns'
			. ' WHERE table_schema = ' . self::sqlstr( (string) $database )
			. ' AND table_name = ' . self::sqlstr( (string) $table )
			. ' ORDER BY ordinal_position';
	}

	public static function keysSql( $database, $table, $primary ): string {
		$needle = true === $primary ? "'PRIMARY'" : 'constraint_name <> ' . "'PRIMARY'";
		return 'SELECT kcu.column_name, kcu.ordinal_position'
			. ' FROM information_schema.key_column_usage kcu'
			. ' WHERE kcu.table_schema = ' . self::sqlstr( (string) $database )
			. ' AND kcu.table_name = ' . self::sqlstr( (string) $table )
			. ' AND kcu.constraint_name = ' . $needle
			. ' ORDER BY kcu.ordinal_position';
	}

	public static function statsSql( $database, $table ): string {
		return 'SELECT table_name, engine, table_rows, data_length, index_length'
			. ' FROM information_schema.tables'
			. ' WHERE table_schema = ' . self::sqlstr( (string) $database )
			. ' AND table_name = ' . self::sqlstr( (string) $table );
	}

	public static function anomalySqls( $database ): array {
		$db = self::sqlstr( (string) $database );
		return array(
			'views'    => 'SELECT table_name AS name FROM information_schema.views WHERE table_schema = ' . $db,
			'triggers' => 'SELECT trigger_name AS name, event_object_table AS tab FROM information_schema.triggers WHERE trigger_schema = ' . $db,
			'routines' => 'SELECT routine_name AS name, routine_type AS tab FROM information_schema.routines WHERE routine_schema = ' . $db,
			'events'   => 'SELECT event_name AS name FROM information_schema.events WHERE event_schema = ' . $db,
			'fks'      => 'SELECT constraint_name AS name, table_name AS tab FROM information_schema.referential_constraints WHERE constraint_schema = ' . $db,
		);
	}

	private static function sqlstr( $value ) {
		return "'" . str_replace( "\\", "\\\\", str_replace( "'", "''", (string) $value ) ) . "'";
	}
}
