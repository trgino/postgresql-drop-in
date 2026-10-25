<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Migrate\CopyBatch;
use Slonik\Migrate\MigrateDataError;

final class CopyBatchTest extends TestCase {

	public function test_cell_escaping_copy_text_format(): void {
		$this->assertSame( CopyBatch::NULL_MARKER, CopyBatch::cell( null ) );
		$this->assertSame( 'plain', CopyBatch::cell( 'plain' ) );
		$this->assertSame( '\\\\', CopyBatch::cell( '\\' ) );
		$this->assertSame( 'a\\tb', CopyBatch::cell( "a\tb" ) );
		$this->assertSame( 'a\\nb', CopyBatch::cell( "a\nb" ) );
		$this->assertSame( 'a\\rb', CopyBatch::cell( "a\rb" ) );
	}

	/**
	 * PDO::pgsqlCopyFromArray does not pass the PostgreSQL "\N" marker
	 * through: the marker lands as the literal string "N" (audit D-62).
	 * The copy uses its own marker instead.
	 */
	public function test_the_null_marker_survives_the_pdo_copy_path(): void {
		$this->assertNotSame( '\\N', CopyBatch::NULL_MARKER );
		$this->assertStringNotContainsString( '\\', CopyBatch::NULL_MARKER );
		$this->assertStringNotContainsString( "\n", CopyBatch::NULL_MARKER );
		$this->assertStringNotContainsString( "\r", CopyBatch::NULL_MARKER );
	}

	public function test_a_value_equal_to_the_null_marker_is_refused(): void {
		$this->expectException( MigrateDataError::class );
		CopyBatch::cell( CopyBatch::NULL_MARKER );
	}

	public function test_row_batches_by_row_limit(): void {
		$rows = array(
			array( '1', 'a' ),
			array( '2', 'b' ),
			array( '3', 'c' ),
			array( '4', 'd' ),
			array( '5', 'e' ),
		);
		$batches = CopyBatch::batches( $rows, 2, 1024 * 1024 );
		$this->assertCount( 3, $batches );
		$this->assertSame( array( "1\ta", "2\tb" ), $batches[0] );
		$this->assertSame( array( "5\te" ), $batches[2] );
	}

	public function test_row_batches_by_bytes_limit(): void {
		$rows = array(
			array( str_repeat( 'x', 40 ) ),
			array( str_repeat( 'x', 40 ) ),
			array( str_repeat( 'x', 40 ) ),
		);
		$batches = CopyBatch::batches( $rows, 1000, 50 );
		$this->assertCount( 3, $batches );
	}

	public function test_empty_and_null_cells(): void {
		$lines = CopyBatch::batches( array( array( null, '' ), array( 7, null ) ), 100, 1024 );
		$this->assertSame( array( CopyBatch::NULL_MARKER . "\t", "7\t" . CopyBatch::NULL_MARKER ), $lines[0] );
	}
}
