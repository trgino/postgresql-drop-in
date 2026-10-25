<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Dialect\Pg\MinEmitter;
use Slonik\Sql\TranslationException;

final class MinEmitterTest extends TestCase {

	/**
	 * @dataProvider golden_pairs
	 */
	public function test_golden_pairs( $mysql, $expected ): void {
		$this->assertSame( $expected, MinEmitter::emit( $mysql ) );
	}

	public function golden_pairs() {
		return array(
			'plain select'            => array(
				'SELECT a FROM t WHERE b = 1',
				'SELECT "a" FROM "t" WHERE "b" = 1',
			),
			'backticks'               => array(
				'SELECT `ID`, `post_title` FROM `wp_posts`',
				'SELECT "ID", "post_title" FROM "wp_posts"',
			),
			'backtick escape'         => array(
				'SELECT `we``ird` FROM t',
				'SELECT "we`ird" FROM "t"',
			),
			'string with keywords'    => array(
				"SELECT * FROM t WHERE note = 'LIMIT 5, 10 AND `x`'",
				"SELECT * FROM \"t\" WHERE \"note\" = 'LIMIT 5, 10 AND `x`'",
			),
			'limit offset,count'      => array(
				'SELECT a FROM t LIMIT 5, 10',
				'SELECT "a" FROM "t" LIMIT 10 OFFSET 5',
			),
			'limit count'             => array(
				'SELECT a FROM t LIMIT 10',
				'SELECT "a" FROM "t" LIMIT 10',
			),
			'huge limit'              => array(
				'SELECT a FROM t LIMIT 18446744073709551615',
				'SELECT "a" FROM "t" LIMIT ALL',
			),
			'nested limit'            => array(
				'SELECT * FROM (SELECT a FROM t LIMIT 2, 3) q LIMIT 1, 2',
				'SELECT * FROM (SELECT "a" FROM "t" LIMIT 3 OFFSET 2) "q" LIMIT 2 OFFSET 1',
			),
			'double-quoted string'    => array(
				'SELECT * FROM t WHERE a = "x\'y"',
				"SELECT * FROM \"t\" WHERE \"a\" = 'x''y'",
			),
			'insert'                  => array(
				"INSERT INTO `wp_options` (`option_name`) VALUES ('x')",
				"INSERT INTO \"wp_options\" (\"option_name\") VALUES ('x')",
			),
			'update with backticks'   => array(
				'UPDATE `wp_posts` SET `post_status` = \'draft\' WHERE `ID` = 5',
				'UPDATE "wp_posts" SET "post_status" = \'draft\' WHERE "ID" = 5',
			),
			'delete with limit'       => array(
				'DELETE FROM t WHERE a = 1 LIMIT 5',
				'DELETE FROM "t" WHERE "a" = 1 LIMIT 5',
			),
			'huge limit with offset'  => array(
				'SELECT a FROM t LIMIT 5, 18446744073709551615',
				'SELECT "a" FROM "t" LIMIT ALL OFFSET 5',
			),
			'huge limit offset form'  => array(
				'SELECT a FROM t LIMIT 18446744073709551615 OFFSET 5',
				'SELECT "a" FROM "t" LIMIT ALL OFFSET 5',
			),
			'calc stripped'           => array(
				'SELECT SQL_CALC_FOUND_ROWS * FROM t LIMIT 10',
				'SELECT  * FROM "t" LIMIT 10',
			),
		);
	}

	public function test_case_map_resolves_uppercase(): void {
		$this->assertSame(
			'SELECT "ID" FROM "wp_users" WHERE "ID" = 5',
			MinEmitter::emit( 'SELECT ID FROM wp_users WHERE ID = 5', array( 'id' => 'ID' ) )
		);
	}

	public function test_unknown_case_preserved(): void {
		$this->assertSame(
			'SELECT "Q" FROM "t"',
			MinEmitter::emit( 'SELECT Q FROM t' )
		);
	}

	public function test_unsupported_throws(): void {
		$this->expectException( TranslationException::class );
		MinEmitter::emit( 'REPLACE INTO t (a) VALUES (1)' );
	}

	/**
	 * D-25: the LIKE pattern is the MySQL string value. MySQL keeps
	 * "\_" and "\%" as they are and only unescapes "\\", so the pattern
	 * must reach PostgreSQL decoded, not as the raw source text.
	 */
	public function test_like_pattern_uses_the_decoded_value(): void {
		$this->assertSame(
			"SELECT * FROM \"t\" WHERE \"s\" LIKE 'a\\%b'",
			MinEmitter::emit( "SELECT * FROM t WHERE s LIKE 'a\\\\%b'" )
		);
	}

	/**
	 * D-25: the ESCAPE argument is a single character. MySQL's "\\"
	 * is one backslash; passed through raw, PostgreSQL would see two
	 * characters and reject the clause.
	 */
	public function test_like_escape_argument_is_converted(): void {
		$this->assertSame(
			"SELECT * FROM \"t\" WHERE \"s\" LIKE 'a!%b' ESCAPE '\\'",
			MinEmitter::emit( "SELECT * FROM t WHERE s LIKE 'a!%b' ESCAPE '\\\\'" )
		);
	}

	/**
	 * ANY_VALUE has a PostgreSQL counterpart since 16.
	 */
	public function test_any_value_maps_to_postgres(): void {
		$this->assertSame(
			'SELECT ANY_VALUE("c") FROM "t"',
			MinEmitter::emit( 'SELECT ANY_VALUE(c) FROM t' )
		);
	}

	public function test_plain_limit_needs_no_span(): void {
		$ast = \Slonik\Sql\MysqlParser::parse( 'SELECT a FROM t LIMIT 10' );
		$this->assertSame( array(), MinEmitter::limit_spans( $ast ) );
	}

	public function test_excluding_drops_insertion_inside_removal(): void {
		$this->assertSame(
			array(),
			MinEmitter::excluding( array( array( 8, 0, ' NULLS LAST' ) ), array( array( 0, 10 ) ) )
		);
		$this->assertSame(
			array( array( 20, 0, ' X' ) ),
			MinEmitter::excluding( array( array( 20, 0, ' X' ) ), array( array( 0, 10 ) ) )
		);
	}

	/**
	 * D-72: two REPLACEMENT spans over the same source bytes are a
	 * rewriter bug; applying the second mangles the first's output into
	 * silently wrong SQL. The overlap has to fail loud at apply time.
	 */
	public function test_overlapping_replacement_spans_throw(): void {
		$this->expectException( TranslationException::class );
		$this->expectExceptionMessage( 'overlapping' );
		MinEmitter::apply_spans( 'a = 1', array( array( 4, 1, '2' ), array( 4, 1, "'2'" ) ) );
	}
}
