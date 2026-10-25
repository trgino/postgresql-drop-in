<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Schema\Catalog;
use Slonik\Sql\MinTranslator;
use Slonik\Sql\TranslationException;

final class SemanticsTest extends TestCase {

	private static function tx( $mysql ) {
		return MinTranslator::translate( $mysql )->getSteps()[0]->getSql();
	}

	public function test_group_wraps_ungrouped(): void {
		$this->assertSame(
			'SELECT "ID", any_value("post_title"), COUNT(*) FROM "wp_posts" GROUP BY "ID"',
			self::tx( 'SELECT ID, post_title, COUNT(*) FROM wp_posts GROUP BY ID' )
		);
	}

	public function test_group_all_covered_untouched(): void {
		$this->assertSame(
			'SELECT "ID" FROM "wp_posts" GROUP BY "ID"',
			self::tx( 'SELECT ID FROM wp_posts GROUP BY ID' )
		);
	}

	public function test_group_without_clause_untouched(): void {
		$this->assertSame(
			'SELECT "a" FROM "t"',
			self::tx( 'SELECT a FROM t' )
		);
	}

	public function test_group_by_alias_is_not_wrapped(): void {
		// MySQL groups by the expression the alias stands for; PostgreSQL
		// resolves the alias to the select-list expression, so an
		// any_value() wrapper would make the grouping key an aggregate
		// (42803, audit D-83).
		$this->assertSame(
			'SELECT COUNT(*) AS "cnt", CASE WHEN ("jsRun" = ? OR "userID" > ?) THEN ? ELSE ? END AS "isHuman", "statusCode" FROM "wp_wfhits" WHERE "ctime" > ? GROUP BY "isHuman", "statusCode"',
			self::tx( 'SELECT COUNT(*) AS cnt, CASE WHEN (jsRun = ? OR userID > ?) THEN ? ELSE ? END AS isHuman, statusCode FROM wp_wfhits WHERE ctime > ? GROUP BY isHuman, statusCode' )
		);
	}

	public function test_group_by_alias_collides_with_input_column(): void {
		// MySQL resolves a bare GROUP BY name to the input column first, so
		// when the alias spelling matches a column the item is not the
		// grouping key and keeps the any_value() wrapper (review of D-83).
		$catalog = Catalog::fromRows(
			array(
				array( 'table' => 'wp_options', 'column' => 'option_value', 'nullable' => true, 'default' => null ),
				array( 'table' => 'wp_options', 'column' => 'option_name', 'nullable' => false, 'default' => null ),
			),
			array()
		);
		$this->assertSame(
			'SELECT any_value("option_value") AS "option_name", COUNT(*) FROM "wp_options" GROUP BY "option_name"',
			MinTranslator::translate( 'SELECT option_value AS option_name, COUNT(*) FROM wp_options GROUP BY option_name', $catalog )->getSteps()[0]->getSql()
		);
	}

	public function test_group_by_expression_keeps_alias_wrapped(): void {
		// The grouping key is the expression, not the bare alias; the select
		// item stays wrapped (review of D-83).
		$this->assertSame(
			'SELECT any_value(status) AS "grp", COUNT(*) FROM "t" GROUP BY "grp" + 1',
			self::tx( 'SELECT status AS grp, COUNT(*) FROM t GROUP BY grp + 1' )
		);
	}

	public function test_group_by_qualified_column_keeps_alias_wrapped(): void {
		// A qualified GROUP BY name never resolves to a select-list alias.
		$this->assertSame(
			'SELECT any_value(name) AS "x", COUNT(*) FROM "t" "o" GROUP BY "o"."x"',
			self::tx( 'SELECT name AS x, COUNT(*) FROM t o GROUP BY o.x' )
		);
	}

	public function test_group_by_keyword_alias_is_not_wrapped(): void {
		// MySQL accepts keyword-spelled aliases (AS date); the alias must
		// still be detected so the grouped item is not wrapped (review of
		// D-83).
		$this->assertSame(
			'SELECT "status" AS date, COUNT(*) FROM "t" GROUP BY "date"',
			self::tx( 'SELECT status AS date, COUNT(*) FROM t GROUP BY date' )
		);
	}

