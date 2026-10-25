<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Migrate\MigrateDataError;
use Slonik\Migrate\RowTransform;
use Slonik\Migrate\SkipRowSignal;

final class RowTransformTest extends TestCase {

	/**
	 * The copy has to report the NUL bytes it removes: PostgreSQL text
	 * cannot store them (audit D-63).
	 */
	public function test_row_counts_the_removed_nul_bytes(): void {
		$columns = array( 'id', 'v' );
		$colmeta = array(
			'id' => array( 'type' => 'int', 'unsigned' => false, 'datetime_precision' => 0 ),
			'v'  => array( 'type' => 'longtext', 'unsigned' => false, 'datetime_precision' => 0 ),
		);
		$row = RowTransform::row( array( 'id' => '1', 'v' => "a\0b\0" ), $columns, $colmeta );
		$this->assertSame( array( '1', 'ab' ), $row['cells'] );
		$this->assertSame( 2, $row['nul'] );

		$clean = RowTransform::row( array( 'id' => '2', 'v' => 'plain' ), $columns, $colmeta );
		$this->assertSame( 0, $clean['nul'] );
	}

	public function test_null_passes_through(): void {
		$this->assertSame(
			array( 'value' => null, 'nul_removed' => 0, 'micro_trimmed' => false ),
			RowTransform::value( null, array( 'type' => 'varchar' ) )
		);
	}

	public function test_zero_date_sentinels_p06(): void {
		$this->assertSame( '0001-01-01', RowTransform::value( '0000-00-00', array( 'type' => 'date' ) )['value'] );
		$this->assertSame( '0001-01-01 00:00:00', RowTransform::value( '0000-00-00 00:00:00', array( 'type' => 'datetime' ) )['value'] );
		$this->assertSame( '0000-00-00', RowTransform::value( '0000-00-00', array( 'type' => 'varchar' ) )['value'] );
		$this->assertSame( '2024-02-29', RowTransform::value( '2024-02-29', array( 'type' => 'date' ) )['value'] );
		$this->assertSame( '0001-01-01 00:00:00', RowTransform::value( '0000-00-00', array( 'type' => 'timestamp' ) )['value'] );
	}

	public function test_microsecond_timestamps_trimmed(): void {
		$out = RowTransform::value( '2021-06-01 10:20:30.123456', array( 'type' => 'datetime', 'datetime_precision' => 6 ) );
		$this->assertSame( '2021-06-01 10:20:30', $out['value'] );
		$this->assertTrue( $out['micro_trimmed'] );

		$out2 = RowTransform::value( '2021-06-01 10:20:30', array( 'type' => 'datetime', 'datetime_precision' => 0 ) );
		$this->assertSame( '2021-06-01 10:20:30', $out2['value'] );
		$this->assertFalse( $out2['micro_trimmed'] );
	}

	public function test_nul_removed_in_text_not_binary(): void {
		$out = RowTransform::value( "ab\0cd\0", array( 'type' => 'varchar' ) );
		$this->assertSame( 'abcd', $out['value'] );
		$this->assertSame( 2, $out['nul_removed'] );

		$raw = "b\0in";
		$out2 = RowTransform::value( $raw, array( 'type' => 'blob' ) );
		// A binary column targets bytea: the value goes in PostgreSQL's
		// hex input format ("\x…"), the raw bytes would be refused by
		// the bytea input ("invalid input syntax for type bytea").
		$this->assertSame( '\x' . bin2hex( $raw ), $out2['value'] );
		$this->assertSame( 0, $out2['nul_removed'] );

		$out3 = RowTransform::value( "\0", array( 'type' => 'binary', 'unsigned' => false ) );
		$this->assertSame( '\x00', $out3['value'] );

		// VARBINARY is binary too: it must not lose its NUL bytes.
		$out4 = RowTransform::value( "ab\0cd", array( 'type' => 'varbinary' ) );
		$this->assertSame( '\x6162006364', $out4['value'] );
		$this->assertSame( 0, $out4['nul_removed'] );
	}

	/**
	 * D-74: a column whose target type is overridden to bytea (the
	 * ActionScheduler `schedule` column) keeps its bytes: the payload is
	 * a serialized PHP object and the NUL bytes are part of it.
	 */
	public function test_a_column_overridden_to_bytea_keeps_its_bytes(): void {
		$raw = "O:30:\"S\":1:{s:4:\"\0*\0a\";i:1;}";
		$out = RowTransform::value( $raw, array( 'type' => 'longtext', 'binary' => true ) );
		$this->assertSame( '\x' . bin2hex( $raw ), $out['value'] );
		$this->assertSame( 0, $out['nul_removed'] );
	}

	public function test_invalid_utf8_policies(): void {
		$bad = "ok \xC3\x28 bad"; // invalid sequence

		try {
			RowTransform::value( $bad, array( 'type' => 'text' ), 'abort' );
			$this->fail( 'abort policy must throw' );
		} catch ( MigrateDataError $e ) {
			$this->assertStringContainsString( 'invalid UTF-8', $e->getMessage() );
		}

		$replaced = RowTransform::value( $bad, array( 'type' => 'text' ), 'replace' )['value'];
		$this->assertNotFalse( mb_check_encoding( $replaced, 'UTF-8' ) );
		$this->assertStringContainsString( "\u{FFFD}", $replaced );

		try {
			RowTransform::value( $bad, array( 'type' => 'varchar' ), 'skip-row' );
			$this->fail( 'skip-row policy must signal' );
		} catch ( SkipRowSignal $e ) {
			$this->assertStringNotContainsString( 'table', $e->getMessage() );
		}

		// A binary column keeps its bytes regardless of the policy (in
		// PostgreSQL's hex input format).
		$this->assertSame( '\x' . bin2hex( $bad ), RowTransform::value( $bad, array( 'type' => 'blob' ), 'abort' )['value'] );
	}

	public function test_unsigned_overflow_rejected_no_truncation(): void {
		try {
			RowTransform::value( '4294967296', array( 'type' => 'int', 'unsigned' => true ) );
			$this->fail( 'overflow must throw' );
		} catch ( MigrateDataError $e ) {
			$this->assertStringContainsString( 'overflow', $e->getMessage() );
		}
		$this->assertSame( '4294967295', RowTransform::value( '4294967295', array( 'type' => 'int', 'unsigned' => true ) )['value'] );
		$this->assertSame( '18446744073709551615', RowTransform::value( '18446744073709551615', array( 'type' => 'bigint', 'unsigned' => true ) )['value'] );
		try {
			RowTransform::value( '18446744073709551616', array( 'type' => 'bigint', 'unsigned' => true ) );
			$this->fail( 'bigint overflow must throw' );
		} catch ( MigrateDataError $e ) {
		}
	}

	public function test_signed_and_non_numeric_values_pass(): void {
		$this->assertSame( '-7', RowTransform::value( '-7', array( 'type' => 'int', 'unsigned' => false ) )['value'] );
		$this->assertSame( '255', RowTransform::value( '255', array( 'type' => 'tinyint', 'unsigned' => true ) )['value'] );
		// non-numeric garbage is not our business here: the driver surfaces it
		$this->assertSame( 'nope', RowTransform::value( 'nope', array( 'type' => 'int', 'unsigned' => true ) )['value'] );
	}
}
