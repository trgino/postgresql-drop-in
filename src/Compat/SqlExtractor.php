<?php
/**
 * SQL template extraction from a call-site node (docs/10 §2.2).
 *
 * Best-effort constant folding: literals, concat, sprintf/printf/prepare
 * placeholders (fake fills), $wpdb->prefix/$wpdb-><table> unwrap. Anything
 * unresolved becomes the ‹DYN› marker and flags the entry dynamic.
 *
 * Simplifications (ledger): IN-list single/multi variants are NOT expanded;
 * const-folding of PHP class constants is not attempted; interpolated
 * strings keep literal parts and mark the rest ‹DYN›.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Compat;

use PhpParser\Node;

final class SqlExtractor {

	const TABLE_PROPS = array(
		'posts'              => true,
		'postmeta'           => true,
		'comments'           => true,
		'commentmeta'        => true,
		'terms'              => true,
		'term_taxonomy'      => true,
		'term_relationships' => true,
		'termmeta'           => true,
		'users'              => true,
		'usermeta'           => true,
		'options'            => true,
		'links'              => true,
		'site'               => true,
		'sitemeta'           => true,
		'blogs'              => true,
		'blogmeta'           => true,
		'signups'            => true,
		'registration_log'   => true,
		'sitecategories'     => true,
	);
	const DYN = '‹DYN›';

	/**
	 * Extract a template from the first SQL argument of a call site.
	 *
	 * @param ?object $arg    PhpParser arg of SQL candidate (may be null).
	 * @param string  $method Call site method (query, insert, prepare, …).
	 * @return array{template:string, dynamic:bool}
	 */
	public function extract( $arg, $method ) {
		if ( null === $arg ) {
			return $this->dyn();
		}
		$result = $this->template( $arg->value, $method );
		if ( 'prepare' === $method ) {
			$result = $this->fake_fill( $result );
			return $result;
		}
		$has_placeholder = (bool) preg_match( '/%(?:\d+\$)?[sdif]/', $result['template'] );
		if ( $has_placeholder ) {
			$result['dynamic'] = true;
		}
		return $result;
	}

	/**
	 * Recursively resolve a node to an SQL template string.
	 *
	 * @param object $node   PhpParser node.
	 * @param string $method Call-site method for table-arg semantics.
	 * @return array{template:string, dynamic:bool}
	 */
	private function template( $node, $method = 'query' ) {
		if ( $node instanceof Node\Scalar\String_ ) {
			return array(
				'template' => $node->value,
				'dynamic'  => false,
			);
		}
		if ( $node instanceof Node\Scalar\InterpolatedString ) {
			return $this->implode_parts( $node->parts );
		}
		if ( $node instanceof Node\Expr\BinaryOp\Concat ) {
			$left  = $this->template( $node->left, 'query' );
			$right = $this->template( $node->right, 'query' );
			return array(
				'template' => $left['template'] . $right['template'],
				'dynamic'  => $left['dynamic'] || $right['dynamic'],
			);
		}
		if ( $node instanceof Node\Expr\CallLike ) {
			if ( $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name ) {
				$fn = strtolower( $node->name->toString() );
				if ( in_array( $fn, array( 'sprintf', 'printf' ), true ) ) {
					return $this->fill_format( $node );
				}
				return $this->dyn();
			}
			if ( $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall ) {
				if ( $node->name instanceof Node\Identifier && 'prepare' === strtolower( $node->name->toString() ) ) {
					return $this->fill_format( $node );
				}
				return $this->dyn();
			}
			return $this->dyn();
		}
		if ( $node instanceof Node\Expr\PropertyFetch ) {
			return $this->property( $node );
		}
		return $this->dyn();
	}

	/**
	 * Interpolated-string parts: literals + dynamic markers.
	 *
	 * @param array $parts Node parts (strings and expressions).
	 * @return array{template:string, dynamic:bool}
	 */
	private function implode_parts( $parts ) {
		$template = '';
		$dynamic  = false;
		foreach ( $parts as $part ) {
			if ( is_string( $part ) ) {
				$template .= $part;
				continue;
			}
			if ( $part instanceof Node\InterpolatedStringPart ) {
				$template .= (string) $part->value;
				continue;
			}
			$piece     = $this->template( $part, 'query' );
			$template .= $piece['template'];
			$dynamic   = $dynamic || $piece['dynamic'];
		}
		return array(
			'template' => $template,
			'dynamic'  => $dynamic,
		);
	}

	/**
	 * sprintf/printf/prepare: fake-fill placeholders in the format literal.
	 *
	 * Accepts any CallLike (global sprintf/printf or wpdb->prepare).
	 *
	 * @param Node\Expr\CallLike $node Call node.
	 * @return array{template:string, dynamic:bool}
	 */
	private function fill_format( $node ) {
		$first = isset( $node->args[0] ) ? $node->args[0] : null;
		if ( ! $first ) {
			return $this->dyn();
		}
		$format = $this->template( $first->value, 'query' );
		if ( $format['dynamic'] ) {
			return $format;
		}
		return $this->fake_fill( $format );
	}

	/**
	 * Fake-fill placeholders in a resolved template string.
	 *
	 * @param array{template:string, dynamic:bool} $result Resolved fragment.
	 * @return array{template:string, dynamic:bool}
	 */
	private function fake_fill( $result ) {
		$fake = (string) preg_replace_callback(
			'/%%|%(\d+\$)?[sdif]/',
			static function ( array $m ) {
				if ( '%%' === $m[0] ) {
					return '%';
				}
				$spec = $m[0][ strlen( $m[0] ) - 1 ];
				if ( 's' === $spec ) {
					return "'x'";
				}
				if ( 'd' === $spec ) {
					return '1';
				}
				if ( 'i' === $spec ) {
					return '`id`';
				}
				if ( 'f' === $spec ) {
					return '1.0';
				}
				return $m[0];
			},
			$result['template']
		);
		return array(
			'template' => $fake,
			'dynamic'  => $result['dynamic'],
		);
	}

	/**
	 * wpdb table property unwrap: prefix and known table props.
	 *
	 * @param Node\Expr\PropertyFetch $node Node.
	 * @return array{template:string, dynamic:bool}
	 */
	private function property( $node ) {
		if ( ! $node->name instanceof Node\Identifier ) {
			return $this->dyn();
		}
		$name = strtolower( $node->name->toString() );
		if ( 'prefix' === $name ) {
			return array(
				'template' => 'wp_',
				'dynamic'  => false,
			);
		}
		if ( isset( self::TABLE_PROPS[ $name ] ) ) {
			return array(
				'template' => 'wp_' . $name,
				'dynamic'  => false,
			);
		}
		return $this->dyn();
	}

	/**
	 * @return array{template:string, dynamic:bool}
	 */
	private function dyn() {
		return array(
			'template' => self::DYN,
			'dynamic'  => true,
		);
	}
}
