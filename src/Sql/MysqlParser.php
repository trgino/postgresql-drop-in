<?php
/**
 * Thin loader for the vendored MySQL parser runtime.
 *
 * Upstream classes are global (`WP_MySQL_*`, `WP_Parser*`) and loaded from
 * `third-party/mysql-parser/` byte-identical (see PROVENANCE.md there).
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Sql;

final class MysqlParser {

	/**
	 * Load the vendored runtime. Safe to call repeatedly.
	 */
	public static function load(): void {
		if ( class_exists( 'WP_MySQL_Lexer' ) && class_exists( 'WP_MySQL_Parser_Factory' ) ) {
			return;
		}
		$base = self::base_dir();
		foreach ( array(
			$base . '/parser/class-wp-parser-token.php',
			$base . '/parser/class-wp-parser-node.php',
			$base . '/parser/class-wp-parser-grammar.php',
			$base . '/parser/class-wp-parser.php',
			$base . '/class-wp-mysql-token.php',
			$base . '/class-wp-mysql-lexer.php',
			$base . '/class-wp-mysql-parser-factory.php',
		) as $file ) {
			require_once $file;
		}
	}

	/**
	 * Parse one MySQL statement into an AST node (null when unparseable).
	 *
	 * @param string $sql MySQL SQL.
	 * @return \WP_Parser_Node|null
	 */
	public static function parse( $sql ) {
		self::load();
		$lexer = new \WP_MySQL_Lexer( $sql );
		return self::create_parser()->parse( $lexer->remaining_tokens() );
	}

	/**
	 * Create a parser backed by the shared vendored grammar.
	 *
	 * @return \WP_Parser
	 */
	public static function create_parser() {
		self::load();
		return \WP_MySQL_Parser_Factory::create_parser();
	}

	/**
	 * Absolute path of the vendored `src/` directory.
	 *
	 * @return string
	 */
	public static function base_dir() {
		return dirname( __DIR__, 2 ) . '/third-party/mysql-parser/src';
	}
}