	public function test_distinct_covered_untouched(): void {
		$this->assertSame(
			'SELECT DISTINCT "post_status" FROM "wp_posts" ORDER BY "post_status" DESC NULLS LAST',
			self::tx( 'SELECT DISTINCT post_status FROM wp_posts ORDER BY post_status DESC' )
		);
	}

	public function test_distinct_hidden_column(): void {
		$this->assertSame(
			'SELECT "post_status" FROM (SELECT  "post_status", "post_date" AS "__slonik_ob0", row_number() OVER (PARTITION BY "post_status" ORDER BY "post_date" DESC NULLS LAST) AS "__slonik_rn" FROM "wp_posts" ORDER BY "__slonik_ob0" DESC NULLS LAST) "__slonik_t" WHERE "__slonik_rn" = 1 ORDER BY "__slonik_ob0" DESC NULLS LAST',
			self::tx( 'SELECT DISTINCT post_status FROM wp_posts ORDER BY post_date DESC' )
		);
	}

	public function test_distinct_star_expands_via_catalog(): void {
		$catalog = Catalog::fromRows(
			array(
				array( 'table' => 'wp_posts', 'column' => 'ID', 'nullable' => false, 'default' => null ),
				array( 'table' => 'wp_posts', 'column' => 'post_title', 'nullable' => false, 'default' => null ),
			),
			array()
		);
		$sql = MinTranslator::translate( 'SELECT DISTINCT wp_posts.* FROM wp_posts ORDER BY post_date DESC', $catalog )->getSteps()[0]->getSql();
		$this->assertStringContainsString( '"ID","post_title" FROM (SELECT', $sql );
		$this->assertStringContainsString( '"post_date" AS "__slonik_ob0"', $sql );
		$this->assertStringContainsString( 'row_number() OVER (PARTITION BY "ID", "post_title" ORDER BY "post_date" DESC NULLS LAST)', $sql );
	}

	public function test_distinct_star_multi_table_throws(): void {
		$this->expectException( TranslationException::class );
		MinTranslator::translate( 'SELECT DISTINCT a.*, b.* FROM a JOIN b ON a.id = b.id ORDER BY c' );
	}

	public function test_distinct_count_star_not_expanded(): void {
		$this->assertSame(
			'SELECT COUNT(*) FROM (SELECT DISTINCT COUNT(*), "a" AS "__slonik_ob0" FROM "t" ORDER BY "__slonik_ob0" NULLS FIRST) "__slonik_t" ORDER BY "__slonik_ob0" NULLS FIRST',
			self::tx( 'SELECT DISTINCT COUNT(*) FROM t ORDER BY a' )
		);
	}

	/**
	 * D-25: DISTINCT over an expression repeats the value; the window
	 * function makes it deterministic (one row per value).
	 */
	public function test_distinct_with_an_expression_in_the_select_list_is_supported(): void {
		$sql = self::tx( 'SELECT DISTINCT post_status || post_title FROM wp_posts ORDER BY post_date DESC' );
		$this->assertStringContainsString( 'row_number() OVER (PARTITION BY "post_status" || "post_title" ORDER BY "post_date" DESC NULLS LAST)', $sql );
		$this->assertStringContainsString( 'WHERE "__slonik_rn" = 1', $sql );
	}

	public function test_distinct_with_a_function_in_the_select_list_is_supported(): void {
		$sql = self::tx( 'SELECT DISTINCT CONCAT(post_status, post_title) FROM wp_posts ORDER BY post_date DESC' );
		$this->assertStringContainsString( 'row_number() OVER (PARTITION BY CONCAT(', $sql );
		$this->assertStringContainsString( 'WHERE "__slonik_rn" = 1', $sql );
	}

	public function test_distinct_applies_like_inside(): void {
		$sql = self::tx( "SELECT DISTINCT post_status FROM wp_posts WHERE post_title LIKE '%x%' ORDER BY post_date DESC" );
		$this->assertStringContainsString( 'ILIKE', $sql );
		$this->assertStringContainsString( 'row_number() OVER (PARTITION BY "post_status" ORDER BY "post_date" DESC NULLS LAST)', $sql );
	}

	public function test_distinct_alias_star_throws(): void {
		$catalog = Catalog::fromRows(
			array(
				array( 'table' => 'wp_posts', 'column' => 'ID', 'nullable' => false, 'default' => null ),
			),
			array()
		);
		$this->expectException( TranslationException::class );
		MinTranslator::translate( 'SELECT DISTINCT p.* FROM wp_posts AS p ORDER BY post_date DESC', $catalog );
	}

