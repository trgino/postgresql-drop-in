<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Migrate\MySqlSource;

final class MySqlSourceTest extends TestCase {

	public function test_ident_doubles_backticks(): void {
		$this->assertSame( '`wp_posts`', MySqlSource::ident( 'wp_posts' ) );
		$this->assertSame( '`wp_``weird`', MySqlSource::ident( 'wp_`weird' ) );
	}

	public function test_table_list_sql_scope(): void {
		$sql = MySqlSource::tableListSql( 'wp', 'wp_', array(), array() );
		$this->assertStringContainsString( "table_schema = 'wp'", $sql );
		$this->assertStringContainsString( "table_name LIKE 'wp_%'", $sql );
		$this->assertStringContainsString( 'ORDER BY table_name', $sql );

		$sql2 = MySqlSource::tableListSql( 'wp', 'wp_', array( 'legacy' ), array( 'wp_cruft' ) );
		$this->assertStringContainsString( "table_name NOT IN ('wp_cruft')", $sql2 );
		$this->assertStringContainsString( "table_name IN ('legacy')", $sql2 );
	}

	/**
	 * D-08: the exclusion must not be OR-ed into the scope (that made
	 * every table of the schema match).
	 */
	public function test_exclusion_is_not_ored_into_the_scope(): void {
		$sql = MySqlSource::tableListSql( 'wp', 'wp_', array( 'legacy' ), array( 'wp_cruft' ) );
		$this->assertStringNotContainsString( 'OR table_name <>', $sql );
		$this->assertStringContainsString( "table_name NOT IN ('wp_cruft')", $sql );
		$this->assertStringContainsString( "table_name LIKE 'wp_%' OR table_name IN ('legacy')", $sql );
	}

	public function test_scalar_literals_escaped(): void {
		$sql = MySqlSource::tableListSql( "db'x", 'wp_', array(), array() );
		$this->assertStringContainsString( "table_schema = 'db''x'", $sql );
	}

	/**
	 * D-32: the charset preflight needs the table collation.
	 */
	public function test_table_list_carries_the_collation(): void {
		$sql = MySqlSource::tableListSql( 'wp', 'wp_', array(), array() );
		$this->assertStringContainsString( 'table_collation', $sql );
	}

	public function test_columns_and_primary_keys_sql(): void {
		$cols = MySqlSource::columnsSql( 'wp', 'wp_posts' );
		$this->assertStringContainsString( 'FROM information_schema.columns', $cols );
		$this->assertStringContainsString( "table_name = 'wp_posts'", $cols );
		$this->assertStringContainsString( 'ORDER BY ordinal_position', $cols );

		$keys = MySqlSource::keysSql( 'wp', 'wp_options', true );
		$this->assertStringContainsString( "constraint_name = 'PRIMARY'", $keys );
		$this->assertStringContainsString( 'key_column_usage', $keys );
	}

	public function test_anomaly_sqls_cover_v1_objects(): void {
		$sqls = MySqlSource::anomalySqls( 'wp' );
		$this->assertSame( array( 'views', 'triggers', 'routines', 'events', 'fks' ), array_keys( $sqls ) );
		foreach ( $sqls as $kind => $q ) {
			$this->assertStringContainsString( "WHERE trigger_schema = 'wp'" === $q ? 'x' : 'wp', $q, $kind . ' must scope to the database' );
		}
	}
}
