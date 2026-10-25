<?php
/**
 * MySQL DATE_FORMAT() → PostgreSQL expression (audit follow-up, 2026-10-21).
 *
 * The reference is MySQL 8.4 itself: every specifier was captured from a
 * live MySQL server (tools/pg-accept/results.sql) and is asserted
 * against PostgreSQL by the acceptance gate.
 *
 * Some MySQL specifiers have no direct PostgreSQL counterpart, so the
 * converter returns a PostgreSQL *expression* (literal and computed
 * parts are concatenated in the order of the format string).
 *
 * Notes on the reference behaviour:
 * - %s is the seconds (00..59), not the epoch; the epoch comes from
 *   UNIX_TIMESTAMP().
 * - %C is not implemented by MySQL 8.4 (it prints a literal "C"), so it
 *   stays literal here as well.
 * - %v follows %u (the Monday based week number) in MySQL 8.4.
 * - %W is the weekday name.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Dialect\Pg;

final class DateFormat {

	/**
	 * Specifiers that map to a PostgreSQL to_char() pattern (the others
	 * need an expression).
	 *
	 * @var array<string,string>
	 */
	const PATTERNS = array(
		'a' => 'Dy',
		'b' => 'Mon',
		'd' => 'DD',
		'e' => 'FMDD',
		'f' => 'US',
		'H' => 'HH24',
		'h' => 'HH12',
		'I' => 'HH12',
		'i' => 'MI',
		'j' => 'DDD',
		'k' => 'FMHH24',
		'l' => 'FMHH12',
		'M' => 'FMMonth',
		'm' => 'MM',
		'p' => 'AM',
		'r' => 'hh12:MI:SS AM',
		'S' => 'SS',
		's' => 'SS',
		'T' => 'HH24:MI:SS',
		'W' => 'FMDay',
		'Y' => 'YYYY',
		'y' => 'YY',
	);

	/**
	 * Convert a MySQL format string into a PostgreSQL expression.
	 *
	 * @param string $format    MySQL format (e.g. '%Y-%m-%d %H:%i:%s').
	 * @param string $date_expr PostgreSQL expression for the date value.
	 * @return string PostgreSQL expression.
	 */
	public static function to_pg( $format, $date_expr = 'd' ) {
		$format  = (string) $format;
		$expr    = self::typed( $date_expr );
		$parts   = array();
		$literal = '';
		$pattern = '';
		$length  = strlen( $format );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $format[ $i ];
			if ( '%' !== $char || $i + 1 >= $length ) {
				if ( '' !== $pattern ) {
					// Inside a pattern run the literal belongs to the
					// pattern (one to_char() call).
					$pattern .= $char;
					continue;
				}
				$literal .= $char;
				continue;
			}
			$spec = $format[ ++$i ];
			if ( isset( self::PATTERNS[ $spec ] ) ) {
				if ( '' !== $literal ) {
					// A literal before the first pattern is its own part.
					self::pushLiteral( $parts, $literal );
				}
				$pattern .= self::PATTERNS[ $spec ];
				continue;
			}
			$pg = self::specifier( $spec, $expr );
			if ( null === $pg ) {
				// Unknown specifier: MySQL prints it literally.
				if ( '' !== $pattern ) {
					$pattern .= $spec;
					continue;
				}
				// MySQL prints an unknown specifier without the percent sign.
				$literal .= $spec;
				continue;
			}
			self::pushPattern( $parts, $pattern, $expr );
			self::pushLiteral( $parts, $literal );
			$parts[] = $pg;
		}
		self::pushPattern( $parts, $pattern, $expr );
		self::pushLiteral( $parts, $literal );

		if ( array() === $parts ) {
			return "''";
		}
		return implode( ' || ', $parts );
	}

	/**
	 * A date expression with an explicit type: PostgreSQL has no
	 * unambiguous overload for an untyped literal.
	 *
	 * @param string $expr Date expression.
	 * @return string
	 */
	private static function typed( $expr ) {
		return '(' . trim( (string) $expr ) . ')::timestamp';
	}

	/**
	 * Append the pending to_char() pattern.
	 *
	 * @param array  $parts   Parts (by reference).
	 * @param string $pattern Pending pattern (by reference).
	 * @param string $expr    Date expression.
	 * @return void
	 */
	private static function pushPattern( array &$parts, string &$pattern, string $expr ) {
		if ( '' === $pattern ) {
			return;
		}
		$parts[] = "to_char(" . $expr . ", '" . $pattern . "')";
		$pattern  = '';
	}

	/**
	 * Append the pending literal text.
	 *
	 * @param array  $parts   Parts (by reference).
	 * @param string $literal Pending literal (by reference).
	 * @return void
	 */
	private static function pushLiteral( array &$parts, string &$literal ) {
		if ( '' === $literal ) {
			return;
		}
		$parts[] = self::literal( $literal );
		$literal = '';
	}

	/**
	 * PostgreSQL fragment for one MySQL specifier.
	 *
	 * @param string $spec MySQL specifier (without '%').
	 * @param string $expr PostgreSQL date expression (typed).
	 * @return string|null Fragment or null when the specifier is unknown.
	 */
	public static function specifier( $spec, $expr = 'd' ) {
		$e = trim( (string) $expr );
		if ( '' === $e ) {
			$e = '(d)::timestamp';
		}
		switch ( $spec ) {
			case 'c':
				// Month, numeric (1..12).
				return 'EXTRACT(MONTH FROM ' . $e . ')::int::text';
			case 'D':
				// Day of month with the English suffix (1st, 2nd, 3rd, ...).
				return "to_char(" . $e . ", 'FMDD') || CASE " .
					"WHEN EXTRACT(DAY FROM " . $e . ") % 10 = 1 AND EXTRACT(DAY FROM " . $e . ") <> 11 THEN 'st' " .
					"WHEN EXTRACT(DAY FROM " . $e . ") % 10 = 2 AND EXTRACT(DAY FROM " . $e . ") <> 12 THEN 'nd' " .
					"WHEN EXTRACT(DAY FROM " . $e . ") % 10 = 3 AND EXTRACT(DAY FROM " . $e . ") <> 13 THEN 'rd' " .
					"ELSE 'th' END";
			case 'U':
				// Week 00..53, Sunday first.
				return 'trunc((EXTRACT(DOY FROM ' . $e . ')::int + EXTRACT(DOW FROM date_trunc(\'year\', ' . $e . '))::int - 1) / 7)::int::text';
			case 'u':
				// Week 01..53, Monday first.
				return '(trunc((EXTRACT(DOY FROM ' . $e . ')::int + ((EXTRACT(DOW FROM date_trunc(\'year\', ' . $e . '))::int + 6) % 7) - 1) / 7)::int + 1)::text';
			case 'V':
				// Week used with %X (the same week as %U in MySQL 8.4).
				return 'trunc((EXTRACT(DOY FROM ' . $e . ')::int + EXTRACT(DOW FROM date_trunc(\'year\', ' . $e . '))::int - 1) / 7)::int::text';
			case 'v':
				// MySQL 8.4 follows %u here.
				return '(trunc((EXTRACT(DOY FROM ' . $e . ')::int + ((EXTRACT(DOW FROM date_trunc(\'year\', ' . $e . '))::int + 6) % 7) - 1) / 7)::int + 1)::text';
			case 'w':
				// Day 0..6, Sunday first.
				return 'EXTRACT(DOW FROM ' . $e . ')::int::text';
			case 't':
				// A tab character.
				return 'chr(9)';
			case 'X':
				// Year of the week (Sunday first).
				return "EXTRACT(YEAR FROM (" . $e . " - (EXTRACT(DOW FROM " . $e . ")::int * INTERVAL '1 day')))::int::text";
			case 'x':
				// Year of the week (Monday first).
				return "EXTRACT(YEAR FROM (" . $e . " - (((EXTRACT(DOW FROM " . $e . ")::int + 6) % 7) * INTERVAL '1 day')))::int::text";
			case '%':
				return "'%'";
		}
		if ( isset( self::PATTERNS[ $spec ] ) ) {
			return "to_char(" . $e . ", '" . self::PATTERNS[ $spec ] . "')";
		}
		return null;
	}

	/**
	 * Literal text as a PostgreSQL expression.
	 *
	 * @param string $text Literal text.
	 * @return string
	 */
	private static function literal( $text ) {
		return "'" . str_replace( "'", "''", (string) $text ) . "'";
	}
}
