<?php
/**
 * The canonical list of the MySQL constructs without a PostgreSQL
 * equivalent (audit D-25, docs/05 R-062).
 *
 * The list is data, not code: it is the single source for the docs, the
 * public artifacts (README/readme.txt), the compatibility test plan and
 * the static scan report. Everything here fails closed: the statement is
 * rejected with an explicit error instead of reaching PostgreSQL with a
 * different meaning (K-52).
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Sql;

final class Unsupported {

	/**
	 * The rule id of "no PostgreSQL equivalent".
	 *
	 * @var string
	 */
	const RULE = 'R-062';

	/**
	 * The constructs without a PostgreSQL equivalent.
	 *
	 * Function names map to "NAME()"; the syntaxes keep their own
	 * label.
	 *
	 * @var array<string,string>
	 */
	const CONSTRUCTS = array(
		// Functions: no counterpart, or not even a call in the grammar.
		'SHA1'                     => 'SHA1()',
		'SHA2'                     => 'SHA2()',
		'SUBSTRING_INDEX'          => 'SUBSTRING_INDEX()',
		'DATABASE'                 => 'DATABASE()',
		// Syntaxes.
		'UPDATE LIMIT'             => 'UPDATE … LIMIT',
		'DELETE LIMIT'             => 'DELETE … LIMIT',
		'multi-table UPDATE'       => 'multi-table UPDATE',
		'multi-table DELETE'       => 'multi-table DELETE',
		'@@ system variables'      => '@@ system variables',
		'CREATE TABLE AS SELECT'   => 'CREATE TABLE … AS SELECT',
		'CREATE TABLE LIKE'        => 'CREATE TABLE … LIKE',
		'division'                 => 'complex or chained division',
		'DISTINCT division'        => 'DISTINCT with division in ORDER BY',
		'out of scope statement'   => 'statement outside the supported subset',
	);

	/**
	 * The function names among the constructs.
	 *
	 * @var array<int,string>
	 */
	const FUNCTIONS = array(
		'SHA1',
		'SHA2',
		'SUBSTRING_INDEX',
		'DATABASE',
	);

	/**
	 * The known constructs, id => label.
	 *
	 * @return array<string,string>
	 */
	public static function constructs(): array {
		return self::CONSTRUCTS;
	}

	/**
	 * The function names among the constructs.
	 *
	 * @return array<int,string>
	 */
	public static function functions(): array {
		return self::FUNCTIONS;
	}

	/**
	 * The human label of a construct.
	 *
	 * @param string $construct Construct id.
	 * @return string
	 */
	public static function label( string $construct ): string {
		if ( isset( self::CONSTRUCTS[ $construct ] ) ) {
			return self::CONSTRUCTS[ $construct ];
		}
		return $construct;
	}

	/**
	 * The rule id of a construct.
	 *
	 * @param string $construct Construct id.
	 * @return string
	 */
	public static function rule( string $construct ): string {
		return self::RULE;
	}
}
