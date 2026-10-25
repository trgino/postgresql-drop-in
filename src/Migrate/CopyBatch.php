<?php
/**
 * COPY text-format line builder and batch sizer (docs/15 §5.1).
 *
 * Pure: rows in (already-transformed string|null cells), COPY-format
 * lines out. Batches respect WPSLONIK_MIGRATE_BATCH_ROWS and
 * BATCH_BYTES simultaneously (the smaller wins, per docs §5.1).
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Migrate;

final class CopyBatch {

	/**
	 * NULL marker for the COPY lines.
	 *
	 * PDO::pgsqlCopyFromArray does not pass the PostgreSQL "\N" marker
	 * through: the marker comes back as the literal string "N", so every
	 * NULL would land as "N" (audit D-62). The copy uses its own marker
	 * instead - a string without a backslash (PostgreSQL refuses a null
	 * representation that contains one) and without a newline (the same
	 * refusal). A data value equal to the marker cannot be told apart
	 * from a NULL, so it aborts the table instead of landing as NULL.
	 */
	const NULL_MARKER = 'wpslonik_null_4f2a9c';

	/**
	 * Escape one cell for PG COPY text format.
	 *
	 * @param string|null $cell Already-transformed value.
	 * @return string COPY-escaped cell (the NULL marker for null).
	 * @throws MigrateDataError When the value equals the NULL marker.
	 */
	public static function cell( $cell ) {
		if ( null === $cell ) {
			return self::NULL_MARKER;
		}
		$value = (string) $cell;
		if ( self::NULL_MARKER === $value ) {
			throw new MigrateDataError( 'a value equals the copy NULL marker (' . self::NULL_MARKER . '); it cannot be copied' );
		}
		return str_replace( array( '\\', "\t", "\n", "\r" ), array( '\\\\', '\\t', '\\n', '\\r' ), $value );
	}

	/**
	 * @param array<int,array<int,?string>> $rows      Rows of transformed cells.
	 * @param int                           $max_rows  Soft row bound per COPY call.
	 * @param int                           $max_bytes Soft byte bound per COPY call.
	 * @return array<int,array<int,string>> Line batches (each inner array = one COPY call).
	 */
	public static function batches( array $rows, $max_rows, $max_bytes ) {
		$max_rows  = (int) $max_rows;
		$max_bytes = (int) $max_bytes;
		$batches   = array();
		$current   = array();
		$bytes     = 0;
		foreach ( $rows as $cells ) {
			$cells = (array) $cells;
			$line  = self::cell( isset( $cells[0] ) ? $cells[0] : null );
			for ( $i = 1, $n = count( $cells ); $i < $n; ++$i ) {
				$line .= "\t" . self::cell( $cells[ $i ] );
			}
			$line_bytes = strlen( $line ) + 1;
			if ( array() !== $current
				&& ( $bytes + $line_bytes > $max_bytes || count( $current ) + 1 > $max_rows ) ) {
				$batches[] = $current;
				$current   = array();
				$bytes     = 0;
			}
			$current[] = $line;
			$bytes    += $line_bytes;
		}
		if ( array() !== $current ) {
			$batches[] = $current;
		}
		return $batches;
	}
}
