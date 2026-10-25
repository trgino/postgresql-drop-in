<?php
/**
 * Minimal MySQL→PostgreSQL emitter (M2a thin slice).
 *
 * Token-span surgery on the original SQL: byte-identical output except for
 * rewritten spans (backtick identifiers, double-quoted strings, LIMIT).
 * SELECT/INSERT/UPDATE/DELETE only; anything else throws.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Dialect\Pg;

use Slonik\Sql\MysqlParser;
use Slonik\Sql\PgQuote;
use Slonik\Sql\TranslationException;
use Slonik\Sql\Unsupported;
use WP_MySQL_Lexer;

final class MinEmitter {

	const STATEMENT_RULES = array(
		'select_stmt' => true,
		'insert_stmt' => true,
		'update_stmt' => true,
		'delete_stmt' => true,
	);

	/**
	 * Emit PG SQL for a basic DML statement.
	 *
	 * Bare identifiers (IDENTIFIER tokens) are double-quoted; when $colmap
	 * maps their lowercase form to the canonical stored spelling, that
	 * spelling is used (P-08). Unknown names keep their written case.
	 *
	 * @param string $mysql  MySQL SQL.
	 * @param array  $colmap Lowercase name => canonical stored spelling.
	 * @param array  $binary Token start => true: the literal writes into
	 *                        a bytea column and goes out in PostgreSQL's
	 *                        hex input format (audit D-73).
	 * @param array  $scope  Token start => spelling of the statement's own
	 *                        identifiers (audit D-80). It wins over $colmap.
	 * @return string PG SQL.
	 * @throws TranslationException When unparseable or out of scope.
	 */
	public static function emit( $mysql, $colmap = array(), $drop_clauses = array(), $extra = array(), $ci_mode = '', $binary = array(), $scope = array(), $parametrize = false, array &$params = null ) {
		$ast = MysqlParser::parse( $mysql );
		if ( null === $ast ) {
			throw new TranslationException( 'Unparseable statement.' );
		}
		$simple = $ast->get_first_descendant_node( 'simple_statement' );
		$stmt   = null !== $simple ? $simple->get_first_child_node() : null;
		if ( null === $stmt || ! isset( self::STATEMENT_RULES[ $stmt->rule_name ] ) ) {
			throw new TranslationException( 'Out of scope for minimal emitter.' );
		}
		$dropping_limit = in_array( 'limit_clause', $drop_clauses, true );
		$removals      = array_merge(
			$dropping_limit ? self::limit_spans( $ast, true ) : array(),
			self::drop_spans( $ast, $drop_clauses )
		);
		$ranges = array();
		foreach ( $removals as $span ) {
			$ranges[] = array( $span[0], $span[0] + $span[1] );
		}
		foreach ( $extra as $span ) {
			$ranges[] = array( $span[0], $span[0] + $span[1] );
		}
		$calls = self::function_calls( $ast, (string) $mysql );
		self::reject_unsupported_set_functions( $ast );
		// A rewritten call is replaced as a whole: the identifier spans
		// inside it are dropped, otherwise the inner text would grow and
		// the offsets of the call span would go stale.
		$call_ranges = array();
		foreach ( $calls as $call_start => $call_info ) {
			if ( null === Functions::rewriteMode( $call_info['name'] ) ) {
				continue;
			}
			$call_ranges[] = array( (int) $call_start, (int) $call_info['end'] );
		}
		$spans = array_merge(
			self::excluding( self::ident_spans( $ast, $colmap, (string) $mysql, $calls, $binary, $scope ), array_merge( $ranges, $call_ranges ) ),
			$dropping_limit ? array() : self::limit_spans( $ast, false ),
			self::calc_spans( $ast ),
			$removals,
			// Spans inside a rewritten call are dropped: the call is
			// replaced as a whole and carries them in its own text.
			self::excluding( $extra, $call_ranges ),
			self::call_rewrite_spans( $calls, $ast, $colmap, (string) $mysql, $ci_mode, $scope )
		);
		if ( $parametrize && null !== $params ) {
			// Collect every parameterizable item (IN-list array + individual
			// literals) as (start => value) pairs, then number them $1..$N in
			// source order so placeholder positions match $params order.
			$items        = array();
			$in_values    = array();
			// 1. IN-list normalization: one array param per IN list.
			$in_list = Semantics::in_list_spans( $ast, (string) $mysql, $in_values, $binary );
			foreach ( $in_list['spans'] as $i => $span ) {
				$start      = (int) $span[0];
				$items[ $start ] = array(
					'value'   => $in_values[ $i ] ?? null,
					'len'     => (int) $span[1],
					'template'=> $span[2],
				);
			}
			$in_ranges = $in_list['ranges'];
			// 2. Ordinary literal parameterization, skipping IN-list ranges.
			$lit_params = array();
			$lit_spans  = Semantics::literal_param_spans( $ast, (string) $mysql, $lit_params, array_merge( $call_ranges, $in_ranges ), $binary );
			foreach ( $lit_spans as $i => $span ) {
				$start      = (int) $span[0];
				$items[ $start ] = array(
					'value'   => $lit_params[ $i ] ?? null,
					'len'     => (int) $span[1],
					'template'=> null,
				);
			}
			// 3. Number in source order; rebuild $params to match.
			ksort( $items );
			$params      = array();
			$param_spans = array();
			$index       = 0;
			foreach ( $items as $start => $item ) {
				++$index;
				$params[] = $item['value'];
				$replacement = null === $item['template']
					? '$' . $index
					: str_replace( '__N__', '$' . $index, $item['template'] );
				$param_spans[] = array( $start, $item['len'], $replacement );
			}
			// 4. Never let a parameter span overlap a removal (dropped/call)
			//    span: apply_spans() fails loud on overlapping replacements.
			//    IN-list spans already exclude their own interior (handled by
			//    literal_param_spans via $in_ranges), so do not re-check them
			//    against $in_ranges here.
			$removal = array_merge( $ranges, $call_ranges );
			$kept    = array();
			foreach ( $param_spans as $span ) {
				$s = (int) $span[0];
				$e = $s + (int) $span[1];
				$ok  = true;
				foreach ( $removal as $range ) {
					if ( max( $s, (int) $range[0] ) < min( $e, (int) $range[1] ) ) {
						$ok = false;
						break;
					}
				}
				if ( $ok ) {
					$kept[] = $span;
				}
			}
			$spans = array_merge( $spans, $kept );
		}
		return self::apply_spans( $mysql, $spans );
	}

	/**
	 * Drop spans overlapping any [start, end) range.
	 *
	 * Removal spans swallow inner rewrites; without filtering, stale
	 * offsets corrupt the output (overlapping spans are never valid).
	 * Zero-length insertions anchored inside a removed range (or exactly
	 * on its edge, e.g. NULLS LAST at the end of a dropped ORDER BY)
	 * are dropped too: their anchor text is gone.
	 *
	 * @param array $spans  Lists of [start, length, replacement].
	 * @param array $ranges Lists of [start, end).
	 * @return array Kept spans.
	 */
	public static function excluding( $spans, $ranges ) {
		$kept = array();
		foreach ( $spans as $span ) {
			if ( ! is_array( $span ) || ! isset( $span[0], $span[1] ) ) {
				continue;
			}
			$send    = $span[0] + $span[1];
			$overlap = false;
			foreach ( $ranges as $range ) {
				if ( ! is_array( $range ) || ! isset( $range[0], $range[1] ) ) {
					continue;
				}
				if ( 0 === $span[1] ) {
					if ( $span[0] >= $range[0] && $span[0] <= $range[1] ) {
						$overlap = true;
						break;
					}
					continue;
				}
				if ( max( $span[0], $range[0] ) < min( $send, $range[1] ) ) {
					$overlap = true;
					break;
				}
			}
			if ( ! $overlap ) {
				$kept[] = $span;
			}
		}
		return $kept;
	}

	/**
	 * Identifier quoting spans for an AST.
	 *
	 * @param \WP_Parser_Node $ast    Parsed statement.
	 * @param array           $colmap Lowercase name => canonical stored spelling.
	 * @param string          $mysql  Original SQL (needed for call spans).
	 * @param array           $binary Token start => true: the literal goes
	 *                        out in PostgreSQL's hex input format.
	 * @param array           $scope  Token start => spelling of the statement's own
	 *                        identifiers (audit D-80). It wins over $colmap.
	 * @return array List of [start, length, replacement] spans.
	 */
	public static function ident_spans( $ast, $colmap = array(), $mysql = '', $calls = array(), $binary = array(), $scope = array() ) {
		$spans   = array();
		$regions = array();
		if ( array() === $calls ) {
			$calls = self::function_calls( $ast, (string) $mysql );
		}
		foreach ( array( 'simple_ident', 'simple_ident_nospvar' ) as $rule ) {
			foreach ( $ast->get_descendant_nodes( $rule ) as $si ) {
				$regions[] = array( $si->get_start(), $si->get_start() + $si->get_length() );
			}
		}
		$toks  = array();
		foreach ( $ast->get_descendant_tokens() as $token ) {
			if ( $token instanceof \WP_MySQL_Token ) {
				$toks[] = $token;
			}
		}
		$count = count( $toks );
		for ( $i = 0; $i < $count; ++$i ) {
			$token = $toks[ $i ];
			if ( isset( $calls[ $token->start ] ) ) {
				$spans = array_merge( $spans, self::call_spans( $calls[ $token->start ], $token ) );
				continue;
			}
			if ( WP_MySQL_Lexer::BACK_TICK_QUOTED_ID === $token->id ) {
				// A backticked name keeps its written spelling unless the
				// statement's scope resolved it against the catalog
				// (audit D-80).
				$value = (string) $token->get_value();
				$canon = isset( $scope[ (int) $token->start ] ) ? (string) $scope[ (int) $token->start ] : $value;
				$spans[] = array( $token->start, $token->length, '"' . str_replace( '"', '""', $canon ) . '"' );
				continue;
			}
			if ( WP_MySQL_Lexer::IDENTIFIER === $token->id ) {
				$spans[] = array( $token->start, $token->length, '"' . str_replace( '"', '""', self::spelling( $token, $colmap, $scope ) ) . '"' );
				continue;
			}
			if ( self::in_simple_ident( $token, $regions ) && 1 === preg_match( '/^[A-Za-z_][A-Za-z0-9_$]*$/', (string) $token->get_value() ) ) {
				$spans[] = array( $token->start, $token->length, '"' . str_replace( '"', '""', self::spelling( $token, $colmap, $scope ) ) . '"' );
				continue;
			}
			if ( ! self::is_string_token( $token ) ) {
				if ( WP_MySQL_Lexer::HEX_NUMBER !== $token->id ) {
					continue;
				}
				// A MySQL hex literal (X'…' / 0x…) is a binary string.
				// PostgreSQL reads the same spelling as a bit literal,
				// which cannot be compared with or assigned to a bytea
				// value ("column val is of type bytea but expression is
				// of type bit", audit D-75): the value goes out in the
				// hex input format.
				$literal = PgQuote::bytea_hex( (string) $token->get_value() );
				if ( '' !== $literal ) {
					$spans[] = array( $token->start, $token->length, $literal );
				}
				continue;
			}
			if ( isset( $binary[ (int) $token->start ] ) ) {
				// A bytea column takes the payload in PostgreSQL's hex
				// input format: the raw bytes would end the literal
				// (audit D-73).
				$spans[] = array( $token->start, $token->length, PgQuote::bytea( $token->get_value() ) );
				continue;
			}
			$raw0 = substr( $token->get_bytes(), 0, 1 );
			if ( '"' === $raw0 ) {
				$spans[] = array( $token->start, $token->length, PgQuote::string( $token->get_value() ) );
				continue;
			}
			if ( "'" === $raw0 ) {
				$decoded = $token->get_value();
				if ( 0 === strpos( $decoded, '0000-00-00' ) ) {
					$decoded = '0001-01-01' . substr( $decoded, 10 );
				}
				$requoted = PgQuote::string( $decoded );
				if ( $requoted !== $token->get_bytes() ) {
					$spans[] = array( $token->start, $token->length, $requoted );
				}
			}
		}
		return $spans;
	}

	/**
	 * The spelling of an identifier token.
	 *
	 * The scope of the statement wins over the flat map: the flat map
	 * cannot tell which table a column belongs to (audit D-80).
	 *
	 * @param \WP_MySQL_Token $token  Identifier token.
	 * @param array           $colmap Lowercase name => canonical stored spelling.
	 * @param array           $scope  Token start => spelling.
	 * @return string
	 */
	private static function spelling( $token, $colmap, $scope ) {
		$start = (int) $token->start;
		if ( isset( $scope[ $start ] ) ) {
			return (string) $scope[ $start ];
		}
		$value = (string) $token->get_value();
		$lower = strtolower( $value );
		return isset( $colmap[ $lower ] ) ? (string) $colmap[ $lower ] : $value;
	}

	/**
	 * The set functions (SUM, COUNT, GROUP_CONCAT) are not calls in the
	 * grammar, so the function registry never sees them. The ones
	 * without a PostgreSQL equivalent must fail closed instead of
	 * reaching PostgreSQL as an unknown function.
	 *
	 * @param \WP_Parser_Node $ast Parsed statement.
	 * @return void
	 * @throws TranslationException For a set function without a mapping.
	 */
	private static function reject_unsupported_set_functions( $ast ) {
		foreach ( $ast->get_descendant_nodes( 'set_function_specification' ) as $node ) {
			$token = $node->get_first_descendant_token();
			if ( null === $token ) {
				continue;
			}
			$name = strtoupper( (string) $token->get_value() );
			if ( ! in_array( $name, Unsupported::FUNCTIONS, true ) ) {
				continue;
			}
			throw TranslationException::unsupported(
				$name,
				'Unsupported MySQL function: ' . $name . ' has no PostgreSQL equivalent in WP-Slonik (fail closed).'
			);
		}
	}

	/**
	 * Function calls in an AST, keyed by the offset of the name token.
	 *
	 * A function name is never an identifier: it is mapped to its
	 * PostgreSQL counterpart (audit D-01). Unmapped functions are
	 * rejected before execution.
	 *
	 * @param \WP_Parser_Node $ast   Parsed statement.
	 * @param string          $mysql Original SQL.
	 * @return array<int,array{name:string,open:?int,close:?int,end:int,node:\WP_Parser_Node}>
	 */
	private static function function_calls( $ast, $mysql ) {
		$out = array();
		$rules = array( 'function_call_generic', 'function_call_keyword', 'function_call_nonkeyword', 'function_call_conflict' );
		$nodes = array();
		$nodes = array();
		foreach ( $rules as $rule ) {
			foreach ( $ast->get_descendant_nodes( $rule ) as $node ) {
				$nodes[] = array( $node, $rule );
			}
		}
		foreach ( $nodes as $entry ) {
			list( $node, $rule ) = $entry;
			$tok = null;
			if ( 'function_call_generic' === $rule ) {
				// A generic call keeps its name in an identifier node.
				$name_node = $node->get_first_child_node();
				$tok       = null !== $name_node ? $name_node->get_first_descendant_token() : null;
			}
			if ( null === $tok ) {
				$tok = $node->get_first_child_token();
			}
			if ( null === $tok ) {
				continue;
			}
			$name = strtoupper( (string) $tok->get_value() );
			if ( isset( Functions::HANDLED_ELSEWHERE[ $name ] ) ) {
				continue;
			}
			$open  = null;
			$close = null;
			$pos   = strpos( $mysql, '(', $tok->start + $tok->length );
			if ( false !== $pos ) {
				$open = $pos;
				// Matching close parenthesis (the grammar node length may
				// include trailing whitespace).
				$depth = 0;
				$len   = strlen( $mysql );
				$quote = '';
				for ( $i = $pos; $i < $len; $i++ ) {
					$char = $mysql[ $i ];
					if ( '' !== $quote ) {
						if ( $char === $quote ) {
							$quote = '';
						}
						continue;
					}
					if ( "'" === $char || '"' === $char ) {
						$quote = $char;
						continue;
					}
					if ( '(' === $char ) {
						++$depth;
						continue;
					}
					if ( ')' === $char ) {
						--$depth;
						if ( 0 === $depth ) {
							$close = $i;
							break;
						}
					}
				}
			}
			$out[ $tok->start ] = array(
				'name'  => $name,
				'open'  => $open,
				'close' => $close,
				'end'   => null !== $close ? $close + 1 : $node->get_start() + $node->get_length(),
				'node'  => $node,
			);
		}
		return $out;
	}

	/**
	 * Spans for one function call: the name is replaced by its
	 * PostgreSQL spelling; parentheses are dropped for the spellings
	 * that take none.
	 *
	 * @param array  $call  Call info (name/open/close).
	 * @param object $token Name token.
	 * @return array List of [start, length, replacement] spans.
	 * @throws TranslationException When the function has no mapping.
	 */
	private static function call_spans( array $call, $token ) {
		if ( null !== Functions::rewriteMode( $call['name'] ) ) {
			// The whole call is replaced by the rewrite span.
			return array();
		}
		$pg = Functions::pgName( $call['name'] );
		if ( null === $pg ) {
			throw TranslationException::unsupported(
				strtoupper( (string) $call['name'] ),
				'Unsupported MySQL function: ' . $call['name'] . ' has no PostgreSQL equivalent in WP-Slonik (fail closed).'
			);
		}
		$spans = array( array( $token->start, $token->length, $pg ) );
		if ( ! Functions::takesParens( $pg ) && null !== $call['open'] && null !== $call['close'] ) {
			$spans[] = array( $call['open'], 1, '' );
			$spans[] = array( $call['close'], 1, '' );
		}
		return $spans;
	}

	private static function in_simple_ident( $token, $regions ) {
		foreach ( $regions as $range ) {
			if ( $token->start >= $range[0] && $token->start + $token->length <= $range[1] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * LIMIT spans: rewrite, or removal when dropping.
	 *
	 * @param \WP_Parser_Node $ast  Parsed statement.
	 * @param bool            $drop True to remove LIMIT clauses instead.
	 * @return array List of spans.
	 */
	public static function limit_spans( $ast, $drop = false ) {
		$spans = array();
		foreach ( $ast->get_descendant_nodes( 'limit_clause' ) as $limit ) {
			if ( $drop ) {
				$spans[] = array( $limit->get_start(), $limit->get_length(), '' );
				continue;
			}
			$span = self::limit_span( $limit );
			if ( null !== $span ) {
				$spans[] = $span;
			}
		}
		return $spans;
	}

	/**
	 * Removal spans for whole clause subtrees (e.g. order_clause).
	 *
	 * @param \WP_Parser_Node $ast   Parsed statement.
	 * @param string[]        $rules Rule names to remove.
	 * @return array List of spans.
	 */
	public static function drop_spans( $ast, $rules ) {
		$spans = array();
		foreach ( (array) $rules as $rule ) {
			if ( 'limit_clause' === $rule ) {
				continue;
			}
			foreach ( $ast->get_descendant_nodes( $rule ) as $node ) {
				$spans[] = array( $node->get_start(), $node->get_length(), '' );
			}
		}
		return $spans;
	}

	/**
	 * SQL_CALC_FOUND_ROWS keyword removal spans.
	 *
	 * @param \WP_Parser_Node $ast Parsed statement.
	 * @return array List of spans.
	 */
	public static function calc_spans( $ast ) {
		$spans = array();
		foreach ( $ast->get_descendant_tokens() as $token ) {
			if ( ! $token instanceof \WP_MySQL_Token || self::is_string_token( $token ) ) {
				continue;
			}
			if ( WP_MySQL_Lexer::BACK_TICK_QUOTED_ID === $token->id || WP_MySQL_Lexer::IDENTIFIER === $token->id ) {
				continue;
			}
			if ( 'SQL_CALC_FOUND_ROWS' === strtoupper( $token->get_value() ) ) {
				$spans[] = array( $token->start, $token->length, '' );
			}
		}
		return $spans;
	}

	private static function is_string_token( $token ) {
		return WP_MySQL_Lexer::SINGLE_QUOTED_TEXT === $token->id
			|| WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT === $token->id;
	}

	private static function limit_span( $limit ) {
		$opts_node = $limit->get_first_child_node( 'limit_options' );
		$options   = null !== $opts_node ? $opts_node->get_child_nodes( 'limit_option' ) : $limit->get_child_nodes( 'limit_option' );
		$numbers = array();
		foreach ( $options as $option ) {
			$tok = $option->get_first_child_token();
			if ( null === $tok || ! $tok instanceof \WP_MySQL_Token || ! ctype_digit( $tok->get_value() ) ) {
				throw new TranslationException( 'Non-literal LIMIT is out of scope.' );
			}
			$numbers[] = $tok->get_value();
		}
		$has_comma  = null !== $limit->get_first_descendant_token() && self::node_has_comma( $limit );
		$count_args = count( $numbers );
		if ( 2 === $count_args && $has_comma ) {
			$count = self::is_huge( $numbers[1] ) ? 'ALL' : $numbers[1];
			$text  = 'LIMIT ' . $count . ' OFFSET ' . $numbers[0];
		} elseif ( 2 === $count_args ) {
			if ( ! self::is_huge( $numbers[0] ) ) {
				return null;
			}
			$text = 'LIMIT ALL OFFSET ' . $numbers[1];
		} elseif ( 1 === $count_args && ! $has_comma ) {
			if ( ! self::is_huge( $numbers[0] ) ) {
				return null;
			}
			$text = 'LIMIT ALL';
		} else {
			return null;
		}
		return array( $limit->get_start(), $limit->get_length(), $text );
	}

	private static function is_huge( $digits ) {
		$d = ltrim( (string) $digits, '0' );
		if ( '' === $d ) {
			return false;
		}
		$max = '9223372036854775807';
		if ( strlen( $d ) !== strlen( $max ) ) {
			return strlen( $d ) > strlen( $max );
		}
		return strcmp( $d, $max ) > 0;
	}

	private static function node_has_comma( $node ) {
		foreach ( $node->get_descendant_tokens() as $token ) {
			if ( 'COMMA' === $token->get_name() || 'COMMA_SYMBOL' === $token->get_name() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Apply spans to source SQL (descending offsets).
	 *
	 * @param string $sql   Original SQL.
	 * @param array  $spans Lists of [start, length, replacement].
	 * @return string Rewritten SQL.
	 */
	public static function apply_spans( $sql, $spans ) {		$spans = array_filter( $spans );
		// The zero-length insertions (the degraded mode's lower() wrapper)
		// are applied last, with their position corrected by the length
		// change of the spans that started before them. Applying them
		// first would make the later spans stale, and applying them with
		// the original position would put them in the wrong place
		// (audit D-72).
		$inserts = array();
		$real    = array();
		foreach ( $spans as $span ) {
			if ( 0 === (int) $span[1] ) {
				$inserts[] = $span;
				continue;
			}
			$real[] = $span;
		}
		usort(
			$real,
			function ( $a, $b ) {
				return $a[0] - $b[0];
			}
		);
		// Two REPLACEMENT spans over the same source bytes are a rewriter
		// bug: the second application would reread the already-rewritten
		// text at stale offsets and mangle the output into silently wrong
		// SQL (audit D-72). Fail loud instead.
		$end = -1;
		foreach ( $real as $span ) {
			if ( (int) $span[0] < $end ) {
				throw new TranslationException( 'Internal: overlapping rewrite spans at offset ' . (int) $span[0] . ' (two span rules claim the same text).' );
			}
			$end = (int) $span[0] + (int) $span[1];
		}
		$deltas = array();
		for ( $i = count( $real ) - 1; $i >= 0; --$i ) {
			$span        = $real[ $i ];
			$replacement = $span[2];
			if ( $replacement instanceof \Closure ) {
				// A call rewrite sees the text with its arguments already
				// quoted: the inner spans are applied first.
				$replacement = $replacement( substr( $sql, $span[0], $span[1] ) );
			}
			$deltas[] = array( (int) $span[0], strlen( $replacement ) - (int) $span[1] );
			$sql = substr( $sql, 0, $span[0] ) . $replacement . substr( $sql, $span[0] + $span[1] );
		}
		if ( array() === $inserts ) {
			return $sql;
		}
		usort(
			$inserts,
			function ( $a, $b ) {
				return $b[0] - $a[0];
			}
		);
		foreach ( $inserts as $span ) {
			$shift = 0;
			foreach ( $deltas as $delta ) {
				if ( $delta[0] < (int) $span[0] ) {
					$shift += $delta[1];
				}
			}
			$at  = (int) $span[0] + $shift;
			$sql = substr( $sql, 0, $at ) . $span[2] . substr( $sql, $at );
		}
		return $sql;
	}

	/**
	 * Rewrite spans for functions that need a mapped call.
	 *
	 * The replacement is a closure: when the spans are applied, the
	 * arguments are already quoted, so the rewrite can use them as they
	 * are (audit D-01, docs/05 R-059).
	 *
	 * @param array $calls Function calls (token start => info).
	 * @return array List of [start, length, replacement] spans.
	 */
	private static function call_rewrite_spans( array $calls, $ast, $colmap, $mysql, $ci_mode = '', $scope = array() ) {
		$spans = array();
		foreach ( (array) $calls as $start => $call ) {
			$mode = Functions::rewriteMode( $call['name'] );
			if ( null === $mode ) {
				continue;
			}
			$length = (int) $call['end'] - (int) $start;
			if ( $length <= 0 ) {
				continue;
			}
			$inner_start = (int) $call['open'] + 1;
			$inner_end   = (int) $call['close'];
			$parts       = self::split_args( substr( (string) $mysql, $inner_start, $inner_end - $inner_start ) );
			$idents      = array_merge(
					self::ident_spans( $call['node'], $colmap, (string) $mysql, array(), array(), $scope ),
					self::ci_compare_spans( $call['node'], $ci_mode )
				);
			$args        = array();
			$offset      = 0;
			foreach ( $parts as $part ) {
				$text    = $part['text'];
				$abs     = $inner_start + $part['start'];
				$rel     = array();
				foreach ( $idents as $span ) {
					if ( (int) $span[0] < $abs || (int) $span[0] + (int) $span[1] > $abs + strlen( $text ) ) {
						continue;
					}
					$rel[] = array( (int) $span[0] - $abs, (int) $span[1], $span[2] );
				}
				usort(
					$rel,
					function ( $a, $b ) {
						return $b[0] - $a[0];
					}
				);
				foreach ( $rel as $span ) {
					$text = substr( $text, 0, $span[0] ) . $span[2] . substr( $text, $span[0] + $span[1] );
				}
				$args[] = $text;
				$offset += strlen( $part['text'] ) + 1;
			}
			$spans[] = array(
				(int) $start,
				$length,
				function ( $ignored ) use ( $mode, $args ) {
					return self::build_call_expression( $mode, $args );
				},
			);
		}
		return $spans;
	}
	/**
	 * Comparison spans for the degraded mode, relative to the node.
	 *
	 * @param \WP_Parser_Node $node    Node.
	 * @param string          $ci_mode Case-sensitivity mode.
	 * @return array
	 */
	private static function ci_compare_spans( $node, $ci_mode ) {
		if ( \Slonik\Schema\CiMode::DEGRADED !== $ci_mode ) {
			return array();
		}
		return Semantics::ci_compare_spans( $node );
	}
	/**
	 * The timestamp source of a date-part rewrite.
	 *
	 * MySQL's YEAR(d), DAYOFWEEK() and friends may be called without an
	 * argument; the empty cast would be a syntax error, so the current
	 * timestamp is used instead (audit D-65).
	 *
	 * @param string $first The first argument (may be empty).
	 * @return string
	 */
	private static function timestamp_source( $first ) {
		$first = trim( (string) $first );
		return '' !== $first ? $first . '::timestamp' : 'NOW()::timestamp';
	}

	private static function build_call_expression( $mode, array $args, array $inner = array() ) {
		$first = isset( $args[0] ) ? trim( $args[0] ) : '';
		$second = isset( $args[1] ) ? trim( $args[1] ) : '';
		$third  = isset( $args[2] ) ? trim( $args[2] ) : '';
		// The INTERVAL argument keeps its original text: the unit lives
		// outside the expression node.
		if ( isset( $inner[1] ) && 0 === stripos( trim( $inner[1] ), 'INTERVAL' ) ) {
			$second = trim( $inner[1] );
		}
		switch ( $mode ) {
			case 'extract:year':
				return 'EXTRACT(YEAR FROM ' . self::timestamp_source( $first ) . ')';
			case 'extract:month':
				return 'EXTRACT(MONTH FROM ' . self::timestamp_source( $first ) . ')';
			case 'extract:day':
				return 'EXTRACT(DAY FROM ' . self::timestamp_source( $first ) . ')';
			case 'extract:hour':
				return 'EXTRACT(HOUR FROM ' . self::timestamp_source( $first ) . ')';
			case 'extract:minute':
				return 'EXTRACT(MINUTE FROM ' . self::timestamp_source( $first ) . ')';
			case 'extract:second':
				return 'trunc(EXTRACT(SECOND FROM ' . self::timestamp_source( $first ) . '))::int';
			case 'epoch':
				// UNIX_TIMESTAMP() without an argument is the current time;
				// the empty cast would be a syntax error (audit D-65).
				// NOW() is a timestamptz: the epoch from it is absolute,
				// like MySQL's UNIX_TIMESTAMP().
				return 'trunc(EXTRACT(EPOCH FROM ' . ( '' !== trim( (string) $first ) ? $first . '::timestamp' : 'NOW()' ) . '))::bigint';
			case 'dow':
				return '(EXTRACT(DOW FROM ' . self::timestamp_source( $first ) . ')::int + 1)';
			case 'if':
				return 'CASE WHEN ' . $first . ' THEN ' . $second . ' ELSE ' . $third . ' END';
			case 'position':
				return 'POSITION(' . $first . ' IN ' . $second . ')';
			case 'instr':
				return 'POSITION(' . $second . ' IN ' . $first . ')';
			case 'field':
				return self::field_expression( $args );
			case 'date_format':
				return self::date_format_expression( $args );
			case 'datediff':
				return '(' . $first . '::date - ' . $second . '::date)';
			case 'interval_add':
				return '(' . $first . '::timestamp + ' . self::interval_expression( $second ) . ')';
			case 'interval_sub':
				return '(' . $first . '::timestamp - ' . self::interval_expression( $second ) . ')';
			case 'any_value':
				return 'ANY_VALUE(' . self::typed_argument( $first ) . ')';
		}
		return $first;
	}

	/**
	 * An unknown-type literal (a bare string) has no type in
	 * PostgreSQL, so a polymorphic function cannot take it. The literal
	 * is cast to text; a typed argument is passed through.
	 *
	 * @param string $arg Argument text.
	 * @return string
	 */
	private static function typed_argument( $arg ) {
		$text = trim( (string) $arg );
		if ( strlen( $text ) > 1 && "'" === $text[0] && "'" === substr( $text, -1 ) ) {
			return '(' . $text . ')::text';
		}
		return $text;
	}
	private static function split_args( $inner ) {
		$args  = array();
		$depth = 0;
		$quote = '';
		$buf   = '';
		$begin = 0;
		$len   = strlen( (string) $inner );
		for ( $i = 0; $i < $len; $i++ ) {
			$char = $inner[ $i ];
			if ( '' !== $quote ) {
				$buf .= $char;
				if ( $char === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( "'" === $char || '"' === $char ) {
				$quote = $char;
				$buf  .= $char;
				continue;
			}
			if ( '(' === $char ) {
				++$depth;
			} elseif ( ')' === $char ) {
				--$depth;
			} elseif ( ',' === $char && 0 === $depth ) {
				$args[] = self::argument_part( $buf, $begin );
			$buf    = '';
			$begin  = $i + 1;
				continue;
			}
			$buf .= $char;
		}
		$trimmed = trim( $buf );
		if ( '' !== $trimmed || array() !== $args ) {
			$args[] = self::argument_part( $buf, $begin );
		}
		return $args;
	}

	/**
	 * One argument: its text and the offset it starts at.
	 *
	 * @param string $buf   Raw argument text.
	 * @param int    $begin Offset of the raw text.
	 * @return array{text:string,start:int}
	 */
	private static function argument_part( $buf, $begin ) {
		$raw  = (string) $buf;
		$text = trim( $raw );
		$lead = strlen( $raw ) - strlen( ltrim( $raw ) );
		return array( 'text' => $text, 'start' => (int) $begin + $lead );
	}

	/**
	 * FIELD(value, a, b, ...) → the 1-based index of the match, else 0.
	 *
	 * @param array $args Arguments.
	 * @return string
	 */
	private static function field_expression( array $args ) {
		$value = isset( $args[0] ) ? $args[0] : '';
		$out   = 'CASE';
		$index = 1;
		for ( $i = 1; $i < count( $args ); $i++ ) {
			$out .= ' WHEN ' . $value . ' = ' . $args[ $i ] . ' THEN ' . $index;
			++$index;
		}
		return $out . ' ELSE 0 END';
	}

	/**
	 * DATE_FORMAT(date, format) → to_char() with the converted pattern.
	 *
	 * @param array $args Arguments (the format is a literal).
	 * @return string
	 */
	private static function date_format_expression( array $args ) {
		$date   = isset( $args[0] ) ? trim( $args[0] ) : '';
		$format = isset( $args[1] ) ? trim( $args[1] ) : "''";
		$raw    = $format;
		if ( strlen( $raw ) >= 2 && "'" === $raw[0] && "'" === substr( $raw, -1 ) ) {
			$raw = str_replace( "''", "'", substr( $raw, 1, -1 ) );
		}
		return DateFormat::to_pg( $raw, $date );
	}

	/**
	 * INTERVAL 1 DAY → INTERVAL '1 day'.
	 *
	 * @param string $arg Interval argument.
	 * @return string
	 */
	private static function interval_expression( $arg ) {
		$arg = trim( (string) $arg );
		if ( 0 === stripos( $arg, 'INTERVAL' ) ) {
			$rest = trim( substr( $arg, 8 ) );
			return "INTERVAL '" . str_replace( "'", "''", $rest ) . "'";
		}
		return $arg;
	}
}