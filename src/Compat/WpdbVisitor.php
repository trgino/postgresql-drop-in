<?php
/**
 * $wpdb call-site visitor (docs/10 §2.1).
 *
 * Name-based receiver heuristic v1: variable named wpdb, property fetch
 * ->wpdb, or $GLOBALS['wpdb']. Collects compat methods, dbDelta, and
 * info signals (mysqli_*, PDO with mysql:, ->dbh).
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Compat;

// Scanner may be require'd eagerly by the drop-in loader without a composer
// autoloader: bootstrap the vendored php-parser lazily when needed.
if ( ! class_exists( 'PhpParser\\NodeVisitorAbstract' ) ) {
	require_once __DIR__ . '/../../third-party/php-parser/bootstrap.php';
}

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

final class WpdbVisitor extends NodeVisitorAbstract {

	const COMPAT_METHODS = array(
		'query'      => true,
		'get_results' => true,
		'get_row'    => true,
		'get_var'    => true,
		'get_col'    => true,
		'prepare'    => true,
		'insert'     => true,
		'update'     => true,
		'delete'     => true,
		'replace'    => true,
	);

	/** @var array<int, array{path:string, line:int, kind:string, method:string, sqlNode:?Node\Arg}> */
	private $entries;

	/** @var string Current file path. */
	private $path;

	public function __construct() {
		$this->entries = array();
		$this->path    = '';
	}

	public function reset( $path ) {
		$this->entries = array();
		$this->path    = (string) $path;
	}

	/**
	 * @return array<int, array{path:string, line:int, kind:string, method:string, sqlNode:?Node\Arg}>
	 */
	public function entries() : array {
		return $this->entries;
	}

	public function enterNode( Node $node ) {
		if ( $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall ) {
			$name = $node->name instanceof Node\Identifier ? strtolower( $node->name->toString() ) : '';
			if ( '' !== $name && isset( self::COMPAT_METHODS[ $name ] ) && $this->is_wpdb_receiver( $node->var ) ) {
				$this->add( 'query', $name, isset( $node->args[0] ) ? $node->args[0] : null, $node->getStartLine() );
				return null;
			}
			return null;
		}
		if ( $node instanceof Node\Expr\PropertyFetch || $node instanceof Node\Expr\NullsafePropertyFetch ) {
			$name = $node->name instanceof Node\Identifier ? strtolower( $node->name->toString() ) : '';
			if ( 'dbh' === $name && $this->is_wpdb_receiver( $node->var ) ) {
				$this->add( 'info', 'dbh', null, $node->getStartLine() );
				return null;
			}
			return null;
		}
		if ( $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name ) {
			$fn = strtolower( $node->name->toString() );
			if ( 'dbdelta' === $fn ) {
				$this->add( 'dbdelta', 'dbdelta', null, $node->getStartLine() );
				return null;
			}
			if ( 0 === strpos( $fn, 'mysqli_' ) ) {
				$this->add( 'info', $fn, null, $node->getStartLine() );
				return null;
			}
			return null;
		}
		if ( $node instanceof Node\Expr\New_ && $node->class instanceof Node\Name && 'pdo' === strtolower( $node->class->toString() ) ) {
			$first = isset( $node->args[0] ) ? $node->args[0] : null;
			if ( $first && $first->value instanceof Node\Scalar\String_ && 0 === strpos( strtolower( $first->value->value ), 'mysql:' ) ) {
				$this->add( 'info', 'PDO', null, $node->getStartLine() );
			}
			return null;
		}
		return null;
	}

	/**
	 * Name-based receiver heuristic v1 (ledger): wpdb | ->wpdb | $GLOBALS['wpdb'].
	 *
	 * @param Node\Expr $var Receiver.
	 */
	private function is_wpdb_receiver( Node\Expr $var ) : bool {
		if ( $var instanceof Node\Expr\Variable && is_string( $var->name ) ) {
			return 'wpdb' === strtolower( $var->name );
		}
		if ( $var instanceof Node\Expr\PropertyFetch ) {
			$name = $var->name instanceof Node\Identifier ? strtolower( $var->name->toString() ) : '';
			return 'wpdb' === $name;
		}
		if ( $var instanceof Node\Expr\ArrayDimFetch && $var->var instanceof Node\Expr\Variable && 'GLOBALS' === $var->var->name && $var->dim instanceof Node\Scalar\String_ ) {
			return 'wpdb' === strtolower( $var->dim->value );
		}
		return false;
	}

	/**
	 * @param string    $kind   query|info|dbdelta.
	 * @param string    $method Method name.
	 * @param ?Node\Arg $sql    First argument (SQL candidate) or null.
	 * @param int       $line   Source line.
	 */
	private function add( $kind, $method, ?Node\Arg $sql, $line ) {
		$this->entries[] = array(
			'path'    => $this->path,
			'line'    => (int) $line,
			'kind'    => $kind,
			'method'  => $method,
			'sqlNode' => $sql,
		);
	}
}
