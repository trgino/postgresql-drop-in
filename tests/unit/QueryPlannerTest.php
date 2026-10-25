<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Migrate\MigrateDataError;
use Slonik\Migrate\QueryPlanner;

final class QueryPlannerTest extends TestCase {

	public function test_first_batch_is_plain_ordered_select(): void {
		$sql = QueryPlanner::batchSql( 'wp_posts', array( 'ID', 'post_title' ), array( 'ID' ), '', 500 );
		$this->assertSame( 'SELECT `ID`, `post_title` FROM `wp_posts` ORDER BY `ID` LIMIT 500', $sql );
	}

	public function test_watermark_writes_keyset_where_and_limit(): void {
		$last = QueryPlanner::encodeKey( array( '#9",0' ) );
		$sql  = QueryPlanner::batchSql( 'wp_posts', array( 'ID' ), array( 'ID' ), $last, 500 );
		$this->assertStringContainsString( "WHERE (`ID`) > (", $sql );
		$this->assertStringContainsString( 'LIMIT 500', $sql );
		// M6 live finding: the MySQL clause order is WHERE … ORDER BY …
		// LIMIT; emitting ORDER BY before WHERE is a syntax error.
		$this->assertSame( "SELECT `ID` FROM `wp_posts` WHERE (`ID`) > ('#9\",0') ORDER BY `ID` LIMIT 500", $sql );
	}

	public function test_composite_pk_watermark_roundtrip(): void {
		$tuple = array( 'a`b', "q'on", '2024-09-30 01:02:03' );
		$enc   = QueryPlanner::encodeKey( $tuple );
		$this->assertSame( $tuple, QueryPlanner::decodeKey( $enc ) );

		$where = QueryPlanner::keysetWhere( array( 'a', 'b' ), $enc );
		$this->assertSame( " WHERE (`a`, `b`) > ('a`b', 'q''on', '2024-09-30 01:02:03')", $where );
	}

	public function test_no_pk_scan_has_neither_order_nor_where(): void {
		$sql = QueryPlanner::batchSql( 'wp_x', array( 'n' ), array(), '', 50 );
		$this->assertSame( 'SELECT `n` FROM `wp_x` LIMIT 50', $sql );
		$this->assertStringNotContainsString( 'ORDER BY', $sql );
		$this->assertStringNotContainsString( 'WHERE', $sql );
	}

	public function test_corrupt_watermark_fails_loud(): void {
		try {
			QueryPlanner::decodeKey( 'not-json' );
			$this->fail( 'corrupt watermark must throw' );
		} catch ( MigrateDataError $e ) {
			$this->assertStringContainsString( 'watermark', $e->getMessage() );
		}
	}
}
