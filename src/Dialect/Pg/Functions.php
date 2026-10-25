<?php
/**
 * MySQL function registry (audit D-01).
 *
 * A function name is never quoted as an identifier: it is mapped to its
 * PostgreSQL counterpart. A function that has no mapping is rejected
 * before execution (fail closed) instead of reaching PostgreSQL as a
 * quoted identifier ("ABS"(x) -> function does not exist).
 *
 * The table is data, not code: adding a function is a one-line change
 * plus a test. Semantics notes:
 * - CONCAT: MySQL returns NULL if any argument is NULL, PostgreSQL
 *   ignores NULL arguments (documented deviation, docs/05 S-xx).
 * - CURRENT_DATE/CURRENT_TIME: PostgreSQL spells them without
 *   parentheses -> the call parens are dropped.
 * - DATABASE(): MySQL returns the current database name, PostgreSQL
 *   current_database() -> the same value (the matrix found 21 uses in
 *   three plugins).
 * - VERSION(): the value differs (PostgreSQL's version, not MySQL's) -
 *   a documented deviation, see docs/05.
 * - FOUND_ROWS: answered by the runtime before translation
 *   (WpslonikDb::is_found_rows_probe); it never reaches PostgreSQL.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Dialect\Pg;

final class Functions {

	/**
	 * MySQL name (upper case) => PostgreSQL name (upper case).
	 *
	 * @var array<string,string>
	 */
	const MAP = array(
		'ABS'           => 'ABS',
		'ANY_VALUE'     => 'ANY_VALUE',
		'ASCII'         => 'ASCII',
		'CURRENT_USER'  => 'CURRENT_USER',
		'DATABASE'      => 'CURRENT_DATABASE',
		'VERSION'       => 'VERSION',
		'CEIL'          => 'CEIL',
		'CEILING'       => 'CEILING',
		'CHAR_LENGTH'   => 'CHAR_LENGTH',
		'COALESCE'      => 'COALESCE',
		'CONCAT'        => 'CONCAT',
		'CONCAT_WS'     => 'CONCAT_WS',
		'CONNECTION_ID' => 'PG_BACKEND_PID',
		'CURRENT_DATE' => 'CURRENT_DATE',
		'CURRENT_TIME' => 'CURRENT_TIME',
		'CURRENT_TIMESTAMP' => 'CURRENT_TIMESTAMP',
		'LOCALTIME' => 'LOCALTIME',
		'LOCALTIMESTAMP' => 'LOCALTIMESTAMP',
		'CURDATE'       => 'CURRENT_DATE',
		'CURTIME'       => 'CURRENT_TIME',
		'DATE'          => 'DATE',
		'EXP'           => 'EXP',
		'FOUND_ROWS'    => 'FOUND_ROWS',
		'FROM_UNIXTIME' => 'TO_TIMESTAMP',
		'GREATEST'      => 'GREATEST',
		'HEX'           => 'HEX',
		'IFNULL'        => 'COALESCE',
		'LAST_INSERT_ID' => 'LASTVAL',
		'LEAST'         => 'LEAST',
		'LENGTH'        => 'LENGTH',
		'LN'            => 'LN',
		'LOG'           => 'LOG',
		'LOG10'         => 'LOG10',
		'LOWER'         => 'LOWER',
		'LPAD'          => 'LPAD',
		'LTRIM'         => 'LTRIM',
		'MD5'           => 'MD5',
		'MOD'           => 'MOD',
		'NOW'           => 'NOW',
		'NULLIF'        => 'NULLIF',
		'POW'           => 'POWER',
		'POWER'         => 'POWER',
		'RAND'          => 'RANDOM',
		'REPEAT'        => 'REPEAT',
		'REPLACE'       => 'REPLACE',
		'REVERSE'       => 'REVERSE',
		'RIGHT'         => 'RIGHT',
		'ROUND'         => 'ROUND',
		'RPAD'          => 'RPAD',
		'RTRIM'         => 'RTRIM',
		'SIGN'          => 'SIGN',
		'SQRT'          => 'SQRT',
		'SUBSTR'        => 'SUBSTR',
		'SUBSTRING'     => 'SUBSTRING',
		'TIME'          => 'TIME',
		'TIMESTAMP'     => 'TIMESTAMP',
		'TRIM'          => 'TRIM',
		'UPPER'         => 'UPPER',
		'UUID'          => 'UUID',
	);

	/**
	 * PostgreSQL spellings that take no parentheses.
	 *
	 * @var array<string,true>
	 */
	const NO_PARENS = array(
		'CURRENT_DATE' => true,
		'CURRENT_TIME' => true,
	);

	/**
	 * Functions that need a rewritten call, not a renamed one.
	 *
	 * The value is the rewrite mode; the emitter turns the call into a
	 * PostgreSQL expression (docs/05 R-059).
	 *
	 * @var array<string,string>
	 */
	const REWRITES = array(
		'YEAR'         => 'extract:year',
		'MONTH'        => 'extract:month',
		'DAY'          => 'extract:day',
		'HOUR'         => 'extract:hour',
		'MINUTE'       => 'extract:minute',
		'SECOND'       => 'extract:second',
		'UNIX_TIMESTAMP' => 'epoch',
		'DAYOFWEEK'    => 'dow',
		'IF'           => 'if',
		'LOCATE'       => 'position',
		'INSTR'        => 'instr',
		'FIELD'        => 'field',
		'DATE_FORMAT'  => 'date_format',
		'DATEDIFF'     => 'datediff',
		'DATE_ADD'     => 'interval_add',
		'DATE_SUB'     => 'interval_sub',
		'ANY_VALUE'    => 'any_value',
	);

	/**
	 * Functions rewritten elsewhere (not by the registry).
	 *
	 * The lock family is rewritten by Semantics::lock_spans() into
	 * PostgreSQL advisory locks (the timeout semantics differ).
	 *
	 * @var array<string,true>
	 */
	const HANDLED_ELSEWHERE = array(
		'GET_LOCK'      => true,
		'RELEASE_LOCK'  => true,
		'IS_FREE_LOCK'  => true,
		'IS_USED_LOCK'  => true,
	);

	/**
	 * PostgreSQL name for a MySQL function, or null when unmapped.
	 *
	 * @param string $name MySQL function name.
	 * @return string|null
	 */
	public static function pgName( $name ) {
		$key = strtoupper( (string) $name );
		return isset( self::MAP[ $key ] ) ? self::MAP[ $key ] : null;
	}

	/**
	 * Rewrite mode for a function, or null when it is a plain rename.
	 *
	 * @param string $name MySQL function name.
	 * @return string|null
	 */
	public static function rewriteMode( $name ) {
		$key = strtoupper( (string) $name );
		return isset( self::REWRITES[ $key ] ) ? self::REWRITES[ $key ] : null;
	}

	/**
	 * Does the mapped PostgreSQL form take parentheses?
	 *
	 * @param string $pg_name PostgreSQL function name.
	 * @return bool
	 */
	public static function takesParens( $pg_name ) {
		return ! isset( self::NO_PARENS[ strtoupper( (string) $pg_name ) ] );
	}

	/**
	 * Known MySQL function names (registry keys).
	 *
	 * @return string[]
	 */
	public static function names(): array {
		return array_keys( self::MAP );
	}
}
