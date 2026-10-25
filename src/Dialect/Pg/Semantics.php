<?php
/**
 * Semantic rewrite spans (M3b): GROUP BY, DISTINCT/ORDER, NULLS, casts.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Dialect\Pg;

use Slonik\Schema\Catalog;
use Slonik\Schema\ColumnOverrides;
use Slonik\Sql\TranslationException;
use WP_MySQL_Lexer;

final class Semantics {

	/** Token id of the MySQL `IN` keyword (vendored lexer: 504). */
	const IN_TOKEN_ID = 504;

	/**
	 * GROUP_CONCAT -> string_agg (R-067).
	 *
	 * MySQL's GROUP_CONCAT(expr) concatenates with ',' by default;
	 * PostgreSQL's string_agg(expr, delimiter) needs the delimiter. The
	 * DISTINCT stays in place. The SEPARATOR and ORDER BY forms are
	 * rejected: the aggregate ORDER BY is outside the supported subset.
	 *
	 * @param \WP_Parser_Node $ast   Statement node.
	 * @param string          $mysql Original SQL.
	 * @return array<int,array{0:int,1:int,2:string}> Rewrite spans.
	 * @throws TranslationException When the form is not supported.
	 */
	public static function group_concat_spans( $ast, $mysql = '' ) {
		$spans = array();
		foreach ( $ast->get_descendant_nodes( 'set_function_specification' ) as $node ) {
			$tokens = array();
			foreach ( $node->get_descendant_tokens() as $token ) {
				if ( $token instanceof \WP_MySQL_Token ) {
					$tokens[] = $token;
				}
			}
			if ( array() === $tokens ) {
				continue;
			}
			if ( 'GROUP_CONCAT' !== strtoupper( trim( (string) $tokens[0]->get_value() ) ) ) {
				continue;
			}
			if ( null !== $node->get_first_descendant_node( 'opt_gconcat_separator' )
				|| null !== $node->get_first_descendant_node( 'opt_gorder_clause' ) ) {
				throw TranslationException::unsupported(
					'GROUP_CONCAT',
					'GROUP_CONCAT with SEPARATOR or ORDER BY has no PostgreSQL equivalent in WP-Slonik (fail closed).'
				);
			}
			// The name becomes string_agg.
			$spans[] = array( (int) $tokens[0]->start, (int) $tokens[0]->length, 'string_agg' );
			// The closing parenthesis carries the delimiter.
			$close = $tokens[ count( $tokens ) - 1 ];
			if ( ')' !== trim( (string) $close->get_value() ) ) {
				continue;
			}
			$spans[] = array( (int) $close->start, 1, ", ',')" );
		}
		return $spans;
	}

	/**
	 * INSERT IGNORE -> ON CONFLICT DO NOTHING (R-066).
	 *
	 * MySQL's IGNORE downgrades every error to a warning; PostgreSQL's
	 * ON CONFLICT DO NOTHING covers the unique conflict only - the other
	 * errors keep failing (a documented deviation, docs/05 R-066).
	 *
	 * @param \WP_Parser_Node $ast   Statement node.
	 * @param string          $mysql Original SQL.
	 * @return array<int,array{0:int,1:int,2:string}> Rewrite spans.
	 */
	/**
	 * The string literals that write into a bytea column.
	 *
	 * A bytea column cannot take a plain text literal: the payload's
	 * NUL bytes would end the literal ("unterminated quoted string").
	 * The value goes out in PostgreSQL's hex input format ("\x…")
	 * instead, which keeps every byte (audit D-73).
	 *
	 * @param \WP_Parser_Node $stmt    Statement node (insert or update).
	 * @param string          $mysql   Original SQL.
	 * @param Catalog|null    $catalog Catalog (knows the column types).
	 * @return array<int,true> Token start => true.
	 */
	public static function bytea_literals( $stmt, $mysql = '', $catalog = null ) {
		$out = array();
		$table = self::table_name( $stmt );
		if ( '' === $table ) {
			return $out;
		}
		if ( 'insert_stmt' === $stmt->rule_name ) {
			$cols = array();
			foreach ( $stmt->get_descendant_nodes( 'insert_column' ) as $col ) {
				$tok = $col->get_first_descendant_token();
				if ( null !== $tok ) {
					$cols[] = (string) $tok->get_value();
				}
			}
			if ( array() === $cols ) {
				return $out;
			}
			// INSERT ... VALUES (a, b): the values are positional.
			foreach ( $stmt->get_descendant_nodes( 'row_value' ) as $row ) {
				$items = $row->get_descendant_nodes( 'expr_or_default' );
				foreach ( $items as $i => $item ) {
					if ( ! isset( $cols[ $i ] ) ) {
						continue;
					}
					self::mark_bytea( $out, $item, $catalog, $table, $cols[ $i ] );
				}
			}
			// INSERT ... SELECT a, b: the select list is positional too
			// (ActionScheduler saves its actions that way).
			$spec = $stmt->get_first_descendant_node( 'query_expression_with_opt_locking_clauses' );
			$spec = null !== $spec ? $spec->get_first_descendant_node( 'query_specification' ) : null;
			$list = null !== $spec ? $spec->get_first_descendant_node( 'select_item_list' ) : null;
			if ( null !== $list ) {
				$items = $list->get_descendant_nodes( 'select_item' );
				foreach ( $items as $i => $item ) {
					if ( ! isset( $cols[ $i ] ) ) {
						continue;
					}
					self::mark_bytea( $out, $item, $catalog, $table, $cols[ $i ] );
				}
			}
			return $out;
		}
		if ( 'update_stmt' === $stmt->rule_name ) {
			foreach ( $stmt->get_descendant_nodes( 'update_elem' ) as $elem ) {
				$column = '';
				$value  = null;
				foreach ( $elem->get_child_nodes() as $child ) {
					if ( 'simple_ident_nospvar' === $child->rule_name && '' === $column ) {
						$tok = $child->get_first_descendant_token();
						if ( null !== $tok ) {
							$column = (string) $tok->get_value();
						}
						continue;
					}
					if ( 'expr_or_default' === $child->rule_name && null === $value ) {
						$value = $child;
					}
				}
				if ( '' === $column || null === $value ) {
					continue;
				}
				self::mark_bytea( $out, $value, $catalog, $table, $column );
			}
		}
		return $out;
	}

	/**
	 * Mark the string literal of a value that writes into a bytea column.
	 *
	 * @param array           $out     Token start => true (by reference).
	 * @param \WP_Parser_Node $item    Value expression node.
	 * @param Catalog        $catalog Catalog.
	 * @param string         $table   Table name.
	 * @param string         $column  Column name.
	 * @return void
	 */
	private static function mark_bytea( array &$out, $item, $catalog, $table, $column ) {
		$type = null;
		if ( $catalog instanceof Catalog ) {
			$type = $catalog->pgType( $table, $column );
		}
		if ( null === $type ) {
			// The catalog does not know the column (no catalog at all, or
			// a table created in this session): the static override
			// decides (audit D-74).
			if ( ! ColumnOverrides::isBytea( $table, $column ) ) {
				return;
			}
		} elseif ( 'bytea' !== strtolower( (string) $type ) ) {
			return;
		}
		foreach ( $item->get_descendant_tokens() as $token ) {
			if ( ! $token instanceof \WP_MySQL_Token ) {
				continue;
			}
			if ( WP_MySQL_Lexer::HEX_NUMBER === $token->id ) {
				// A MySQL hex literal: PostgreSQL reads the same spelling
				// as a bit literal (audit D-75). The emitter always
				// rewrites it, so it does not need a bytea column.
				return;
			}
			if ( ! self::is_string_token_id( $token->id ) ) {
				// Not a plain literal: the value cannot be rewritten
				// into the hex format, so it stays as it is.
				return;
			}
			$out[ (int) $token->start ] = true;
			return;
		}
	}

	/**
	 * The table a statement writes to.
	 *
	 * @param \WP_Parser_Node $stmt Statement node.
	 * @return string Table name (empty when the statement has none).
	 */
	private static function table_name( $stmt ) {
		$ident = $stmt->get_first_descendant_node( 'table_ident' );
		$tok   = null !== $ident ? $ident->get_first_descendant_token() : null;
		return null !== $tok ? (string) $tok->get_value() : '';
	}

	public static function insert_ignore_spans( $ast, $mysql = '' ) {
		$spans = array();
		$ignore = $ast->get_first_descendant_node( 'opt_ignore' );
		if ( null === $ignore ) {
			return $spans;
		}
		$token = $ignore->get_first_descendant_token();
		if ( null === $token ) {
			return $spans;
		}
		// The IGNORE keyword goes away (with the whitespace before it, so
		// the tokens do not grow a double space).
		$start = (int) $token->start;
		$len   = strlen( (string) $mysql );
		while ( $start > 0 && ( ' ' === $mysql[ $start - 1 ] || "\t" === $mysql[ $start - 1 ] ) ) {
			--$start;
		}
		$spans[] = array( $start, (int) $token->start + (int) $token->length - $start, '' );

		// The clause goes at the end of the statement (before a trailing
		// semicolon, which is not part of it).
		$end = 0;
		foreach ( $ast->get_descendant_tokens() as $t ) {
			if ( ! $t instanceof \WP_MySQL_Token ) {
				continue;
			}
			if ( ';' === trim( (string) $t->get_value() ) ) {
				continue;
			}
			$end = max( $end, (int) $t->start + (int) $t->length );
		}
		$spans[] = array( $end, 0, ' ON CONFLICT DO NOTHING' );
		return $spans;
	}

	/**
	 * Remove MySQL's FROM DUAL (R-063).
	 *
	 * MySQL's DUAL is a dummy table that lets a SELECT run without a
	 * table; PostgreSQL has no such table, but a constant SELECT needs no
	 * FROM at all. The Action Scheduler writes
	 * "INSERT ... SELECT ... FROM DUAL WHERE ( SELECT NULL FROM DUAL ) IS NULL"
	 * (WooCommerce), so the clause is removed instead of failing.
	 *
	 * @param \WP_Parser_Node $ast   Statement node.
	 * @param string          $mysql Original SQL.
	 * @return array<int,array{0:int,1:int,2:string}> Rewrite spans.
	 * @throws TranslationException When DUAL is combined with another table.
	 */
	public static function dual_spans( $ast, $mysql = '' ) {
		$spans = array();
		$toks  = array();
		foreach ( $ast->get_descendant_tokens() as $token ) {
			if ( $token instanceof \WP_MySQL_Token ) {
				$toks[] = $token;
			}
		}
		$count = count( $toks );
		$len   = strlen( (string) $mysql );
		for ( $i = 0; $i + 1 < $count; ++$i ) {
			$from = $toks[ $i ];
			$dual = $toks[ $i + 1 ];
			if ( 'FROM' !== strtoupper( trim( (string) $from->get_value() ) ) ) {
				continue;
			}
			// A quoted "DUAL" is a real table, not the dummy one.
			if ( WP_MySQL_Lexer::BACK_TICK_QUOTED_ID === $dual->id ) {
				continue;
			}
			if ( 'DUAL' !== strtoupper( trim( (string) $dual->get_value() ) ) ) {
				continue;
			}
			$after = isset( $toks[ $i + 2 ] ) ? strtoupper( trim( (string) $toks[ $i + 2 ]->get_value() ) ) : '';
			if ( ',' === $after ) {
				throw TranslationException::unsupported(
					'FROM DUAL with another table',
					'FROM DUAL combined with another table has no PostgreSQL equivalent; rewrite the query.'
				);
			}
			// Only a clause that ends the FROM may go; an alias or a join
			// would leave a broken statement behind.
			if ( ! in_array( $after, array( '', 'WHERE', 'GROUP', 'HAVING', 'ORDER', 'LIMIT', 'UNION', ';', ')' ), true ) ) {
				throw TranslationException::unsupported(
					'FROM DUAL with an alias',
					'FROM DUAL with an alias has no PostgreSQL equivalent; rewrite the query.'
				);
			}
			$start = (int) $from->start;
			$end   = (int) $dual->start + (int) $dual->length;
			// Swallow the whitespace before the clause so the result does not
			// end in a space ("SELECT 1 FROM DUAL" -> "SELECT 1").
			while ( $start > 0 && ( ' ' === $mysql[ $start - 1 ] || "\t" === $mysql[ $start - 1 ] ) ) {
				--$start;
			}
			$spans[] = array( $start, $end - $start, '' );
			++$i;
		}
		return $spans;
	}

	/**
	 * Wrap non-grouped, non-aggregated select expressions in any_value().
	 *
	 * Wrapping is semantically safe: functionally dependent columns keep
	 * their value, others match MySQL's lax indeterminism. Mixed
	 * aggregate/bare expressions (e.g. COUNT(*) + col) are left alone and
	 * fail loud in PG (M3c may go expr-granular).
	 *
	 * @param \WP_Parser_Node $select select_stmt node.
	 * @param string          $mysql  Original SQL.
	 * @param array           $colmap Lowercase name => canonical spelling.
	 * @param array           $scope  Token start => spelling of the statement's own
	 *                                identifiers (audit D-80).
	 * @return array List of [start, length, replacement] spans.
	 */
	public static function group_spans( $select, $mysql, $colmap = array(), $scope = array() ) {
		$groups = $select->get_descendant_nodes( 'group_list' );
		if ( array() === $groups ) {
			return array();
		}
		$grouped = array();
		$bare    = array();
		$gexprs  = $groups[0]->get_descendant_nodes( 'grouping_expr' );
		if ( array() === $gexprs ) {
			foreach ( $groups[0]->get_descendant_tokens() as $token ) {
				if ( $token instanceof \WP_MySQL_Token && self::is_ident( $token ) ) {
					$grouped[ strtolower( $token->get_value() ) ] = true;
				}
			}
			foreach ( self::ident_names( $groups[0] ) as $lower => $unused ) {
				$grouped[ $lower ] = true;
			}
		}
		foreach ( $gexprs as $gexpr ) {
			if ( self::has_function_call( $gexpr ) ) {
				continue;
			}
			$name = self::bare_ident_name( $gexpr );
			if ( '' !== $name ) {
				$bare[ $name ] = true;
			}
			foreach ( self::ident_names( $gexpr ) as $lower => $unused ) {
				$grouped[ $lower ] = true;
			}
		}
		$spans = array();
		foreach ( $select->get_descendant_nodes( 'select_item' ) as $item ) {
			if ( $item->get_first_descendant_node( 'set_function_specification' ) ) {
				continue;
			}
			$alias = self::select_alias( $item );
			if ( '' !== $alias
				&& isset( $bare[ $alias ] )
				&& ! isset( $colmap[ $alias ] )
			) {
				// The alias is the grouping key: MySQL groups by the
				// expression the alias stands for, so the item must not be
				// wrapped (audit D-83). Only a bare GROUP BY identifier
				// qualifies, and only when it cannot resolve to an input
				// column instead — MySQL resolves a bare GROUP BY name
				// against input columns first, so a colliding alias is not
				// the grouping key (review of D-83).
				continue;
			}
			$idents   = self::ident_names( $item );
			$has_star = false;
			foreach ( $item->get_descendant_tokens() as $token ) {
				if ( ! $token instanceof \WP_MySQL_Token ) {
					continue;
				}
				if ( 'MULT_OPERATOR' === $token->get_name() ) {
					$has_star = true;
				}
			}
			if ( $has_star || array() === $idents ) {
				continue;
			}
			$needs_wrap = false;
			foreach ( $idents as $name => $unused ) {
				if ( ! isset( $grouped[ $name ] ) ) {
					$needs_wrap = true;
					break;
				}
			}
			if ( ! $needs_wrap ) {
				continue;
			}
			$expr = $item->get_first_descendant_node( 'expr' );
			if ( null === $expr ) {
				continue;
			}
			$inner = substr( $mysql, $expr->get_start(), $expr->get_length() );
			$inner = self::quote_idents_in( $inner, $expr->get_start(), $expr, $colmap, $scope );
			$spans[] = array(
				$expr->get_start(),
				$expr->get_length(),
				'any_value(' . $inner . ')',
			);
		}
		return $spans;
	}

	private static function quote_idents_in( $text, $base, $expr, $colmap, $scope = array() ) {
		$spans = array();
		foreach ( $expr->get_descendant_tokens() as $token ) {
			if ( ! $token instanceof \WP_MySQL_Token || ! self::is_ident( $token ) ) {
				continue;
			}
			$value = (string) $token->get_value();
			$canon = isset( $scope[ (int) $token->start ] )
				? (string) $scope[ (int) $token->start ]
				: ( isset( $colmap[ strtolower( $value ) ] ) ? (string) $colmap[ strtolower( $value ) ] : $value );
			$spans[] = array( $token->start - $base, $token->length, '"' . str_replace( '"', '""', $canon ) . '"' );
		}
		usort(
			$spans,
			function ( $a, $b ) {
				return $b[0] - $a[0];
			}
		);
		foreach ( $spans as $span ) {
			$text = substr( $text, 0, $span[0] ) . $span[2] . substr( $text, $span[0] + $span[1] );
		}
		return $text;
	}

	private static function has_function_call( $node ) {
		foreach ( $node->get_descendant_nodes() as $desc ) {
			if ( false !== stripos( $desc->rule_name, 'function' ) ) {
				return true;
			}
		}
		return false;
	}

	private static function select_alias( $item ) {
		$node = $item->get_first_child_node( 'select_alias' );
		if ( null === $node ) {
			return '';
		}
		$as      = WP_MySQL_Lexer::KEYWORDS['AS'];
		$seen_as = false;
		foreach ( $node->get_descendant_tokens() as $token ) {
			if ( ! $token instanceof \WP_MySQL_Token ) {
				continue;
			}
			if ( ! $seen_as ) {
				$seen_as = true;
				if ( $as === $token->id ) {
					continue;
				}
			}
			if ( self::is_ident( $token ) ) {
				return strtolower( (string) $token->get_value() );
			}
			// Keyword-spelled alias (e.g. `AS date`): the lexer tags the
			// word with its keyword id, but it is still the alias here.
			if ( self::is_word( $token->get_value() ) ) {
				return strtolower( (string) $token->get_value() );
			}
			return '';
		}
		return '';
	}

	/**
	 * Lowercase name when the grouping expression is a single bare
	 * identifier; '' otherwise (qualified name, parenthesis, expression).
	 */
	private static function bare_ident_name( $gexpr ) {
		$si = $gexpr->get_first_descendant_node( 'simple_ident' );
		if ( null === $si ) {
			return '';
		}
		$regions = array( array( $si->get_start(), $si->get_start() + $si->get_length() ) );
		foreach ( $gexpr->get_descendant_tokens() as $token ) {
			if ( ! $token instanceof \WP_MySQL_Token ) {
				continue;
			}
			if ( ! self::in_regions( $token, $regions ) ) {
				// Another token (operator, second part) makes it an expression.
				return '';
			}
		}
		$names = self::ident_names( $gexpr );
		return 1 === count( $names ) ? (string) key( $names ) : '';
	}

	private static function is_ident( $token ) {
		return WP_MySQL_Lexer::BACK_TICK_QUOTED_ID === $token->id
			|| WP_MySQL_Lexer::IDENTIFIER === $token->id;
	}

	private static function ident_regions( $node ) {
		$regions = array();
		foreach ( $node->get_descendant_nodes( 'simple_ident' ) as $si ) {
			$regions[] = array( $si->get_start(), $si->get_start() + $si->get_length() );
		}
		return $regions;
	}

	private static function in_regions( $token, $regions ) {
		foreach ( $regions as $range ) {
			if ( $token->start >= $range[0] && $token->start + $token->length <= $range[1] ) {
				return true;
			}
		}
		return false;
	}

	private static function is_word( $value ) {
		return 1 === preg_match( '/^[A-Za-z_][A-Za-z0-9_$]*$/', (string) $value );
	}

	/**
	 * Identifier-like names under a node (lowercase => as-written).
	 *
	 * Covers backticked, plain and keyword-spelled identifiers; the latter
	 * only inside simple_ident regions (grammar-placed).
	 *
	 * @param \WP_Parser_Node $node Subtree to scan.
	 * @return array Map.
	 */
	public static function ident_names( $node ) {
		$regions = self::ident_regions( $node );
		$names   = array();
		foreach ( $node->get_descendant_tokens() as $token ) {
			if ( ! $token instanceof \WP_MySQL_Token ) {
				continue;
			}
			if ( self::is_ident( $token ) ) {
				$names[ strtolower( $token->get_value() ) ] = $token->get_value();
				continue;
			}
			if ( self::in_regions( $token, $regions ) && self::is_word( $token->get_value() ) ) {
				$names[ strtolower( $token->get_value() ) ] = $token->get_value();
			}
		}
		return $names;
	}

	/**
	 * Rewrite DISTINCT with uncovered ORDER BY into a hidden-column subquery.
	 *
	 * The outer query intentionally has no DISTINCT: PG requires ORDER BY
	 * expressions in the outer select list, which would leak the helper
	 * column. Deduplication happens inside; when selected items contain a
	 * unique key (the WP norm) no duplicates can leak. Non-unique items
	 * with divergent order keys may duplicate (MySQL-lax collapses them) —
	 * accepted M3b gap, revisit in M3c.
	 *
	 * @param string          $mysql   Original SQL.
	 * @param \WP_Parser_Node $select  select_stmt node.
	 * @param Catalog|null    $catalog Catalog (needed for star expansion).
	 * @return string|null Full replacement SQL, or null when no rewrite needed.
	 * @throws TranslationException When DISTINCT+ORDER cannot be made valid.
	 */
	public static function distinct_rewrite( $mysql, $select, $catalog ) {
		if ( ! self::has_distinct( $select ) ) {
			return null;
		}
		$orders = $select->get_descendant_nodes( 'order_expr' );
		if ( array() === $orders ) {
			return null;
		}
		foreach ( $orders as $order ) {
			foreach ( $order->get_descendant_tokens() as $token ) {
				if ( $token instanceof \WP_MySQL_Token && '/' === $token->get_value() && ! self::is_string_token_id( $token->id ) ) {
					throw TranslationException::unsupported( 'DISTINCT division', 'DISTINCT with division in ORDER BY needs M4 expression typing.' );
				}
			}
		}
		$colmap = $catalog instanceof Catalog ? $catalog->colmap() : array();
		$scope  = IdentScope::resolve( $select, $catalog );
		$ident  = MinEmitter::ident_spans( $select, $colmap, $mysql, array(), array(), $scope );
		$items  = $select->get_descendant_nodes( 'select_item' );
		if ( array() === $items ) {
			throw new TranslationException( 'DISTINCT without select list.' );
		}

		// A star is expanded with the catalog: the window partitions by
		// plain columns.
		$star_spans = array();
		$star_ranges = array();
		$star_cols  = array();
		foreach ( $items as $i => $item ) {
			$text = trim( substr( $mysql, $item->get_start(), $item->get_length() ) );
			if ( ! self::is_star_item( $item, $text ) ) {
				continue;
			}
			$cols = self::star_columns( $select, $text, $catalog );
			$star_cols[ $i ]  = $cols;
			$star_spans[]     = array( $item->get_start(), $item->get_length(), implode( ', ', $cols ) );
			$star_ranges[]    = array( $item->get_start(), $item->get_start() + $item->get_length() );
		}

		// The select items: the text and the expression (the window
		// partitions by the expression, not by the alias).
		$item_quoted = array();
		$partition   = array();
		foreach ( $items as $i => $item ) {
			if ( isset( $star_cols[ $i ] ) ) {
				$item_quoted[ $i ] = implode( ', ', $star_cols[ $i ] );
				foreach ( $star_cols[ $i ] as $col ) {
					$partition[] = $col;
				}
				continue;
			}
			$item_quoted[ $i ] = self::quote_range( $mysql, $ident, $item->get_start(), $item->get_length() );
			$expr = $item->get_first_descendant_node( 'expr' );
			if ( null === $expr ) {
				throw new TranslationException( 'DISTINCT select item without expression.' );
			}
			$partition[] = self::quote_range( $mysql, $ident, $expr->get_start(), $expr->get_length() );
		}

		// The ORDER BY items that are not in the select list become
		// hidden columns.
		$aliases = self::item_aliases( $mysql, $items );
		$hidden  = array();
		$mapped  = array();
		$window_order = array();
		foreach ( $orders as $n => $order ) {
			$expr = $order->get_first_descendant_node( 'expr' );
			if ( null === $expr ) {
				throw new TranslationException( 'ORDER BY without expression.' );
			}
			$raw   = substr( $mysql, $expr->get_start(), $expr->get_length() );
			$found = false;
			foreach ( $items as $item ) {
				$item_expr = $item->get_first_descendant_node( 'expr' );
				$item_text = null === $item_expr ? '' : substr( $mysql, $item_expr->get_start(), $item_expr->get_length() );
				if ( trim( $item_text ) === trim( $raw ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found && isset( $aliases[ strtolower( trim( $raw ) ) ] ) ) {
				$found = true;
			}
			$dir      = self::order_direction( $order );
			$dirpart  = '' !== $dir ? ' ' . $dir : '';
			$nullpart = self::nulls_suffix( $order, $catalog );
			$nullpart = '' !== $nullpart ? ' ' . $nullpart : '';
			$quoted   = self::quote_range( $mysql, $ident, $expr->get_start(), $expr->get_length() );
			$window_order[] = $quoted . $dirpart . $nullpart;
			if ( $found ) {
				$mapped[] = $quoted . $dirpart . $nullpart;
				continue;
			}
			$hidden[] = $quoted . ' AS "__slonik_ob' . $n . '"';
			$mapped[] = '"__slonik_ob' . $n . '"' . $dirpart . $nullpart;
		}
		if ( array() === $hidden ) {
			return null;
		}

		$list = $select->get_first_descendant_node( 'select_item_list' );
		if ( null === $list ) {
			throw new TranslationException( 'DISTINCT without select list.' );
		}
		$order_node = $select->get_first_descendant_node( 'order_clause' );
		if ( null === $order_node ) {
			throw new TranslationException( 'ORDER BY without clause.' );
		}
		$list_end   = $list->get_start() + $list->get_length();
		$order_range = array( $order_node->get_start(), $order_node->get_start() + $order_node->get_length() );
		$ctx        = self::compare_context( $catalog, $select );

		$inner_extra = MinEmitter::excluding(
			array_merge(
				MinEmitter::limit_spans( $select ),
				MinEmitter::calc_spans( $select ),
				self::group_spans( $select, $mysql, $colmap, $scope ),
				self::type_compare_spans( $select, $ctx[0], $ctx[1], $mysql ),
				self::operator_spans( $select ),
				self::lock_spans( $select ),
				self::div_spans( $select, $catalog, $mysql )
			),
			array( $order_range )
		);

		$outer_items = self::outer_items( $mysql, $select, $items, $item_quoted, $catalog );
		$distinct_span = array();
		$options = $select->get_first_descendant_node( 'select_options' );
		if ( null !== $options ) {
			foreach ( $options->get_descendant_tokens() as $token ) {
				if ( $token instanceof \WP_MySQL_Token && 'DISTINCT' === strtoupper( (string) $token->get_value() ) ) {
					$distinct_span = array( $token->start, $token->length, '' );
					break;
				}
			}
		}

		// An aggregate cannot be partitioned by; the DISTINCT stays in
		// the inner query (the aggregate returns one row per group).
		$window = '';
		if ( self::has_aggregate_item( $select, $items ) ) {
			$distinct_span = array();
			foreach ( $select->get_descendant_nodes( 'select_options' ) as $node ) {
				foreach ( $node->get_descendant_tokens() as $token ) {
					if ( $token instanceof \WP_MySQL_Token && 'DISTINCT' === strtoupper( (string) $token->get_value() ) ) {
						$distinct_span = array( $token->start, $token->length, 'DISTINCT' );
						break 2;
					}
				}
			}
		} else {
			$window = 'row_number() OVER (PARTITION BY ' . implode( ', ', $partition ) . ' ORDER BY ' . implode( ', ', $window_order ) . ') AS "__slonik_rn"';
		}

		$spans = array_merge(
			MinEmitter::excluding( $ident, array_merge( array( $order_range ), $star_ranges ) ),
			array_filter( array(
				array( $order_node->get_start(), $order_node->get_length(), 'ORDER BY ' . implode( ', ', $mapped ) ),
				array( $list_end, 0, ', ' . implode( ', ', $hidden ) . ( '' !== $window ? ', ' . $window : '' ) ),
				$distinct_span,
			) ),
			$star_spans,
			$inner_extra
		);
		$inner = MinEmitter::apply_spans( $mysql, $spans );

		$outer = 'SELECT ' . implode( ',', $outer_items ) . ' FROM (' . $inner . ') "__slonik_t"';
		if ( '' !== $window ) {
			$outer .= ' WHERE "__slonik_rn" = 1';
		}
		return $outer . ' ORDER BY ' . implode( ', ', $mapped );
	}

	/**
	 * The aliases of the select items (lowercase alias => true).
	 *
	 * @param string           $mysql Original SQL.
	 * @param array            $items The select items.
	 * @return array<string,true>
	 */
	private static function item_aliases( $mysql, array $items ): array {
		$out = array();
		foreach ( $items as $item ) {
			$alias = $item->get_first_descendant_node( 'select_alias' );
			if ( null === $alias ) {
				continue;
			}
			$text = trim( substr( $mysql, $alias->get_start(), $alias->get_length() ) );
			if ( 0 === stripos( $text, 'AS ' ) ) {
				$text = trim( substr( $text, 3 ) );
			}
			if ( '' === $text ) {
				continue;
			}
			$out[ strtolower( $text ) ] = true;
		}
		return $out;
	}

	/**
	 * Does a select item hold an aggregate call?
	 *
	 * @param \WP_Parser_Node $select The select statement.
	 * @param array           $items  The select items.
	 * @return bool
	 */
	private static function has_aggregate_item( $select, array $items ): bool {
		foreach ( $items as $item ) {
			foreach ( $item->get_descendant_tokens() as $token ) {
				if ( ! $token instanceof \WP_MySQL_Token ) {
					continue;
				}
				if ( in_array( strtoupper( (string) $token->get_value() ), array( 'SUM', 'COUNT', 'MIN', 'MAX', 'AVG' ), true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * The columns of a star item (the table's columns, quoted).
	 *
	 * @param \WP_Parser_Node $select  The select statement.
	 * @param string          $text    The item text.
	 * @param mixed           $catalog Catalog or null.
	 * @return array<int,string>
	 * @throws TranslationException When the table cannot be resolved.
	 */
	private static function star_columns( $select, $text, $catalog ): array {
		$table = self::star_table( $select, $text );
		if ( null === $table || ! $catalog instanceof Catalog ) {
			throw new TranslationException( 'DISTINCT star needs a resolvable single table and catalog.' );
		}
		$cols = $catalog->columns( $table );
		if ( array() === $cols ) {
			throw new TranslationException( 'DISTINCT star needs known table columns.' );
		}
		$out = array();
		foreach ( $cols as $col ) {
			$out[] = '"' . str_replace( '"', '""', $col ) . '"';
		}
		return $out;
	}
	private static function quote_range( $mysql, $ident_spans, $start, $len ) {		$local = array();
		foreach ( $ident_spans as $span ) {
			if ( $span[0] >= $start && $span[0] + $span[1] <= $start + $len ) {
				$local[] = array( $span[0] - $start, $span[1], $span[2] );
			}
		}
		$text = substr( $mysql, $start, $len );
		usort(
			$local,
			function ( $a, $b ) {
				return $b[0] - $a[0];
			}
		);
		foreach ( $local as $span ) {
			$text = substr( $text, 0, $span[0] ) . $span[2] . substr( $text, $span[0] + $span[1] );
		}
		return $text;
	}

	private static function has_distinct( $select ) {
		$opt = $select->get_first_descendant_node( 'select_options' );
		if ( null === $opt ) {
			return false;
		}
		foreach ( $opt->get_descendant_tokens() as $token ) {
			if ( $token instanceof \WP_MySQL_Token && 'DISTINCT' === strtoupper( $token->get_value() ) ) {
				return true;
			}
		}
		return false;
	}

	private static function order_direction( $order_expr ) {		$dir = $order_expr->get_first_descendant_node( 'ordering_direction' );
		if ( null === $dir ) {
			return '';
		}
		$tok = $dir->get_first_descendant_token();
		return null !== $tok ? strtoupper( $tok->get_value() ) : '';
	}

	/**
	 * Append NULLS FIRST/LAST to ORDER BY items (MySQL null ordering).
	 *
	 * Appends unless the column is proven NOT NULL everywhere; unknown
	 * columns get the clause (harmless no-op on non-nullable data).
	 *
	 * @param \WP_Parser_Node $select  select_stmt node.
	 * @param string          $mysql   Original SQL.
	 * @param Catalog|null    $catalog Catalog or null.
	 * @return array List of spans.
	 */
	public static function nulls_spans( $select, $mysql, $catalog ) {
		$spans = array();
		foreach ( $select->get_descendant_nodes( 'order_expr' ) as $order ) {
			$text = self::nulls_suffix( $order, $catalog );
			if ( '' === $text ) {
				continue;
			}
			$spans[] = array( $order->get_start() + $order->get_length(), 0, ' ' . $text );
		}
		return $spans;
	}

	public static function nulls_suffix( $order_expr, $catalog ) {
		$expr = $order_expr->get_first_descendant_node( 'expr' );
		if ( null === $expr ) {
			return '';
		}
		$col = null;
		$names = self::ident_names( $expr );
		if ( array() !== $names ) {
			$keys = array_keys( $names );
			$col  = $names[ $keys[0] ];
		}
		if ( null !== $col && $catalog instanceof Catalog && false === $catalog->nullableAny( $col ) ) {
			return '';
		}
		$dir = self::order_direction( $order_expr );
		return 'DESC' === $dir ? 'NULLS LAST' : 'NULLS FIRST';
	}

	private static function outer_items( $mysql, $select, $items, $item_quoted, $catalog ) {
		$out = array();
		foreach ( $items as $i => $item ) {
			$text = substr( $mysql, $item->get_start(), $item->get_length() );
			if ( ! self::is_star_item( $item, $text ) ) {
				$out[] = $item_quoted[ $i ];
				continue;
			}
			$table = self::star_table( $select, $text );
			if ( null === $table || ! $catalog instanceof Catalog ) {
				throw new TranslationException( 'DISTINCT star needs a resolvable single table and catalog.' );
			}
			$cols = $catalog->columns( $table );
			if ( array() === $cols ) {
				throw new TranslationException( 'DISTINCT star needs known table columns.' );
			}
			foreach ( $cols as $col ) {
				$out[] = '"' . str_replace( '"', '""', $col ) . '"';
			}
		}
		return $out;
	}

	private static function is_star_item( $item, $text ) {
		if ( '*' === trim( $text ) ) {
			return true;
		}
		$toks = array();
		foreach ( $item->get_descendant_tokens() as $token ) {
			if ( $token instanceof \WP_MySQL_Token ) {
				$toks[] = $token;
			}
		}
		$count = count( $toks );
		if ( $count < 3 ) {
			return false;
		}
		$last = $toks[ $count - 1 ];
		$dot  = $toks[ $count - 2 ];
		if ( 'MULT_OPERATOR' !== $last->get_name() || 'DOT_SYMBOL' !== $dot->get_name() ) {
			return false;
		}
		$first = $toks[0];
		return WP_MySQL_Lexer::BACK_TICK_QUOTED_ID === $first->id
			|| WP_MySQL_Lexer::IDENTIFIER === $first->id
			|| 1 === preg_match( '/^[A-Za-z_][A-Za-z0-9_$]*$/', (string) $first->get_value() );
	}

	private static function star_table( $select, $item_text ) {
		$from = $select->get_first_descendant_node( 'from_clause' );
		if ( null === $from ) {
			return null;
		}
		$idents = $from->get_descendant_nodes( 'table_ident' );
		$dot    = strpos( $item_text, '.' );
		if ( false === $dot ) {
			if ( 1 !== count( $idents ) ) {
				return null;
			}
			$parts = self::ident_parts( $idents[0] );
			return $parts[0];
		}
		$prefix = strtolower( trim( substr( $item_text, 0, $dot ), " \t\n\r\"`" ) );
		$match  = null;
		foreach ( $idents as $ident_node ) {
			$parts = self::ident_parts( $ident_node );
			if ( strtolower( $parts[0] ) === $prefix || strtolower( $parts[1] ) === $prefix ) {
				if ( null !== $match ) {
					return null;
				}
				$match = $parts[0];
			}
		}
		return $match;
	}

	private static function ident_parts( $table_ident ) {
		// NOTE (M3c): AS aliases live outside table_ident in this grammar, so
		// alias-qualified stars (p.*) throw in star_table; extend here with
		// table_reference shapes when JOIN scoping lands.
		$names = array();
		foreach ( $table_ident->get_descendant_tokens() as $token ) {
			if ( ! $token instanceof \WP_MySQL_Token ) {
				continue;
			}
			if ( WP_MySQL_Lexer::BACK_TICK_QUOTED_ID === $token->id || WP_MySQL_Lexer::IDENTIFIER === $token->id ) {
				$names[] = $token->get_value();
			}
		}
		if ( array() === $names ) {
			return array( '', '' );
		}
		return array( $names[0], end( $names ) );
	}

	/**
	 * CAST(x AS CHAR|VARCHAR|TEXT) => x::text (docs/05 R-052).
	 *
	 * PostgreSQL reads CHAR as char(1): the cast would silently truncate
	 * ('123' -> '1'). The rewrite drops the CAST() wrapper and the type
	 * and casts to text instead.
	 *
	 * @param \WP_Parser_Node $stmt  Statement node.
	 * @param string          $mysql Original SQL.
	 * @return array List of [start, length, replacement] spans.
	 */
	public static function cast_as_text_spans( $stmt, $mysql ) {
		$spans     = array();
		$mysql     = (string) $mysql;
		$text_types = array( 'CHAR', 'CHARACTER', 'VARCHAR', 'TEXT', 'NCHAR', 'NVARCHAR' );
		$tokens    = array();
		foreach ( $stmt->get_descendant_tokens() as $token ) {
			$tokens[] = $token;
		}
		foreach ( $stmt->get_descendant_nodes( 'cast_type' ) as $type ) {
			$type_tok = $type->get_first_descendant_token();
			if ( null === $type_tok || ! in_array( strtoupper( (string) $type_tok->get_value() ), $text_types, true ) ) {
				continue;
			}
			$type_start = $type->get_start();
			$type_end   = $type->get_start() + $type->get_length();
			$as_tok     = null;
			$cast_tok   = null;
			foreach ( $tokens as $token ) {
				if ( 'AS' === strtoupper( (string) $token->get_value() ) && $token->start < $type_start ) {
					$as_tok = $token;
				}
			}
			if ( null !== $as_tok ) {
				foreach ( $tokens as $token ) {
					if ( 'CAST' === strtoupper( (string) $token->get_value() ) && $token->start < $as_tok->start ) {
						$cast_tok = $token;
					}
				}
			}
			if ( null === $as_tok || null === $cast_tok ) {
				continue;
			}
			$open = strpos( $mysql, '(', $cast_tok->start + $cast_tok->length );
			$close = strpos( $mysql, ')', $type_end );
			if ( false === $open || false === $close || $open > $as_tok->start || $close < $type_end ) {
				continue;
			}
			// The whitespace between the expression and AS belongs to the
			// removed text, otherwise "x AS CHAR" leaves a stray space.
			$as_start = $as_tok->start;
			while ( $as_start > 0 && ( ' ' === $mysql[ $as_start - 1 ] || "\t" === $mysql[ $as_start - 1 ] ) ) {
				--$as_start;
			}
			$spans[] = array( $cast_tok->start, $open - $cast_tok->start + 1, '' );
			$spans[] = array( $as_start, $type_end - $as_start, '::text' );
			$spans[] = array( $close, 1, '' );
		}
		return $spans;
	}

	/**
	 * Lower() comparison spans for the degraded case-sensitivity mode.
	 *
	 * Without citext a text comparison has to be rewritten, so that
	 * `user_login = 'Admin'` finds the row MySQL would find (audit D-02).
	 *
	 * @param \WP_Parser_Node $ast          Statement node.
	 * @param array           $text_columns Lowercase column name => true for text columns.
	 * @return array List of [start, length, replacement] spans.
	 */
	/**
	 * Spans for a multi-table DELETE.
	 *
	 * MySQL deletes the rows of every alias in the list; PostgreSQL has no
	 * such statement. WordPress core uses one form (the same table twice,
	 * for the transient cleanup), which folds into a single-table DELETE
	 * with the aliases replaced by the table name. A delete across
	 * different tables is refused instead of guessed.
	 *
	 * @param \WP_Parser_Node $stmt   delete_stmt node.
	 * @param array           $colmap Lowercase name => canonical spelling.
	 * @return array List of [start, length, replacement] spans.
	 * @throws TranslationException For a delete across different tables.
	 */
	public static function multi_table_delete_spans( $stmt, array $colmap = array() ) {
		$targets = $stmt->get_first_descendant_node( 'table_alias_ref_list' );
		if ( null === $targets ) {
			return array();
		}
		$refs = $stmt->get_first_descendant_node( 'table_reference_list' );
		if ( null === $refs ) {
			throw new TranslationException( 'Multi-table DELETE without a table list.' );
		}
		$alias_to_table = array();
		foreach ( $refs->get_descendant_nodes( 'table_reference' ) as $ref ) {
			$ident = $ref->get_first_descendant_node( 'table_ident' );
			$tok   = null !== $ident ? $ident->get_first_descendant_token() : null;
			$table = null !== $tok ? (string) $tok->get_value() : '';
			$alias = '';
			$alias_node = $ref->get_first_descendant_node( 'opt_table_alias' );
			if ( null !== $alias_node ) {
				$atok = $alias_node->get_first_descendant_token();
				$alias = null !== $atok ? (string) $atok->get_value() : '';
			}
			$alias_to_table[ strtolower( '' !== $alias ? $alias : $table ) ] = $table;
		}
		$target_tables = array();
		foreach ( $targets->get_descendant_nodes( 'table_ident_opt_wild' ) as $target ) {
			$tok = $target->get_first_descendant_token();
			if ( null === $tok ) {
				continue;
			}
			$alias = strtolower( (string) $tok->get_value() );
			if ( ! isset( $alias_to_table[ $alias ] ) ) {
				throw new TranslationException( 'Multi-table DELETE with an unknown target: ' . $alias );
			}
			$target_tables[ strtolower( $alias_to_table[ $alias ] ) ] = true;
		}
		if ( 1 !== count( $target_tables ) ) {
			throw new TranslationException(
				'Multi-table DELETE across different tables is not supported; use one table per statement.'
			);
		}
		$table  = (string) array_key_first( $target_tables );
		$quoted = '"' . str_replace( '"', '""', isset( $colmap[ strtolower( $table ) ] ) ? $colmap[ strtolower( $table ) ] : $table ) . '"';

		$spans = array(
			// The target list disappears (with the space before it):
			// PostgreSQL deletes from one table.
			array( $targets->get_start() - 1, $targets->get_length() + 1, '' ),
			// The table list becomes the single table.
			array( $refs->get_start(), $refs->get_length(), $quoted ),
		);
		// The aliases in the condition become the table name.
		$from_start = $refs->get_start();
		$from_end   = $refs->get_start() + $refs->get_length();
		$target_start = $targets->get_start();
		$target_end   = $targets->get_start() + $targets->get_length();
		foreach ( $stmt->get_descendant_tokens() as $token ) {
			if ( ! $token instanceof \WP_MySQL_Token ) {
				continue;
			}
			if ( WP_MySQL_Lexer::IDENTIFIER !== $token->id && WP_MySQL_Lexer::BACK_TICK_QUOTED_ID !== $token->id ) {
				continue;
			}
			if ( $token->start >= $from_start && $token->start < $from_end ) {
				continue; // The alias definitions are replaced with the table.
			}
			if ( $token->start >= $target_start && $token->start < $target_end ) {
				continue; // The target list is dropped as a whole.
			}
			$alias = strtolower( (string) $token->get_value() );
			if ( ! isset( $alias_to_table[ $alias ] ) ) {
				continue;
			}
			$spans[] = array( $token->start, $token->length, $quoted );
		}
		return $spans;
	}
	/**
	 * Spans that make a mixed-type comparison work (docs/05 S-01).
	 *
	 * MySQL compares a text column with a number numerically; PostgreSQL
	 * refuses it ("operator does not exist: character varying < integer").
	 * The literal becomes an unknown-type literal, so PostgreSQL coerces
	 * it to the column type. The order of numeric strings is the same as
	 * the order of the numbers, which is what the comparison needs.
	 *
	 * @param \WP_Parser_Node $ast          Statement node.
	 * @param array           $text_columns Lowercase column name => true.
	 * @return array List of [start, length, replacement] spans.
	 */
	/**
	 * The tables the statement names, for the comparison classification:
	 * FROM list for selects/deletes, the target table for updates/inserts
	 * (audit D-72: the same column name can carry a different type in
	 * another table, so a name-level lookup sees too much).
	 *
	 * @param \WP_Parser_Node $stmt Statement node.
	 * @return array<string,true> Lowercase table name => true.
	 */
	public static function stmt_tables( $stmt ) {
		$tables = array();
		$refs   = $stmt->get_first_descendant_node( 'table_reference_list' );
		if ( null !== $refs ) {
			foreach ( $refs->get_descendant_nodes( 'table_reference' ) as $ref ) {
				$ident = $ref->get_first_descendant_node( 'table_ident' );
				$tok   = null !== $ident ? $ident->get_first_descendant_token() : null;
				if ( null !== $tok ) {
					$tables[ strtolower( (string) $tok->get_value() ) ] = true;
				}
			}
		}
		if ( array() === $tables ) {
			// The DML target: UPDATE/INSERT name the table without a
			// table_reference list (the grammar puts table_ident directly
			// in the statement).
			$ident = $stmt->get_first_descendant_node( 'table_ident' );
			$tok   = null !== $ident ? $ident->get_first_descendant_token() : null;
			if ( null !== $tok ) {
				$tables[ strtolower( (string) $tok->get_value() ) ] = true;
			}
		}
		return $tables;
	}

	/**
	 * The column classification for the compare rewrites, scoped to the
	 * statement: when the statement names exactly one table the columns
	 * resolve against it; more than one table falls back to the name-
	 * level view. Returns [text columns, known non-text columns]
	 * (audit D-72).
	 *
	 * @param Catalog|null    $catalog Catalog or null.
	 * @param \WP_Parser_Node $stmt    Statement node.
	 * @return array<int,array<string,true>>
	 */
	public static function compare_context( $catalog, $stmt ) {
		if ( ! $catalog instanceof Catalog ) {
			return array( array(), array() );
		}
		$tables = self::stmt_tables( $stmt );
		if ( 1 !== count( $tables ) ) {
			// Name-level fallback: every table's columns, first spelling
			// wins. The type of the first table in the catalog is
			// authoritative for the name.
			$text = array();
			foreach ( $catalog->colmap() as $lower => $stored ) {
				$type = strtolower( (string) $catalog->coltype( $lower ) );
				if ( self::is_text_pgtype( $type ) ) {
					$text[ $lower ] = true;
				}
			}
			return array( $text, array() );
		}
		$table = (string) array_key_first( $tables );
		$text  = array();
		$known = array();
		foreach ( $catalog->columns( $table ) as $lower ) {
			$pg = strtolower( (string) $catalog->pgType( $table, $lower ) );
			if ( '' === $pg ) {
				continue;
			}
			if ( self::is_text_pgtype( $pg ) ) {
				$text[ $lower ] = true;
				continue;
			}
			$known[ $lower ] = true;
		}
		return array( $text, $known );
	}

	/**
	 * Is this PG type binary/listing the case-insensitive text forms the
	 * compare rewrites treat as text?
	 *
	 * @param string $type PG type name, lowercased.
	 * @return bool
	 */
	private static function is_text_pgtype( $type ) {
		return in_array(
			(string) $type,
			array( 'text', 'character varying', 'character', 'citext', 'name' ),
			true
		);
	}

	/**
	 * Quote a number literal that a text column is compared with (S-01).
	 *
	 * A text column compared with `5` fails in PostgreSQL with a missing
	 * operator; the unknown-type literal works. The column must be a
	 * known text column (it may be keyword-spelled: the classification
	 * reads the grammar, not the token kind).
	 *
	 * @param \WP_Parser_Node $ast        Root statement node.
	 * @param array           $text_columns Lowercase column name => true.
	 * @param array           $non_text     Column names known to be
	 *                                      non-text: no quote among them.
	 * @param string          $mysql      Original SQL.
	 * @return array List of spans.
	 */
	public static function type_compare_spans( $ast, array $text_columns = array(), array $non_text = array(), $mysql = '' ) {
		// The redundant cast-rule flavor was removed: two rules emitting
		// different rewrites for one literal overlapped in apply_spans
		// and corrupted the statement (audit D-72).
		$spans = array();
		foreach ( self::comparisons( $ast ) as $comparison ) {
			list( $left, $right ) = $comparison;
			foreach ( array(
				array( $left, $right ),
				array( $right, $left ),
			) as $pair ) {
				list( $colside, $otherside ) = $pair;
				if ( self::side_is_known_non_text( $colside, $non_text ) ) {
					// The column the classification knows is non-text:
					// quoting a number for it would break the type PG
					// gave the column (e.g. a bytea between binaries).
					continue;
				}
				if ( self::is_text_column( $colside, $text_columns ) && self::is_number_literal( $otherside, $mysql ) ) {
					$spans[] = self::quote_literal_span( $otherside, $mysql );
				}
			}
		}
		return $spans;
	}

	/**
	 * Does the operand address a column the classification knows to be
	 * non-text (numbers or plain bytea comparisons)? (audit D-72)
	 *
	 * @param \WP_Parser_Node $node     Operand node.
	 * @param array           $non_text Lowercase column name => true.
	 * @return bool
	 */
	private static function side_is_known_non_text( $node, array $non_text ) {
		if ( array() === $non_text ) {
			return false;
		}
		foreach ( self::ident_names( $node ) as $lower => $unused ) {
			if ( isset( $non_text[ $lower ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Does the operand reference a known text column?
	 *
	 * The lookup is by the grammar's identifier regions, so a keyword-
	 * spelled column (the lexer emits no identifier token for it) is
	 * covered too.
	 *
	 * @param \WP_Parser_Node $node         Operand node.
	 * @param array           $text_columns Lowercase column name => true.
	 * @return bool
	 */
	private static function is_text_column( $node, array $text_columns ) {
		if ( array() === $text_columns ) {
			return false;
		}
		foreach ( self::ident_names( $node ) as $lower => $unused ) {
			if ( isset( $text_columns[ $lower ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Is the operand a plain number literal?
	 *
	 * @param \WP_Parser_Node $node Operand node.
	 * @return bool
	 */
	private static function is_number_literal( $node, $mysql = '' ) {
		// The whole operand must be a number: a call like CONCAT('x', 11)
		// contains digits but is not a literal.
		$text = trim( substr( (string) $mysql, $node->get_start(), $node->get_length() ) );
		if ( '' === $text ) {
			return false;
		}
		return is_numeric( $text );
	}

	/**
	 * Span that turns a number literal into an unknown-type literal.
	 *
	 * @param \WP_Parser_Node $node Operand node.
	 * @return array [start, length, replacement]
	 */
	private static function quote_literal_span( $node, $mysql = '' ) {
		$text = trim( substr( (string) $mysql, $node->get_start(), $node->get_length() ) );
		return array( $node->get_start(), $node->get_length(), "'" . str_replace( "'", "''", $text ) . "'" );
	}
	/**
	 * Comparison spans for the case-insensitive comparison mode: the
	 * operands of a text equality get a lower() wrapper.
	 *
	 * A side is wrapped when the comparison carries a text column, or —
	 * only without a catalog (nothing is known about the column) — when
	 * it holds a string literal, which was the old global rule. A side
	 * the classification knows to be NON-text (an int column, bytea)
	 * never gets one: lower() has no PostgreSQL signature for those
	 * types (audit D-72).
	 *
	 * @param \WP_Parser_Node $ast          Root statement node.
	 * @param array           $text_columns Lowercase text column name => true.
	 * @param array           $non_text     Lowercase known non-text column name => true.
	 * @return array List of [start, length, replacement] spans.
	 */
	public static function ci_compare_spans( $ast, array $text_columns = array(), array $non_text = array() ) {
		$spans      = array();
		$no_catalog = array() === $text_columns && array() === $non_text;
		foreach ( self::comparisons( $ast ) as $comparison ) {
			list( $left, $right ) = $comparison;
			$trigger              = self::side_takes_lower( $left, $text_columns, $no_catalog )
				|| self::side_takes_lower( $right, $text_columns, $no_catalog );
			if ( ! $trigger ) {
				continue;
			}
			foreach ( array( $left, $right ) as $side ) {
				if ( self::side_is_known_non_text( $side, $non_text ) ) {
					continue;
				}
				$spans[] = array( $side->get_start(), 0, 'lower(' );
				$spans[] = array( $side->get_start() + $side->get_length(), 0, ')' );
			}
		}
		return $spans;
	}

	/**
	 * Should this side get the lower() wrapper?
	 *
	 * The side counts when it references a text column, or — only when
	 * no catalog is known at all — when it holds a string literal (the
	 * old global rule for the degraded mode).
	 *
	 * @param \WP_Parser_Node $node         Operand node.
	 * @param array           $text_columns Lowercase text column name => true.
	 * @param bool            $no_catalog   True when nothing is known: literal sides wrap.
	 * @return bool
	 */
	private static function side_takes_lower( $node, array $text_columns, $no_catalog ) {
		if ( self::is_text_column( $node, $text_columns ) ) {
			return true;
		}
		if ( ! $no_catalog ) {
			return false;
		}
		foreach ( $node->get_descendant_tokens() as $token ) {
			if ( $token instanceof \WP_MySQL_Token && self::is_string_token_id( $token->id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The operands of the equality comparisons in a statement.
	 *
	 * @param \WP_Parser_Node $ast Statement node.
	 * @return array<int,array{0:\WP_Parser_Node,1:\WP_Parser_Node}>
	 */
	private static function comparisons( $ast ) {
		$out = array();
		foreach ( $ast->get_descendant_nodes( 'bool_pri' ) as $bit ) {
			$children = $bit->get_children();
			$op_index = null;
			foreach ( $children as $i => $child ) {
				if ( is_object( $child ) && ! $child instanceof \WP_Parser_Node ) {
					if ( '=' === trim( (string) $child->get_value() ) ) {
						$op_index = $i;
						break;
					}
					continue;
				}
				if ( is_object( $child ) && $child instanceof \WP_Parser_Node && 'comp_op' === $child->rule_name ) {
					$op_index = $i;
					break;
				}
			}
			if ( null === $op_index ) {
				continue;
			}
			$left  = null;
			$right = null;
			foreach ( $children as $i => $child ) {
				if ( ! is_object( $child ) || ! $child instanceof \WP_Parser_Node ) {
					continue;
				}
				if ( $i < $op_index && null === $left ) {
					$left = $child;
					continue;
				}
				if ( $i > $op_index && null === $right ) {
					$right = $child;
				}
			}
			if ( null === $left || null === $right ) {
				continue;
			}
			$out[] = array( $left, $right );
		}
		return $out;
	}

	/**
	 * CASE result-arm unification (audit D-77).
	 *
	 * PostgreSQL refuses a CASE whose result arms do not share one type
	 * ("CASE types text and bytea cannot be matched", "CASE types
	 * integer and text cannot be matched"); MySQL silently mixes them
	 * and Wordfence's block listing sorts a text column with a bytea
	 * column and an integer sentinel through one CASE. When the
	 * statement names one table and an arm is one of its columns (or a
	 * plain number), a bytea arm that coexists with any other known arm
	 * makes the whole group textual: the bytea arm goes out as
	 * encode(arm,'hex') — the byte order and the sort survive — and a
	 * number arm gets ::text (MySQL coerces the arm to its string
	 * form). Arms that already unify (all text, all bytea, all number,
	 * unknown literals or calls) and joined statements see no rewrite.
	 *
	 * @param \WP_Parser_Node $ast     Root statement node.
	 * @param string          $mysql   Original SQL.
	 * @param Catalog|null    $catalog Schema catalog.
	 * @param \WP_Parser_Node $stmt    Statement node.
	 * @return array<int,array{0:int,1:int,2:string}> Rewrite spans.
	 */
	public static function case_arm_spans( $ast, $mysql, $catalog, $stmt ) {
		$spans = array();
		if ( ! $catalog instanceof Catalog || null === $stmt ) {
			return $spans;
		}
		$tables = self::stmt_tables( $stmt );
		if ( 1 !== count( $tables ) ) {
			// lean-ctx ceiling: joins would need alias-aware type
			// resolution (audit D-77); they keep failing loud.
			return $spans;
		}
		$mysql   = (string) $mysql;
		$table   = (string) array_key_first( $tables );
		$kw_case = WP_MySQL_Lexer::KEYWORDS['CASE'];
		$kw_when = WP_MySQL_Lexer::KEYWORDS['WHEN'];
		$kw_then = WP_MySQL_Lexer::KEYWORDS['THEN'];
		$kw_else = WP_MySQL_Lexer::KEYWORDS['ELSE'];
		$kw_end  = WP_MySQL_Lexer::KEYWORDS['END'];

		// One frame per open CASE; WHEN/THEN/ELSE/END tokens belong to
		// the deepest frame, so nested and IF()-emitted CASEs resolve.
		$frames = array();
		foreach ( $ast->get_descendant_tokens() as $token ) {
			if ( ! $token instanceof \WP_MySQL_Token ) {
				continue;
			}
			$id = (int) $token->id;
			if ( $kw_case === $id ) {
				$frames[] = array( 'start' => null, 'arms' => array() );
				continue;
			}
			$open = count( $frames );
			if ( 0 === $open ) {
				continue;
			}
			if ( $kw_then === $id ) {
				$frames[ $open - 1 ]['start'] = (int) $token->start + (int) $token->length;
				continue;
			}
			if ( $kw_when !== $id && $kw_else !== $id && $kw_end !== $id ) {
				continue;
			}
			self::case_arm_record( $mysql, $table, $catalog, $frames[ $open - 1 ], (int) $token->start );
			if ( $kw_else === $id ) {
				$frames[ $open - 1 ]['start'] = (int) $token->start + (int) $token->length;
			}
			if ( $kw_end === $id ) {
					$has_bytea = false;
					$has_other = false;
					$group     = array_pop( $frames );
					foreach ( $group['arms'] as $arm ) {
						if ( 'bytea' === $arm['kind'] ) {
							$has_bytea = true;
						}
						if ( 'text' === $arm['kind'] || 'num' === $arm['kind'] ) {
							$has_other = true;
						}
					}
					if ( $has_bytea && $has_other ) {
						foreach ( $group['arms'] as $arm ) {
							if ( 'bytea' === $arm['kind'] ) {
								$spans[] = array( $arm['start'], 0, 'encode(' );
								$spans[] = array( $arm['end'], 0, ",'hex')" );
							}
							if ( 'num' === $arm['kind'] ) {
								$spans[] = array( $arm['end'], 0, '::text' );
							}
						}
					}
				}
		}
		return $spans;
	}

	/**
	 * Record one result arm of the open CASE (D-77).
	 *
	 * An arm runs from the THEN (or ELSE) it follows to the terminator
	 * keyword before the next; the AST does not structure the arm, so
	 * the region is read off the original SQL. Only a single plain
	 * column name in the statement's table counts — literals, calls and
	 * unknown columns fall out, they unify anyway or fail the same way
	 * they did before.
	 *
	 * @param string       $mysql   Original SQL.
	 * @param string       $table   The statement's table (lowercase).
	 * @param Catalog      $catalog Schema catalog.
	 * @param array        $frame   The scanned CASE group (by ref).
	 * @param int          $offset  Terminator keyword start.
	 * @return void
	 */
	private static function case_arm_record( $mysql, $table, $catalog, array &$frame, $offset ) {
		$begin = isset( $frame['start'] ) ? (int) $frame['start'] : null;
		if ( null === $begin ) {
			return;
		}
		$frame['start'] = null;
		$raw            = substr( (string) $mysql, $begin, $offset - $begin );
		$len            = strlen( (string) $raw );
		$spaces         = ' ' . "\t" . "\r" . "\n";
		$s              = 0;
		while ( $s < $len && false !== strpos( $spaces, $raw[ $s ] ) ) {
			++$s;
		}
		$e = $len;
		while ( $e > $s && false !== strpos( $spaces, $raw[ $e - 1 ] ) ) {
			--$e;
		}
		if ( $e === $s ) {
			return;
		}
		$arm = array( 'start' => $begin + $s, 'end' => $begin + $e );
		$shaped = substr( $raw, $s, $e - $s );
		// A number arm (MySQL's int sentinel like ELSE 9999) takes the
		// textual form beside a bytea arm (audit D-77).
		if ( preg_match( '/^-?[0-9]+(?:\.[0-9]+)?$/', $shaped ) ) {
			$arm['kind'] = 'num';
			$frame['arms'][] = $arm;
			return;
		}
		$name = $shaped;
		if ( strlen( $name ) > 1 && '`' === $name[0] && '`' === substr( $name, -1 ) ) {
			$name = substr( $name, 1, -1 );
		}
		if ( ! preg_match( '/^[A-Za-z0-9_$\x80-\xff]+$/', $name, $m ) ) {
			return;
		}
		$pg = strtolower( (string) $catalog->pgType( $table, strtolower( $m[0] ) ) );
		if ( '' === $pg ) {
			return;
		}
		$arm['kind'] = 'bytea' === $pg ? 'bytea' : ( self::is_text_pgtype( $pg ) ? 'text' : 'number' );
		$frame['arms'][] = $arm;
	}

	public static function operator_spans( $ast ) {
		$spans = array();
		$toks  = array();
		foreach ( $ast->get_descendant_tokens() as $token ) {
			if ( $token instanceof \WP_MySQL_Token ) {
				$toks[] = $token;
			}
		}
		$count = count( $toks );
		for ( $i = 0; $i < $count; ++$i ) {
			$token = $toks[ $i ];
			if ( WP_MySQL_Lexer::BACK_TICK_QUOTED_ID === $token->id || WP_MySQL_Lexer::IDENTIFIER === $token->id ) {
				continue;
			}
			if ( self::is_string_token_id( $token->id ) ) {
				continue;
			}
			$upper = strtoupper( $token->get_value() );
			if ( 'LIKE' === $upper ) {
				$spans[] = array( $token->start, $token->length, 'ILIKE' );
				continue;
			}
			if ( '||' === $token->get_value() ) {
				$spans[] = array( $token->start, $token->length, 'OR' );
				continue;
			}
			if ( 'REGEXP' === $upper || 'RLIKE' === $upper ) {
				$prev = $i > 0 ? $toks[ $i - 1 ] : null;
				$next = isset( $toks[ $i + 1 ] ) ? $toks[ $i + 1 ] : null;
				$is_not = null !== $prev && 'NOT' === strtoupper( $prev->get_value() );
				$is_bin = null !== $next && 'BINARY' === strtoupper( $next->get_value() );
				if ( $is_not && $is_bin ) {
					$spans[] = array( $prev->start, $next->start + $next->length - $prev->start, '!~' );
					++$i;
					continue;
				}
				if ( $is_bin ) {
					$spans[] = array( $token->start, $next->start + $next->length - $token->start, '~' );
					++$i;
					continue;
				}
				if ( $is_not ) {
					$spans[] = array( $prev->start, $token->start + $token->length - $prev->start, '!~*' );
					continue;
				}
				$spans[] = array( $token->start, $token->length, '~*' );
			}
		}
		return $spans;
	}

	private static function is_string_token_id( $id ) {
		// SINGLE_QUOTED_TEXT and DOUBLE_QUOTED_TEXT share id 827 upstream.
		return WP_MySQL_Lexer::SINGLE_QUOTED_TEXT === $id;
	}

	/**
	 * Division rewrite (R-042 DIV, R-043 '/').
	 *
	 * MySQL's '/' always divides exactly (5/2 is 2.5000), PostgreSQL
	 * truncates when both sides are integers; the divisor is cast to
	 * numeric. MySQL's DIV truncates (R-042).
	 *
	 * MySQL returns NULL for a division by zero, PostgreSQL raises an
	 * error: the divisor is guarded with NULLIF.
	 *
	 * The operands are a column, a number or an aggregate call
	 * (SUM(x), COUNT(*)); anything else throws for M4 expression typing.
	 *
	 * @param \WP_Parser_Node $select  select_stmt node.
	 * @param Catalog|null    $catalog Catalog or null.
	 * @return array List of spans.
	 * @throws TranslationException For operands that cannot be typed.
	 */
	public static function div_spans( $select, $catalog, $mysql = '' ) {
		$spans = array();
		$toks  = array();
		foreach ( $select->get_descendant_tokens() as $token ) {
			if ( ! $token instanceof \WP_MySQL_Token ) {
				continue;
			}
			if ( self::is_string_token_id( $token->id ) ) {
				continue;
			}
			$toks[] = $token;
		}
		$names  = self::ident_names( $select );
		$colmap = $catalog instanceof Catalog ? $catalog->colmap() : array();
		$count  = count( $toks );

		// The division operators: '/' (DIV_OPERATOR) and DIV (keyword).
		$ops = array();
		for ( $i = 0; $i < $count; ++$i ) {
			$name = (string) $toks[ $i ]->get_name();
			if ( 'DIV_OPERATOR' === $name ) {
				$ops[ $i ] = true;
			} elseif ( 'DIV' === $name ) {
				$ops[ $i ] = false;
			}
		}
		if ( array() === $ops ) {
			return $spans;
		}

		// Chained division (a/2/2) shares one operand token between two
		// operators: MySQL divides left to right and the scale grows by
		// div_precision_increment per level. The chain becomes one span
		// with a nested expression.
		$chains = array();
		$chain  = array();
		foreach ( array_keys( $ops ) as $index ) {
			if ( array() !== $chain ) {
				$previous  = $chain[ count( $chain ) - 1 ];
				$prev_last = self::right_operand( $toks, $previous, $count );
				$cur_first = self::left_operand( $toks, $index, $count );
				$shared    = ( null !== $prev_last && null !== $cur_first
					&& $toks[ $prev_last[1] ]->start === $toks[ $cur_first[0] ]->start );
				if ( ! $shared ) {
					$chains[] = $chain;
					$chain    = array();
				}
			}
			$chain[] = $index;
		}
		if ( array() !== $chain ) {
			$chains[] = $chain;
		}

		$ranges = array();
		foreach ( $chains as $chain ) {
			$first = self::left_operand( $toks, $chain[0], $count );
			$last  = self::right_operand( $toks, $chain[ count( $chain ) - 1 ], $count );
			if ( null === $first || null === $last ) {
				throw TranslationException::unsupported( 'division', 'Complex division operands need M4 expression typing.' );
			}
			$numeric = true;
			foreach ( $chain as $index ) {
				$l = self::left_operand( $toks, $index, $count );
				$r = self::right_operand( $toks, $index, $count );
				if ( null === $l || null === $r ) {
					throw TranslationException::unsupported( 'division', 'Complex division operands need M4 expression typing.' );
				}
				if ( ! self::is_numeric_operand( $toks, $l, $names, $catalog ) || ! self::is_numeric_operand( $toks, $r, $names, $catalog ) ) {
					$numeric = false;
				}
			}
			if ( ! $numeric ) {
				continue;
			}
			$start = $toks[ $first[0] ]->start;
			$end   = $toks[ $last[1] ]->start + $toks[ $last[1] ]->length;
			foreach ( $ranges as $range ) {
				if ( max( $start, $range[0] ) < min( $end, $range[1] ) ) {
					throw TranslationException::unsupported( 'division', 'Chained division needs M4 expression typing.' );
				}
			}
			$ranges[] = array( $start, $end );

			// The nested expression: MySQL divides left to right, so the
			// first operand is the outer one and every division wraps
			// the previous result.
			$first_left = self::left_operand( $toks, $chain[0], $count );
			$expression = self::operand_text( $toks, $first_left, $names, $colmap, $mysql );
			if ( '' === $expression ) {
				throw TranslationException::unsupported( 'division', 'Complex division operands need M4 expression typing.' );
			}
			foreach ( $chain as $index ) {
				$right      = self::right_operand( $toks, $index, $count );
				$right_text = self::operand_text( $toks, $right, $names, $colmap, $mysql );
				if ( '' === $right_text ) {
					throw TranslationException::unsupported( 'division', 'Complex division operands need M4 expression typing.' );
				}
				$is_slash    = $ops[ $index ];
				$expression = $is_slash
					? 'ROUND(' . $expression . '::numeric / NULLIF(' . $right_text . ', 0), 4)'
					: 'trunc(' . $expression . '::numeric / NULLIF(' . $right_text . ', 0))';
			}
			$spans[] = array(
				$start,
				$end - $start,
				$expression,
			);
		}
		return $spans;
	}

	/**
	 * The aggregate functions: always numeric, and their call is one
	 * operand (SUM(x) / COUNT(*)).
	 *
	 * @param string $name Function name.
	 * @return bool
	 */
	private static function is_aggregate_name( $name ) {
		return in_array( strtoupper( (string) $name ), array( 'SUM', 'COUNT', 'MIN', 'MAX', 'AVG' ), true );
	}

	/**
	 * The operand on the left of the operator at $index.
	 *
	 * @param array $toks  The non-string tokens.
	 * @param int   $index The operator index.
	 * @param int   $count The token count.
	 * @return array|null [first, last] token indexes, or null.
	 */
	private static function left_operand( array $toks, $index, $count ) {
		$last = $index - 1;
		if ( $last < 0 ) {
			return null;
		}
		if ( ')' !== (string) $toks[ $last ]->get_value() ) {
			return array( $last, $last );
		}
		$open = self::matching_open( $toks, $last, $count );
		if ( null === $open ) {
			return null;
		}
		if ( $open - 1 < 0 ) {
			return null;
		}
		$first = $open - 1;
		if ( self::is_aggregate_name( (string) $toks[ $first ]->get_value() ) ) {
			return array( $first, $last );
		}
		// A parenthesised expression: the whole group is the operand.
		return array( $open, $last );
	}

	/**
	 * The operand on the right of the operator at $index.
	 *
	 * @param array $toks  The non-string tokens.
	 * @param int   $index The operator index.
	 * @param int   $count The token count.
	 * @return array|null [first, last] token indexes, or null.
	 */
	private static function right_operand( array $toks, $index, $count ) {
		$first = $index + 1;
		if ( $first >= $count ) {
			return null;
		}
		if ( '(' === (string) $toks[ $first ]->get_value() ) {
			// A parenthesised expression: the whole group is the operand.
			$close = self::matching_close( $toks, $first, $count );
			if ( null === $close ) {
				return null;
			}
			return array( $first, $close );
		}
		if ( ! self::is_aggregate_name( (string) $toks[ $first ]->get_value() ) ) {
			return array( $first, $first );
		}
		$close = self::matching_close( $toks, $first, $count );
		if ( null === $close ) {
			return null;
		}
		return array( $first, $close );
	}

	/**
	 * The '(' that opens the ')' at $index.
	 *
	 * @param array $toks  The non-string tokens.
	 * @param int   $index The ')' index.
	 * @param int   $count The token count.
	 * @return int|null
	 */
	private static function matching_open( array $toks, $index, $count ) {
		$depth = 0;
		for ( $i = $index; $i >= 0; --$i ) {
			$value = (string) $toks[ $i ]->get_value();
			if ( ')' === $value ) {
				++$depth;
			} elseif ( '(' === $value ) {
				--$depth;
				if ( 0 === $depth ) {
					return $i;
				}
			}
		}
		return null;
	}

	/**
	 * The ')' that closes the '(' at $index.
	 *
	 * @param array $toks  The non-string tokens.
	 * @param int   $index The '(' index.
	 * @param int   $count The token count.
	 * @return int|null
	 */
	private static function matching_close( array $toks, $index, $count ) {
		$depth = 0;
		for ( $i = $index; $i < $count; ++$i ) {
			$value = (string) $toks[ $i ]->get_value();
			if ( '(' === $value ) {
				++$depth;
			} elseif ( ')' === $value ) {
				--$depth;
				if ( 0 === $depth ) {
					return $i;
				}
			}
		}
		return null;
	}

	/**
	 * The text of an operand, with the identifiers quoted.
	 *
	 * @param array $toks   The non-string tokens.
	 * @param array $extent [first, last] token indexes.
	 * @param array $names  The identifier names.
	 * @param array $colmap The catalog column map.
	 * @return string The text, or '' when it cannot be built.
	 */
	private static function operand_text( array $toks, array $extent, $names, $colmap, $mysql = '' ) {
		$first = $extent[0];
		$last  = $extent[1];
		if ( $first === $last ) {
			return self::quote_operand( $toks[ $first ], $names, $colmap );
		}
		if ( ! self::is_aggregate_name( (string) $toks[ $first ]->get_value() ) ) {
			// A parenthesised expression: the source text with the
			// identifiers quoted.
			return self::region_text( $toks, $first, $last, $names, $colmap, $mysql );
		}
		// An aggregate: NAME(argument).
		$name = strtoupper( (string) $toks[ $first ]->get_value() );
		$arg  = array();
		// The tokens between the parentheses.
		for ( $i = $first + 2; $i < $last; ++$i ) {
			$arg[] = $toks[ $i ];
		}
		// COUNT(*)
		if ( 1 === count( $arg ) && '*' === (string) $arg[0]->get_value() ) {
			return $name . '(*)';
		}
		// SUM(column) / SUM(DISTINCT column)
		$distinct = false;
		if ( isset( $arg[0] ) && 'DISTINCT' === strtoupper( (string) $arg[0]->get_value() ) ) {
			$distinct = true;
			array_shift( $arg );
		}
		if ( 1 !== count( $arg ) ) {
			return '';
		}
		$quoted = self::quote_operand( $arg[0], $names, $colmap );
		if ( '' === $quoted ) {
			return '';
		}
		return $name . '(' . ( $distinct ? 'DISTINCT ' : '' ) . $quoted . ')';
	}

	/**
	 * The source text of a token range, with the identifiers quoted.
	 *
	 * @param array  $toks   The non-string tokens.
	 * @param int    $from   The first token index.
	 * @param int    $to     The last token index.
	 * @param array  $names  The identifier names.
	 * @param array  $colmap The catalog column map.
	 * @param string $mysql  The original SQL.
	 * @return string
	 */
	private static function region_text( array $toks, $from, $to, $names, $colmap, $mysql = '' ) {
		$start = $toks[ $from ]->start;
		$end   = $toks[ $to ]->start + $toks[ $to ]->length;
		$text  = substr( (string) $mysql, $start, $end - $start );
		for ( $i = $to; $i >= $from; --$i ) {
			$token = $toks[ $i ];
			$value = (string) $token->get_value();
			$lower = strtolower( $value );
			if ( ! isset( $names[ $lower ] ) ) {
				continue;
			}
			$canon  = isset( $colmap[ $lower ] ) ? $colmap[ $lower ] : $value;
			$offset = $token->start - $start;
			$text   = substr( $text, 0, $offset ) . '"' . str_replace( '"', '""', $canon ) . '"'
				. substr( $text, $offset + $token->length );
		}
		return $text;
	}

	/**
	 * Is the parenthesised expression numeric?
	 *
	 * The shallow inference: only the arithmetic operators and the
	 * numeric columns and literals. A string literal or an unknown
	 * token makes it non-numeric (no rewrite).
	 *
	 * @param array  $toks   The non-string tokens.
	 * @param int    $from   The first token index.
	 * @param int    $to     The last token index.
	 * @param array  $names  The identifier names.
	 * @param mixed  $catalog Catalog or null.
	 * @return bool
	 */
	private static function is_numeric_expression( array $toks, $from, $to, $names, $catalog ) {
		$operator = false;
		for ( $i = $from; $i <= $to; ++$i ) {
			$token = $toks[ $i ];
			$value = (string) $token->get_value();
			if ( '(' === $value || ')' === $value ) {
				continue;
			}
			if ( in_array( $value, array( '+', '-', '*' ), true ) ) {
				$operator = true;
				continue;
			}
			$name = $token->get_name();
			if ( 'INT_NUMBER' === $name || 'DECIMAL_NUMBER' === $name ) {
				continue;
			}
			if ( ! self::is_numeric_operand( $toks, array( $i, $i ), $names, $catalog ) ) {
				return false;
			}
		}
		return $operator;
	}

	/**
	 * Is the operand a number (literal, numeric column or aggregate)?
	 *
	 * @param array $toks   The non-string tokens.
	 * @param array $extent [first, last] token indexes.
	 * @param array $names  The identifier names.
	 * @param mixed  $catalog Catalog or null.
	 * @return bool
	 */
	private static function is_numeric_operand( array $toks, array $extent, $names, $catalog ) {
		$first = $extent[0];
		$last  = $extent[1];
		if ( $first !== $last ) {
			if ( self::is_aggregate_name( (string) $toks[ $first ]->get_value() ) ) {
				// An aggregate: always numeric.
				return true;
			}
			// A parenthesised expression: numeric when it only adds,
			// subtracts or multiplies numeric columns and literals.
			return self::is_numeric_expression( $toks, $first, $last, $names, $catalog );
		}
		$token = $toks[ $first ];
		if ( ! $token instanceof \WP_MySQL_Token ) {
			return false;
		}
		$name = $token->get_name();
		if ( 'INT_NUMBER' === $name || 'DECIMAL_NUMBER' === $name ) {
			return true;
		}
		$lower = strtolower( (string) $token->get_value() );
		if ( ! isset( $names[ $lower ] ) ) {
			return false;
		}
		if ( ! $catalog instanceof Catalog ) {
			return false;
		}
		return in_array( $catalog->coltype( $token->get_value() ), array( 'smallint', 'integer', 'bigint', 'numeric', 'decimal' ), true );
	}

	/**
	 * Quote an operand token for PostgreSQL.
	 *
	 * @param \WP_MySQL_Token $token  The token.
	 * @param array           $names  The identifier names.
	 * @param array           $colmap The catalog column map.
	 * @return string
	 */
	private static function quote_operand( $token, $names, $colmap ) {
		$value = (string) $token->get_value();
		$lower = strtolower( $value );
		if ( WP_MySQL_Lexer::BACK_TICK_QUOTED_ID === $token->id || WP_MySQL_Lexer::IDENTIFIER === $token->id || isset( $names[ $lower ] ) ) {
			$canon = isset( $colmap[ $lower ] ) ? $colmap[ $lower ] : $value;
			return '"' . str_replace( '"', '""', $canon ) . '"';
		}
		return $value;
	}
	/**
	 * Advisory-lock function rewrites (GET_LOCK/RELEASE_LOCK).
	 *
	 * Timeout literal 0/absent/non-literal → non-blocking TRY (WP norm is
	 * timeout 0); positive literal → blocking acquire. IS_FREE_LOCK and
	 * IS_USED_LOCK have no session-safe equivalent and throw.
	 *
	 * @param \WP_Parser_Node $select select_stmt node.
	 * @return array List of spans.
	 * @throws TranslationException For unsupported lock functions.
	 */
	public static function lock_spans( $select ) {
		$spans = array();
		$toks  = array();
		foreach ( $select->get_descendant_tokens() as $token ) {
			if ( $token instanceof \WP_MySQL_Token ) {
				$toks[] = $token;
			}
		}
		$count = count( $toks );
		for ( $i = 0; $i < $count; ++$i ) {
			if ( self::is_string_token_id( $toks[ $i ]->id ) || WP_MySQL_Lexer::BACK_TICK_QUOTED_ID === $toks[ $i ]->id ) {
				continue;
			}
			$upper = strtoupper( $toks[ $i ]->get_value() );
			if ( ! in_array( $upper, array( 'GET_LOCK', 'RELEASE_LOCK', 'IS_FREE_LOCK', 'IS_USED_LOCK' ), true ) ) {
				continue;
			}
			$after = isset( $toks[ $i + 1 ] ) ? $toks[ $i + 1 ] : null;
			if ( null === $after || '(' !== self::lock_raw( $after ) ) {
				continue;
			}
			if ( 'IS_FREE_LOCK' === $upper || 'IS_USED_LOCK' === $upper ) {
				throw new TranslationException( $upper . ' has no session-safe equivalent.' );
			}
			$open = isset( $toks[ $i + 1 ] ) ? $toks[ $i + 1 ] : null;
			if ( null === $open || '(' !== self::lock_raw( $open ) ) {
				throw new TranslationException( $upper . ' without argument list.' );
			}
			$depth = 0;
			$end   = null;
			$items = array();
			$cur   = array();
			for ( $j = $i + 1; $j < $count; ++$j ) {
				$raw = self::lock_raw( $toks[ $j ] );
				if ( '(' === $raw ) {
					++$depth;
				}
				if ( ')' === $raw ) {
					--$depth;
					if ( 0 === $depth ) {
						$end = $j;
						break;
					}
				}
				if ( 1 === $depth && ',' === $raw ) {
					$items[] = $cur;
					$cur     = array();
					continue;
				}
				if ( $depth >= 1 ) {
					$cur[] = $j;
				}
			}
			if ( null === $end ) {
				throw new TranslationException( $upper . ' with unbalanced parentheses.' );
			}
			if ( array() !== $cur ) {
				$items[] = $cur;
			}
			$name_tok = self::first_string_arg( $toks, $items );
			if ( null === $name_tok ) {
				throw new TranslationException( $upper . ' needs a literal lock name.' );
			}
			$name = "'" . str_replace( "'", "''", $name_tok->get_value() ) . "'";
			if ( 'GET_LOCK' === $upper ) {
				$wait = self::timeout_arg( $toks, $items );
				if ( null !== $wait && $wait > 0 ) {
					$repl = 'CASE WHEN pg_advisory_lock(hashtext(' . $name . ')) IS NULL THEN 0 ELSE 1 END';
				} else {
					$repl = 'pg_try_advisory_lock(hashtext(' . $name . '))';
				}
			} else {
				$repl = 'pg_advisory_unlock(hashtext(' . $name . '))';
			}
			$spans[] = array( $toks[ $i ]->start, $toks[ $end ]->start + $toks[ $end ]->length - $toks[ $i ]->start, $repl );
			$i = $end;
		}
		return $spans;
	}

	/**
	 * Rewrite spans that turn string and numeric literals into PostgreSQL
	 * parameters ($1, $2 …). Only literal *values* are parameterized:
	 * identifiers, keywords, hex literals and literals inside a rewritten
	 * function call are left alone.
	 *
	 * The decoded values are pushed onto $params in placeholder order, so
	 * the caller can bind them when executing.
	 *
	 * Zero dates (0000-00-00…) are rewritten to the 0001-01-01 sentinel
	 * to match the emitter's CAST-as-text handling (audit D-20/D-64).
	 *
	 * @param \WP_Parser_Node $ast        Statement node.
	 * @param string          $mysql      Original SQL.
	 * @param array           $params     Out: decoded parameter values.
	 * @param array           $call_ranges [start,end) ranges of rewritten calls to skip.
	 * @param array           $binary     Token starts that write into bytea columns.
	 * @return array Rewrite spans ([start, length, '$N']).
	 */
	public static function literal_param_spans( $ast, $mysql, array &$params, array $call_ranges = array(), array $binary = array() ) {
		$spands = array();
		$toks   = array();
		foreach ( $ast->get_descendant_tokens() as $token ) {
			if ( $token instanceof \WP_MySQL_Token ) {
				$toks[] = $token;
			}
		}
		$count = count( $toks );
		for ( $i = 0; $i < $count; ++$i ) {
			$token = $toks[ $i ];
			$start = (int) $token->start;
			if ( self::inside_range( $start, $call_ranges ) || isset( $binary[ $start ] ) ) {
				continue;
			}
			$id = $token->id;
			$is_string = WP_MySQL_Lexer::SINGLE_QUOTED_TEXT === $id;
			$is_number = in_array( $id, array(
				WP_MySQL_Lexer::INT_NUMBER,
				WP_MySQL_Lexer::DECIMAL_NUMBER,
				WP_MySQL_Lexer::LONG_NUMBER,
				WP_MySQL_Lexer::ULONGLONG_NUMBER,
				WP_MySQL_Lexer::FLOAT_NUMBER,
				WP_MySQL_Lexer::BIN_NUMBER,
			), true );
			if ( ! $is_string && ! $is_number ) {
				continue;
			}
			$bytes = (string) $token->get_bytes();
			if ( '' === $bytes ) {
				continue;
			}
			if ( '"' === $bytes[0] ) {
				// Double-quoted string value (MySQL accepts "…" as a
				// string in non-ANSI mode): strip quotes and unescape "".
				$decoded = substr( $bytes, 1, -1 );
				$decoded = str_replace( '""', '"', $decoded );
			} else {
				$decoded = (string) $token->get_value();
				if ( 0 === strpos( $decoded, '0000-00-00' ) ) {
					$decoded = '0001-01-01' . substr( $decoded, 10 );
				}
			}
			$params[] = $decoded;
			$spands[] = array( $start, (int) $token->length, null );
		}
		return $spands;
	}

	/**
	 * Normalize `col IN (v1, v2 …)` / `col NOT IN (v1, v2 …)` into a
	 * single-shape predicate `col = ANY($N::type[])` / `col <> ALL($N::type[])`.
	 *
	 * This collapses all IN-list lengths into one statement shape, so the
	 * prepared-statement cache (M7.2) reuses one plan for every list length.
	 * Only homogeneous literal lists are normalized; mixed types, subqueries
	 * and row lists are left for the ordinary literal parameterization.
	 *
	 * Spans are returned unnumbered ([start, length, null]); the caller
	 * numbers them in source order. The PG array literal string is appended
	 * to $params as a single value. Exclusion ranges (the whole `IN (…)`
	 * text) are returned so literal_param_spans() skips the consumed values.
	 *
	 * @param \WP_Parser_Node $ast   Statement node.
	 * @param string          $mysql Original SQL.
	 * @param array           $params Out: one PG array literal per IN list.
	 * @param array           $binary Token starts that write into bytea columns.
	 * @return array{spans: list<array{0:int,1:int,2:string}>, ranges: list<array{0:int,1:int}>}
	 */
	public static function in_list_spans( $ast, $mysql, array &$params, array $binary = array() ) {
		$spands = array();
		$ranges = array();
		foreach ( $ast->get_descendant_nodes( 'predicate' ) as $pred ) {
			$in_tok         = null;
			$lparen         = null;
			$rparen         = null;
			$paren_after_in = 0;
			foreach ( $pred->get_descendant_tokens() as $tok ) {
				if ( ! $tok instanceof \WP_MySQL_Token ) {
					continue;
				}
				if ( $tok->id === self::IN_TOKEN_ID ) {
					$in_tok = $tok;
				}
				if ( $tok->id === 40 ) { // '('
					if ( null !== $in_tok && (int) $tok->start > (int) $in_tok->start ) {
						++$paren_after_in;
						if ( null === $lparen ) {
							$lparen = $tok;
						}
					}
				}
				if ( $tok->id === 41 ) { // ')'
					if ( null !== $lparen && (int) $tok->start > (int) $lparen->start ) {
						$rparen = $tok;
					}
				}
			}
			if ( null === $in_tok || null === $lparen || null === $rparen || 1 !== $paren_after_in ) {
				// Not a simple `IN (…)`: subquery, row list or nested parens.
				continue;
			}
			$negated = null !== $pred->get_first_descendant_node( 'not' );
			// Collect homogeneous literal values strictly between ( and ).
			$values    = array();
			$value_toks = array();
			$nested    = false;
			foreach ( $pred->get_descendant_tokens() as $tok ) {
				if ( ! $tok instanceof \WP_MySQL_Token ) {
					continue;
				}
				$start = (int) $tok->start;
				if ( $start <= (int) $lparen->start || $start >= (int) $rparen->start ) {
					continue;
				}
				if ( $tok->id === 40 ) { // nested '(' inside the list -> row/subquery
					$nested = true;
					break;
				}
				if ( isset( $binary[ $start ] ) ) {
					continue;
				}
				$id = $tok->id;
				// Only plain literals and commas may appear in a normalizable
				// list: a leading '-' (MINUS), '+', etc. would silently change
				// a value's meaning, so bail out and leave it to literal
				// parameterization.
				if ( $id !== 44
					&& ! in_array( $id, array(
						WP_MySQL_Lexer::SINGLE_QUOTED_TEXT,
						WP_MySQL_Lexer::INT_NUMBER,
						WP_MySQL_Lexer::DECIMAL_NUMBER,
						WP_MySQL_Lexer::LONG_NUMBER,
						WP_MySQL_Lexer::ULONGLONG_NUMBER,
						WP_MySQL_Lexer::FLOAT_NUMBER,
						WP_MySQL_Lexer::BIN_NUMBER,
					), true ) ) {
					$nested = true;
					break;
				}
				$is_string = WP_MySQL_Lexer::SINGLE_QUOTED_TEXT === $id;
				$is_number = in_array( $id, array(
					WP_MySQL_Lexer::INT_NUMBER,
					WP_MySQL_Lexer::DECIMAL_NUMBER,
					WP_MySQL_Lexer::LONG_NUMBER,
					WP_MySQL_Lexer::ULONGLONG_NUMBER,
					WP_MySQL_Lexer::FLOAT_NUMBER,
					WP_MySQL_Lexer::BIN_NUMBER,
				), true );
				if ( ! $is_string && ! $is_number ) {
					continue;
				}
				$bytes = (string) $tok->get_bytes();
				if ( '' === $bytes ) {
					continue;
				}
				if ( '"' === $bytes[0] ) {
					$decoded = substr( $bytes, 1, -1 );
					$decoded = str_replace( '""', '"', $decoded );
				} else {
					$decoded = (string) $tok->get_value();
					if ( 0 === strpos( $decoded, '0000-00-00' ) ) {
						$decoded = '0001-01-01' . substr( $decoded, 10 );
					}
				}
				$values[]   = $decoded;
				$value_toks[] = $tok;
			}
			if ( $nested || array() === $values ) {
				continue;
			}
			$type = self::in_list_pg_type( $value_toks );
			if ( null === $type ) {
				continue; // mixed types: leave to ordinary parameterization
			}
			$params[] = self::pg_array_literal( $values, $type );
			$span_start = $negated ? (int) $pred->get_first_descendant_node( 'not' )->get_start() : (int) $in_tok->start;
			$span_end   = (int) $rparen->start + (int) $rparen->length;
			$op         = $negated ? '<> ALL' : '= ANY';
			$template   = $op . '(__N__::' . $type . '[])';
			$spands[]   = array( $span_start, $span_end - $span_start, $template );
			$ranges[]   = array( $span_start, $span_end );
		}
		return array( 'spans' => $spands, 'ranges' => $ranges );
	}

	/**
	 * Decide the PostgreSQL array element type for a homogeneous IN list.
	 *
	 * @param \WP_MySQL_Token[] $toks Literal tokens of the list.
	 * @return string|null 'int', 'numeric', 'text', or null when mixed.
	 */
	private static function in_list_pg_type( array $toks ): ?string {
		$has_int = $has_decimal = $has_string = false;
		foreach ( $toks as $tok ) {
			$id = $tok->id;
			if ( in_array( $id, array(
				WP_MySQL_Lexer::INT_NUMBER,
				WP_MySQL_Lexer::LONG_NUMBER,
				WP_MySQL_Lexer::ULONGLONG_NUMBER,
				WP_MySQL_Lexer::BIN_NUMBER,
			), true ) ) {
				$has_int = true;
			} elseif ( in_array( $id, array(
				WP_MySQL_Lexer::DECIMAL_NUMBER,
				WP_MySQL_Lexer::FLOAT_NUMBER,
			), true ) ) {
				$has_decimal = true;
			} elseif ( WP_MySQL_Lexer::SINGLE_QUOTED_TEXT === $id ) {
				$has_string = true;
			} else {
				return null;
			}
		}
		$categories = (int) $has_int + (int) $has_decimal + (int) $has_string;
		if ( 1 !== $categories ) {
			return null;
		}
		if ( $has_int ) {
			return 'int';
		}
		if ( $has_decimal ) {
			return 'numeric';
		}
		return 'text';
	}

	/**
	 * Build a PostgreSQL array literal string for typed values.
	 *
	 * @param array $values Decoded literal values.
	 * @param string $type 'int', 'numeric' or 'text'.
	 * @return string PG array literal, e.g. '{1,2,3}' or '{"a","b"}'.
	 */
	private static function pg_array_literal( array $values, $type ): string {
		$parts = array();
		foreach ( $values as $value ) {
			if ( 'text' === $type ) {
				$parts[] = '"' . str_replace( '"', '""', (string) $value ) . '"';
			} else {
				$parts[] = (string) $value;
			}
		}
		return '{' . implode( ',', $parts ) . '}';
	}

	private static function inside_range( int $offset, array $ranges ): bool {
		foreach ( $ranges as $range ) {
			if ( ! is_array( $range ) || ! isset( $range[0], $range[1] ) ) {
				continue;
			}
			if ( $offset >= (int) $range[0] && $offset < (int) $range[1] ) {
				return true;
			}
		}
		return false;
	}

	private static function lock_raw( $token ) {
		return $token->get_bytes();
	}

	private static function first_string_arg( $toks, $items ) {
		if ( array() === $items ) {
			return null;
		}
		foreach ( $items[0] as $j ) {
			$t = $toks[ $j ];
			if ( WP_MySQL_Lexer::SINGLE_QUOTED_TEXT === $t->id || WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT === $t->id ) {
				return $t;
			}
		}
		return null;
	}

	private static function timeout_arg( $toks, $items ) {
		if ( count( $items ) < 2 ) {
			return null;
		}
		foreach ( $items[1] as $j ) {
			$t = $toks[ $j ];
			if ( false !== strpos( $t->get_name(), 'NUMBER' ) && is_numeric( $t->get_value() ) ) {
				return (float) $t->get_value();
			}
		}
		return null;
	}
}
