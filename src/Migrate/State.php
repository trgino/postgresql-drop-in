<?php
/**
 * Migration state store (docs/15 §5.2: wpslonik_migration in the TARGET
 * schema; batch state updates commit in the same transaction as the data,
 * so resume stays consistent) plus the advisory single-runner lock
 * (docs/15 §5.1 parity).
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Migrate;

final class State implements StateStore {

	const LOCK_KEY = 424242424;

	/** @var \PDO */
	private $pdo;

	/** @var string */
	private $qualified;

	/** @var string Unqualified target schema name. */
	private $schema;

	public function __construct( \PDO $pdo, $schema ) {
		$this->pdo       = $pdo;
		$this->schema    = (string) ( '' !== (string) $schema ? $schema : 'public' );
		$this->qualified = '"' . str_replace( '"', '""', $this->schema ) . '"."wpslonik_migration"';
	}

	public function qualifiedName() {
		return $this->qualified;
	}

	public function ensureTable() {
		// Schema itself is created by the run (docs/15 §2: operators provide
		// the DATABASE; the tool owns the schema inside it).
		$this->pdo->exec( 'CREATE SCHEMA IF NOT EXISTS "'
			. str_replace( '"', '""', $this->schema ) . '"' );
		$this->pdo->exec( 'CREATE TABLE IF NOT EXISTS ' . $this->qualifiedName() . ' ('
			. ' "table_name" text PRIMARY KEY,'
			. ' "status" text NOT NULL,'
			. ' "last_key" text,'
			. ' "rows_done" bigint NOT NULL DEFAULT 0,'
			. ' "skipped" int NOT NULL DEFAULT 0,'
			. ' "note" text'
			. ' )' );
		return true;
	}

	/**
	 * @param string $table  Table name.
	 * @param string $status pending|schema|copy|done|error|skipped.
	 * @param string $lastKey Encoded keyset watermark ('' = start).
	 */
	public function set( $table, $status, $lastKey, $rowsDone, $skipped, $note = '' ) {
		$stmt = $this->pdo->prepare( 'INSERT INTO ' . $this->qualifiedName()
			. ' ("table_name", "status", "last_key", "rows_done", "skipped", "note")'
			. ' VALUES (?, ?, ?, ?, ?, ?)'
			. ' ON CONFLICT ("table_name") DO UPDATE SET'
			. ' "status" = EXCLUDED."status", "last_key" = EXCLUDED."last_key",'
			. ' "rows_done" = EXCLUDED."rows_done", "skipped" = EXCLUDED."skipped", "note" = EXCLUDED."note"' );
		$stmt->execute( array( (string) $table, (string) $status, (string) $lastKey, max( 0, (int) $rowsDone ), max( 0, (int) $skipped ), (string) $note ) );
		return true;
	}

	/** @return array|null Row for a table (state columns) or null. */
	public function get( $table ) {
		$stmt = $this->pdo->prepare( 'SELECT "table_name", "status", "last_key", "rows_done", "skipped", "note"'
			. ' FROM ' . $this->qualifiedName() . ' WHERE "table_name" = ?' );
		$stmt->execute( array( (string) $table ) );
		$row = $stmt->fetch( \PDO::FETCH_ASSOC );
		return false === $row ? null : $row;
	}

	/** @return array<string,array> All state rows keyed by table name. */
	public function all() {
		$rows = $this->pdo->query( 'SELECT "table_name", "status", "last_key", "rows_done", "skipped", "note" FROM ' . $this->qualifiedName() . ' ORDER BY "table_name"' )
			->fetchAll( \PDO::FETCH_ASSOC );
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['table_name'] ] = $row;
		}
		return $out;
	}

	public function clear( $table ) {
		$stmt = $this->pdo->prepare( 'DELETE FROM ' . $this->qualifiedName() . ' WHERE "table_name" = ?' );
		$stmt->execute( array( (string) $table ) );
		return true;
	}

	public function clearAll() {
		$this->pdo->exec( 'DELETE FROM ' . $this->qualifiedName() );
		return true;
	}

	/** Advisory lock: second migrate runner is rejected (docs/15 §5.1). */
	public function lock() {
		$stmt = $this->pdo->prepare( 'SELECT pg_try_advisory_lock( ? )' );
		$stmt->execute( array( self::LOCK_KEY ) );
		$value = false === $stmt ? false : $stmt->fetchColumn();
		return (bool) $value;
	}

	public function unlock() {
		$stmt = $this->pdo->prepare( 'SELECT pg_advisory_unlock( ? )' );
		$stmt->execute( array( self::LOCK_KEY ) );
		return true;
	}
}
