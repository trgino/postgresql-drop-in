<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Cli\MigrateCommand;

final class MigrateCommandTest extends TestCase {

	/**
	 * D-19: a failed final phase (index/sequence/ANALYZE) is not a clean
	 * run: the exit code must warn.
	 */
	public function test_final_phase_errors_warn(): void {
		$this->assertSame( 1, \Slonik\Migrate\Runner::result_code( array( 'errors' => array( 'final phase: wp_posts: boom' ) ) ) );
		$this->assertSame( 0, \Slonik\Migrate\Runner::result_code( array( 'errors' => array() ) ) );
		$this->assertSame( 1, \Slonik\Migrate\Runner::result_code( array( 'errors' => array() ), array( 'warnings' => array( 'something' ) ) ) );
	}

	public function test_unknown_subcommand_is_usage(): void {
		ob_start();
		$code = MigrateCommand::dispatch( array( 'bogus' ), array() );
		$out  = (string) ob_get_clean();
		$this->assertSame( 3, $code );
		$this->assertStringContainsString( 'usage: wp slonik migrate', $out );
	}

	public function test_bare_invocation_is_usage(): void {
		ob_start();
		$code = MigrateCommand::dispatch( array(), array() );
		$out  = (string) ob_get_clean();
		$this->assertSame( 3, $code );
		$this->assertStringContainsString( '--dry-run', $out );
	}

	public function test_reset_target_without_yes_is_refused_at_definition_level(): void {
		// With no runtime defines the config would fail first (WP-CLI-only
		// path); the refusal order is exercised live in T8.
		ob_start();
		$code = MigrateCommand::dispatch( array( 'reset-target' ), array() );
		$out  = (string) ob_get_clean();
		$this->assertTrue( in_array( $code, array( 2, 3 ), true ), 'either usage (no defines) or blocker (no --yes); got ' . $code );
		$this->assertNotFalse( '' !== $out );
	}

	/**
	 * D-32: reset-target on an empty state must not report "done": the
	 * migrated tables are unknown, so the operator is told instead.
	 */
	public function test_reset_target_with_an_empty_state_is_a_blocker(): void {
		$outcome = \Slonik\Migrate\Runner::resetOutcome( array(), array( 'wpslonik_migration' => true ) );
		$this->assertSame( 'state', $outcome['action'] );

		$outcome = \Slonik\Migrate\Runner::resetOutcome( array(), array( 'wpslonik_migration' => true, 'wp_posts' => true ) );
		$this->assertSame( 'block', $outcome['action'] );
		$this->assertStringContainsString( 'wp_posts', $outcome['message'] );

		$outcome = \Slonik\Migrate\Runner::resetOutcome( array( 'wp_posts' => array( 'status' => 'done' ) ), array() );
		$this->assertSame( 'drop', $outcome['action'] );
	}
}
