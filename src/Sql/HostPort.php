<?php
/**
 * The PostgreSQL host and port, parsed in one place.
 *
 * The runtime and the migration tool must agree: a naive
 * explode( ':' ) breaks "[::1]:5432" (audit V-09).
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Sql;

final class HostPort {

	/**
	 * Split a host into the host and the port.
	 *
	 * Accepted forms: "host", "host:port", "[::1]:port", "::1" (a
	 * plain IPv6 address, no port) and ":/path/to/socket" (a unix
	 * socket).
	 *
	 * @param string $host        The configured host.
	 * @param int    $default_port The port when the host has none.
	 * @return array{0:string,1:int} The host and the port.
	 */
	public static function split( $host, $default_port = 5432 ): array {
		$host = trim( (string) $host );
		$port = (int) $default_port;
		if ( '' === $host ) {
			return array( '', $port );
		}
		// Bracketed IPv6 with a port: [::1]:5432.
		if ( 1 === preg_match( '/^\[[^\]]+\]:(\d+)$/', $host, $matches ) ) {
			$pos = strrpos( $host, ':' );
			return array( substr( $host, 0, $pos ), (int) $matches[1] );
		}
		// A plain IPv6 address has several colons and no port.
		if ( 1 === preg_match( '/^\[?[0-9A-Fa-f]*:[0-9A-Fa-f:]*$/', $host ) && substr_count( $host, ':' ) > 1 ) {
			return array( $host, $port );
		}
		// A unix socket path: :/var/run/postgresql.
		if ( '/' === substr( $host, 0, 1 ) || ( ':' === substr( $host, 0, 1 ) && '/' === substr( $host, 1, 1 ) ) ) {
			return array( $host, $port );
		}
		$pos = strrpos( $host, ':' );
		if ( false !== $pos ) {
			$tail = substr( $host, $pos + 1 );
			if ( 1 === preg_match( '/^\d+$/', $tail ) ) {
				return array( substr( $host, 0, $pos ), (int) $tail );
			}
		}
		return array( $host, $port );
	}

	/**
	 * The DSN fragment of the host and the port.
	 *
	 * @param string $host         The configured host.
	 * @param int    $default_port The port when the host has none.
	 * @return string
	 */
	public static function dsn( $host, $default_port = 5432 ): string {
		list( $name, $port ) = self::split( $host, $default_port );
		// A unix socket path loses the leading colon (libpq form).
		if ( ':' === substr( $name, 0, 1 ) && '/' === substr( $name, 1, 1 ) ) {
			$name = substr( $name, 1 );
		}
		return 'host=' . $name . ';port=' . $port . ';';
	}
}
