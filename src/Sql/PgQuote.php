<?php
/**
 * PostgreSQL literal quoting (decoded value in, PG literal out).
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Sql;

final class PgQuote {

	/**
	 * @param string $decoded Decoded string value (lexer already unescaped MySQL escapes).
	 * @return string Single-quoted PG literal.
	 *
	 * S-09: backslash is literal in PG standard strings, so only the
	 * quote itself is escaped. Callers must ensure
	 * standard_conforming_strings is on (WpslonikDb sets it per session).
	 */
	public static function string( $decoded ) {
		return "'" . str_replace( "'", "''", (string) $decoded ) . "'";
	}

	/**
	 * A bytea literal in PostgreSQL's hex input format.
	 *
	 * The hex format keeps every byte of the payload: the raw bytes
	 * would end the literal ("unterminated quoted string") and a NUL
	 * byte cannot be stored in a text value at all (audit D-73).
	 *
	 * @param string $decoded Decoded byte string.
	 * @return string Single-quoted PG bytea literal.
	 */
	public static function bytea( $decoded ) {
		return "'" . '\x' . bin2hex( (string) $decoded ) . "'";
	}

	/**
	 * A MySQL hex literal (X'…' or 0x…) as a PostgreSQL bytea literal.
	 *
	 * MySQL's spelling is a binary string; in PostgreSQL the same
	 * characters are a bit literal, which cannot be assigned to a bytea
	 * column ("column val is of type bytea but expression is of type
	 * bit", audit D-75).
	 *
	 * @param string $literal Literal text (X'…' or 0x…).
	 * @return string Single-quoted PG bytea literal ('' when the text is
	 *                not a hex literal).
	 */
	public static function bytea_hex( $literal ) {
		$literal = trim( (string) $literal );
		$hex     = '';
		if ( preg_match( "/^X'([0-9A-Fa-f]*)'$/", $literal, $m ) ) {
			$hex = $m[1];
		} elseif ( preg_match( '/^0x([0-9A-Fa-f]*)$/', $literal, $m ) ) {
			$hex = $m[1];
		}
		if ( '' === $hex ) {
			return '';
		}
		if ( 1 === strlen( $hex ) % 2 ) {
			$hex = '0' . $hex;
		}
		$bytes = hex2bin( $hex );
		if ( false === $bytes ) {
			return '';
		}
		return self::bytea( $bytes );
	}

	/**
	 * @param array $data JSON object payload (string keys/values only).
	 * @return string Single-quoted PG string literal with JSON content.
	 */
	public static function comment_json( $data ) {
		$json = json_encode( (array) $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			$json = '{}';
		}
		return "'" . str_replace( "'", "''", $json ) . "'";
	}
}
