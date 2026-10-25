<?php
/**
 * Statement-scoped identifier resolution (audit D-80).
 *
 * MySQL matches a column name case-insensitively against the table the
 * statement reads or writes; PostgreSQL matches the quoted spelling
 * exactly. The emitter therefore needs the spelling the catalog stores
 * for the column of the statement's own table (P-08).
 *
 * A flat name => spelling map cannot answer that: two tables may hold
 * the same column name in a different case (wp_posts.ID and
 * wp_wfhits.id), and a table alias is not a column at all. This class
 * walks the statement, resolves every column reference against the
 * table its scope names, and returns the spelling per identifier token.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Dialect\Pg;

use Slonik\Schema\Catalog;
use WP_MySQL_Token;

final class IdentScope {

	/**
	 * Rules that own a table scope.
	 *
	 * A query block is a `query_expression`: it holds the select list, the
	 * FROM clause and the ORDER BY. The `query_specification` inside it
	 * ends before the ORDER BY, so it cannot own the block's tables.
	 *
	 * @var array<string,bool>
	 */
	const SCOPE_RULES = array(
		'query_expression' => true,
		'update_stmt'      => true,
		'insert_stmt'      => true,
		'delete_stmt'      => true,
		'replace_stmt'     => true,
	);

	/**
	 * Rules that reference a column.
	 *
	 * @var array<string,bool>
	 */
	const COLUMN_RULES = array(
		'simple_ident'         => true,
		'simple_ident_nospvar' => true,
	);

	/**
	 * The spelling of every identifier of a statement.
	 *
	 * A column reference that resolves against the catalog gets the
	 * stored spelling; a table name, an alias or a select alias keeps
	 * the written one (P-08). A column reference that cannot be
	 * resolved is left out: the caller falls back to the flat map.
	 *
	 * @param \WP_Parser_Node $stmt    Statement node.
	 * @param Catalog|null    $catalog Schema catalog.
	 * @return array<int,string> Token start => spelling.
	 */
	public static function resolve( $stmt, $catalog ) {
		$out = array();
		if ( ! $stmt instanceof \WP_Parser_Node || ! $catalog instanceof Catalog ) {
			return $out;
		}
		$scopes = self::scopes( $stmt );
		$tables = self::tables( $stmt, $scopes );
		$column_tokens = array();
		foreach ( self::column_refs( $stmt ) as $ref ) {
			$parts = self::ref_parts( $ref );
			if ( null === $parts ) {
				continue;
			}
			list( $qualifier, $column ) = $parts;
			$column_tokens[ (int) $column->start ] = true;
			if ( null !== $qualifier ) {
				$column_tokens[ (int) $qualifier->start ] = true;
				// The qualifier names a table or an alias: it is not a
				// column and keeps the written spelling.
				$out[ (int) $qualifier->start ] = (string) $qualifier->get_value();
			}
			$owner = self::owner( $scopes, $ref->get_start(), $ref->get_start() + $ref->get_length() );
			$scope_tables = null !== $owner && isset( $tables[ $owner['id'] ] ) ? $tables[ $owner['id'] ] : array();
			if ( null !== $qualifier ) {
				$table = self::table_for( $scope_tables, (string) $qualifier->get_value() );
				$canon = null !== $table ? $catalog->canonical( $table['name'], (string) $column->get_value() ) : null;
			} else {
				$canon = self::unqualified( $scope_tables, (string) $column->get_value(), $catalog );
			}
			if ( null !== $canon ) {
				$out[ (int) $column->start ] = (string) $canon;
			}
		}
		foreach ( self::ident_tokens( $stmt ) as $start => $token ) {
			if ( isset( $column_tokens[ $start ] ) ) {
				continue;
			}
			$out[ $start ] = (string) $token->get_value();
		}
		return $out;
	}

	/**
	 * Every identifier token of the statement.
	 *
	 * @param \WP_Parser_Node $stmt Statement node.
	 * @return array<int,\WP_MySQL_Token> Token start => token.
	 */
	private static function ident_tokens( $stmt ) {
		$out = array();
		foreach ( $stmt->get_descendant_nodes( 'ident' ) as $node ) {
			$token = $node->get_first_descendant_token();
			if ( ! $token instanceof WP_MySQL_Token ) {
				continue;
			}
			$out[ (int) $token->start ] = $token;
		}
		return $out;
	}

	/**
	 * The nodes that reference a column.
	 *
	 * @param \WP_Parser_Node $stmt Statement node.
	 * @return array<\WP_Parser_Node>
	 */
	private static function column_refs( $stmt ) {
		$out = array();
		foreach ( array_keys( self::COLUMN_RULES ) as $rule ) {
			foreach ( $stmt->get_descendant_nodes( $rule ) as $node ) {
				$out[] = $node;
			}
		}
		return $out;
	}

	/**
	 * The qualifier and the column of a column reference.
	 *
	 * @param \WP_Parser_Node $ref Column reference node.
	 * @return array{0:\WP_MySQL_Token|null,1:\WP_MySQL_Token}|null
	 */
	private static function ref_parts( $ref ) {
		$idents = $ref->get_descendant_nodes( 'ident' );
		if ( 1 === count( $idents ) ) {
			$token = $idents[0]->get_first_descendant_token();
			return $token instanceof WP_MySQL_Token ? array( null, $token ) : null;
		}
		if ( count( $idents ) < 2 ) {
			return null;
		}
		$qualifier = $idents[0]->get_first_descendant_token();
		$column    = $idents[1]->get_first_descendant_token();
		if ( ! $qualifier instanceof WP_MySQL_Token || ! $column instanceof WP_MySQL_Token ) {
			return null;
		}
		return array( $qualifier, $column );
	}

	/**
	 * The scopes of the statement, innermost last.
	 *
	 * A subquery is a scope of its own: its columns belong to its
	 * tables, not to the outer ones. The scope that owns a node is the
	 * smallest one that contains it.
	 *
	 * @param \WP_Parser_Node $stmt Statement node.
	 * @return array<int,array{id:int,start:int,end:int}>
	 */
	private static function scopes( $stmt ) {
		$out = array();
		foreach ( array_keys( self::SCOPE_RULES ) as $rule ) {
			if ( $stmt->rule_name === $rule ) {
				$out[] = self::scope( $stmt, count( $out ) );
			}
			foreach ( $stmt->get_descendant_nodes( $rule ) as $node ) {
				$out[] = self::scope( $node, count( $out ) );
			}
		}
		return $out;
	}

	/**
	 * @param \WP_Parser_Node $node Scope node.
	 * @param int             $id   Scope id.
	 * @return array{id:int,start:int,end:int}
	 */
	private static function scope( $node, $id ) {
		$start = $node->get_start();
		return array(
			'id'    => $id,
			'start' => $start,
			'end'   => $start + $node->get_length(),
		);
	}

	/**
	 * The scope that owns a node.
	 *
	 * @param array<int,array{id:int,start:int,end:int}> $scopes Scopes.
	 * @param int                                      $start  Node start.
	 * @param int                                      $end    Node end.
	 * @return array{id:int,start:int,end:int}|null
	 */
	private static function owner( array $scopes, $start, $end ) {
		$best     = null;
		$best_len = null;
		foreach ( $scopes as $scope ) {
			if ( $scope['start'] > $start || $scope['end'] < $end ) {
				continue;
			}
			$len = $scope['end'] - $scope['start'];
			if ( null === $best_len || $len < $best_len ) {
				$best     = $scope;
				$best_len = $len;
			}
		}
		return $best;
	}

	/**
	 * The tables of every scope, with their alias.
	 *
	 * @param \WP_Parser_Node                      $stmt   Statement node.
	 * @param array<int,array{id:int,start:int,end:int}> $scopes Scopes.
	 * @return array<int,array<int,array{name:string,alias:string}>>
	 */
	private static function tables( $stmt, array $scopes ) {
		$out     = array();
		$singles = array();
		foreach ( $stmt->get_descendant_nodes( 'single_table' ) as $node ) {
			$singles[] = $node;
		}
		foreach ( $stmt->get_descendant_nodes( 'table_ident' ) as $node ) {
			$start = $node->get_start();
			$end   = $start + $node->get_length();
			$owner = self::owner( $scopes, $start, $end );
			if ( null === $owner ) {
				continue;
			}
			$name = self::table_name( $node );
			if ( '' === $name ) {
				continue;
			}
			$out[ $owner['id'] ][] = array(
				'name'  => $name,
				'alias' => self::table_alias( $node, $singles ),
			);
		}
		return $out;
	}

	/**
	 * The table name of a table reference.
	 *
	 * @param \WP_Parser_Node $node table_ident node.
	 * @return string
	 */
	private static function table_name( $node ) {
		$idents = $node->get_descendant_nodes( 'ident' );
		if ( array() === $idents ) {
			return '';
		}
		$token = $idents[ count( $idents ) - 1 ]->get_first_descendant_token();
		return $token instanceof WP_MySQL_Token ? (string) $token->get_value() : '';
	}

	/**
	 * The alias of a table reference.
	 *
	 * @param \WP_Parser_Node $node    table_ident node.
	 * @param array<\WP_Parser_Node>  $singles single_table nodes.
	 * @return string
	 */
	private static function table_alias( $node, array $singles ) {
		$start = $node->get_start();
		$end   = $start + $node->get_length();
		$best     = null;
		$best_len = null;
		foreach ( $singles as $single ) {
			$s = $single->get_start();
			$e = $s + $single->get_length();
			if ( $s > $start || $e < $end ) {
				continue;
			}
			$len = $e - $s;
			if ( null === $best_len || $len < $best_len ) {
				$best     = $single;
				$best_len = $len;
			}
		}
		if ( null === $best ) {
			return '';
		}
		$alias = $best->get_first_child_node( 'opt_table_alias' );
		if ( null === $alias ) {
			return '';
		}
		$token = $alias->get_first_descendant_token();
		return $token instanceof WP_MySQL_Token ? (string) $token->get_value() : '';
	}

	/**
	 * The table a qualifier names.
	 *
	 * @param array<int,array{name:string,alias:string}> $tables    Tables of the scope.
	 * @param string                                     $qualifier Qualifier.
	 * @return array{name:string,alias:string}|null
	 */
	private static function table_for( array $tables, $qualifier ) {
		$lower = strtolower( $qualifier );
		foreach ( $tables as $table ) {
			if ( '' !== $table['alias'] && strtolower( $table['alias'] ) === $lower ) {
				return $table;
			}
		}
		foreach ( $tables as $table ) {
			if ( strtolower( $table['name'] ) === $lower ) {
				return $table;
			}
		}
		return null;
	}

	/**
	 * The spelling of a column that carries no qualifier.
	 *
	 * The column belongs to the only table of the scope that has it.
	 * When several tables have it, MySQL calls the reference ambiguous;
	 * the statement is left alone then.
	 *
	 * @param array<int,array{name:string,alias:string}> $tables  Tables of the scope.
	 * @param string                                     $column Column name.
	 * @param Catalog                                    $catalog Schema catalog.
	 * @return string|null
	 */
	private static function unqualified( array $tables, $column, $catalog ) {
		$canon = null;
		foreach ( $tables as $table ) {
			$stored = $catalog->canonical( $table['name'], $column );
			if ( null === $stored ) {
				continue;
			}
			if ( null === $canon ) {
				$canon = $stored;
				continue;
			}
			if ( $stored !== $canon ) {
				return null;
			}
		}
		return null !== $canon ? (string) $canon : null;
	}
}
