<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Runtime\ResultShaper;

final class ResultShaperTest extends TestCase {

	public function test_bool_mapping(): void {
		$this->assertSame( '1', ResultShaper::map_value( 'bool', 't' ) );
		$this->assertSame( '0', ResultShaper::map_value( 'bool', 'f' ) );
		$this->assertSame( '1', ResultShaper::map_value( 'bool', true ) );
		$this->assertSame( '0', ResultShaper::map_value( 'bool', false ) );
	}

	public function test_passthrough(): void {
		$this->assertSame( 't', ResultShaper::map_value( 'text', 't' ) );
		$this->assertSame( '5', ResultShaper::map_value( 'int8', '5' ) );
		$this->assertNull( ResultShaper::map_value( 'text', null ) );
	}

	public function test_zero_date_sentinel(): void {
		$this->assertSame( '0000-00-00 00:00:00', ResultShaper::map_value( 'timestamp', '0001-01-01 00:00:00' ) );
		$this->assertSame( '0000-00-00', ResultShaper::map_value( 'date', '0001-01-01' ) );
		$this->assertSame( '2026-10-14 01:02:03', ResultShaper::map_value( 'timestamp', '2026-10-14 01:02:03' ) );
	}

	public function test_bytea_hex_decoded(): void {
		$this->assertSame( 'AB', ResultShaper::map_value( 'bytea', '\x4142' ) );
		$this->assertSame( 'plain', ResultShaper::map_value( 'bytea', 'plain' ) );
	}
}