	public function test_distinct_unknown_star_table_throws(): void {
		$catalog = Catalog::fromRows( array(), array() );
		$this->expectException( TranslationException::class );
		MinTranslator::translate( 'SELECT DISTINCT wp_posts.* FROM wp_posts ORDER BY post_date DESC', $catalog );
	}

	public function test_like_lookbehind_stops_at_and(): void {
		$sql = self::tx( "SELECT * FROM t WHERE a LIKE 'x%' AND b = 'a\\nb'" );
		$this->assertStringContainsString( 'ILIKE', $sql );
		$this->assertStringContainsString( "'a\nb'", $sql );
	}

	public function test_double_quoted_like_pattern_single_quoted(): void {
		$this->assertSame(
			'SELECT * FROM "t" WHERE "a" ILIKE \'x\'',
			self::tx( 'SELECT * FROM t WHERE a LIKE "x"' )
		);
	}

	public function test_get_lock_blocking(): void {
		$this->assertSame(
			'SELECT CASE WHEN pg_advisory_lock(hashtext(\'x\')) IS NULL THEN 0 ELSE 1 END FROM "t"',
			self::tx( "SELECT GET_LOCK('x', 10) FROM t" )
		);
	}

	public function test_get_lock_try(): void {
		$this->assertSame(
			'SELECT pg_try_advisory_lock(hashtext(\'x\')) FROM "t"',
			self::tx( "SELECT GET_LOCK('x', 0) FROM t" )
		);
	}

	public function test_release_lock(): void {
		$this->assertSame(
			'SELECT pg_advisory_unlock(hashtext(\'x\')) FROM "t"',
			self::tx( "SELECT RELEASE_LOCK('x') FROM t" )
		);
	}

	public function test_is_free_lock_throws(): void {
		$this->expectException( TranslationException::class );
		MinTranslator::translate( "SELECT IS_FREE_LOCK('x') FROM t" );
	}

	public function test_lock_names_untouched(): void {
		$this->assertSame(
			"SELECT 'get_lock' FROM \"t\"",
			self::tx( "SELECT 'get_lock' FROM t" )
		);
		$this->assertSame(
			'SELECT "get_lock" FROM "t"',
			self::tx( 'SELECT get_lock FROM t' )
		);
	}

	public function test_negative_timeout_blocks(): void {
		$this->assertSame(
			'SELECT CASE WHEN pg_advisory_lock(hashtext(\'x\')) IS NULL THEN 0 ELSE 1 END FROM "t"',
			self::tx( "SELECT GET_LOCK('x', -1) FROM t" )
		);
	}

	public function test_nulls_first_on_nullable(): void {
		$catalog = Catalog::fromRows(
			array( array( 'table' => 't', 'column' => 'a', 'nullable' => true, 'default' => null ) ),
			array()
		);
		$sql = MinTranslator::translate( 'SELECT a FROM t ORDER BY a', $catalog )->getSteps()[0]->getSql();
		$this->assertSame( 'SELECT "a" FROM "t" ORDER BY "a" NULLS FIRST', $sql );
	}

	public function test_nulls_last_on_desc(): void {
		$sql = MinTranslator::translate( 'SELECT a FROM t ORDER BY a DESC' )->getSteps()[0]->getSql();
		$this->assertSame( 'SELECT "a" FROM "t" ORDER BY "a" DESC NULLS LAST', $sql );
	}

	public function test_nulls_skipped_when_not_null(): void {
		$catalog = Catalog::fromRows(
			array( array( 'table' => 't', 'column' => 'ID', 'nullable' => false, 'default' => null ) ),
			array()
		);
		$sql = MinTranslator::translate( 'SELECT ID FROM t ORDER BY ID', $catalog )->getSteps()[0]->getSql();
		$this->assertSame( 'SELECT "ID" FROM "t" ORDER BY "ID"', $sql );
	}

