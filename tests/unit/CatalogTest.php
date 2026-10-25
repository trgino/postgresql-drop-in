<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Schema\Catalog;

final class CatalogTest extends TestCase {

	//
	// D-03/D-41: the catalog must follow the configured schema, not
	// current_schema() (which is 'public' unless the search path says
	// otherwise -> empty key lists, broken ODKU and DESCRIBE).
	//

	public function test_catalog_sql_is_scoped_to_the_given_schema(): void {
		$this->assertStringContainsString( "table_schema = 'wp'", Catalog::columnsSql( 'wp' ) );
		foreach ( array( 'commentsSql', 'keysSql' ) as $builder ) {
			$sql = Catalog::$builder( 'wp' );
			$this->assertStringContainsString( "to_regnamespace('wp')", $sql, $builder );
			$this->assertStringNotContainsString( 'current_schema()', $sql, $builder );
		}
		$this->assertStringNotContainsString( 'current_schema()', Catalog::columnsSql( 'wp' ) );
	}

	public function test_catalog_keeps_its_schema(): void {
		$cat = Catalog::fromRows( array(), array(), 'wp' );
		$this->assertSame( 'wp', $cat->schema() );
		$this->assertSame( '', Catalog::fromRows( array(), array() )->schema() );
	}

	/**
	 * D-05: the generated identity column decides the RETURNING clause.
	 */
	public function test_identity_column_is_reported(): void {
		$cat = Catalog::fromRows(
			array(
				array( 'table' => 'wp_posts', 'column' => 'ID', 'nullable' => false, 'default' => null, 'identity' => true ),
				array( 'table' => 'wp_posts', 'column' => 'post_title', 'nullable' => false, 'default' => '' ),
			),
			array()
		);
		$this->assertSame( 'ID', $cat->identityColumn( 'wp_posts' ) );
		$this->assertNull( $cat->identityColumn( 'wp_others' ) );
		$plain = Catalog::fromRows(
			array( array( 'table' => 'wp_terms', 'column' => 'term_id', 'nullable' => false, 'default' => null ) ),
			array()
		);
		$this->assertNull( $plain->identityColumn( 'wp_terms' ) );
	}

	private static function sample() {
		return Catalog::fromRows(
			array(
				array( 'table' => 'wp_options', 'column' => 'option_id', 'nullable' => false, 'default' => null ),
				array( 'table' => 'wp_options', 'column' => 'option_name', 'nullable' => false, 'default' => '' ),
				array( 'table' => 'wp_posts', 'column' => 'ID', 'nullable' => false, 'default' => null ),
				array( 'table' => 'wp_posts', 'column' => 'post_title', 'nullable' => false, 'default' => '' ),
			),
			array(
				array( 'table' => 'wp_options', 'name' => 'wp_options__PRIMARY', 'columns' => array( 'option_id' ), 'primary' => true ),
				array( 'table' => 'wp_options', 'name' => 'wp_options__option_name', 'columns' => array( 'option_name' ), 'primary' => false ),
				array( 'table' => 'wp_posts', 'name' => 'wp_posts__PRIMARY', 'columns' => array( 'ID' ), 'primary' => true ),
			)
		);
	}

	public function test_canonical_case_resolution(): void {
		$c = self::sample();
		$this->assertSame( 'ID', $c->canonical( 'wp_posts', 'id' ) );
		$this->assertSame( 'ID', $c->canonical( 'wp_posts', 'ID' ) );
		$this->assertNull( $c->canonical( 'wp_posts', 'nope' ) );
		$this->assertNull( $c->canonical( 'missing', 'id' ) );
	}

	public function test_keys(): void {
		$c = self::sample();
		$this->assertSame( array( 'option_id' ), $c->primaryKey( 'wp_options' ) );
		$this->assertSame( array( array( 'option_name' ) ), $c->uniqueKeys( 'wp_options' ) );
		$this->assertSame( array(), $c->uniqueKeys( 'missing' ) );
	}

	public function test_nullable(): void {
		$c = self::sample();
		$this->assertFalse( $c->isNullable( 'wp_posts', 'ID' ) );
		$this->assertNull( $c->isNullable( 'wp_posts', 'nope' ) );
	}

