<?php
/**
 * information_schema metadata probes (D-55, R-079).
 *
 * Translates the known MySQL information_schema probe shapes to PostgreSQL:
 *
 *   SELECT TABLE_NAME FROM information_schema.TABLES WHERE
 *       TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
 *   SELECT * FROM information_schema.COLUMNS WHERE
 *       TABLE_SCHEMA = DATABASE() AND [COLUMN_NAME = ?] AND [TABLE_NAME = ?]
 *   SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE ...
 *   SELECT COLUMN_KEY FROM information_schema.COLUMNS WHERE ...
 *   SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE ... INDEX_NAME = 'PRIMARY'
 *
 * PG carries the metadata views, but the emitted identifier case is
 * preserved ("information_schema"."TABLES"), which always ends in 42P01,
 * and INFORMATION_SCHEMA.STATISTICS has no equivalent at all. The probes
 * are therefore form-matched and re-written wholesale; any other shape
 * fails loud (R-079).
 *
 * Parameter markers keep their source order; PDO binds by position, so
 * the emitted SQL never reorders the original markers and the derived
 * COLUMN_KEY probe correlates to the outer view instead of repeating
 * its markers.
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

final class MinInfo {

	/**
	 * "Unbounded" signal for bytea columns. VARBINARY/BINARY map to bytea
	 * (R-033), whose MySQL-side n-byte length is gone; widening probes
	 * that compare against n (wordfence hostKeySize < 124) must never
	 * see "shorter". 16777215 is the MEDIUMBLOB upper bound.
	 */
	const BYTEA_UNBOUNDED = '16777215';

	/** @var array<string,string> Supported I_S containers, lowercased. */
	const CONTAINERS = array(
		'tables'     => 'tables',
		'columns'    => 'columns',
		'statistics' => 'statistics',
	);

	/** @var array<string,string> Predicate columns we accept, lowercased. */
	const PREDICATES = array(
		'table_schema' => 'table_schema',
		'table_name'   => 'table_name',
		'column_name'  => 'column_name',
		'index_name'   => 'index_name',
	);

	/**
	 * Does this statement look like an information_schema probe?
	 *
	 * @param object $stmt Parsed select statement node.
	 * @return bool
	 */
	public static function handles( $stmt ) {
		if ( null === $stmt || 'select_stmt' !== $stmt->rule_name ) {
			return false;
		}
		$parts = self::from_parts( $stmt );
		return null !== $parts && 2 === count( $parts ) && 'information_schema' === $parts[0]
			&& isset( self::CONTAINERS[ $parts[1] ] );
	}

	/**
	 * Translate an information_schema probe.
	 *
	 * @param string       $mysql   The MySQL statement.
	 * @param Catalog|null $catalog Catalog for the schema filter.
	 * @return string[] Single PG SELECT statement.
	 * @throws TranslationException When outside the v1 shapes.
	 */
	public static function translate( $mysql, $catalog = null ) {
		$ast = MysqlParser::parse( $mysql );
		if ( null === $ast ) {
			throw new TranslationException( 'Unparseable statement.' );
		}
		$simple = $ast->get_first_descendant_node( 'simple_statement' );
		$stmt   = null !== $simple ? $simple->get_first_child_node() : null;
		if ( null === $stmt || 'select_stmt' !== $stmt->rule_name ) {
			throw new TranslationException( 'Not an information_schema probe statement.' );
		}
		$parts = self::from_parts( $stmt );
		if ( null === $parts || 2 !== count( $parts ) || ! isset( self::CONTAINERS[ $parts[1] ] ) ) {
			throw new TranslationException(
				'information_schema probe outside the minimal shapes: only TABLES, COLUMNS and STATISTICS rewrites exist (R-079); saw '
					. ( null === $parts ? 'no table reference' : implode( '.', $parts ) )
			);
		}
		if ( null !== $stmt->get_first_descendant_node( 'opt_order_clause' ) ) {
			throw new TranslationException( 'information_schema probe with ORDER BY is outside the minimal shapes (R-079).' );
		}
		$item  = self::select_item( $stmt );
		$pairs = self::predicate_pairs( $stmt );

		if ( 'tables' === $parts[1] ) {
			return array( self::tables_sql( $item, $pairs, $catalog ) );
		}
		if ( 'columns' === $parts[1] ) {
			return array( self::columns_sql( $item, $pairs, $catalog ) );
		}
		return array( self::statistics_sql( $item, $pairs, $catalog ) );
	}

	/**
	 * FROM table identity as identifier parts (lowercased), or null when
	 * the statement is not a single-table probe.
	 *
	 * @param object $stmt Statement node.
	 * @return string[]|null
	 */
	private static function from_parts( $stmt ) {
		$from = $stmt->get_first_descendant_node( 'opt_from_clause' );
		if ( null === $from ) {
			return null;
		}
		$refs = array();
		foreach ( $from->get_descendant_nodes() as $node ) {
			if ( 'table_reference' === $node->rule_name ) {
				$refs[] = $node;
			}
		}
		if ( 1 !== count( $refs ) ) {
			return null;
		}
		$children = $refs[0]->get_child_nodes();
		if ( 1 !== count( $children ) || 'table_factor' !== $children[0]->rule_name ) {
			return null;
		}
		$factorChildren = $children[0]->get_child_nodes();
		if ( 1 !== count( $factorChildren ) || 'single_table' !== $factorChildren[0]->rule_name ) {
			// Aliases, parentheses, joins or derived tables: out of shape.
			return null;
		}
		$ident = $factorChildren[0]->get_first_descendant_node( 'table_ident' );
		if ( null === $ident ) {
			return null;
		}
		$text = '';
		foreach ( $ident->get_descendant_tokens() as $token ) {
			$text .= (string) $token->get_value();
		}
		$parts = explode( '.', strtolower( str_replace( ' ', '', $text ) ) );
		// Unqualified or over-qualified table names are not probes.
		if ( 2 !== count( $parts ) ) {
			return null;
		}
		return $parts;
	}

	/**
	 * The single select item text; more than one item fails loud.
	 *
	 * @param object $stmt Statement node.
	 * @return string Lowercased compact select-item text.
	 */
	private static function select_item( $stmt ) {
		$list = $stmt->get_first_descendant_node( 'select_item_list' );
		if ( null === $list ) {
			throw new TranslationException( 'information_schema probe without a select list.' );
		}
		$items = array();
		foreach ( $list->get_child_nodes() as $node ) {
			if ( 'select_item' === $node->rule_name ) {
				$items[] = strtolower( self::tokens_text( $node, true ) );
			}
		}
		if ( array() === $items ) {
			// A bare `SELECT *` list without a select_item parses as a token.
			$star = $list->get_first_descendant_token();
			if ( null !== $star && '*' === (string) $star->get_value() ) {
				$items[] = '*';
			}
		}
		if ( 1 !== count( $items ) ) {
			throw new TranslationException(
				'information_schema probe with more than one select item is outside the minimal shapes (R-079): '
					. implode( ', ', $items )
			);
		}
		return $items[0];
	}

	/**
	 * Equality pairs of the WHERE clause, in encounter order.
	 *
	 * Each pair: array('name' => lowercased predicate column, 'pos' =>
	 * 1-based encounter position, 'value' => right-side descriptor).
	 *
	 * @param object $stmt Statement node.
	 * @return array[] Pairs.
	 */
	private static function predicate_pairs( $stmt ) {
		$where = $stmt->get_first_descendant_node( 'opt_where_clause' );
		if ( null === $where ) {
			throw new TranslationException( 'information_schema probe without WHERE is outside the minimal shapes (R-079).' );
		}
		$body = $where->get_first_descendant_node( 'expr' );
		if ( null === $body ) {
			throw new TranslationException( 'information_schema probe with an empty WHERE clause.' );
		}
		$pairs = array();
		self::collect_pairs( $body, $pairs );
		return $pairs;
	}

	/**
	 * Walk a WHERE expr and collect equality pairs.
	 *
	 * @param object $node  Expression node.
	 * @param array  $pairs Collector.
	 */
	private static function collect_pairs( $node, array &$pairs ) {
		$rule = $node->rule_name;
		if ( 'expr' === $rule ) {
			foreach ( $node->get_child_nodes() as $child ) {
				if ( 'and' === $child->rule_name ) {
					continue;
				}
				self::collect_pairs( $child, $pairs );
			}
			return;
		}
		if ( 'bool_pri' === $rule ) {
			$pairs[] = self::pair( $node, 1 + count( $pairs ) );
			return;
		}
		// Anything else (OR, IN lists, BETWEEN, ...) is out of shape.
		throw new TranslationException(
			'information_schema probe has a WHERE term outside the minimal shapes (R-079): '
				. self::tokens_text( $node )
		);
	}

	/**
	 * One `identifier = operand` pair (either side may come first).
	 *
	 * @param object $node bool_pri node.
	 * @param int    $pos  1-based encounter position.
	 * @return array Pair.
	 */
	private static function pair( $node, $pos ) {
		$children = $node->get_child_nodes();
		if ( 3 !== count( $children ) || 'comp_op' !== $children[1]->rule_name ) {
			throw new TranslationException(
				'information_schema probe has a WHERE predicate outside the minimal shapes (R-079): '
					. self::tokens_text( $node )
			);
		}
		$op = $children[1]->get_first_descendant_token();
		if ( null === $op || '=' !== (string) $op->get_value() ) {
			throw new TranslationException(
				'information_schema probe supports simple equality only (R-079), got: '
					. self::tokens_text( $node )
			);
		}
		$name    = self::column_name( $children[0] );
		$operand = null;
		if ( null !== $name ) {
			$operand = self::operand( $children[2] );
		} else {
			// Operand-first form: DATABASE() = TABLE_SCHEMA.
			$name = self::column_name( $children[2] );
			$operand = self::operand( $children[0] );
		}
		if ( null === $name ) {
			throw new TranslationException(
				'information_schema probe compares non-metadata operands (R-079): '
					. self::tokens_text( $node )
			);
		}
		if ( 'unknown' === $operand['kind'] || 'database' === $operand['kind'] && 'table_schema' !== $name ) {
			throw new TranslationException(
				'information_schema probe compares ' . $name . ' against an unsupported operand (R-079): '
					. ( 'unknown' === $operand['kind'] ? self::tokens_text( $node ) : $operand['kind'] )
			);
		}
		self::require_pair_shape( $node, $name, $operand['kind'] );
		return array(
			'name'  => $name,
			'pos'   => $pos,
			'value' => $operand,
		);
	}

	/**
	 * Reject decorations like BINARY/NOT prefixes near the operands:
	 * the pair must consist of exactly the three parts it claims to be.
	 *
	 * @param object $node bool_pri node.
	 * @param string $name Predicate column (lowercased).
	 * @param string $kind Operand kind.
	 */
	private static function require_pair_shape( $node, $name, $kind ) {
		$expected = 1 + 1 + ( 'param' === $kind || 'literal' === $kind ? 1 : 3 );
		$tokens   = array();
		foreach ( $node->get_descendant_tokens() as $token ) {
			$tokens[] = $token;
		}
		if ( count( $tokens ) !== $expected ) {
			throw new TranslationException(
				'information_schema probe has a decorated WHERE predicate (BINARY/NOT prefixes are outside the minimal shapes, R-079): '
					. self::tokens_text( $node )
			);
		}
	}

	/**
	 * Identifier side: the lowercased metadata column name, or null.
	 *
	 * @param object $node Predicate node.
	 * @return string|null
	 */
	private static function column_name( $node ) {
		$identNode = $node->get_first_descendant_node( 'simple_ident' );
		if ( null === $identNode ) {
			return null;
		}
		$text  = self::tokens_text( $identNode, true );
		$lower = strtolower( $text );
		// Qualified references (t.col) are out of the v1 shapes: fail loud
		// so an unknown predicate is never silently translated.
		if ( false !== strpos( $lower, '.' ) ) {
			throw new TranslationException(
				'information_schema probe qualifies a metadata column (R-079): ' . $text
			);
		}
		if ( isset( self::PREDICATES[ $lower ] ) ) {
			return $lower;
		}
		throw new TranslationException(
			'information_schema probe predicates a column outside the minimal shapes (R-079): ' . $text
				. ' (supported: table_schema, table_name, column_name, index_name)'
		);
	}

	/**
	 * Operand side: a usable value kind.
	 *
	 * @param object $node Node.
	 * @return array Descriptor of kind database|param|literal|unknown.
	 */
	private static function operand( $node ) {
		$joined = strtolower( self::tokens_text( $node, true ) );
		if ( 'database()' === $joined ) {
			return array(
				'kind'  => 'database',
				'value' => null,
			);
		}
		if ( null !== $node->get_first_descendant_node( 'param_marker' ) ) {
			return array(
				'kind'  => 'param',
				'value' => null,
			);
		}
		$token = $node->get_first_descendant_token();
		if ( null !== $token && self::is_string_token( $token ) ) {
			return array(
				'kind'  => 'literal',
				'value' => (string) $token->get_value(),
			);
		}
		return array(
			'kind'  => 'unknown',
			'value' => null,
		);
	}

	/**
	 * SELECT TABLE_NAME FROM information_schema.tables shape.
	 *
	 * @param string       $item    Select-item text ('table_name').
	 * @param array[]      $pairs   Where equality pairs.
	 * @param Catalog|null $catalog Catalog for the schema filter.
	 * @return string
	 */
	private static function tables_sql( $item, array $pairs, $catalog ) {
		if ( 'table_name' !== $item ) {
			throw new TranslationException(
				'information_schema.TABLES probe supports only SELECT TABLE_NAME (R-079), got: ' . $item
			);
		}
		self::require_schema_pair( $pairs, 'TABLES' );
		$tableName = self::require_pair( $pairs, 'table_name', 'TABLES' );
		self::fail_extra_predicates( $pairs, 2, 'TABLES' );
		return 'SELECT table_name AS "TABLE_NAME" FROM information_schema.tables WHERE table_schema = '
			. MinShow::schema_filter( $catalog )
			. ' AND table_name = ' . self::value_sql( $tableName['value'] );
	}

	/**
	 * SELECT ... FROM information_schema.columns shapes.
	 *
	 * @param string       $item    Select-item text.
	 * @param array[]      $pairs   Where equality pairs.
	 * @param Catalog|null $catalog Catalog for the schema filter.
	 * @return string
	 */
	private static function columns_sql( $item, array $pairs, $catalog ) {
		if ( ! in_array( $item, array( '*', 'column_key', 'character_maximum_length' ), true ) ) {
			throw new TranslationException(
				'information_schema.COLUMNS probe supports only SELECT *, COLUMN_KEY and CHARACTER_MAXIMUM_LENGTH (R-079), got: ' . $item
			);
		}
		self::require_schema_pair( $pairs, 'COLUMNS' );
		$column = self::require_pair( $pairs, 'column_name', 'COLUMNS' );
		$tableName = self::require_pair( $pairs, 'table_name', 'COLUMNS' );
		self::fail_extra_predicates( $pairs, 3, 'COLUMNS' );
		$predicate = 'table_schema = ' . MinShow::schema_filter( $catalog )
			. ' AND ' . self::existence_predicate( $column, $tableName );
		$selection = '*';
		if ( 'character_maximum_length' === $item ) {
			$selection = 'CASE WHEN data_type = \'bytea\' THEN ' . self::BYTEA_UNBOUNDED
				. ' ELSE character_maximum_length END AS "CHARACTER_MAXIMUM_LENGTH"';
		}
		if ( 'column_key' === $item ) {
			$selection = self::column_key_sql();
		}
		return 'SELECT ' . $selection . ' FROM information_schema.columns WHERE ' . $predicate;
	}

	/**
	 * SELECT COUNT(*) FROM information_schema.statistics shape.
	 *
	 * The only proven consumer (woocommerce) counts the columns of the
	 * PRIMARY index on a table. A general index-existence case has no
	 * faithful PG form without the D-71 name composition, so the matcher
	 * only accepts INDEX_NAME = 'PRIMARY' here.
	 *
	 * @param string       $item    Select-item text ('count(*)').
	 * @param array[]      $pairs   Where equality pairs.
	 * @param Catalog|null $catalog Catalog for the schema filter.
	 * @return string
	 */
	private static function statistics_sql( $item, array $pairs, $catalog ) {
		if ( 'count(*)' !== $item ) {
			throw new TranslationException(
				'information_schema.STATISTICS probe supports only SELECT COUNT(*) (R-079), got: ' . $item
			);
		}
		self::require_schema_pair( $pairs, 'STATISTICS' );
		$index = self::require_pair( $pairs, 'index_name', 'STATISTICS' );
		$tableName = self::require_pair( $pairs, 'table_name', 'STATISTICS' );
		self::fail_extra_predicates( $pairs, 3, 'STATISTICS' );
		if ( 'literal' !== $index['value']['kind'] || 'primary' !== strtolower( (string) $index['value']['value'] ) ) {
			throw new TranslationException(
				'information_schema.STATISTICS probe only supports INDEX_NAME = \'PRIMARY\' (R-079); other index names need the D-71 name composition (v1 open).'
			);
		}
		return 'SELECT COUNT(*) FROM information_schema.key_column_usage kcu'
			. ' JOIN information_schema.table_constraints tc ON tc.constraint_name = kcu.constraint_name'
			. ' AND tc.table_schema = kcu.table_schema AND tc.table_name = kcu.table_name'
			. " WHERE tc.constraint_type = 'PRIMARY KEY'"
			. ' AND kcu.table_schema = ' . MinShow::schema_filter( $catalog )
			. ' AND kcu.table_name = ' . self::value_sql( $tableName['value'] );
	}

	/**
	 * TABLE_SCHEMA predicate: the value must be DATABASE().
	 *
	 * @param array[] $pairs Equality pairs.
	 * @param string  $what  Container label for messages.
	 */
	private static function require_schema_pair( array $pairs, $what ) {
		$schema = self::require_pair( $pairs, 'table_schema', $what );
		if ( 'database' !== $schema['value']['kind'] ) {
			throw new TranslationException(
				'information_schema.' . $what . ' probe must compare TABLE_SCHEMA with DATABASE() (R-079).'
			);
		}
	}

	/**
	 * The two existence predicates in the source order, so that parameter
	 * bindings by position keep matching.
	 *
	 * The compare follows the MySQL collation of the metadata column:
	 * TABLE_NAME is utf8mb3_bin (case-sensitive), COLUMN_NAME is
	 * utf8mb3_tolower_ci (case-insensitive).
	 *
	 * @param array $column Column pair.
	 * @param array $tableName Table pair.
	 * @return string SQL text.
	 */
	private static function existence_predicate( array $column, array $tableName ) {
		$first  = $column['pos'] < $tableName['pos'] ? $column : $tableName;
		$second = $first === $column ? $tableName : $column;
		return self::pair_predicate( $first ) . ' AND ' . self::pair_predicate( $second );
	}

	/**
	 * One existence predicate, in the compare mode of its column.
	 *
	 * @param array $pair Equality pair.
	 * @return string SQL text.
	 */
	private static function pair_predicate( array $pair ) {
		if ( 'table_name' === $pair['name'] ) {
			return 'table_name = ' . self::value_sql( $pair['value'] );
		}
		return 'lower(column_name) = ' . self::value_sql_ci( $pair['value'] );
	}

	/**
	 * COLUMN_KEY reconstruction. MySQL reports the key flag of a column;
	 * PostgreSQL does not carry it in information_schema, so derive it
	 * from the index catalogs (R-079 (4)): PRI for the primary key,
	 * UNI for a unique constraint or unique index, MUL for any other
	 * index membership, and no row at all when the column is missing.
	 *
	 * The markers live in the outer WHERE; the derivation correlates to
	 * the outer view so no marker is duplicated.
	 *
	 * @return string Scalar expression over information_schema.columns.
	 */
	private static function column_key_sql() {
		$case = 'CASE WHEN co.contype = \'p\' THEN \'PRI\' WHEN co.contype = \'u\' OR i.indisunique THEN \'UNI\''
			. ' WHEN i.indrelid IS NOT NULL THEN \'MUL\' END';
		$order = 'ORDER BY CASE WHEN co.contype = \'p\' THEN 0 WHEN co.contype = \'u\' OR i.indisunique THEN 1 ELSE 2 END LIMIT 1';
		// The relation lookup is scoped to the schema, to the table kinds
		// and to the spelling: the schema can hold both spellings of a
		// table name (the identifier case policy, D-82), and a ci lookup
		// can pick the other relation. PostgreSQL refuses a scalar
		// subquery that returns more than one row.
		$relation = '(SELECT c.oid FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace'
			. ' WHERE c.relname = information_schema.columns.table_name'
			. ' AND n.nspname = information_schema.columns.table_schema'
			. ' AND c.relkind IN (\'r\',\'p\') LIMIT 1)';
		return 'COALESCE((SELECT ' . $case . ' FROM pg_attribute a'
			. ' LEFT JOIN pg_constraint co ON co.conrelid = a.attrelid AND a.attnum = ANY (co.conkey)'
			. ' LEFT JOIN pg_index i ON i.indrelid = a.attrelid AND a.attnum = ANY (i.indkey)'
			. ' WHERE a.attrelid = ' . $relation
			. ' AND lower(a.attname) = lower(information_schema.columns.column_name)'
			. ' ' . $order . "), '') AS \"COLUMN_KEY\"";
	}

	/**
	 * Require one pair by name.
	 *
	 * @param array[] $pairs Equality pairs.
	 * @param string  $name  Predicate column.
	 * @param string  $what  Container label for messages.
	 * @return array Pair.
	 */
	private static function require_pair( array $pairs, $name, $what ) {
		foreach ( $pairs as $pair ) {
			if ( $pair['name'] === $name ) {
				return $pair;
			}
		}
		throw new TranslationException(
			'information_schema.' . $what . ' probe must predicate ' . $name . ' (R-079); not found in the WHERE clause.'
		);
	}

	/**
	 * Right side of an equality as SQL text, compared byte for byte.
	 *
	 * MySQL 8.4 carries utf8mb3_bin on the metadata name columns
	 * (TABLE_NAME, TABLE_SCHEMA), so the compare is case-sensitive.
	 *
	 * @param array $value Right-side descriptor (kind param|literal; the
	 *                     database kind never reaches here).
	 * @return string
	 */
	private static function value_sql( array $value ) {
		if ( 'param' === $value['kind'] ) {
			return '?';
		}
		if ( 'literal' === $value['kind'] ) {
			return '\'' . str_replace( "'", "''", (string) $value['value'] ) . '\'';
		}
		throw new TranslationException(
			'information_schema probe compares against an unsupported operand (R-079): ' . $value['kind']
		);
	}

	/**
	 * Right side of a case-insensitive equality as SQL text.
	 *
	 * MySQL 8.4 carries utf8mb3_tolower_ci on the metadata column and
	 * index name columns (COLUMN_NAME, INDEX_NAME).
	 *
	 * @param array $value Right-side descriptor (kind param|literal; the
	 *                     database kind never reaches here).
	 * @return string
	 */
	private static function value_sql_ci( array $value ) {
		if ( 'param' === $value['kind'] ) {
			return 'lower(?)';
		}
		if ( 'literal' === $value['kind'] ) {
			return 'lower(\'' . str_replace( "'", "''", (string) $value['value'] ) . '\')';
		}
		throw new TranslationException(
			'information_schema probe compares against an unsupported operand (R-079): ' . $value['kind']
		);
	}

	private static function fail_extra_predicates( array $pairs, $max, $what ) {
		if ( count( $pairs ) <= $max ) {
			return;
		}
		throw new TranslationException(
			'information_schema.' . $what . ' probe has predicates outside the minimal shapes (R-079): at most '
				. $max . ' supported, saw ' . count( $pairs )
		);
	}

	/**
	 * Raw token text of a node.
	 *
	 * @param object $node     Node.
	 * @param bool   $collapse Drop all whitespace.
	 * @return string
	 */
	private static function tokens_text( $node, $collapse = false ) {
		$text = '';
		foreach ( $node->get_descendant_tokens() as $token ) {
			$text .= (string) $token->get_value();
		}
		if ( $collapse ) {
			return str_replace( ' ', '', $text );
		}
		return $text;
	}

	/**
	 * Mirrors MinShow::is_string_token.
	 *
	 * @param object $token Token.
	 * @return bool
	 */
	private static function is_string_token( $token ) {
		return WP_MySQL_Lexer::SINGLE_QUOTED_TEXT === $token->id
			|| WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT === $token->id;
	}
}