	public function test_text_column_int_literal_cast(): void {
		$catalog = Catalog::fromRows(
			array( array( 'table' => 't', 'column' => 'name', 'nullable' => true, 'default' => null, 'pgtype' => 'text' ) ),
			array()
		);
		$sql = MinTranslator::translate( 'SELECT * FROM t WHERE name = 5', $catalog )->getSteps()[0]->getSql();
		$this->assertSame( 'SELECT * FROM "t" WHERE "name" = \'5\'', $sql );
	}

	/**
	 * D-72: a keyword-spelled column (the lexer gives no identifier token
	 * for it) still gets the literal coercion: the classification reads
	 * the grammar's simple_ident regions, not the token kind.
	 */
	public function test_keyword_spelled_column_gets_the_numeric_coercion(): void {
		$catalog = Catalog::fromRows(
			array( array( 'table' => 't', 'column' => 'name', 'nullable' => true, 'default' => null, 'pgtype' => 'text' ) ),
			array()
		);
		$sql = MinTranslator::translate( 'SELECT * FROM t WHERE `name` = 5', $catalog )->getSteps()[0]->getSql();
		$this->assertSame( 'SELECT * FROM "t" WHERE "name" = \'5\'', $sql );
	}

	public function test_like_becomes_ilike(): void {
		$this->assertSame(
			"SELECT * FROM \"t\" WHERE \"a\" ILIKE '%x%'",
			self::tx( "SELECT * FROM t WHERE a LIKE '%x%'" )
		);
	}

	public function test_pipes_become_or(): void {
		$this->assertSame(
			'SELECT "a" FROM "t" WHERE "a" OR "b"',
			self::tx( 'SELECT a FROM t WHERE a || b' )
		);
	}

	public function test_regexp_maps(): void {
		$this->assertSame(
			'SELECT "a" FROM "t" WHERE "a" ~* \'^x\'',
			self::tx( "SELECT a FROM t WHERE a REGEXP '^x'" )
		);
	}

	public function test_regexp_binary_and_not_forms(): void {
		$this->assertSame(
			'SELECT "a" FROM "t" WHERE "a" ~ \'^x\'',
			self::tx( "SELECT a FROM t WHERE a REGEXP BINARY '^x'" )
		);
		$this->assertSame(
			'SELECT "a" FROM "t" WHERE "a" !~* \'^x\'',
			self::tx( "SELECT a FROM t WHERE a NOT REGEXP '^x'" )
		);
	}

	public function test_single_quote_escape_reemitted(): void {
		$this->assertSame(
			"SELECT * FROM \"t\" WHERE \"a\" = 'It''s'",
			self::tx( "SELECT * FROM t WHERE a = 'It\\'s'" )
		);
	}

	public function test_backslash_reemitted(): void {
		$sql = self::tx( "SELECT * FROM t WHERE a = 'a\\nb'" );
		$this->assertStringContainsString( "'a\nb'", $sql );
	}

	public function test_zero_date_literal_becomes_sentinel(): void {
		$this->assertSame(
			'INSERT INTO "t" ("d") VALUES (\'0001-01-01 00:00:00\')',
			MinTranslator::translate( "INSERT INTO t (d) VALUES ('0000-00-00 00:00:00')" )->getSteps()[0]->getSql()
		);
		$this->assertSame(
			'SELECT "d" FROM "t" WHERE "d" = \'0001-01-01\'',
			self::tx( "SELECT d FROM t WHERE d = '0000-00-00'" )
		);
	}

	public function test_like_pattern_verbatim(): void {
		$this->assertSame(
			"SELECT * FROM \"t\" WHERE \"a\" ILIKE 'a\\%b'",
			self::tx( "SELECT * FROM t WHERE a LIKE 'a\\%b'" )
		);
	}

	public function test_group_by_expression_wraps_inner(): void {
		$this->assertSame(
			'SELECT any_value("post_date") FROM "wp_posts" GROUP BY DATE("post_date")',
			self::tx( 'SELECT post_date FROM wp_posts GROUP BY DATE(post_date)' )
		);
	}

	public function test_not_regexp_binary_maps(): void {
		$this->assertSame(
			'SELECT "a" FROM "t" WHERE "a" !~ \'^x\'',
			self::tx( "SELECT a FROM t WHERE a NOT REGEXP BINARY '^x'" )
		);
	}

