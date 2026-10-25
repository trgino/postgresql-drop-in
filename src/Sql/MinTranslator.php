<?php
/**
 * Minimal statement dispatcher (M2a thin slice).
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Sql;

use Slonik\Dialect\Pg\IdentScope;
use Slonik\Dialect\Pg\MinEmitter;
use Slonik\Dialect\Pg\Semantics;
use Slonik\Schema\Catalog;
use Slonik\Schema\MinAlter;
use Slonik\Schema\MinDdl;
use Slonik\Schema\MinInfo;
use Slonik\Schema\MinShow;
use WP_MySQL_Lexer;

final class MinTranslator {

	/**
	 * @param string        $mysql   MySQL SQL.
	 * @param Catalog|null  $catalog Schema catalog (required for ODKU/REPLACE targets).
	 * @return ExecutionPlan
	 * @throws TranslationException When unparseable or out of scope.
	 */
	public static function translate( $mysql, $catalog = null, array $options = array() ) {
		$ci_mode = isset( $options['ci_mode'] ) ? (string) $options['ci_mode'] : '';
		// Parameterization (M7.2): opt-in, enabled by the runtime so the
		// translator stays neutral for callers that only need the SQL.
		$parametrize = isset( $options['parametrize'] ) && $options['parametrize'];
		$ast = MysqlParser::parse( $mysql );
		if ( null === $ast ) {
			throw new TranslationException( 'Unparseable statement.' );
		}
		$simple = $ast->get_first_descendant_node( 'simple_statement' );
		$stmt   = null !== $simple ? $simple->get_first_child_node() : null;
		$rule   = null !== $stmt ? $stmt->rule_name : '';
		$binary = array();
		if ( in_array( $rule, array( 'insert_stmt', 'update_stmt', 'delete_stmt', 'replace_stmt', 'select_stmt' ), true ) ) {
			// The literals that write into a bytea column are collected
			// first: they carry bytes a text literal cannot hold
			// (audit D-73).
			$binary = Semantics::bytea_literals( $stmt, (string) $mysql, $catalog );
			self::assert_supported( $stmt, $mysql, $binary );
		}
		if ( 'set' === $rule ) {
			$txn = self::autocommit_sql( $stmt );
			if ( null !== $txn ) {
				return new ExecutionPlan( ExecutionPlan::KIND_TXN, array( new PlanStep( $txn, PlanStep::MODE_EXEC ) ) );
			}
			return new ExecutionPlan( ExecutionPlan::KIND_NOOP );
		}
		if ( in_array( $rule, array( 'start', 'begin', 'commit', 'rollback' ), true ) ) {
			$map = array( 'start' => 'BEGIN', 'begin' => 'BEGIN', 'commit' => 'COMMIT', 'rollback' => 'ROLLBACK' );
			return new ExecutionPlan( ExecutionPlan::KIND_TXN, array( new PlanStep( $map[ $rule ], PlanStep::MODE_EXEC ) ) );
		}
		if ( in_array( $rule, array( 'show_tables_stmt', 'describe_stmt', 'show_columns_stmt', 'show_keys_stmt', 'show_variables_stmt' ), true ) ) {
			$steps = array();
			foreach ( MinShow::translate( $mysql, $catalog ) as $statement ) {
				$steps[] = new PlanStep( $statement, PlanStep::MODE_QUERY );
			}
			return new ExecutionPlan( ExecutionPlan::KIND_SHOW, $steps );
		}
		if ( in_array( $rule, array( 'create_table_stmt', 'drop_table_stmt', 'truncate_stmt' ), true ) ) {
			$steps = array();
			foreach ( MinDdl::translate( $mysql, $options ) as $statement ) {
				$steps[] = new PlanStep( $statement, PlanStep::MODE_EXEC );
			}
			return new ExecutionPlan( ExecutionPlan::KIND_DDL, $steps );
		}
		if ( 'alter_table_stmt' === $rule ) {
			$steps = array();
			foreach ( MinAlter::translate( $mysql, $options ) as $statement ) {
				$steps[] = new PlanStep( $statement, PlanStep::MODE_EXEC );
			}
			return new ExecutionPlan( ExecutionPlan::KIND_DDL, $steps );
		}
		if ( 'select_stmt' === $rule && MinInfo::handles( $stmt ) ) {
			$steps = array();
			foreach ( MinInfo::translate( $mysql, $catalog ) as $statement ) {
				$steps[] = new PlanStep( $statement, PlanStep::MODE_QUERY );
			}
			return new ExecutionPlan( ExecutionPlan::KIND_SELECT, $steps );
		}
		if ( 'select_stmt' === $rule && self::is_engine_probe( $mysql ) ) {
			throw new TranslationException( 'Engine version/database probes need M3 replies.' );
		}
		if ( 'select_stmt' === $rule && self::has_calc( $ast ) ) {
			return self::calc_plan( $mysql, $ast, $stmt, $catalog, $ci_mode );
		}
		if ( 'replace_stmt' === $rule ) {
			return self::replace_plan( $mysql, $ast, $stmt, $catalog, $ci_mode );
		}
		if ( '' === $rule ) {
			$first = $ast->get_first_descendant_token();
			$word  = null !== $first ? strtoupper( $first->get_value() ) : '';
			$map   = array( 'START' => 'BEGIN', 'BEGIN' => 'BEGIN', 'COMMIT' => 'COMMIT', 'ROLLBACK' => 'ROLLBACK' );
			if ( isset( $map[ $word ] ) ) {
				return new ExecutionPlan( ExecutionPlan::KIND_TXN, array( new PlanStep( $map[ $word ], PlanStep::MODE_EXEC ) ) );
			}
		}
		$kinds = array(
			'select_stmt' => ExecutionPlan::KIND_SELECT,
			'insert_stmt' => ExecutionPlan::KIND_INSERT,
			'update_stmt' => ExecutionPlan::KIND_UPDATE,
			'delete_stmt' => ExecutionPlan::KIND_DELETE,
		);
		if ( 'insert_stmt' === $rule && null !== $stmt->get_first_descendant_node( 'opt_insert_update_list' ) ) {
			return self::on_conflict( $mysql, $ast, $stmt, $catalog );
		}
		if ( 'select_stmt' === $rule && ! self::is_engine_probe( $mysql ) && ! self::has_calc( $ast ) ) {
			$colmap = $catalog instanceof Catalog ? $catalog->colmap() : array();
			$scope  = IdentScope::resolve( $stmt, $catalog );
			$ctx    = Semantics::compare_context( $catalog, $stmt );
			$rewritten = Semantics::distinct_rewrite( $mysql, $stmt, $catalog );
			if ( null !== $rewritten ) {
				return new ExecutionPlan( ExecutionPlan::KIND_SELECT, array( new PlanStep( $rewritten, PlanStep::MODE_QUERY ) ) );
			}
			$emit_params = array();
			$sql = MinEmitter::emit(
				$mysql,
				$colmap,
				array(),
				array_merge(
					Semantics::group_spans( $stmt, $mysql, $colmap, $scope ),
					Semantics::nulls_spans( $stmt, $mysql, $catalog ),
					Semantics::cast_as_text_spans( $stmt, $mysql ),
					Semantics::group_concat_spans( $ast, $mysql ),
					Semantics::dual_spans( $ast, $mysql ),
					self::ci_compare_spans( $ast, $ci_mode, $ctx ),
					Semantics::type_compare_spans( $ast, $ctx[0], $ctx[1], $mysql ),
					Semantics::case_arm_spans( $ast, $mysql, $catalog, $stmt ),
					Semantics::operator_spans( $ast ),
					Semantics::lock_spans( $stmt ),
					Semantics::div_spans( $stmt, $catalog, $mysql )
				),
				$ci_mode,
				array(),
				$scope,
				$parametrize,
				$emit_params
			);
			return new ExecutionPlan( ExecutionPlan::KIND_SELECT, array( new PlanStep( $sql, PlanStep::MODE_QUERY, null, $emit_params ) ) );
		}
		if ( isset( $kinds[ $rule ] ) ) {
			$colmap = $catalog instanceof Catalog ? $catalog->colmap() : array();
			$scope  = IdentScope::resolve( $stmt, $catalog );
			$extra  = array();
			if ( 'update_stmt' === $rule || 'delete_stmt' === $rule || 'insert_stmt' === $rule ) {
				$ctx = Semantics::compare_context( $catalog, $stmt );
				$extra = array_merge(
					Semantics::operator_spans( $ast ),
					Semantics::div_spans( $stmt, $catalog, $mysql ),
					Semantics::cast_as_text_spans( $stmt, $mysql ),
					Semantics::dual_spans( $ast, $mysql ),
					Semantics::insert_ignore_spans( $ast, $mysql ),
					Semantics::multi_table_delete_spans( $stmt, $colmap ),
					Semantics::type_compare_spans( $ast, $ctx[0], $ctx[1], $mysql )
				);
			}
			$emit_params = array();
			$plan = new ExecutionPlan( $kinds[ $rule ], array( new PlanStep( MinEmitter::emit( $mysql, $colmap, array(), $extra, $ci_mode, $binary, $scope, $parametrize, $emit_params ), PlanStep::MODE_QUERY, null, $emit_params ) ) );
			if ( 'insert_stmt' === $rule ) {
				$plan->setMeta( array( 'insert' => self::insert_meta( $stmt, $catalog ) ) );
			}
			return $plan;
		}
		throw TranslationException::unsupported( 'out of scope statement', 'Out of scope for minimal translator: ' . $rule );
	}

	/**
	 * Reject MySQL constructs PostgreSQL does not accept (audit D-04).
	 *
	 * Without this guard the statement translates "successfully" and dies
	 * inside PostgreSQL with a generic syntax error. Failing closed keeps
	 * the error actionable and stops the write before it happens.
	 *
	 * @param \WP_Parser_Node $stmt  Statement node.
	 * @param string          $mysql Original SQL.
	 * @param array           $binary Token start => true: the literal
	 *                        writes into a bytea column (audit D-73).
	 * @return void
	 * @throws TranslationException When the construct has no PostgreSQL form.
	 */
	private static function assert_supported( $stmt, $mysql, $binary = array() ) {
		$rule = $stmt->rule_name;
		if ( 'update_stmt' === $rule || 'delete_stmt' === $rule ) {
			if ( null !== $stmt->get_first_descendant_node( 'opt_simple_limit' ) ) {
				throw TranslationException::unsupported(
				strtoupper( $rule ) . ' LIMIT',
				'MySQL ' . strtoupper( $rule ) . ' ... LIMIT has no PostgreSQL equivalent; express the limit in the WHERE clause.'
			);
			}
			$targets = $stmt->get_first_descendant_node( 'table_reference_list' );
			if ( null !== $targets && null !== $targets->get_first_descendant_node( 'joined_table' ) ) {
				throw TranslationException::unsupported(
				'multi-table ' . strtoupper( $rule ),
				'Multi-table ' . strtoupper( $rule ) . ' with JOIN has no PostgreSQL equivalent; rewrite it as a subquery.'
			);
			}
		}
		if ( false !== strpos( (string) $mysql, '@@' ) ) {
			throw TranslationException::unsupported( '@@ system variables', 'MySQL system variables (@@) have no PostgreSQL equivalent.' );
		}
		self::assert_no_nul( $stmt, $binary );
	}

	/**
	 * Reject a NUL byte inside a text value (audit D-64).
	 *
	 * PostgreSQL's text type cannot store a NUL byte, and its parser stops
	 * at the byte: the statement dies with "unterminated quoted string",
	 * which points at the wrong place. The migration removes the byte and
	 * reports it (D-63); the runtime refuses it with the real reason.
	 *
	 * @param \WP_Parser_Node $stmt Statement node.
	 * @param array           $binary Token start => true: the literal goes
	 *                        out in PostgreSQL's hex input format, so its
	 *                        NUL bytes are legitimate.
	 * @return void
	 * @throws TranslationException When a value carries a NUL byte.
	 */
	private static function assert_no_nul( $stmt, $binary = array() ) {
		foreach ( $stmt->get_descendant_tokens() as $token ) {
			if ( ! $token instanceof \WP_MySQL_Token ) {
				continue;
			}
			if ( isset( $binary[ (int) $token->start ] ) ) {
				continue;
			}
			if ( false !== strpos( (string) $token->get_value(), "\0" ) ) {
				throw TranslationException::unsupported(
					'NUL byte in a text value',
					'A NUL byte in a text value cannot be stored by PostgreSQL; store the column as bytea or remove the byte.'
				);
			}
		}
	}

	/**
	 * Comparison spans for the degraded case-sensitivity mode.
	 *
	 * @param \WP_Parser_Node      $ast     Statement node.
	 * @param string               $ci_mode Case-sensitivity mode.
	 * @param array<int,array>     $ctx     [text columns, known non-text columns] from compare_context().
	 * @return array
	 */
	private static function ci_compare_spans( $ast, $ci_mode, $ctx ) {
		if ( \Slonik\Schema\CiMode::DEGRADED !== $ci_mode ) {
			return array();
		}
		return Semantics::ci_compare_spans( $ast, $ctx[0], $ctx[1] );
	}

	private static function insert_meta( $stmt, $catalog = null ) {
		$table_tok = $stmt->get_first_descendant_node( 'table_ident' );
		$table_tok = null !== $table_tok ? $table_tok->get_first_descendant_token() : null;
		$cols      = array();
		foreach ( $stmt->get_descendant_nodes( 'insert_column' ) as $col ) {
			$tok = $col->get_first_descendant_token();
			if ( null !== $tok ) {
				$cols[] = $tok->get_value();
			}
		}
		$table = null !== $table_tok ? $table_tok->get_value() : '';
		if ( array() === $cols && '' !== $table && $catalog instanceof Catalog ) {
			$cols = $catalog->columns( $table );
		}
		return array(
			'table'   => $table,
			'columns' => $cols,
		);
	}

	/**
	 * Build an ON CONFLICT plan for INSERT ... ON DUPLICATE KEY UPDATE.
	 *
	 * Target follows P-10: covering keys only, PK first, then column order;
	 * several candidates resolve deterministically with a SQL comment.
	 *
	 * @param string          $mysql   Original SQL.
	 * @param \WP_Parser_Node $ast     Parsed statement.
	 * @param \WP_Parser_Node $stmt    insert_stmt node.
	 * @param Catalog|null    $catalog Schema catalog.
	 * @return ExecutionPlan
	 * @throws TranslationException When the target cannot be determined.
	 */
	private static function on_conflict( $mysql, $ast, $stmt, $catalog ) {
		if ( ! $catalog instanceof Catalog ) {
			throw new TranslationException( 'ON DUPLICATE KEY UPDATE needs a catalog for the conflict target.' );
		}
		$table_tok = $stmt->get_first_descendant_node( 'table_ident' );
		$table_tok = null !== $table_tok ? $table_tok->get_first_descendant_token() : null;
		if ( null === $table_tok ) {
			throw new TranslationException( 'INSERT without table name.' );
		}
		$table = $table_tok->get_value();
		$cols  = array();
		foreach ( $stmt->get_descendant_nodes( 'insert_column' ) as $col ) {
			$tok = $col->get_first_descendant_token();
			if ( null !== $tok ) {
				$cols[] = $tok->get_value();
			}
		}
		$covered = array();
		foreach ( $cols as $col ) {
			$covered[ strtolower( $col ) ] = true;
		}
		$candidates = array();
		$pk         = $catalog->primaryKey( $table );
		if ( array() !== $pk && self::covers( $covered, $pk ) ) {
			$candidates[] = array( 'name' => (string) $catalog->primaryKeyName( $table ), 'columns' => $pk, 'pk' => true );
		}
		foreach ( $catalog->uniqueKeyEntries( $table ) as $entry ) {
			if ( self::covers( $covered, $entry['columns'] ) ) {
				$candidates[] = array( 'name' => $entry['name'], 'columns' => $entry['columns'], 'pk' => false );
			}
		}
		if ( array() === $candidates ) {
			throw new TranslationException( 'No ON CONFLICT target: insert columns cover no unique key.' );
		}
		usort(
			$candidates,
			function ( $a, $b ) {
				if ( $a['pk'] !== $b['pk'] ) {
					return $a['pk'] ? -1 : 1;
				}
				return strcmp( (string) $a['name'], (string) $b['name'] );
			}
		);
		$chosen    = $candidates[0];
		$ambiguous = count( $candidates ) > 1;
		$quoted    = array();
		foreach ( $chosen['columns'] as $col ) {
			$canon    = $catalog->canonical( $table, $col );
			$quoted[] = '"' . str_replace( '"', '""', null !== $canon ? $canon : $col ) . '"';
		}
		$div        = Semantics::div_spans( $stmt, $catalog, $mysql );
		$div_ranges = array();
		foreach ( $div as $span ) {
			$div_ranges[] = array( $span[0], $span[0] + $span[1] );
		}
		$spans = array_merge(
			MinEmitter::excluding( MinEmitter::ident_spans( $ast, $catalog->colmap(), $mysql, array(), array(), IdentScope::resolve( $stmt, $catalog ) ), array_merge( self::odku_value_ranges( $stmt ), $div_ranges ) ),
			MinEmitter::limit_spans( $ast ),
			MinEmitter::calc_spans( $ast ),
			self::odku_spans( $stmt, $quoted, $table, $catalog ),
			$div
		);
		$sql = MinEmitter::apply_spans( $mysql, $spans );
		if ( $ambiguous ) {
			$sql = '-- ambiguous ODKU target, chose ' . $chosen['name'] . "\n" . $sql;
		}
		$plan = new ExecutionPlan( ExecutionPlan::KIND_INSERT, array( new PlanStep( $sql, PlanStep::MODE_QUERY ) ) );
		$plan->setMeta( array( 'insert' => array( 'table' => $table, 'columns' => $cols ) ) );
		return $plan;
	}

	private static function covers( $covered, $key_columns ) {		foreach ( $key_columns as $col ) {
			if ( ! isset( $covered[ strtolower( $col ) ] ) ) {
				return false;
			}
		}
		return true;
	}

	private static function odku_value_ranges( $stmt ) {
		$odku = $stmt->get_first_descendant_node( 'opt_insert_update_list' );
		if ( null === $odku ) {
			return array();
		}
		$ranges = array();
		$toks   = $odku->get_descendant_tokens();
		$count  = count( $toks );
		for ( $i = 0; $i < $count; ++$i ) {
			$token = $toks[ $i ];
			if ( ! $token instanceof \WP_MySQL_Token || 'VALUES' !== strtoupper( $token->get_value() ) ) {
				continue;
			}
			$shut = isset( $toks[ $i + 3 ] ) ? $toks[ $i + 3 ] : null;
			if ( $shut instanceof \WP_MySQL_Token ) {
				$ranges[] = array( $token->start, $shut->start + $shut->length );
			}
		}
		return $ranges;
	}

	private static function odku_spans( $stmt, $quoted_target, $table, $catalog ) {
		$spans = array();
		$odku  = $stmt->get_first_descendant_node( 'opt_insert_update_list' );
		if ( null === $odku ) {
			throw new TranslationException( 'ON DUPLICATE KEY UPDATE clause not found.' );
		}
		$update_end = null;
		foreach ( $odku->get_descendant_tokens() as $token ) {
			if ( $token instanceof \WP_MySQL_Token && 'UPDATE' === strtoupper( $token->get_value() ) ) {
				$update_end = $token->start + $token->length;
				break;
			}
		}
		if ( null === $update_end ) {
			throw new TranslationException( 'ON DUPLICATE KEY UPDATE clause without UPDATE.' );
		}
		$spans[] = array( $odku->get_start(), $update_end - $odku->get_start(), 'ON CONFLICT (' . implode( ',', $quoted_target ) . ') DO UPDATE SET' );
		$toks    = $odku->get_descendant_tokens();
		$count   = count( $toks );
		for ( $i = 0; $i < $count; ++$i ) {
			$token = $toks[ $i ];
			if ( ! $token instanceof \WP_MySQL_Token ) {
				continue;
			}
			if ( 'VALUES' !== strtoupper( $token->get_value() ) ) {
				continue;
			}
			$open = isset( $toks[ $i + 1 ] ) ? $toks[ $i + 1 ] : null;
			$name = isset( $toks[ $i + 2 ] ) ? $toks[ $i + 2 ] : null;
			$shut = isset( $toks[ $i + 3 ] ) ? $toks[ $i + 3 ] : null;
			if ( null === $open || null === $name || null === $shut
				|| '(' !== self::token_raw( $open ) || ')' !== self::token_raw( $shut )
				|| ! $name instanceof \WP_MySQL_Token ) {
				throw new TranslationException( 'VALUES() with non-column argument is out of scope.' );
			}
			$col     = $name->get_value();
			$canon   = $catalog->canonical( $table, $col );
			$spans[] = array( $token->start, $shut->start + $shut->length - $token->start, 'EXCLUDED."' . str_replace( '"', '""', null !== $canon ? $canon : $col ) . '"' );
			$i      += 3;
		}
		return $spans;
	}

	private static function token_raw( $token ) {
		return $token->get_bytes();
	}

	private static function autocommit_sql( $stmt ) {
		$toks = array();
		foreach ( $stmt->get_descendant_tokens() as $token ) {
			if ( $token instanceof \WP_MySQL_Token ) {
				$toks[] = $token;
			}
		}
		$count = count( $toks );
		for ( $i = 0; $i < $count; ++$i ) {
			if ( 'AUTOCOMMIT' !== strtoupper( $toks[ $i ]->get_value() ) ) {
				continue;
			}
			for ( $j = $i + 1; $j < $count; ++$j ) {
				$v = strtoupper( $toks[ $j ]->get_value() );
				if ( in_array( $v, array( '=', '==', 'TO' ), true ) ) {
					continue;
				}
				if ( in_array( $v, array( '0', 'OFF' ), true ) ) {
					return 'BEGIN';
				}
				if ( in_array( $v, array( '1', 'ON' ), true ) ) {
					return 'COMMIT';
				}
				return null;
			}
			return null;
		}
		return null;
	}

	private static function has_calc( $ast ) {
		foreach ( $ast->get_descendant_tokens() as $token ) {
			if ( ! $token instanceof \WP_MySQL_Token ) {
				continue;
			}
			if ( WP_MySQL_Lexer::BACK_TICK_QUOTED_ID === $token->id || WP_MySQL_Lexer::IDENTIFIER === $token->id ) {
				continue;
			}
			if ( self::is_string_token_id( $token->id ) ) {
				continue;
			}
			if ( 'SQL_CALC_FOUND_ROWS' === strtoupper( $token->get_value() ) ) {
				return true;
			}
		}
		return false;
	}

	private static function has_union( $ast ) {
		foreach ( $ast->get_descendant_tokens() as $token ) {
			if ( ! $token instanceof \WP_MySQL_Token ) {
				continue;
			}
			if ( WP_MySQL_Lexer::BACK_TICK_QUOTED_ID === $token->id || WP_MySQL_Lexer::IDENTIFIER === $token->id ) {
				continue;
			}
			if ( self::is_string_token_id( $token->id ) ) {
				continue;
			}
			if ( 'UNION' === strtoupper( $token->get_value() ) ) {
				return true;
			}
		}
		return false;
	}

	private static function is_string_token_id( $id ) {
		// SINGLE_QUOTED_TEXT and DOUBLE_QUOTED_TEXT share id 827 upstream.
		return WP_MySQL_Lexer::SINGLE_QUOTED_TEXT === $id;
	}

	private static function calc_plan( $mysql, $ast, $stmt, $catalog, $ci_mode = '' ) {
		if ( self::has_union( $ast ) ) {
			throw new TranslationException( 'UNION with SQL_CALC_FOUND_ROWS needs M3b analysis.' );
		}
		$colmap = $catalog instanceof Catalog ? $catalog->colmap() : array();
		$scope  = IdentScope::resolve( $stmt, $catalog );
		$ctx    = Semantics::compare_context( $catalog, $stmt );
		$extra  = array_merge(
			Semantics::group_spans( $stmt, $mysql, $colmap, $scope ),
			Semantics::nulls_spans( $stmt, $mysql, $catalog ),
			Semantics::type_compare_spans( $ast, $ctx[0], $ctx[1], $mysql ),
			Semantics::operator_spans( $ast ),
			Semantics::lock_spans( $stmt ),
			Semantics::div_spans( $stmt, $catalog, $mysql )
		);
		$main  = MinEmitter::emit( $mysql, $colmap, array(), $extra, $ci_mode, array(), $scope );
		$drop  = array();
		foreach ( array( 'limit_clause', 'order_clause' ) as $clause ) {
			foreach ( $stmt->get_descendant_nodes( $clause ) as $node ) {
				$drop[] = array( $node->get_start(), $node->get_start() + $node->get_length() );
			}
		}
		$inner = MinEmitter::emit( $mysql, $colmap, array( 'limit_clause', 'order_clause' ), MinEmitter::excluding( $extra, $drop ), $ci_mode, array(), $scope );
		return new ExecutionPlan(
			ExecutionPlan::KIND_SELECT,
			array(
				new PlanStep( $main, PlanStep::MODE_QUERY ),
				new PlanStep( 'SELECT COUNT(*) FROM (' . $inner . ') t', PlanStep::MODE_QUERY, 'found_rows' ),
			)
		);
	}

	private static function replace_plan( $mysql, $ast, $stmt, $catalog, $ci_mode = '' ) {
		// M3c owns H-10 (explicit-ID sequence desync needs setval sync) and
		// R-007 atomicity (DELETE+INSERT in one transaction/SAVEPOINT).
		if ( ! $catalog instanceof Catalog ) {
			throw new TranslationException( 'REPLACE needs a catalog for conflict detection.' );
		}
		$table_tok = $stmt->get_first_descendant_node( 'table_ident' );
		$table_tok = null !== $table_tok ? $table_tok->get_first_descendant_token() : null;
		if ( null === $table_tok ) {
			throw new TranslationException( 'REPLACE without table name.' );
		}
		$table = '"' . str_replace( '"', '""', $table_tok->get_value() ) . '"';
		$cols  = array();
		foreach ( $stmt->get_descendant_nodes( 'insert_column' ) as $col ) {
			$tok = $col->get_first_descendant_token();
			if ( null !== $tok ) {
				$cols[] = $tok->get_value();
			}
		}
		$rows = array();
		foreach ( $stmt->get_descendant_nodes( 'row_value' ) as $row ) {
			$items = array();
			foreach ( $row->get_descendant_nodes( 'expr_or_default' ) as $item ) {
				$items[] = substr( $mysql, $item->get_start(), $item->get_length() );
			}
			if ( count( $items ) !== count( $cols ) || array() === $cols ) {
				throw new TranslationException( 'REPLACE row/column count mismatch.' );
			}
			$rows[] = $items;
		}
		if ( array() === $rows ) {
			throw new TranslationException( 'REPLACE without VALUES rows.' );
		}
		$covered = array();
		foreach ( $cols as $col ) {
			$covered[ strtolower( $col ) ] = true;
		}
		$bare_table = $table_tok->get_value();
		$keys       = array();
		$pk         = $catalog->primaryKey( $bare_table );
		if ( array() !== $pk && self::covers( $covered, $pk ) ) {
			$keys[] = $pk;
		}
		foreach ( $catalog->uniqueKeys( $bare_table ) as $key ) {
			if ( self::covers( $covered, $key ) ) {
				$keys[] = $key;
			}
		}
		if ( array() === $keys ) {
			throw new TranslationException( 'No usable unique key for REPLACE.' );
		}
		$index_of = array();
		foreach ( $cols as $i => $col ) {
			$index_of[ strtolower( $col ) ] = $i;
		}
		$conds = array();
		foreach ( $keys as $key ) {
			$names  = array();
			$tuples = array();
			foreach ( $key as $col ) {
				$canon   = $catalog->canonical( $bare_table, $col );
				$names[] = '"' . str_replace( '"', '""', null !== $canon ? $canon : $col ) . '"';
			}
			foreach ( $rows as $items ) {
				$vals = array();
				foreach ( $key as $col ) {
					$vals[] = $items[ $index_of[ strtolower( $col ) ] ];
				}
				$tuples[] = '(' . implode( ',', $vals ) . ')';
			}
			$conds[] = '(' . implode( ',', $names ) . ') IN (' . implode( ',', $tuples ) . ')';
		}
		$first = $stmt->get_first_child_token();
		if ( null === $first || 'REPLACE' !== strtoupper( $first->get_value() ) ) {
			throw new TranslationException( 'REPLACE statement without REPLACE keyword.' );
		}
		$colmap = $catalog->colmap();
		$div    = Semantics::div_spans( $stmt, $catalog, $mysql );
		$div_ranges = array();
		foreach ( $div as $span ) {
			$div_ranges[] = array( $span[0], $span[0] + $span[1] );
		}
		$spans  = array_merge(
			array( array( $first->start, $first->length, 'INSERT' ) ),
			MinEmitter::excluding( MinEmitter::ident_spans( $ast, $colmap, $mysql, array(), array(), IdentScope::resolve( $stmt, $catalog ) ), $div_ranges ),
			MinEmitter::limit_spans( $ast ),
			MinEmitter::calc_spans( $ast ),
			$div
		);
		$plan = new ExecutionPlan(
			ExecutionPlan::KIND_INSERT,
			array(
				new PlanStep( 'DELETE FROM ' . $table . ' WHERE ' . implode( ' OR ', $conds ), PlanStep::MODE_EXEC ),
				new PlanStep( MinEmitter::apply_spans( $mysql, $spans ), PlanStep::MODE_QUERY ),
			)
		);
		$plan->setMeta( array( 'insert' => array( 'table' => $bare_table, 'columns' => $cols ) ) );
		return $plan;
	}

	/**
	 * Whether the SQL is a bare engine probe (MySQL version / current database).
	 *
	 * @param string $mysql MySQL SQL.
	 * @return bool
	 */
	public static function is_engine_probe( $mysql ) {
		$norm = strtoupper( str_replace( array( ' ', "\t", "\n", "\r", "\v", "\f" ), '', $mysql ) );
		$norm = rtrim( $norm, ';' );
		return in_array( $norm, array( 'SELECTVERSION()', 'SELECT@@VERSION', 'SELECTDATABASE()' ), true );
	}
}
