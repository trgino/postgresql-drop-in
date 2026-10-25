<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Migrate\QueryPlanner;
use Slonik\Migrate\Verifier;

final class VerifierTest extends TestCase {

	public function test_numeric_canon_normalizes_scales(): void {
		$this->assertSame( '', Verifier::canon( null, 'int' ) );
		$this->assertSame( '3', Verifier::canon( '3.00', 'decimal' ) );
		$this->assertSame( '3', Verifier::canon( '3.0', 'double' ) );
		$this->assertSame( '3', Verifier::canon( '3.00', 'numeric' ) );
		$this->assertSame( '-3.25', Verifier::canon( '-3.250', 'decimal' ) );
		$this->assertSame( '10', Verifier::canon( '010.0', 'int' ) );
		$this->assertSame( '0.5', Verifier::canon( '0.5', 'decimal' ) );
		$this->assertSame( '0', Verifier::canon( '0.00', 'decimal' ) );
		$this->assertSame( '4294967295', Verifier::canon( '4294967295', 'bigint' ) );
	}

	public function test_datetime_fraction_stripped(): void {
		$this->assertSame( '2024-01-02 03:04:05', Verifier::canon( '2024-01-02 03:04:05', 'timestamp' ) );
		$this->assertSame( '2024-01-02 03:04:05', Verifier::canon( '2024-01-02 03:04:05.123456', 'datetime' ) );
		$this->assertSame( '2024-01-02', Verifier::canon( '2024-01-02', 'date' ) );
		$this->assertSame( '2024-01-02 03:04:05', Verifier::canon( '2024-01-02 03:04:05', 'varchar' ) );
		$this->assertSame( '0001-01-01', Verifier::canon( '0001-01-01', 'date' ) );
	}

	public function test_binary_blob_raw_passthrough(): void {
		// MySQL side raw bytes equal themselves.
		$this->assertSame( "raw\0bytes", Verifier::canon( "raw\0bytes", 'blob' ) );
		$this->assertSame( "raw\0bytes", Verifier::canon( "raw\0bytes", 'binary' ) );
	}

	public function test_bytea_hex_unwrap(): void {
		$this->assertSame( 'AB', Verifier::canon( '\x4142', 'bytea' ) );
		$this->assertSame( '\xZZ', Verifier::canon( '\xZZ', 'bytea' ) );
	}

	/**
	 * D-68: PDO's pgsql driver returns a bytea column as a stream: the
	 * raw bytes are the value. VARBINARY is binary too.
	 */
	public function test_bytea_stream_readback_and_varbinary(): void {
		$stream = fopen( 'php://memory', 'r+' );
		fwrite( $stream, "raw\0bytes" );
		rewind( $stream );
		$this->assertSame( "raw\0bytes", Verifier::canon( $stream, 'bytea' ) );
		fclose( $stream );

		$this->assertSame( "raw\0bytes", Verifier::canon( "raw\0bytes", 'varbinary' ) );
	}

	public function test_json_order_sorted(): void {
		$this->assertSame(
			Verifier::canon( '{"b":2,"a":1}', 'json' ),
			Verifier::canon( '{"a":1,"b":2}', 'json' )
		);
		$this->assertSame( '{"a":1}', Verifier::canon( '{ "a": 1 }', 'json' ) );
		$this->assertSame( '[1,2]', Verifier::canon( '[1, 2]', 'json' ) );
	}

	public function test_row_hash_column_type_order_matters(): void {
		$types = array( 'int', 'varchar' );
		$h1    = Verifier::rowHash( array( '5', 'x' ), $types );
		$h2    = Verifier::rowHash( array( '5', 'x' ), $types );
		$this->assertSame( $h1, $h2 );
		$h3 = Verifier::rowHash( array( '05', 'x' ), $types );
		$this->assertSame( $h1, $h3 );
		$h4 = Verifier::rowHash( array( '5', 'X' ), $types );
		$this->assertNotSame( $h1, $h4 );
	}

	public function test_target_scan_sql_quotes_case_preserved_order_limited(): void {
		$sql = Verifier::targetScanSql(
			'wp_posts',
			array( 'ID', 'post_title' ),
			array( 'ID' ),
			QueryPlanner::encodeKey( array( '9' ) ),
			10
		);
		$this->assertSame(
			'SELECT "ID", "post_title" FROM "wp_posts" WHERE ("ID") > (\'9\') ORDER BY "ID" LIMIT 10',
			$sql
		);
		$sql_first = Verifier::targetScanSql( 'wp_t', array( 'a' ), array(), '', 5 );
		$this->assertSame( 'SELECT "a" FROM "wp_t" LIMIT 5', $sql_first );
	}

	/**
	 * Rows dropped by the skip-row policy are not in the target: the
	 * expected target count is the source count minus the skipped rows
	 * (audit D-42).
	 */
	public function test_expected_target_count_accounts_for_skipped_rows(): void {
		$this->assertSame( 8, Verifier::expectedTargetCount( 10, 2 ) );
		$this->assertSame( 10, Verifier::expectedTargetCount( 10, 0 ) );
		$this->assertSame( 0, Verifier::expectedTargetCount( 5, 9 ) );
	}

	/**
	 * D-09: rows are matched by primary key, not by position (a target
	 * row order that differs from the source must not create false
	 * mismatches).
	 */
	public function test_row_key_is_built_from_the_primary_key(): void {
		$this->assertSame( '7', Verifier::row_key( array( 'ID' => '7', 't' => 'x' ), array( 'ID' ) ) );
		$this->assertSame( "7\x00x", Verifier::row_key( array( 'a' => '7', 'b' => 'x' ), array( 'a', 'b' ) ) );
		$this->assertSame( '', Verifier::row_key( array( 'ID' => '7' ), array() ) );
	}

	public function test_rows_are_matched_by_key(): void {
		$source = array(
			array( 'ID' => '1', 't' => 'a' ),
			array( 'ID' => '2', 't' => 'b' ),
		);
		// The target returns the same rows in the opposite order.
		$target = array(
			array( 'ID' => '2', 't' => 'b' ),
			array( 'ID' => '1', 't' => 'a' ),
		);
		$this->assertSame( 0, Verifier::compare_rows( $source, $target, array( 'ID' ), array( 'int', 'varchar' ), array( 'ID', 't' ) )['mismatches'] );
		// A real difference is still reported.
		$target[0]['t'] = 'changed';
		$this->assertSame( 1, Verifier::compare_rows( $source, $target, array( 'ID' ), array( 'int', 'varchar' ), array( 'ID', 't' ) )['mismatches'] );
		// A row that only exists on one side is a mismatch too.
		$this->assertSame( 1, Verifier::compare_rows( array( array( 'ID' => '9' ) ), $target, array( 'ID' ), array( 'int' ), array( 'ID' ) )['missing'] );
	}
}