	/**
	 * D-25: a parenthesised arithmetic operand is numeric when its
	 * operands are numeric columns or literals.
	 */
	public function test_parenthesised_division_operand_is_supported(): void {
		$catalog = Catalog::fromRows(
			array(
				array( 'table' => 't', 'column' => 'score', 'nullable' => true, 'default' => null, 'pgtype' => 'integer' ),
			),
			array()
		);
		$sql = MinTranslator::translate( 'SELECT (score + 1) / 2 FROM t', $catalog )->getSteps()[0]->getSql();
		$this->assertSame( 'SELECT ROUND(("score" + 1)::numeric / NULLIF(2, 0), 4) FROM "t"', $sql );
	}

	/**
	 * A text operand is not numeric: no rewrite (the type is unknown).
	 */
	public function test_text_operand_division_is_not_rewritten(): void {
		$catalog = Catalog::fromRows(
			array(
				array( 'table' => 't', 'column' => 'label', 'nullable' => true, 'default' => null, 'pgtype' => 'character varying' ),
			),
			array()
		);
		$sql = MinTranslator::translate( 'SELECT (label + 1) / 2 FROM t', $catalog )->getSteps()[0]->getSql();
		$this->assertStringNotContainsString( 'ROUND(', $sql );
	}

	/**
	 * D-25: MySQL returns NULL for a division by zero, PostgreSQL raises
	 * an error. The divisor is guarded.
	 */
	public function test_division_by_zero_becomes_null(): void {
		$sql = MinTranslator::translate( 'SELECT 5 / 0 FROM t' )->getSteps()[0]->getSql();
		$this->assertStringContainsString( 'NULLIF(0, 0)', $sql );
	}

	/**
	 * R-042: DIV is the integer division (MySQL truncates).
	 */
	public function test_div_is_the_integer_division(): void {
		$catalog = Catalog::fromRows(
			array(
				array( 'table' => 't', 'column' => 'score', 'nullable' => true, 'default' => null, 'pgtype' => 'integer' ),
			),
			array()
		);
		$sql = MinTranslator::translate( 'SELECT score DIV 2 FROM t', $catalog )->getSteps()[0]->getSql();
		$this->assertStringContainsString( 'trunc("score"::numeric / NULLIF(2, 0))', $sql );
	}

	/**
	 * D-25: an aggregate operand (SUM(x) / COUNT(*)) is supported.
	 */
	public function test_aggregate_operands_are_supported(): void {
		$catalog = Catalog::fromRows(
			array(
				array( 'table' => 't', 'column' => 'score', 'nullable' => true, 'default' => null, 'pgtype' => 'integer' ),
			),
			array()
		);
		$sql = MinTranslator::translate( 'SELECT SUM(score) / COUNT(*) FROM t', $catalog )->getSteps()[0]->getSql();
		$this->assertStringContainsString( 'ROUND(SUM("score")::numeric / NULLIF(COUNT(*), 0), 4)', $sql );
	}

	public function test_chained_division_divides_left_to_right(): void {
		$catalog = Catalog::fromRows(
			array(
				array( 'table' => 't', 'column' => 'score', 'nullable' => true, 'default' => null, 'pgtype' => 'integer' ),
			),
			array()
		);
		$sql = MinTranslator::translate( 'SELECT score / 2 / 2 FROM t', $catalog )->getSteps()[0]->getSql();
		$this->assertSame(
			'SELECT ROUND(ROUND("score"::numeric / NULLIF(2, 0), 4)::numeric / NULLIF(2, 0), 4) FROM "t"',
			$sql
		);
	}

	public function test_fractional_zero_date_maps(): void {
		$this->assertSame(
			'INSERT INTO "t" ("d") VALUES (\'0001-01-01 00:00:00.000\')',
			MinTranslator::translate( "INSERT INTO t (d) VALUES ('0000-00-00 00:00:00.000')" )->getSteps()[0]->getSql()
		);
	}

	public function test_int_division_casts_left(): void {
		$catalog = Catalog::fromRows(
			array(
				array( 'table' => 't', 'column' => 'score', 'nullable' => true, 'default' => null, 'pgtype' => 'integer' ),
			),
			array()
		);
		$sql = MinTranslator::translate( 'SELECT score / 2 AS h FROM t WHERE id = 1', $catalog )->getSteps()[0]->getSql();
		$this->assertSame( 'SELECT ROUND("score"::numeric / NULLIF(2, 0), 4) AS "h" FROM "t" WHERE "id" = 1', $sql );
	}

