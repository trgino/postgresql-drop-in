<?php
/**
 * Pure SELECT/keyset SQL builders for the copy phase (docs/15 §5.1):
 * keyset pagination on the primary key tuple, full scan when no PK.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Migrate;

final class QueryPlanner {

	/** Maven column list of the (already filtered) copy columns. */
	public static function selectColumnsSql( array $columns ) {
		$want = array();
		foreach ( (array) $columns as $col ) {
			$want[] = MySqlSource::ident( (string) $col );
		}
		return implode( ', ', $want );
	}

	public static function keysetWhere( array $pk, $last_key ) {
		if ( array() === (array) $pk || '' === (string) $last_key ) {
			return '';
		}
		$cols = array();
		foreach ( (array) $pk as $col ) {
			$cols[] = MySqlSource::ident( (string) $col );
		}
		$values = array();
		foreach ( self::decodeKey( $last_key ) as $v ) {
			$values[] = 'NULL' === $v ? 'NULL' : self::universal_string( $v );
		}
		return ' WHERE (' . implode( ', ', $cols ) . ') > (' . implode( ', ', $values ) . ')';
	}

	/**
	 * Full batch query; $last_key is the encoded watermark ('' = first batch).
	 */
	public static function batchSql( $table, array $columns, array $pk, $last_key, $limit ) {
		$where = self::keysetWhere( $pk, $last_key );
		$cols  = self::selectColumnsSql( $columns );
		$sql   = 'SELECT ' . $cols . ' FROM ' . MySqlSource::ident( (string) $table )
			. $where
			. ( array() !== (array) $pk ? ' ORDER BY ' . implode( ', ', array_map( array( MySqlSource::class, 'ident' ), (array) $pk ) ) : '' );
		$limit = (int) $limit;
		if ( $limit >= 1 ) {
			$sql .= ' LIMIT ' . $limit;
		}
		return $sql;
	}

	/** Encode the PK watermark as an opaque storable text blob. */
	public static function encodeKey( array $tuple ) {
		return (string) json_encode( array_map( 'strval', (array) $tuple ) );
	}

	/** @return array<int,string> */
	public static function decodeKey( $encoded ) {
		$data = json_decode( (string) $encoded, true );
		if ( ! is_array( $data ) ) {
			throw new MigrateDataError( 'state watermark is corrupt; re-run the table with a reset state (migrate resume --repair)' );
		}
		$out = array();
		foreach ( $data as $v ) {
			$out[] = (string) $v;
		}
		return $out;
	}

	private static function universal_string( $v ) {
		return "'" . str_replace( '\\', '\\\\', str_replace( "'", "''", (string) $v ) ) . "'";
	}
}
