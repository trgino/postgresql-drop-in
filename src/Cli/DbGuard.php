<?php
/**
 * `wp db *` guard decision logic (K-40, docs/09 §6).
 *
 * Pure and unit-tested; the WP-CLI wiring lives in tools/cli/guard-db.php.
 * wp-config.php is scanned with token_get_all (PHP code, never SQL):
 * an undecidable value (getenv, expression) never blocks.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Cli;

final class DbGuard {

	const MESSAGE = 'This site runs on PostgreSQL. Use wp slonik dump/restore/health instead of wp db *. To work on the rollback MySQL copy knowingly, set WPSLONIK_ALLOW_WP_DB=1 (environment variable) or define(\'WPSLONIK_ALLOW_WP_DB\', 1) in wp-config.php.';

	/**
	 * Walk up from $dir looking for wp-config.php.
	 *
	 * @param string $dir Start directory.
	 * @return string|null Config path or null.
	 */
	public static function findConfig( $dir ) {
		$dir = (string) $dir;
		for ( $i = 0; $i < 20; ++$i ) {
			$candidate = rtrim( $dir, '/\\' ) . '/wp-config.php';
			if ( is_file( $candidate ) ) {
				return $candidate;
			}
			$parent = dirname( $dir );
			if ( $parent === $dir ) {
				return null;
			}
			$dir = $parent;
		}
		return null;
	}

	/**
	 * Extract the WPSLONIK_ENABLED value (last define wins).
	 *
	 * @param string $wp_config Path to wp-config.php.
	 * @return bool|null True/false, or null when absent/undecidable.
	 */
	public static function readEnabled( $wp_config ) {
		$code = @file_get_contents( (string) $wp_config );
		if ( ! is_string( $code ) ) {
			return null;
		}
		$tokens = token_get_all( $code );
		$count  = count( $tokens );
		$found  = null;
		for ( $i = 0; $i < $count; ++$i ) {
			$token = $tokens[ $i ];
			if ( ! is_array( $token ) || T_STRING !== $token[0] || 'define' !== strtolower( (string) $token[1] ) ) {
				continue;
			}
			$args = self::defineArgs( $tokens, $i );
			if ( null === $args ) {
				continue;
			}
			list( $name, $value ) = $args;
			if ( 'WPSLONIK_ENABLED' !== $name ) {
				continue;
			}
			// Only a boolean is decisive: a string or a number is a
			// configuration mistake, not a mode switch.
			$found = is_bool( $value ) ? $value : null;
		}
		return $found;
	}

	/**
	 * @param array $tokens token_get_all output.
	 * @param int   $at     Index of the define T_STRING.
	 * @return array|null Array(name, bool|null) or null when not parseable.
	 */
	private static function defineArgs( $tokens, $at ) {
		$count = count( $tokens );
		$i     = (int) $at + 1;
		while ( $i < $count && self::isSkipped( $tokens[ $i ] ) ) {
			++$i;
		}
		if ( $i >= $count || '(' !== self::text( $tokens[ $i ] ) ) {
			return null;
		}
		$groups = array();
		$cur    = array();
		$depth  = 0;
		for ( $j = $i + 1; $j < $count; ++$j ) {
			$text = self::text( $tokens[ $j ] );
			if ( '(' === $text ) {
				++$depth;
				$cur[] = $tokens[ $j ];
				continue;
			}
			if ( ')' === $text ) {
				if ( 0 === $depth ) {
					break;
				}
				--$depth;
				$cur[] = $tokens[ $j ];
				continue;
			}
			if ( ',' === $text && 0 === $depth ) {
				$groups[] = $cur;
				$cur      = array();
				continue;
			}
			if ( ! self::isSkipped( $tokens[ $j ] ) ) {
				$cur[] = $tokens[ $j ];
			}
		}
		if ( array() === $groups ) {
			return null;
		}
		$name_tokens = $groups[0];
		if ( 1 !== count( $name_tokens ) || ! is_array( $name_tokens[0] ) || T_CONSTANT_ENCAPSED_STRING !== $name_tokens[0][0] ) {
			return null;
		}
		$name = stripcslashes( substr( (string) $name_tokens[0][1], 1, -1 ) );
		if ( 1 !== count( $cur ) || ! is_array( $cur[0] ) ) {
			return array( $name, null );
		}
		$value = $cur[0];
		if ( T_STRING === $value[0] ) {
			$lower = strtolower( (string) $value[1] );
			if ( 'true' === $lower ) {
				return array( $name, true );
			}
			if ( 'false' === $lower || 'null' === $lower ) {
				return array( $name, false );
			}
			return array( $name, null );
		}
		if ( T_CONSTANT_ENCAPSED_STRING === $value[0] ) {
			return array( $name, stripcslashes( substr( (string) $value[1], 1, -1 ) ) );
		}
		if ( T_LNUMBER === $value[0] || T_DNUMBER === $value[0] ) {
			return array( $name, (string) $value[1] );
		}
		return array( $name, null );
	}

	private static function text( $token ) {
		return is_array( $token ) ? (string) $token[1] : (string) $token;
	}

	private static function isSkipped( $token ) {
		return is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
	}

	/**
	 * @param bool|null $enabled PG mode (null = undecidable).
	 * @param string    $allow_env WPSLONIK_ALLOW_WP_DB value ('' when unset).
	 * @return bool
	 */
	/**
	 * Read a constant value from wp-config.php (never loads WordPress).
	 *
	 * @param string $wp_config Config path.
	 * @param string $name      Constant name.
	 * @return string|null The value, or null when it is not set.
	 */
	public static function readConstant( $wp_config, $name ) {
		$code = @file_get_contents( (string) $wp_config );
		if ( ! is_string( $code ) ) {
			return null;
		}
		$tokens = token_get_all( $code );
		$count  = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( ! is_array( $token ) || T_STRING !== $token[0] || 'define' !== strtolower( (string) $token[1] ) ) {
				continue;
			}
			$args = self::defineArgs( $tokens, $i );
			if ( null === $args ) {
				continue;
			}
			if ( (string) $args[0] === (string) $name ) {
				return (string) $args[1];
			}
		}
		return null;
	}

	public static function shouldBlock( $enabled, $allow_env ) {
		if ( true !== $enabled ) {
			return false;
		}
		return '1' !== (string) $allow_env;
	}

	/**
	 * The bypass value: the environment variable or the constant.
	 *
	 * @param string $allow_env Environment variable value.
	 * @param string $allow_const Constant value ('' when unset).
	 * @return string
	 */
	public static function allowValue( $allow_env, $allow_const = '' ) {
		if ( '1' === (string) $allow_env ) {
			return '1';
		}
		return '1' === (string) $allow_const ? '1' : '';
	}
}