	public function test_decimal_division_rounds(): void {
		$catalog = Catalog::fromRows(
			array(
				array( 'table' => 't', 'column' => 'ratio', 'nullable' => true, 'default' => null, 'pgtype' => 'numeric' ),
			),
			array()
		);
		$sql = MinTranslator::translate( 'SELECT ratio / 2 FROM t', $catalog )->getSteps()[0]->getSql();
		$this->assertSame( 'SELECT ROUND("ratio"::numeric / NULLIF(2, 0), 4) FROM "t"', $sql );
	}

	public function test_float_division_passthrough(): void {
		$catalog = Catalog::fromRows(
			array(
				array( 'table' => 't', 'column' => 'fval', 'nullable' => true, 'default' => null, 'pgtype' => 'double precision' ),
			),
			array()
		);
		$sql = MinTranslator::translate( 'SELECT fval / 2 FROM t', $catalog )->getSteps()[0]->getSql();
		$this->assertSame( 'SELECT "fval" / 2 FROM "t"', $sql );
	}

	public function test_slash_string_literal_untouched(): void {
		$this->assertSame( 'SELECT \'/\' FROM "t"', self::tx( "SELECT '/' FROM t" ) );
		$this->assertSame( 'SELECT "id" FROM "t" WHERE "payload" = \'/\'', self::tx( "SELECT id FROM t WHERE payload = '/'" ) );
	}

	public function test_decimal_literal_division_rounds(): void {
		$this->assertSame( 'SELECT ROUND(5.5::numeric / NULLIF(2, 0), 4) FROM "t"', self::tx( 'SELECT 5.5 / 2 FROM t' ) );
	}

	public function test_distinct_order_division_throws(): void {
		$this->expectException( TranslationException::class );
		self::tx( 'SELECT DISTINCT score FROM t ORDER BY score / 2 LIMIT 10' );
	}

	public function test_distinct_order_slash_string_ok(): void {
		$this->assertNotEmpty( self::tx( "SELECT DISTINCT a FROM t ORDER BY '/'" ) );
	}

	public function test_calc_division_rounds_both_steps(): void {
		$catalog = Catalog::fromRows(
			array(
				array( 'table' => 't', 'column' => 'score', 'nullable' => true, 'default' => null, 'pgtype' => 'integer' ),
			),
			array()
		);
		$steps = MinTranslator::translate( 'SELECT SQL_CALC_FOUND_ROWS score / 2 AS h FROM t LIMIT 10', $catalog )->getSteps();
		$this->assertCount( 2, $steps );
		$this->assertSame( 'SELECT  ROUND("score"::numeric / NULLIF(2, 0), 4) AS "h" FROM "t" LIMIT 10', $steps[0]->getSql() );
		$this->assertSame( 'SELECT COUNT(*) FROM (SELECT  ROUND("score"::numeric / NULLIF(2, 0), 4) AS "h" FROM "t" ) t', $steps[1]->getSql() );
	}

	public function test_update_division_rounds(): void {
		$catalog = Catalog::fromRows(
			array(
				array( 'table' => 't', 'column' => 'score', 'nullable' => true, 'default' => null, 'pgtype' => 'integer' ),
			),
			array()
		);
		$sql = MinTranslator::translate( 'UPDATE t SET score = score / 2 WHERE id = 1', $catalog )->getSteps()[0]->getSql();
		$this->assertSame( 'UPDATE "t" SET "score" = ROUND("score"::numeric / NULLIF(2, 0), 4) WHERE "id" = 1', $sql );
	}

	public function test_distinct_hidden_order_with_limit(): void {
		$this->assertSame(
			'SELECT "post_status" FROM (SELECT  "post_status", "post_date" AS "__slonik_ob0", row_number() OVER (PARTITION BY "post_status" ORDER BY "post_date" DESC NULLS LAST) AS "__slonik_rn" FROM "wp_posts" ORDER BY "__slonik_ob0" DESC NULLS LAST LIMIT 10) "__slonik_t" WHERE "__slonik_rn" = 1 ORDER BY "__slonik_ob0" DESC NULLS LAST',
			self::tx( 'SELECT DISTINCT post_status FROM wp_posts ORDER BY post_date DESC LIMIT 10' )
		);
	}

}
