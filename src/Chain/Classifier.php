<?php
/**
 * Drop-in chain classifier (A/B/C) per docs/03 §7.
 *
 * Token-based structural scan (PHP code, not SQL): class-A decorators
 * (extend wpdb, neither override db_connect nor open their own
 * mysqli/PDO connection) can be chained; class-B (own connection or
 * db_connect override) is detected and refused; anything else is C.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Chain;

final class Classifier {

	/**
	 * @param string $phpCode Drop-in PHP source.
	 * @return string One of A, B, C.
	 */
	public static function classify( $phpCode ) {
		$tokens       = token_get_all( (string) $phpCode );
		$extends_wpdb = false;
		$has_connect  = false;
		$has_own_db   = false;
		$count        = count( $tokens );
		for ( $i = 0; $i < $count; ++$i ) {
			$token = $tokens[ $i ];
			if ( ! is_array( $token ) ) {
				continue;
			}
			if ( T_CLASS === $token[0] ) {
				if ( self::extendsName( $tokens, $i ) === 'wpdb' ) {
					$extends_wpdb = true;
				}
				continue;
			}
			if ( T_FUNCTION === $token[0] ) {
				$name = self::nextString( $tokens, $i );
				if ( 'db_connect' === strtolower( $name ) ) {
					$has_connect = true;
				}
				continue;
			}
			if ( T_NEW === $token[0] ) {
				$name = self::nextString( $tokens, $i );
				if ( in_array( strtolower( $name ), array( 'mysqli', 'pdo' ), true ) ) {
					$has_own_db = true;
				}
				continue;
			}
			if ( T_STRING === $token[0] && in_array( strtolower( $token[1] ), array( 'mysql_connect', 'mysql_pconnect', 'mysqli_connect' ), true ) ) {
				$has_own_db = true;
			}
		}
		if ( $has_own_db || $has_connect ) {
			return 'B';
		}
		if ( $extends_wpdb ) {
			return 'A';
		}
		return 'C';
	}

	/**
	 * @param string $phpCode Drop-in PHP source.
	 * @return string Declared class name or empty string.
	 */
	public static function className( $phpCode ) {
		$tokens = token_get_all( (string) $phpCode );
		$count  = count( $tokens );
		for ( $i = 0; $i < $count; ++$i ) {
			$token = $tokens[ $i ];
			if ( is_array( $token ) && T_CLASS === $token[0] ) {
				return self::nextString( $tokens, $i );
			}
		}
		return '';
	}

	private static function nextString( $tokens, $i ) {
		$count = count( $tokens );
		for ( $j = $i + 1; $j < $count; ++$j ) {
			$token = $tokens[ $j ];
			if ( ! is_array( $token ) ) {
				continue;
			}
			if ( T_STRING === $token[0] ) {
				return (string) $token[1];
			}
			if ( T_WHITESPACE === $token[0] || T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] || T_NS_SEPARATOR === $token[0] ) {
				continue;
			}
			return '';
		}
		return '';
	}

	private static function extendsName( $tokens, $class_at ) {
		$count = count( $tokens );
		$seen_extends = false;
		for ( $j = $class_at + 1; $j < $count; ++$j ) {
			$token = $tokens[ $j ];
			if ( ! is_array( $token ) ) {
				if ( '{' === $token ) {
					return '';
				}
				continue;
			}
			if ( T_EXTENDS === $token[0] ) {
				$seen_extends = true;
				continue;
			}
			if ( $seen_extends && T_STRING === $token[0] ) {
				return strtolower( (string) $token[1] );
			}
		}
		return '';
	}
}
