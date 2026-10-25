<?php
/**
 * Row value transforms for the MySQL → PG data copy (docs/15 §5.2).
 *
 * Pure per-cell function: blob-safe binary (bytea target) passes through;
 * date/time cells get the zero-date sentinel (P-06); text cells get NUL
 * removal (with warning counts). Unsigned range overflow aborts the row
 * with a MigrateDataError — nothing is silently truncated (K-31).
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Migrate;

final class RowTransform {

	const SENTINEL_DATE      = '0001-01-01';
	const SENTINEL_TIMESTAMP = '0001-01-01 00:00:00';

	/** @var array<string,string> Unsigned DATA_TYPE → max value (decimal strings; no float drift). */
	const UNSIGNED_MAX = array(
		'tinyint'   => '255',
		'smallint'  => '65535',
		'mediumint' => '16777215',
		'int'       => '4294967295',
		'bigint'    => '18446744073709551615',
	);

	/**
	 * Is the column type a binary one (bytea target)?
	 *
	 * VARBINARY counts too: it is a binary column, so its bytes must not
	 * be treated as text (audit D-68).
	 *
	 * @param string $type Lower-case DATA_TYPE.
	 * @return bool
	 */
	private static function is_binary( $type ) {
		return 'binary' === $type || 'varbinary' === $type || false !== strpos( $type, 'blob' );
	}

	/**
	 * Transform one cell.
	 *
	 * @param string|null $raw    Raw source value (mysqli returns strings
	 *                            for everything except NULL under the
	 *                            binary protocol).
	 * @param array       $col    Column meta: type (lower-case DATA_TYPE),
	 *                            unsigned (bool), datetime_precision (int),
	 *                            binary (bool: the column targets bytea
	 *                            although its MySQL type is a text one -
	 *                            audit D-74).
	 * @param string      $policy Invalid-UTF-8 policy: abort | replace | skip-row.
	 * @return array{value:?string,nul_removed:int,micro_trimmed:bool}
	 * @throws MigrateDataError On unsigned overflow (abortable, row reported).
	 * @throws MigrateDataError On invalid UTF-8 in a text column with policy=abort.
	 * @throws SkipRowSignal    On invalid UTF-8 in a text column with policy=skip-row.
	 */
	public static function value( $raw, array $col, $policy = 'abort' ) {
		if ( null === $raw ) {
			return array( 'value' => null, 'nul_removed' => 0, 'micro_trimmed' => false );
		}
		$value = (string) $raw;
		$type  = strtolower( (string) ( $col['type'] ?? '' ) );

		$micro = false;
		if ( ( 'datetime' === $type || 'timestamp' === $type ) && (int) ( $col['datetime_precision'] ?? 0 ) > 0 ) {
			$trimmed = preg_replace( '/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\.\d+$/', '$1', $value );
			if ( null !== $trimmed && $trimmed !== $value ) {
				$value = $trimmed;
				$micro = true;
			}
		}

		if ( 'date' === $type && '0000-00-00' === $value ) {
			$value = self::SENTINEL_DATE;
		} elseif ( ( 'datetime' === $type || 'timestamp' === $type ) && ( '0000-00-00 00:00:00' === $value || '0000-00-00' === $value ) ) {
			$value = self::SENTINEL_TIMESTAMP;
		}

		$nul = 0;
		$is_binary = self::is_binary( $type ) || ! empty( $col['binary'] );
		$is_text = ! $is_binary;
		if ( $is_text ) {
			if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $value, 'UTF-8' ) ) {
				if ( 'replace' === $policy ) {
					mb_substitute_character( 0xfffd );
					$value = mb_convert_encoding( $value, 'UTF-8', 'UTF-8' );
				} elseif ( 'skip-row' === $policy ) {
					throw new SkipRowSignal();
				} else {
					throw new MigrateDataError( 'invalid UTF-8 in a text column' );
				}
			}
			$nul = substr_count( $value, "\0" );
			if ( $nul > 0 ) {
				$value = str_replace( "\0", '', $value );
			}
		} else {
			// A binary column targets bytea: PostgreSQL's bytea input
			// expects the hex format ("\x…"). The raw bytes would be
			// refused ("invalid input syntax for type bytea"), and the
			// hex form keeps every byte (audit D-68).
			$value = '\x' . bin2hex( $value );
		}

		if ( isset( self::UNSIGNED_MAX[ $type ] ) && true === (bool) ( $col['unsigned'] ?? false ) ) {
			$digits = ltrim( $value, '0' );
			if ( '' !== $digits && ctype_digit( $digits ) ) {
				$max = ltrim( self::UNSIGNED_MAX[ $type ], '0' );
				if ( strlen( $digits ) > strlen( $max )
					|| ( strlen( $digits ) === strlen( $max ) && strcmp( $digits, $max ) > 0 ) ) {
					throw new MigrateDataError( 'unsigned range overflow for column type ' . $type );
				}
			}
		}

		return array( 'value' => $value, 'nul_removed' => $nul, 'micro_trimmed' => $micro );
	}

	/**
	 * Transform a whole row.
	 *
	 * @param array<string,mixed> $assoc   Source row (column name => raw value).
	 * @param array<int,string>   $columns Column order.
	 * @param array<string,array> $colmeta Column metadata by name (type/unsigned/precision).
	 * @param string              $policy  Invalid-UTF-8 policy: abort | replace | skip-row.
	 * @return array{cells:array<int,?string>,nul:int} The transformed cells and the
	 *         number of removed NUL bytes (PostgreSQL text cannot store them, so
	 *         the copy has to report them - audit D-63).
	 * @throws MigrateDataError On unsigned overflow or invalid UTF-8 (policy=abort).
	 * @throws SkipRowSignal    On invalid UTF-8 (policy=skip-row).
	 */
	public static function row( array $assoc, array $columns, array $colmeta, $policy = 'abort' ): array {
		$cells = array();
		$nul   = 0;
		foreach ( $columns as $name ) {
			$raw    = array_key_exists( $name, $assoc ) ? $assoc[ $name ] : null;
			$col    = isset( $colmeta[ $name ] ) && is_array( $colmeta[ $name ] ) ? $colmeta[ $name ] : array();
			$xform  = self::value( $raw, $col, $policy );
			$nul   += (int) $xform['nul_removed'];
			$cells[] = $xform['value'];
		}
		return array( 'cells' => $cells, 'nul' => $nul );
	}
}
