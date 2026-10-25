<?php
/**
 * APCu-backed translation-plan cache (M7.1).
 *
 * Shared across requests on a single PHP-FPM pool. Degrades silently when
 * APCu is not available.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Cache;

final class ApcuCache implements CacheInterface {

	/** @var string Prefix to avoid collisions with other APCu users. */
	private $prefix;

	public function __construct( string $prefix = 'slonik:' ) {
		$this->prefix = $prefix;
	}

	private function available(): bool {
		return function_exists( 'apcu_fetch' ) && function_exists( 'apcu_store' ) && function_exists( 'apcu_delete' );
	}

	public function get( string $key ): ?array {
		if ( ! $this->available() ) {
			return null;
		}
		$entry = apcu_fetch( $this->prefix . $key, $success );
		if ( ! $success || ! is_array( $entry ) || ! isset( $entry['value'], $entry['expires'] ) ) {
			return null;
		}
		if ( $entry['expires'] > 0 && time() > (int) $entry['expires'] ) {
			@apcu_delete( $this->prefix . $key );
			return null;
		}
		return $entry['value'];
	}

	public function set( string $key, array $value, int $ttl = self::DEFAULT_TTL ): bool {
		if ( ! $this->available() ) {
			return false;
		}
		return (bool) apcu_store(
			$this->prefix . $key,
			array(
				'value'   => $value,
				'expires' => $ttl > 0 ? time() + $ttl : 0,
			),
			$ttl > 0 ? $ttl : 0
		);
	}

	public function clear(): bool {
		if ( ! $this->available() ) {
			return false;
		}
		// APCu has no prefix scan; clearing one key at a time is out of
		// scope. Flush the user cache (acceptable for a cache reset).
		return (bool) apcu_clear_cache();
	}
}
