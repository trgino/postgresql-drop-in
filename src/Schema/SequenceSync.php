<?php
/**
 * Identity sequence synchronization (P-09/H-10).
 *
 * After an INSERT that lists an identity column explicitly, the sequence
 * lags behind and the next default insert would collide. Sync it to the
 * column maximum.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Schema;

final class SequenceSync {

	/**
	 * @param string $table  Table name (unquoted).
	 * @param string $column Identity column name (unquoted).
	 * @return string setval statement.
	 */
	public static function sql( $table, $column ) {
		$t = '"' . str_replace( '"', '""', (string) $table ) . '"';
		$c = '"' . str_replace( '"', '""', (string) $column ) . '"';
		$lit_col = "'" . str_replace( "'", "''", (string) $column ) . "'";
		$seq = 'pg_get_serial_sequence(' . "'" . str_replace( "'", "''", $t ) . "', " . $lit_col . ')';
		// M6 live finding: empty table + unused sequence must not hit
		// setval(0) (out of bounds). GREATEST(…, 1) floors the value; the
		// is_called flag stays false until the column actually has rows,
		// so the next default insert re-emits 1 on empty targets.
		return 'SELECT setval(' . $seq
			. ', GREATEST(COALESCE((SELECT MAX(' . $c . ') FROM ' . $t . '), 0), COALESCE(pg_sequence_last_value(' . $seq . '), 0), 1)'
			. ', COALESCE((SELECT MAX(' . $c . ') FROM ' . $t . '), 0) > 0)';
	}

	/**
	 * @param Catalog|null $catalog Catalog or null.
	 * @param string       $table   Table name.
	 * @param string[]     $columns Insert column list.
	 * @return string|null Canonical identity column when an explicit sync is needed.
	 */
	public static function needs_sync( $catalog, $table, $columns ) {
		if ( ! $catalog instanceof Catalog ) {
			return null;
		}
		foreach ( (array) $columns as $col ) {
			if ( $catalog->isIdentity( $table, $col ) ) {
				$canon = $catalog->canonical( $table, $col );
				return null !== $canon ? $canon : (string) $col;
			}
		}
		return null;
	}
}
