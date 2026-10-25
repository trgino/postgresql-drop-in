<?php
/**
 * PHPStan-only WP-CLI stubs (never loaded at runtime or in tests).
 *
 * @package Slonik
 */

if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {

		public static function add_command( $name, $callable ) {
		}

		public static function log( $message ) {
		}

		public static function line( $message ) {
		}

		public static function success( $message ) {
		}

		public static function warning( $message ) {
		}

		public static function error( $message, $exit = true ) {
		}

		public static function add_hook( $name, $callable ) {
		}
	}
}
