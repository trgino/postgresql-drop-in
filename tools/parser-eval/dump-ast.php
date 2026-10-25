<?php
/**
 * Dump AST rule/token names for emitter development (dev only).
 * Usage: php tools/parser-eval/dump-ast.php "<sql>" [depth]
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

require_once dirname( __DIR__, 2 ) . '/src/Sql/MysqlParser.php';

use Slonik\Sql\MysqlParser;

function slonik_dump_ast( $node, $depth, $max ) {
	$pad = str_repeat( '  ', $depth );
	if ( $node instanceof WP_Parser_Node ) {
		echo $pad . 'N:' . $node->rule_name . "\n";
		if ( $depth < $max ) {
			foreach ( $node->get_children() as $child ) {
				slonik_dump_ast( $child, $depth + 1, $max );
			}
		} elseif ( $node->has_child() ) {
			echo $pad . "  ...\n";
		}
		return;
	}
	$name = method_exists( $node, 'get_name' ) ? $node->get_name() : '?';
	$val  = method_exists( $node, 'get_value' ) ? substr( $node->get_value(), 0, 32 ) : '?';
	echo $pad . 'T:' . $name . ' start=' . $node->start . ' len=' . $node->length . ' val=[' . $val . "]\n";
}

$sql   = isset( $argv[1] ) ? (string) $argv[1] : '';
$depth = isset( $argv[2] ) ? (int) $argv[2] : 6;
if ( '' === $sql ) {
	fwrite( STDERR, "Usage: php tools/parser-eval/dump-ast.php \"<sql>\" [depth]\n" );
	exit( 2 );
}
$ast = MysqlParser::parse( $sql );
if ( null === $ast ) {
	echo "(null)\n";
	exit( 1 );
}
slonik_dump_ast( $ast, 0, $depth );
