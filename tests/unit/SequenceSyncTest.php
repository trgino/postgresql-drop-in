<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Schema\Catalog;
use Slonik\Schema\SequenceSync;

final class SequenceSyncTest extends TestCase {

	public function test_sql_uses_setval_with_max(): void {
		$this->assertSame(
			'SELECT setval(pg_get_serial_sequence(\'"t"\', \'ID\'), GREATEST(COALESCE((SELECT MAX("ID") FROM "t"), 0), COALESCE(pg_sequence_last_value(pg_get_serial_sequence(\'"t"\', \'ID\')), 0), 1), COALESCE((SELECT MAX("ID") FROM "t"), 0) > 0)',
			SequenceSync::sql( 't', 'ID' )
		);
	}

	public function test_needs_sync_matches_identity(): void {
		$catalog = Catalog::fromRows(
			array(
				array( 'table' => 't', 'column' => 'ID', 'nullable' => false, 'default' => null, 'identity' => true ),
				array( 'table' => 't', 'column' => 'a', 'nullable' => true, 'default' => null ),
			),
			array()
		);
		$this->assertSame( 'ID', SequenceSync::needs_sync( $catalog, 't', array( 'ID', 'a' ) ) );
		$this->assertNull( SequenceSync::needs_sync( $catalog, 't', array( 'a' ) ) );
		$this->assertNull( SequenceSync::needs_sync( null, 't', array( 'ID' ) ) );
	}
}
