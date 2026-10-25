<?php
/**
 * Bootstrap for the vendored nikic/php-parser (BSD-3-Clause, upstream v5.9.0).
 *
 * Registers a lightweight PSR-4 loader when no composer autoloader is
 * available (production plugin packages). In composer contexts this file is
 * harmless: class_exists guards skip duplicate loading.
 *
 * PHP 7.4 compatible (upstream library claims >=7.4).
 *
 * @license BSD-3-Clause — see LICENSE and PROVENANCE.md
 * @package Slonik
 */

if ( ! class_exists( 'PhpParser\\Parser', false ) ) {
	spl_autoload_register(
		static function ( $class ) {
			if ( 0 !== strpos( $class, 'PhpParser\\' ) ) {
				return;
			}
			$path = __DIR__ . '/lib/PhpParser/' . str_replace( '\\', '/', substr( $class, strlen( 'PhpParser\\' ) ) ) . '.php';
			if ( is_file( $path ) ) {
				require $path;
			}
		}
	);
}
