<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Cli\ManageCommand;

final class ManageCommandTest extends TestCase {

	public function test_preflight_gate(): void {
		$this->assertTrue( ManageCommand::preflightOk( array(), false ) );
		$this->assertFalse( ManageCommand::preflightOk( array( 'block' ), false ) );
		$this->assertTrue( ManageCommand::preflightOk( array( 'block' ), true ) );
		$this->assertTrue( ManageCommand::preflightOk( array( 'warn', 'ok' ), false ) );
	}

	public function test_guard_hint_names_shipped_path(): void {
		$this->assertStringContainsString( 'wp-cli-guard.php', ManageCommand::guardHint() );
		$this->assertStringNotContainsString( '/tools/cli/', ManageCommand::guardHint() );
	}

	public function test_render_chain_status(): void {		$out = ManageCommand::renderChainStatus(
			array(
				'state'  => 'chained',
				'class'  => 'A',
				'backup' => true,
				'file'   => '/wp/db.php',
			)
		);
		$this->assertStringContainsString( 'chained', $out );
		$this->assertStringContainsString( 'A', $out );
		$out = ManageCommand::renderChainStatus(
			array(
				'state'  => 'none',
				'class'  => null,
				'backup' => false,
				'file'   => '/wp/db.php',
			)
		);
		$this->assertStringContainsString( 'none', $out );
	}

	public function test_uninstall_refusal_while_pg_mode_is_on(): void {
		$this->assertSame( '', ManageCommand::uninstallRefusal( false ) );
		$refusal = ManageCommand::uninstallRefusal( true );
		$this->assertStringContainsString( 'PostgreSQL', $refusal );
		$this->assertStringContainsString( 'MySQL', $refusal );
	}
}
