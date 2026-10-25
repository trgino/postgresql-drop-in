<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Schema\Catalog;
use Slonik\Schema\MinShow;
use Slonik\Sql\TranslationException;

final class MinShowTest extends TestCase {

	//
	// D-03/D-41: DESCRIBE and SHOW KEYS must not see other schemas.
	//

	public function test_describe_is_scoped_to_the_catalog_schema(): void {
		$cat = Catalog::fromRows( array(), array(), 'wp' );
		$sql = MinShow::describeSql( 'wp_posts', $cat );
		$this->assertStringContainsString( "table_schema = 'wp'", $sql );
	}

	public function test_show_keys_is_scoped_to_the_catalog_schema(): void {
		$cat = Catalog::fromRows( array(), array(), 'wp' );
		$sql = MinShow::showKeysSql( 'wp_posts', $cat );
		$this->assertStringContainsString( "n.nspname = 'wp'", $sql );
	}

	public function test_describe_without_catalog_uses_the_search_path(): void {
		$sql = MinShow::describeSql( 'wp_posts', null );
		$this->assertStringContainsString( 'table_schema = current_schema()', $sql );
	}

	/**
	 * D-18: dbDelta compares the raw default text; PostgreSQL's
	 * nextval()/cast defaults would trigger an ALTER on every run.
	 */
	public function test_describe_normalizes_the_default(): void {
		$sql = MinShow::describeSql( 'wp_posts', null );
		$this->assertStringContainsString( "nextval(%", $sql );
		$this->assertStringContainsString( '0000-00-00 00:00:00', $sql );
		$this->assertStringNotContainsString( 'column_default AS "Default"', $sql );
	}

	public function test_describe_marks_identity_and_primary_columns(): void {
		$cat = Catalog::fromRows(
			array(
				array( 'table' => 'wp_posts', 'column' => 'ID', 'nullable' => false, 'default' => null, 'identity' => true ),
				array( 'table' => 'wp_posts', 'column' => 'post_title', 'nullable' => false, 'default' => '' ),
			),
			array( array( 'table' => 'wp_posts', 'name' => 'wp_posts__PRIMARY', 'columns' => array( 'ID' ), 'primary' => true ) ),
			'wp'
		);
		$sql = MinShow::describeSql( 'wp_posts', $cat );
		$this->assertStringContainsString( "'auto_increment'", $sql );
		$this->assertStringContainsString( "'PRI'", $sql );
	}

	public function test_show_tables_like(): void {
		$this->assertSame(
			array( 'SELECT tablename AS "Tables_in_db" FROM pg_tables WHERE schemaname = current_schema() AND tablename LIKE \'wp_%\'' ),
			MinShow::translate( 'SHOW TABLES LIKE \'wp_%\'' )
		);
	}

	public function test_show_tables(): void {
		$this->assertSame(
			array( 'SELECT tablename AS "Tables_in_db" FROM pg_tables WHERE schemaname = current_schema()' ),
			MinShow::translate( 'SHOW TABLES' )
		);
	}

	public function test_describe_shape(): void {
		$statements = MinShow::translate( 'DESCRIBE wp_posts' );
		$this->assertCount( 1, $statements );
		$this->assertStringContainsString( 'information_schema.columns', $statements[0] );
		$this->assertStringContainsString( '\'wp_posts\'', $statements[0] );
	}

	public function test_show_tables_where_throws(): void {
		$this->expectException( TranslationException::class );
		MinShow::translate( 'SHOW TABLES WHERE Tables_in_db = \'x\'' );
	}

	//
	// D-55: wordfence reads two MySQL server variables to size its
	// chunked writes (wordfence/lib/wfDB.php:161,165 and
	// wordfence/lib/wfConfig.php:817). PostgreSQL has no packet limit,
	// so the emulation reports the MySQL default: it keeps the chunk
	// size inside PostgreSQL's message limit and the consumer's
	// arithmetic unchanged.
	//

	public function test_show_variables_like_emulates_max_allowed_packet(): void {
		$this->assertSame(
			array( 'SELECT \'max_allowed_packet\' AS "Variable_name", \'67108864\' AS "Value"' ),
			MinShow::translate( "SHOW VARIABLES LIKE 'max_allowed_packet'" )
		);
	}

	public function test_show_variables_like_emulates_max_long_data_size(): void {
		$this->assertSame(
			array( 'SELECT \'max_long_data_size\' AS "Variable_name", \'67108864\' AS "Value"' ),
			MinShow::translate( "SHOW VARIABLES LIKE 'max_long_data_size'" )
		);
	}

	public function test_show_variables_like_is_case_insensitive(): void {
		$statements = MinShow::translate( "SHOW VARIABLES LIKE 'MAX_ALLOWED_PACKET'" );
		$this->assertStringContainsString( 'max_allowed_packet', $statements[0] );
	}

	public function test_show_variables_like_with_an_unknown_variable_fails_loud(): void {
		$this->expectException( TranslationException::class );
		$this->expectExceptionMessage( 'innodb_page_size' );
		MinShow::translate( "SHOW VARIABLES LIKE 'innodb_page_size'" );
	}

	public function test_show_variables_without_like_fails_loud(): void {
		$this->expectException( TranslationException::class );
		$this->expectExceptionMessage( 'SHOW VARIABLES' );
		MinShow::translate( 'SHOW VARIABLES' );
	}

	public function test_show_keys_names_reversed(): void {
		$statements = MinShow::translate( 'SHOW INDEX FROM wp_posts' );
		$this->assertCount( 1, $statements );
		$this->assertStringContainsString( "WHEN i.indisprimary THEN 'PRIMARY'", $statements[0] );
		$this->assertStringContainsString( "starts_with(c2.relname, 'wp_posts__')", $statements[0] );
	}

	public function test_describe_uses_catalog_types(): void {
		$catalog = Catalog::fromRows(
			array(
				array( 'table' => 'wp_posts', 'column' => 'ID', 'nullable' => false, 'default' => null, 'mysqltype' => 'bigint(20) unsigned' ),
			),
			array()
		);
		$statements = MinShow::translate( 'DESCRIBE wp_posts', $catalog );
		$this->assertCount( 1, $statements );
		$this->assertStringContainsString( "WHEN column_name = 'ID' THEN 'bigint(20) unsigned'", $statements[0] );
	}
}
