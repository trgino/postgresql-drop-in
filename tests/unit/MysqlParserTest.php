<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Sql\MysqlParser;

final class MysqlParserTest extends TestCase {
	public function test_load_is_idempotent(): void {
		MysqlParser::load();
		MysqlParser::load();
		$this->assertTrue( class_exists( 'WP_MySQL_Parser_Factory' ) );
	}

	public function test_parse_select_returns_node(): void {
		$ast = MysqlParser::parse( 'SELECT 1 + 2' );
		$this->assertNotNull( $ast );
	}

	public function test_vendored_tree_is_complete(): void {
		$root  = dirname( __DIR__, 2 ) . '/third-party/mysql-parser';
		$files = array(
			'src/class-wp-mysql-lexer.php',
			'src/class-wp-mysql-parser-factory.php',
			'src/class-wp-mysql-token.php',
			'src/mysql-parse-table.php',
			'src/parser/class-wp-parser-grammar.php',
			'src/parser/class-wp-parser-node.php',
			'src/parser/class-wp-parser-token.php',
			'src/parser/class-wp-parser.php',
			'LICENSE',
			'PROVENANCE.md',
		);
		foreach ( $files as $rel ) {
			$this->assertFileExists( $root . '/' . $rel );
		}
		$iter  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		$count = 0;
		foreach ( $iter as $file ) {
			++$count;
		}
		$this->assertSame( count( $files ), $count, 'no extra files (data/tests/tools stay out)' );
	}
}
