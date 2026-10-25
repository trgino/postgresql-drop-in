<?php
/**
 * File-based translation-plan cache (M7.1).
 *
 * One file per plan under a writable directory (default
 * wp-content/wpslonik/cache/translations/). Writes are atomic
 * (tmp + rename) and guarded with flock; reads check mtime against TTL.
 * Shared across requests on a single host.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Cache;

final class FileCache implements CacheInterface {

	/** @var string Directory holding the cache files. */
	private $dir;

	public function __construct( string $dir ) {
		$this->dir = rtrim( (string) $dir, '/\\' );
	}

	private function path( string $key ): string {
		return $this->dir . '/' . $key . '.json';
	}

	private function ensureDir(): bool {
		if ( is_dir( $this->dir ) ) {
			return true;
		}
		if ( '' === $this->dir ) {
			return false;
		}
		$created = @mkdir( $this->dir, 0777, true );
		if ( $created || is_dir( $this->dir ) ) {
			$this->guardDir();
			return true;
		}
		return false;
	}

	/**
	 * Guard the cache directory from direct web access (audit D-17).
	 *
	 * @return void
	 */
	private function guardDir(): void {
		$index = $this->dir . '/index.php';
		if ( ! is_file( $index ) ) {
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
		$htaccess = $this->dir . '/.htaccess';
		if ( ! is_file( $htaccess ) ) {
			@file_put_contents( $htaccess, "Options -Indexes\ndeny from all\n" );
		}
	}

	private function decode( string $raw ): ?array {
		$dec = json_decode( $raw, true );
		if ( ! is_array( $dec ) || ! isset( $dec['value'], $dec['expires'] ) ) {
			return null;
		}
		if ( $dec['expires'] > 0 && time() > (int) $dec['expires'] ) {
			return null;
		}
		return $dec['value'];
	}

	public function get( string $key ): ?array {
		$file = $this->path( $key );
		if ( ! is_file( $file ) ) {
			return null;
		}
		$handle = @fopen( $file, 'rb' );
		if ( ! $handle ) {
			return null;
		}
		try {
			if ( ! flock( $handle, LOCK_SH ) ) {
				return null;
			}
			$raw = stream_get_contents( $handle );
			flock( $handle, LOCK_UN );
		} finally {
			fclose( $handle );
		}
		clearstatcache( true, $file );
		$value = $this->decode( (string) $raw );
		if ( null === $value ) {
			@unlink( $file );
		}
		return $value;
	}

	public function set( string $key, array $value, int $ttl = self::DEFAULT_TTL ): bool {
		if ( ! $this->ensureDir() ) {
			return false;
		}
		$file = $this->path( $key );
		$tmp  = $file . '.' . getmypid() . '.' . uniqid( '', true ) . '.tmp';
		$payload = array(
			'value'   => $value,
			'expires' => $ttl > 0 ? time() + $ttl : 0,
		);
		$encoded = @json_encode( $payload );
		if ( false === $encoded ) {
			return false;
		}
		$handle = @fopen( $tmp, 'wb' );
		if ( ! $handle ) {
			return false;
		}
		try {
			if ( ! flock( $handle, LOCK_EX ) ) {
				return false;
			}
			$written = fwrite( $handle, $encoded );
			fflush( $handle );
			flock( $handle, LOCK_UN );
		} finally {
			fclose( $handle );
		}
		if ( false === $written || strlen( $encoded ) !== $written ) {
			@unlink( $tmp );
			return false;
		}
		return (bool) @rename( $tmp, $file );
	}

	public function clear(): bool {
		if ( ! is_dir( $this->dir ) ) {
			return true;
		}
		$ok = true;
		foreach ( glob( $this->dir . '/*.json' ) as $file ) {
			if ( ! @unlink( $file ) ) {
				$ok = false;
			}
		}
		return $ok;
	}
}
