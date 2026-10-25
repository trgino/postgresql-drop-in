<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DiffNormalizeTest extends TestCase {

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/tools/diff/lib.php';
	}

	public function test_stringifies_and_sorts(): void {
		$a = array( array( 'n' => 2 ), array( 'n' => 10 ) );
		$b = array( array( 'n' => '10' ), array( 'n' => '2' ) );
		$this->assertSame( slonik_diff_normalize( $a ), slonik_diff_normalize( $b ) );
	}

	public function test_null_and_bool(): void {
		$rows = slonik_diff_normalize( array( array( 'a' => null, 'b' => true, 'c' => false ) ) );
		$this->assertSame( array( array( 'a' => null, 'b' => '1', 'c' => '0' ) ), $rows );
	}

	public function test_ordered_compare_for_order_by(): void {
		$a = array( array( 'n' => '1' ), array( 'n' => '2' ) );
		$b = array( array( 'n' => '2' ), array( 'n' => '1' ) );
		$this->assertFalse( slonik_diff_shapes_equal( $a, $b, true ) );
		$this->assertTrue( slonik_diff_shapes_equal( $a, $b, false ) );
	}
}
