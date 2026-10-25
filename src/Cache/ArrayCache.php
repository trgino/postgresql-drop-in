<?php
/**
 * Per-request in-memory translation-plan cache (M7.1).
 *
 * Lives for a single WordPress request and dies with it. Useful as the
 * 'off' / fallback tier and in unit tests.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Cache;

final class ArrayCache implements CacheInterface {

	/** @var array<string, array{value: array, expires: int}> */
	private $store = array();

	public function get( string $key ): ?array {
		$entry = $this->store[ $key ] ?? null;
		if ( null === $entry ) {
			return null;
		}
		if ( $entry['expires'] > 0 && time() > $entry['expires'] ) {
			unset( $this->store[ $key ] );
			return null;
		}
		return $entry['value'];
	}

	public function set( string $key, array $value, int $ttl = self::DEFAULT_TTL ): bool {
		$this->store[ $key ] = array(
			'value'   => $value,
			'expires' => $ttl > 0 ? time() + $ttl : 0,
		);
		return true;
	}

	public function clear(): bool {
		$this->store = array();
		return true;
	}
}
