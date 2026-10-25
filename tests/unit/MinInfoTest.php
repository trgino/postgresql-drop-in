<?php
declare( strict_types = 1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Schema\Catalog;
use Slonik\Schema\MinInfo;
use Slonik\Sql\ExecutionPlan;
use Slonik\Sql\MinTranslator;
use Slonik\Sql\PlanStep;
use Slonik\Sql\TranslationException;

/**
 * D-55: information_schema metadata probes (R-079).
 */
final class MinInfoTest extends TestCase {

	/** The woocommerce index-existence shape, with the literal table name. */
	const STATISTICS_PRIMARY = 'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ';

	/** The wordfence table-existence shape (spaces-free form). */
	const TABLES_PARAM = 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?';

	/** The wordfence column-existence shape (COLUMN_NAME first). */
	const COLUMNS_STAR_CN_FIRST = "SELECT * FROM information_schema.COLUMNS\nWHERE TABLE_SCHEMA=DATABASE()\nAND COLUMN_NAME=?\nAND TABLE_NAME=?";

	public function test_tables_probe_rewrites_to_information_schema_tables(): void {
		$sql = MinInfo::translate( self::TABLES_PARAM );
		$this->assertSame(
			array(
				'SELECT table_name AS "TABLE_NAME" FROM information_schema.tables'
					. ' WHERE table_schema = current_schema() AND table_name = ?',
			),
			$sql
		);
	}

	public function test_tables_probe_uses_the_catalog_schema(): void {
		$cat = Catalog::fromRows( array(), array(), 'wp' );
		$sql = MinInfo::translate( self::TABLES_PARAM, $cat );
		$this->assertStringContainsString( "table_schema = 'wp'", $sql[0] );
		$this->assertStringNotContainsString( 'current_schema()', $sql[0] );
	}

	public function test_tables_probe_compares_the_table_name_case_sensitively(): void {
		// MySQL 8.4: information_schema.TABLES.TABLE_NAME is utf8mb3_bin,
		// so the compare is case-sensitive. Wordfence asks for the CamelCase
		// spelling (wp_wfConfig) to decide the table case; a ci compare
		// answers "exists" for the lowercase table, the plugin flips to
		// CamelCase and PG grows a second spelling of the same table
		// (D-82).
		$sql = MinInfo::translate( self::TABLES_PARAM );
		$this->assertStringContainsString( 'table_name = ?', $sql[0] );
		$this->assertStringNotContainsString( 'lower(table_name)', $sql[0] );
		$this->assertStringNotContainsString( 'TABLE_SCHEMA', $sql[0] );
		$this->assertStringNotContainsString( 'DATABASE()', $sql[0] );
	}

	public function test_tables_probe_does_not_match_a_differently_spelled_table(): void {
		$sql = MinInfo::translate(
			"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='wp_wfConfig'"
		);
		$this->assertStringContainsString( "table_name = 'wp_wfConfig'", $sql[0] );
		$this->assertStringNotContainsString( 'lower(', $sql[0] );
	}

	public function test_columns_probe_keeps_the_star(): void {
		$sql = MinInfo::translate( self::COLUMNS_STAR_CN_FIRST );
		$this->assertSame(
			array(
				'SELECT * FROM information_schema.columns'
					. ' WHERE table_schema = current_schema() AND lower(column_name) = lower(?) AND table_name = ?',
			),
			$sql
		);
	}

	public function test_columns_probe_accepts_any_predicate_order(): void {
		$sql = MinInfo::translate(
			'SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
		);
		$this->assertStringContainsString( 'table_name = ? AND lower(column_name) = lower(?)', $sql[0] );
	}

	public function test_character_maximum_length_reports_bytea_as_unbounded(): void {
		$sql = MinInfo::translate(
			"SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS\nWHERE TABLE_SCHEMA=DATABASE()\nAND COLUMN_NAME=?\nAND TABLE_NAME=?"
		);
		$this->assertStringContainsString(
			"CASE WHEN data_type = 'bytea' THEN 16777215 ELSE character_maximum_length END AS \"CHARACTER_MAXIMUM_LENGTH\"",
			$sql[0]
		);
	}

	public function test_column_key_derives_the_key_flag(): void {
		$sql = MinInfo::translate(
			'SELECT COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
		);
		$joined = implode( "\n", $sql );
		$this->assertStringContainsString( "CASE WHEN co.contype = 'p' THEN 'PRI'", $joined );
		$this->assertStringContainsString( 'pg_constraint', $joined );
		$this->assertStringContainsString( 'pg_index', $joined );
		$this->assertStringContainsString( 'indisunique', $joined );
		$this->assertStringContainsString( "'MUL'", $joined );
		$this->assertStringContainsString( ", '') AS \"COLUMN_KEY\"", $joined );
	}

	/**
	 * The relation lookup must be scoped: the schema can hold both
	 * spellings of a table name (the identifier case policy, D-82), and
	 * an unscoped scalar subquery returns more than one row - PostgreSQL
	 * refuses it ("more than one row returned by a subquery used as an
	 * expression").
	 */
	public function test_column_key_probe_scopes_the_relation_lookup(): void {
		$sql = MinInfo::translate(
			'SELECT COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
		);
		$this->assertStringContainsString( 'pg_namespace', $sql[0] );
		$this->assertStringContainsString( 'n.nspname = information_schema.columns.table_schema', $sql[0] );
		$this->assertStringContainsString( "c.relkind IN ('r','p')", $sql[0] );
		$this->assertSame( 2, substr_count( $sql[0], 'LIMIT 1' ) );
	}

	/**
	 * The relation lookup matches the spelling byte for byte: a ci
	 * lookup can pick the other spelling of the same table name and
	 * report the key flag of the wrong relation (D-82).
	 */
	public function test_column_key_probe_matches_the_relation_name_exactly(): void {
		$sql = MinInfo::translate(
			'SELECT COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
		);
		$this->assertStringContainsString( 'c.relname = information_schema.columns.table_name', $sql[0] );
		$this->assertStringNotContainsString( 'lower(c.relname)', $sql[0] );
	}

	public function test_statistics_primary_probe_counts_pk_columns(): void {
		$sql = MinInfo::translate(
			self::STATISTICS_PRIMARY . "'wp_wc_order_product_lookup' AND INDEX_NAME = 'PRIMARY'"
		);
		$joined = implode( "\n", $sql );
		$this->assertStringContainsString( 'SELECT COUNT(*) FROM information_schema.key_column_usage', $joined );
		$this->assertStringContainsString( "tc.constraint_type = 'PRIMARY KEY'", $joined );
		$this->assertStringContainsString( "kcu.table_name = 'wp_wc_order_product_lookup'", $joined );
		$this->assertStringNotContainsString( 'lower(kcu.table_name)', $joined );
	}

	public function test_statistics_probe_with_other_index_names_fails_loud(): void {
		$this->expectException( TranslationException::class );
		MinInfo::translate(
			'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
		);
	}

	public function test_non_information_schema_from_is_rejected(): void {
		$this->expectException( TranslationException::class );
		MinInfo::translate( 'SELECT TABLE_NAME FROM pg_tables WHERE tablename = ?' );
	}

	public function test_diagnostics_shapes_fail_loud(): void {
		// Diagnostic page (not in the matrix corpus): IN-list, ORDER BY,
		// starless COUNT without TABLE_NAME, BINARY prefix compare. All must
		// fail with an actionable message instead of a silent rewrite.
		$cases = array(
			"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('a','b') ORDER BY TABLE_NAME ASC",
			'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()',
			"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND BINARY TABLE_NAME='x'",
			'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND EXTRA=?',
		);
		foreach ( $cases as $case ) {
			try {
				MinInfo::translate( $case );
				$this->fail( 'Expected a TranslationException for: ' . $case );
			} catch ( TranslationException $e ) {
				$this->assertNotFalse( strpos( $e->getMessage(), 'information_schema' ), 'Actionable message for: ' . $case );
			}
		}
	}

	public function test_dispatch_plans_a_query_step(): void {
		$plan   = MinTranslator::translate( self::TABLES_PARAM );
		$steps  = $plan->getSteps();
		$this->assertSame( ExecutionPlan::KIND_SELECT, $plan->getKind() );
		$this->assertCount( 1, $steps );
		$this->assertSame( PlanStep::MODE_QUERY, $steps[0]->getMode() );
		$this->assertStringContainsString( 'information_schema.tables', $steps[0]->getSql() );
	}

	public function test_dispatch_fails_loud_on_out_of_shape_probes(): void {
		$this->expectException( TranslationException::class );
		MinTranslator::translate(
			'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY 1'
		);
	}
}
