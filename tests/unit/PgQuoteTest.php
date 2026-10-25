<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Sql\PgQuote;

final class PgQuoteTest extends TestCase {

	public function test_backslash_is_literal_per_s09(): void {
		// Decoded a\b must NOT be doubled: PG standard strings keep \ literal.
		$this->assertSame( "'a\\b'", PgQuote::string( "a\\b" ) );
	}

	public function test_single_quote_doubled(): void {
		$this->assertSame( "'It''s'", PgQuote::string( "It's" ) );
	}

	public function test_newline_raw(): void {
		$this->assertSame( "'a\nb'", PgQuote::string( "a\nb" ) );
	}
}
