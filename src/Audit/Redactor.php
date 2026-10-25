<?php
/**
 * Token-based SQL redactor for the compat log (P-19).
 *
 * Literal tokens (strings, numbers) become `?`; everything else stays
 * byte-identical so entries group by query shape. Unparseable input
 * yields a single `?` — raw text is never logged.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Audit;

use Slonik\Dialect\Pg\MinEmitter;
use Slonik\Sql\MysqlParser;
use WP_MySQL_Token;

final class Redactor {

	const LITERALS = array(
		'SINGLE_QUOTED_TEXT',
		'DOUBLE_QUOTED_TEXT',
		'NCHAR_TEXT',
		'INT_NUMBER',
		'DECIMAL_NUMBER',
		'FLOAT_NUMBER',
		'HEX_NUMBER',
		'BIN_NUMBER',
		'LONG_NUMBER',
		'ULONGLONG_NUMBER',
	);

	/**
	 * @param string $mysql MySQL SQL.
	 * @return string Redacted shape.
	 */
	public static function redact( $mysql ) {
		$mysql = (string) $mysql;
		$ast   = MysqlParser::parse( $mysql );
		if ( null === $ast ) {
			return '?';
		}
		$spans = array();
		foreach ( $ast->get_descendant_tokens() as $token ) {
			if ( ! $token instanceof WP_MySQL_Token ) {
				continue;
			}
			if ( in_array( $token->get_name(), self::LITERALS, true ) ) {
				$spans[] = array( $token->start, $token->length, '?' );
			}
		}
		return MinEmitter::apply_spans( $mysql, $spans );
	}

	/**
	 * Redact via tokens only (no parse). Templates with dynamic markers
	 * (‹DYN›) keep their shape; raw literals never leave.
	 *
	 * @param string $mysql MySQL-ish SQL.
	 * @return string Redacted shape (or `?` on total failure).
	 */
	public static function redact_template( $mysql ) {
		$mysql = (string) $mysql;
		try {
			MysqlParser::load();
			$lexer = new \WP_MySQL_Lexer( $mysql );
			$spans = array();
			foreach ( $lexer->remaining_tokens() as $token ) {
				if ( ! $token instanceof WP_MySQL_Token ) {
					continue;
				}
				if ( in_array( $token->get_name(), self::LITERALS, true ) ) {
					$spans[] = array( $token->start, $token->length, '?' );
				}
			}
			return MinEmitter::apply_spans( $mysql, $spans );
		} catch ( \Throwable $e ) {
			return '?';
		}
	}
}
