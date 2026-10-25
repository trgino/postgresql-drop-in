<?php
/**
 * Minimal SHOW/DESCRIBE emulation (M2a thin slice).
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Schema;

use Slonik\Sql\MysqlParser;
use Slonik\Sql\TranslationException;
use WP_MySQL_Lexer;

final class MinShow {

	/**
	 * The emulated MySQL server variables (D-55).
	 *
	 * PostgreSQL has no packet limit: the protocol message cap is 1 GB
	 * and a large value is stored out of line. The emulated value is the
	 * MySQL 8.0 default, which keeps the consumer's chunk size
	 * (wordfence: (max_allowed_packet - 50) / 1.2) far inside that cap.
	 *
	 * @var array<string,string>
	 */
	const VARIABLES = array(
		'max_allowed_packet' => '67108864',
		'max_long_data_size' => '67108864',
	);

	/**
	 * @param string        $mysql   SHOW/DESCRIBE statement.
	 * @param Catalog|null  $catalog Catalog for original MySQL types and key names.
	 * @return string[] Single PG SELECT statement.
	 * @throws TranslationException When out of scope.
	 */
	public static function translate( $mysql, $catalog = null ) {
		$ast = MysqlParser::parse( $mysql );
		if ( null === $ast ) {
			throw new TranslationException( 'Unparseable statement.' );
		}
		$simple = $ast->get_first_descendant_node( 'simple_statement' );
		$stmt   = null !== $simple ? $simple->get_first_child_node() : null;
		$rule   = null !== $stmt ? $stmt->rule_name : '';
		if ( 'show_tables_stmt' === $rule ) {
			$like = '';
			$wild = $stmt->get_first_descendant_node( 'opt_wild_or_where' );
			if ( null !== $wild ) {
				$first = $wild->get_first_descendant_token();
				if ( null === $first || 'LIKE' !== strtoupper( $first->get_value() ) ) {
					throw new TranslationException( 'Only SHOW TABLES [LIKE ...] is supported.' );
				}
				foreach ( $wild->get_descendant_tokens() as $token ) {
					if ( ! $token instanceof \WP_MySQL_Token || ! self::is_string_token( $token ) ) {
						continue;
					}
					$like = ' AND tablename LIKE \'' . str_replace( "'", "''", $token->get_value() ) . '\'';
					break;
				}
			}
			return array( 'SELECT tablename AS "Tables_in_db" FROM pg_tables WHERE schemaname = current_schema()' . $like );
		}
		if ( 'describe_stmt' === $rule || 'show_columns_stmt' === $rule ) {
			$table = self::table_name( $stmt );
			return array( self::describeSql( $table, $catalog ) );
		}
		if ( 'show_keys_stmt' === $rule ) {
			$table = self::table_name( $stmt );
			return array( self::showKeysSql( $table, $catalog ) );
		}
		if ( 'show_variables_stmt' === $rule ) {
			return array( self::variablesSql( $stmt ) );
		}
		throw new TranslationException( 'Out of scope for minimal SHOW emulation.' );
	}

	/**
	 * Schema a probe statement must be scoped to. Shared by MinInfo (D-55).
	 *
	 * @param Catalog|null $catalog Catalog (knows its schema).
	 * @return string
	 */
	public static function schema_filter( $catalog ) {
		if ( $catalog instanceof Catalog && '' !== $catalog->schema() ) {
			return "'" . str_replace( "'", "''", $catalog->schema() ) . "'";
		}
		return 'current_schema()';
	}

	/**
	 * DESCRIBE statement for a table.
	 *
	 * @param string       $table   Table name.
	 * @param Catalog|null $catalog Catalog for original MySQL types.
	 * @return string
	 */
	public static function describeSql( $table, $catalog = null ) {
		$type  = self::type_expression( $table, $catalog );
		$table = str_replace( "'", "''", (string) $table );
		return 'SELECT column_name AS "Field", ' . $type . ' AS "Type", is_nullable AS "Null", '
			. self::default_expression() . ' AS "Default", '
			. self::extra_expression( $table, $catalog ) . ' AS "Extra", '
			. self::key_expression( $table, $catalog ) . ' AS "Key" '
			. 'FROM information_schema.columns WHERE table_name = \'' . $table . '\' AND table_schema = ' . self::schema_filter( $catalog ) . ' ORDER BY ordinal_position';
	}

	/**
	 * SHOW KEYS statement for a table.
	 *
	 * @param string       $table   Table name.
	 * @param Catalog|null $catalog Catalog (knows its schema).
	 * @return string
	 */
	public static function showKeysSql( $table, $catalog = null ) {
		$t = str_replace( "'", "''", (string) $table );
		$n = strlen( (string) $table ) + 3;
		return 'SELECT c.relname AS "Table", CASE WHEN i.indisunique THEN 0 ELSE 1 END AS "Non_unique", CASE WHEN i.indisprimary THEN \'PRIMARY\' WHEN starts_with(c2.relname, \'' . $t . '__\') THEN substring(c2.relname from ' . $n . ') ELSE c2.relname END AS "Key_name", s.n AS "Seq_in_index", a.attname AS "Column_name", \'A\' AS "Collation", \'BTREE\' AS "Index_type" FROM pg_index i JOIN pg_class c ON c.oid = i.indrelid JOIN pg_namespace n ON n.oid = c.relnamespace JOIN pg_class c2 ON c2.oid = i.indexrelid JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum = ANY(i.indkey), generate_subscripts(i.indkey, 1) AS s(n) WHERE c.relname = \'' . $t . '\' AND n.nspname = ' . self::schema_filter( $catalog ) . ' AND a.attnum = i.indkey[s.n] ORDER BY c2.relname, s.n';
	}

	/**
	 * SHOW VARIABLES LIKE '<name>' statement.
	 *
	 * The row carries the MySQL column names: the consumers read
	 * $row['Value'] (wordfence/lib/wfDB.php:161,165). An unknown
	 * variable fails loud instead of returning an empty result: the
	 * caller would read a missing key and silently size its writes
	 * with a zero.
	 *
	 * @param object $stmt Statement node.
	 * @return string Single PG SELECT statement.
	 * @throws TranslationException When the variable has no emulation.
	 */
	private static function variablesSql( $stmt ) {
		$wild = $stmt->get_first_descendant_node( 'opt_wild_or_where' );
		$like = null !== $wild ? $wild->get_first_descendant_token() : null;
		if ( null === $like || 'LIKE' !== strtoupper( (string) $like->get_value() ) ) {
			throw new TranslationException(
				'SHOW VARIABLES without LIKE is outside the minimal emulation (D-55): only SHOW VARIABLES LIKE <name> is supported.'
			);
		}
		$name = '';
		foreach ( $wild->get_descendant_tokens() as $token ) {
			if ( ! $token instanceof \WP_MySQL_Token || ! self::is_string_token( $token ) ) {
				continue;
			}
			$name = (string) $token->get_value();
			break;
		}
		if ( '' === $name ) {
			throw new TranslationException(
				'SHOW VARIABLES LIKE needs a literal variable name (D-55); a parameter marker has no emulation.'
			);
		}
		$key = strtolower( $name );
		if ( ! isset( self::VARIABLES[ $key ] ) ) {
			throw new TranslationException(
				'SHOW VARIABLES LIKE \'' . $name . '\' has no PostgreSQL equivalent (D-55); the emulated variables are: '
					. implode( ', ', array_keys( self::VARIABLES ) ) . '.'
			);
		}
		return 'SELECT \'' . $key . '\' AS "Variable_name", \'' . self::VARIABLES[ $key ] . '\' AS "Value"';
	}

	private static function is_string_token( $token ) {
		return WP_MySQL_Lexer::SINGLE_QUOTED_TEXT === $token->id
			|| WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT === $token->id;
	}

	private static function type_expression( $table, $catalog ) {
		if ( ! $catalog instanceof Catalog ) {
			return 'data_type';
		}
		$whens = array();
		foreach ( $catalog->columns( $table ) as $col ) {
			$mysql = $catalog->mysqlType( $table, $col );
			if ( null === $mysql ) {
				continue;
			}
			$whens[] = "WHEN column_name = '" . str_replace( "'", "''", $col ) . "' THEN '" . str_replace( "'", "''", $mysql ) . "'";
		}
		if ( array() === $whens ) {
			return 'data_type';
		}
		return 'CASE ' . implode( ' ', $whens ) . ' ELSE data_type END';
	}

	/**
	 * Default expression in MySQL shape.
	 *
	 * PostgreSQL reports `nextval('wp_posts_id_seq'::regclass)` for an
	 * identity column and `'publish'::character varying` for a string
	 * default; dbDelta compares the raw text and would emit an ALTER on
	 * every run (audit D-18).
	 *
	 * @return string
	 */
	private static function default_expression() {
		return "CASE" .
			" WHEN column_default IS NULL THEN NULL" .
			" WHEN column_default LIKE 'nextval(%' THEN NULL" .
			" WHEN column_default LIKE '%0001-01-01%' THEN '0000-00-00 00:00:00'" .
			" ELSE regexp_replace(regexp_replace(column_default, '::[^,)]*$', ''), '^''(.*)''$', '\\1')" .
			" END";
	}

	/**
	 * Extra column in MySQL shape (auto_increment marker).
	 *
	 * @param string       $table   Table name.
	 * @param Catalog|null $catalog Catalog (knows the identity columns).
	 * @return string
	 */
	private static function extra_expression( $table, $catalog ) {
		if ( ! $catalog instanceof Catalog ) {
			return "''";
		}
		$whens = array();
		foreach ( $catalog->columns( $table ) as $col ) {
			if ( ! $catalog->isIdentity( $table, $col ) ) {
				continue;
			}
			$whens[] = "WHEN column_name = '" . str_replace( "'", "''", $col ) . "' THEN 'auto_increment'";
		}
		if ( array() === $whens ) {
			return "''";
		}
		return 'CASE ' . implode( ' ', $whens ) . " ELSE '' END";
	}

	/**
	 * Key column in MySQL shape (PRI marker).
	 *
	 * @param string       $table   Table name.
	 * @param Catalog|null $catalog Catalog (knows the primary key).
	 * @return string
	 */
	private static function key_expression( $table, $catalog ) {
		if ( ! $catalog instanceof Catalog ) {
			return "''";
		}
		$primary = $catalog->primaryKey( $table );
		if ( array() === $primary ) {
			return "''";
		}
		$whens = array();
		foreach ( $primary as $col ) {
			$whens[] = "WHEN column_name = '" . str_replace( "'", "''", $col ) . "' THEN 'PRI'";
		}
		return 'CASE ' . implode( ' ', $whens ) . " ELSE '' END";
	}

	private static function table_name( $stmt ) {		$ident = $stmt->get_first_descendant_node( 'table_ident' );
		$tok   = null !== $ident ? $ident->get_first_descendant_token() : null;
		if ( null === $tok ) {
			throw new TranslationException( 'SHOW without table name.' );
		}
		return $tok->get_value();
	}
}