	public function test_colmap(): void {
		$c = self::sample();
		$this->assertSame( 'ID', $c->colmap()['id'] );
	}

	public function test_cache_key_changes_when_schema_changes(): void {
		$a = self::sample();
		$b = Catalog::fromRows(
			array(
				array( 'table' => 'wp_options', 'column' => 'option_id', 'nullable' => false, 'default' => null ),
				array( 'table' => 'wp_options', 'column' => 'option_name', 'nullable' => false, 'default' => '' ),
				array( 'table' => 'wp_posts', 'column' => 'ID', 'nullable' => false, 'default' => null ),
				array( 'table' => 'wp_posts', 'column' => 'post_title', 'nullable' => false, 'default' => '' ),
				array( 'table' => 'wp_posts', 'column' => 'new_col', 'nullable' => true, 'default' => '' ),
			),
			array()
		);
		$this->assertNotSame( $a->cacheKey(), $b->cacheKey(), 'a new column must invalidate the cache key' );
	}

	public function test_cache_key_is_stable_for_identical_contents(): void {
		$a = self::sample();
		$b = self::sample();
		$this->assertSame( $a->cacheKey(), $b->cacheKey() );
	}

	public function test_cache_key_includes_schema(): void {
		$a = Catalog::fromRows( array(), array(), 'wp' );
		$b = Catalog::fromRows( array(), array(), 'other' );
		$this->assertNotSame( $a->cacheKey(), $b->cacheKey(), 'different schemas must not share a cache key' );
	}

	public function test_columns_lists_stored_spellings(): void {
		$c = self::sample();
		$this->assertSame( array( 'ID', 'post_title' ), $c->columns( 'wp_posts' ) );
		$this->assertSame( array(), $c->columns( 'missing' ) );
	}

	public function test_key_entries_carry_names(): void {
		$c = self::sample();
		$this->assertSame( 'wp_options__PRIMARY', $c->primaryKeyName( 'wp_options' ) );
		$this->assertSame(
			array( array( 'name' => 'wp_options__option_name', 'columns' => array( 'option_name' ) ) ),
			$c->uniqueKeyEntries( 'wp_options' )
		);
		$this->assertNull( $c->primaryKeyName( 'missing' ) );
	}

	public function test_pgtype_lookup(): void {		$c = Catalog::fromRows(
			array(
				array( 'table' => 't', 'column' => 'ID', 'nullable' => false, 'default' => null, 'pgtype' => 'bigint' ),
				array( 'table' => 't', 'column' => 'name', 'nullable' => true, 'default' => null, 'pgtype' => 'character varying' ),
			),
			array()
		);
		$this->assertSame( 'bigint', $c->pgType( 't', 'ID' ) );
		$this->assertSame( 'character varying', $c->coltype( 'name' ) );
		$this->assertNull( $c->pgType( 't', 'nope' ) );
	}

	public function test_nullable_any(): void {
		$c = Catalog::fromRows(
			array(
				array( 'table' => 'a', 'column' => 'x', 'nullable' => true, 'default' => null ),
				array( 'table' => 'b', 'column' => 'x', 'nullable' => false, 'default' => null ),
				array( 'table' => 'a', 'column' => 'y', 'nullable' => false, 'default' => null ),
			),
			array()
		);
		$this->assertTrue( $c->nullableAny( 'x' ) );
		$this->assertFalse( $c->nullableAny( 'y' ) );
		$this->assertNull( $c->nullableAny( 'nope' ) );
	}

	public function test_identity_and_mysqltype(): void {
		$c = Catalog::fromRows(
			array(
				array( 'table' => 't', 'column' => 'ID', 'nullable' => false, 'default' => null, 'identity' => true, 'mysqltype' => 'bigint(20) unsigned' ),
			),
			array()
		);
		$this->assertTrue( $c->isIdentity( 't', 'ID' ) );
		$this->assertSame( 'bigint(20) unsigned', $c->mysqlType( 't', 'id' ) );
		$this->assertFalse( $c->isIdentity( 't', 'nope' ) );
		$this->assertNull( $c->mysqlType( 't', 'nope' ) );
	}
}
