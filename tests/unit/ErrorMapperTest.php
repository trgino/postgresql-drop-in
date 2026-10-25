<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Runtime\ErrorMapper;

final class ErrorMapperTest extends TestCase {

	public function test_unique_violation(): void {
		$this->assertSame(
			"Duplicate entry '?' for key 'x'",
			ErrorMapper::map( '23505', 'duplicate key value violates unique constraint "x"' )
		);
	}

	public function test_not_null(): void {
		$this->assertSame(
			"Column 'c' cannot be null",
			ErrorMapper::map( '23502', 'null value in column "c"' )
		);
	}

	public function test_truncation(): void {
		$this->assertSame(
			"Data too long for column '?' at row 1",
			ErrorMapper::map( '22001', 'value too long' )
		);
	}

	public function test_undefined_table(): void {
		$this->assertSame(
			"Table 't' doesn't exist",
			ErrorMapper::map( '42P01', 'relation "t" does not exist' )
		);
	}

	public function test_undefined_column(): void {
		$this->assertSame(
			"Unknown column 'c' in '?'",
			ErrorMapper::map( '42703', 'column "c" does not exist' )
		);
	}

	public function test_syntax(): void {
		$this->assertSame(
			'You have an error in your SQL syntax; double-check the translated query',
			ErrorMapper::map( '42601', 'syntax error at or near "SEL"' )
		);
	}

	public function test_deadlock(): void {
		$this->assertSame(
			'Deadlock found when trying to get lock; try restarting transaction',
			ErrorMapper::map( '40P01', 'deadlock detected' )
		);
	}

	public function test_timeout(): void {
		$this->assertSame(
			'Query execution was interrupted (timeout)',
			ErrorMapper::map( '57014', 'canceling statement due to statement timeout' )
		);
	}

	public function test_fallback_redacted(): void {
		$this->assertSame(
			'DB error [XX999]',
			ErrorMapper::map( 'XX999', 'secret value 123' )
		);
	}
}
