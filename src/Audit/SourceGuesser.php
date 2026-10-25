<?php
/**
 * Call-stack source attribution for compat entries (docs/10 §4).
 *
 * Maps a file path to core/plugin:<slug>/theme:<slug>; anything outside
 * the standard prefixes stays 'unknown' — never mislabeled as core.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Audit;

final class SourceGuesser {

	/**
	 * @param string $file Absolute file path.
	 * @return string core | plugin:<slug> | theme:<slug> | unknown
	 */
	public static function slug( $file ) {
		$path = str_replace( '\\', '/', (string) $file );
		if ( false !== ( $p = strpos( $path, 'wp-content/plugins/' ) ) ) {
			$rest = substr( $path, $p + strlen( 'wp-content/plugins/' ) );
			return 'plugin:' . strtok( $rest, '/' );
		}
		if ( false !== ( $p = strpos( $path, 'wp-content/themes/' ) ) ) {
			$rest = substr( $path, $p + strlen( 'wp-content/themes/' ) );
			return 'theme:' . strtok( $rest, '/' );
		}
		foreach ( array( 'wp-includes/', 'wp-admin/' ) as $core ) {
			if ( false !== strpos( $path, $core ) ) {
				return 'core';
			}
		}
		return 'unknown';
	}
}
