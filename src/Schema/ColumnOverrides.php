<?php
/**
 * Column-level type overrides (audit D-74).
 *
 * A column whose MySQL type is a text type but whose payload carries
 * bytes PostgreSQL's text cannot store needs a bytea target. The
 * ActionScheduler `schedule` column is the known case: it holds a
 * serialized PHP object, and serialize() writes NUL bytes into the
 * private/protected property names ("\0*\0prop").
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Schema;

final class ColumnOverrides {

	/**
	 * Table name suffix => column name (lower case) => PostgreSQL type.
	 *
	 * The table is matched on its suffix, so the rule works with every
	 * WordPress table prefix.
	 *
	 * @var array<string,array<string,string>>
	 */
	private static $overrides = array(
		// ActionScheduler (WooCommerce) stores the serialized schedule
		// object here. The payload carries NUL bytes (PHP's private and
		// protected property markers), so the column has to be bytea:
		// PostgreSQL's text type refuses a NUL byte and the payload
		// cannot be repaired afterwards (unserialize() fails).
		'actionscheduler_actions' => array(
			'schedule' => 'bytea',
		),
	);

	/**
	 * The PostgreSQL type a column has to use, or null for the default
	 * mapping.
	 *
	 * @param string $table  Table name (any prefix).
	 * @param string $column Column name.
	 * @return string|null PostgreSQL type or null.
	 */
	public static function pgType( $table, $column ) {
		$table = strtolower( (string) $table );
		foreach ( self::$overrides as $suffix => $columns ) {
			if ( ! self::matches_table( $table, $suffix ) ) {
				continue;
			}
			$column = strtolower( (string) $column );
			if ( isset( $columns[ $column ] ) ) {
				return $columns[ $column ];
			}
		}
		return null;
	}

	/**
	 * Does the column target bytea?
	 *
	 * @param string $table  Table name (any prefix).
	 * @param string $column Column name.
	 * @return bool
	 */
	public static function isBytea( $table, $column ) {
		return 'bytea' === strtolower( (string) self::pgType( $table, $column ) );
	}

	/**
	 * Does the table name end with the rule's table name?
	 *
	 * The suffix has to start on a word boundary, so a table called
	 * "myactionscheduler_actions" does not match the rule for
	 * "actionscheduler_actions".
	 *
	 * @param string $table  Lower-case table name.
	 * @param string $suffix Rule table name.
	 * @return bool
	 */
	private static function matches_table( $table, $suffix ) {
		if ( $table === $suffix ) {
			return true;
		}
		$len = strlen( $suffix );
		if ( strlen( $table ) <= $len ) {
			return false;
		}
		return '_' === $table[ strlen( $table ) - $len - 1 ] && substr( $table, -$len ) === $suffix;
	}
}
