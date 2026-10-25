<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Schema\CiMode;

final class CiModeTest extends TestCase {

	public function test_citext_is_used_when_the_extension_is_available(): void {
		$this->assertSame( CiMode::CITEXT, CiMode::effective( null, true ) );
		$this->assertSame( CiMode::CITEXT, CiMode::effective( CiMode::CITEXT, true ) );
	}

	public function test_degraded_is_used_without_the_extension(): void {
		$this->assertSame( CiMode::DEGRADED, CiMode::effective( null, false ) );
		// An explicit degraded setting wins even when citext exists.
		$this->assertSame( CiMode::DEGRADED, CiMode::effective( CiMode::DEGRADED, true ) );
	}

	public function test_an_unknown_setting_falls_back_to_the_capability(): void {
		$this->assertSame( CiMode::CITEXT, CiMode::effective( 'nonsense', true ) );
		$this->assertSame( CiMode::DEGRADED, CiMode::effective( 'nonsense', false ) );
	}

	public function test_the_mode_is_reported_with_its_consequences(): void {
		$citext   = CiMode::describe( CiMode::CITEXT );
		$degraded = CiMode::describe( CiMode::DEGRADED );
		$this->assertStringContainsString( 'citext', $citext['label'] );
		$this->assertStringContainsString( 'case-insensitive', strtolower( $citext['summary'] ) );
		$this->assertStringContainsString( 'lower()', $degraded['summary'] );
		$this->assertNotSame( $citext['summary'], $degraded['summary'] );
	}

	public function test_the_install_instructions_are_explicit(): void {
		$steps = CiMode::installSteps();
		$this->assertNotEmpty( $steps );
		$joined = implode( ' ', $steps );
		$this->assertStringContainsString( 'CREATE EXTENSION citext', $joined );
		$this->assertStringContainsString( 'superuser', $joined );
	}
}
