<?php
/**
 * Detected environment for health checks (injectable for tests).
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Health;

final class Env {

	/** @var array */
	private $values;

	private function __construct( $values ) {
		$this->values = (array) $values;
	}

	public static function fromValues( $values ) {
		$defaults = array(
			'php_version' => PHP_VERSION,
			'extensions'  => get_loaded_extensions(),
			'wp_version'  => null,
			'defines'     => array(),
			'content_dir' => defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '',
			'behind_pool' => false,
		);
		return new self( array_merge( $defaults, (array) $values ) );
	}

	public static function detect() {
		$wp_version = null;
		if ( isset( $GLOBALS['wp_version'] ) && is_string( $GLOBALS['wp_version'] ) ) {
			$wp_version = $GLOBALS['wp_version'];
		}
		$defines = array();
		$all     = get_defined_constants( true );
		if ( isset( $all['user'] ) && is_array( $all['user'] ) ) {
			foreach ( $all['user'] as $name => $value ) {
				if ( 0 === strpos( (string) $name, 'WPSLONIK_' ) ) {
					$defines[ $name ] = $value;
				}
			}
		}
		$behind_pool = isset( $defines['WPSLONIK_BEHIND_POOLER'] ) && true === $defines['WPSLONIK_BEHIND_POOLER'];
		return self::fromValues(
			array(
				'php_version' => PHP_VERSION,
				'extensions'  => get_loaded_extensions(),
				'wp_version'  => $wp_version,
				'defines'     => $defines,
				'content_dir' => defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '',
				'behind_pool' => $behind_pool,
			)
		);
	}

	public function phpVersion() {
		return (string) $this->values['php_version'];
	}

	public function hasExtension( $name ) {
		return in_array( strtolower( (string) $name ), array_map( 'strtolower', (array) $this->values['extensions'] ), true );
	}

	public function wpVersion() {
		return null === $this->values['wp_version'] ? null : (string) $this->values['wp_version'];
	}

	public function defines() {
		return (array) $this->values['defines'];
	}

	public function define( $name ) {
		$defines = (array) $this->values['defines'];
		return array_key_exists( (string) $name, $defines ) ? $defines[ $name ] : null;
	}

	public function behindPool() {
		return ! empty( $this->values['behind_pool'] );
	}

	public function contentDir() {
		return (string) $this->values['content_dir'];
	}
}
