<?php
/**
 * Translation-plan cache factory (M7.1).
 *
 * Reads WPSLONIK_CACHE ('auto'|'apcu'|'file'|'off', default 'auto') and
 * returns the matching implementation, or null when caching is disabled.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Cache;

final class CacheFactory {

	/** @var array<string,string> mode => class */
	private const MAP = array(
		'apcu' => ApcuCache::class,
		'file' => FileCache::class,
	);

	/**
	 * Build a cache from the WPSLONIK_CACHE constant (or an explicit mode).
	 *
	 * @param string|null $mode Explicit mode; when null the
	 *                          WPSLONIK_CACHE constant is used.
	 * @param string      $dir  Directory for the 'file' tier (defaults to
	 *                          wp-content/wpslonik/cache/translations/).
	 * @return CacheInterface|null null when caching is disabled ('off' or
	 *             no usable backend).
	 */
	public static function make( ?string $mode = null, string $dir = '' ): ?CacheInterface {
		if ( null === $mode ) {
			$mode = defined( 'WPSLONIK_CACHE' ) && is_string( WPSLONIK_CACHE ) ? (string) WPSLONIK_CACHE : 'auto';
		}
		$mode = strtolower( (string) $mode );
		if ( 'off' === $mode ) {
			return null;
		}
		if ( isset( self::MAP[ $mode ] ) ) {
			$class = self::MAP[ $mode ];
			if ( 'file' === $mode && '' === $dir ) {
				$dir = self::defaultDir();
			}
			return new $class( $dir );
		}
		// 'auto' (or unknown): apcu first, then file, then array.
		if ( function_exists( 'apcu_fetch' ) ) {
			return new ApcuCache();
		}
		if ( '' !== $dir && is_writable( dirname( $dir ) ) ) {
			return new FileCache( $dir );
		}
		return new ArrayCache();
	}

	/**
	 * Default file-cache directory under wp-content.
	 *
	 * @return string
	 */
	private static function defaultDir(): string {
		$wp_content = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '';
		if ( '' === $wp_content ) {
			$wp_content = sys_get_temp_dir() . '/wpslonik-cache';
		}
		return $wp_content . '/wpslonik/cache/translations';
	}
}
