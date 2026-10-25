<?php
/**
 * Minimal result shaping (M2a thin slice).
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Runtime;

final class ResultShaper {

	/**
	 * Map one raw PG value to its wpdb-visible form.
	 *
	 * @param string $native_type PG native type name (e.g. bool, int8, text).
	 * @param mixed  $value       Raw value or null.
	 * @return string|null
	 */
	public static function map_value( $native_type, $value ) {
		if ( null === $value ) {
			return null;
		}
		if ( 'bool' === $native_type ) {
			return ( true === $value || 1 === $value || '1' === $value || 't' === $value || 'true' === $value ) ? '1' : '0';
		}
		if ( in_array( $native_type, array( 'timestamp', 'timestamptz', 'date' ), true ) ) {
			$text = (string) $value;
			if ( '0001-01-01' === substr( $text, 0, 10 ) ) {
				return '0000-00-00' . substr( $text, 10 );
			}
			if ( strlen( $text ) > 19 ) {
				return substr( $text, 0, 19 );
			}
			return $text;
		}
		if ( 'bytea' === $native_type ) {
			$text = (string) $value;
			if ( 0 === strpos( $text, '\x' ) ) {
				$raw = hex2bin( substr( $text, 2 ) );
				if ( false !== $raw ) {
					return $raw;
				}
			}
			return $text;
		}
		return (string) $value;
	}
}
