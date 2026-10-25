<?php
/**
 * Drop-in version marker: build, parse and verify.
 *
 * Format: `/* WPSLONIK-DROPIN v<VERSION> hash:<sha256> *\/` (single line).
 * The hash covers the file body with the marker line removed, so the
 * installer (Task 4) and the health check compute identical values.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Chain;

final class DropinMarker {

	const PREFIX = '/* WPSLONIK-DROPIN v';

	public static function build( $version, $body ) {
		return '/* WPSLONIK-DROPIN v' . (string) $version . ' hash:' . hash( 'sha256', (string) $body ) . " */\n";
	}

	/**
	 * @param string $code Full file contents.
	 * @return array|null Array with version, hash and marker keys, or null when absent.
	 */
	public static function parse( $code ) {
		$code = (string) $code;
		$at   = strpos( $code, self::PREFIX );
		if ( false === $at ) {
			return null;
		}
		$end = strpos( $code, '*/', $at );
		if ( false === $end ) {
			return null;
		}
		$marker  = substr( $code, $at, $end + 2 - $at );
		if ( "\n" === substr( $code, $end + 2, 1 ) ) {
			$marker .= "\n";
		}
		$version = substr( $code, $at + strlen( self::PREFIX ) );
		$space   = strpos( $version, ' ' );
		if ( false === $space ) {
			return null;
		}
		$version = substr( $version, 0, $space );
		$hash_at = strpos( $marker, 'hash:' );
		if ( false === $hash_at ) {
			return null;
		}
		$hash = trim( substr( $marker, $hash_at + 5 ), " */\t\n\r" );
		if ( '' === $version || '' === $hash ) {
			return null;
		}
		return array(
			'version' => $version,
			'hash'    => $hash,
			'marker'  => $marker,
		);
	}

	/**
	 * Whether the marker hash matches the body it covers.
	 *
	 * @param string $code Full file contents.
	 * @return bool
	 */
	public static function verify( $code ) {
		$parsed = self::parse( $code );
		if ( null === $parsed ) {
			return false;
		}
		$body = str_replace( $parsed['marker'], '', (string) $code );
		return hash_equals( $parsed['hash'], hash( 'sha256', $body ) );
	}
}
