<?php
/**
 * Map PostgreSQL errors to MySQL-like messages (docs/07 §7).
 *
 * Templates never interpolate literal values (M5 brings redacted logging;
 * last_error stays shape-compatible for plugin string checks).
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Runtime;

final class ErrorMapper {

	/**
	 * The identifier (table, column, key) of a PostgreSQL error message.
	 *
	 * Identifiers are not secrets: redacting them makes the message
	 * useless for the operator (P-19 covers literal values).
	 *
	 * @param string $message PostgreSQL message.
	 * @param string $kind    'column'|'relation'|'key'.
	 * @return string
	 */
	private static function identifier( $message, $kind ) {
		$text = (string) $message;
		$patterns = array(
			'column' => array( '/column "([^"]+)"/', '/column ([A-Za-z0-9_.]+)/' ),
			'relation' => array( '/relation "([^"]+)"/', '/table "([^"]+)"/' ),
			'key' => array( '/key "([^"]+)"/', '/constraint "([^"]+)"/' ),
		);
		$fallback = array( 'column' => "'?'", 'relation' => "'?'", 'key' => "'?'" );
		$candidates = isset( $patterns[ $kind ] ) ? $patterns[ $kind ] : array();
		foreach ( $candidates as $pattern ) {
			if ( preg_match( $pattern, $text, $m ) ) {
				return "'" . $m[1] . "'";
			}
		}
		return isset( $fallback[ $kind ] ) ? $fallback[ $kind ] : '?';
	}

	/**
	 * @param string $state   SQLSTATE (PDOException code) or ''.
	 * @param string $message Original PG message (kept for signature compatibility; never rendered).
	 * @return string MySQL-like message.
	 */
	public static function map( $state, $message ) {
		switch ( (string) $state ) {
			case '23505':
				return "Duplicate entry '?' for key " . self::identifier( $message, 'key' );
			case '23502':
				return 'Column ' . self::identifier( $message, 'column' ) . ' cannot be null';
			case '22001':
				return 'Data too long for column ' . self::identifier( $message, 'column' ) . ' at row 1';
			case '42P01':
				return 'Table ' . self::identifier( $message, 'relation' ) . " doesn't exist";
			case '42703':
				return 'Unknown column ' . self::identifier( $message, 'column' ) . ' in ' . self::identifier( $message, 'relation' );
			case '42601':
				return 'You have an error in your SQL syntax; double-check the translated query';
			case '40P01':
				return 'Deadlock found when trying to get lock; try restarting transaction';
			case '57014':
				return 'Query execution was interrupted (timeout)';
			default:
				return 'DB error [' . (string) $state . ']';
		}
	}
}
