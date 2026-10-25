<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Cli\DbGuard;
use Slonik\Health\Checks\GuardCheck;
use Slonik\Health\Env;
use Slonik\Health\Runner;

final class GuardTest extends TestCase {

	private static function cfgDir( $suffix, $wp_config ) {
		$dir = sys_get_temp_dir() . '/slonik-guard-' . getmypid() . '-' . $suffix;
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		file_put_contents( $dir . '/wp-config.php', $wp_config );
		return $dir;
	}

	public function test_enabled_true(): void {
		$dir = self::cfgDir( 'on', "<?php\ndefine( 'WPSLONIK_ENABLED', true );\n" );
		$this->assertTrue( DbGuard::readEnabled( $dir . '/wp-config.php' ) );
	}

	public function test_enabled_spacing_variants(): void {
		$dir = self::cfgDir( 'sp', "<?php\ndefine('WPSLONIK_ENABLED',true);\n" );
		$this->assertTrue( DbGuard::readEnabled( $dir . '/wp-config.php' ) );
		$dir = self::cfgDir( 'cond', "<?php\nif ( ! defined( 'WPSLONIK_ENABLED' ) ) {\n\tdefine( 'WPSLONIK_ENABLED', true );\n}\n" );
		$this->assertTrue( DbGuard::readEnabled( $dir . '/wp-config.php' ) );
	}

	public function test_enabled_false_and_absent(): void {
		$dir = self::cfgDir( 'off', "<?php\ndefine( 'WPSLONIK_ENABLED', false );\n" );
		$this->assertFalse( DbGuard::readEnabled( $dir . '/wp-config.php' ) );
		$dir = self::cfgDir( 'abs', "<?php\n// nothing\n" );
		$this->assertNull( DbGuard::readEnabled( $dir . '/wp-config.php' ) );
	}

	public function test_enabled_dynamic_is_undecidable(): void {
		$dir = self::cfgDir( 'dyn', "<?php\ndefine( 'WPSLONIK_ENABLED', getenv( 'X' ) );\n" );
		$this->assertNull( DbGuard::readEnabled( $dir . '/wp-config.php' ) );
	}

	public function test_enabled_int_is_undecidable(): void {
		$dir = self::cfgDir( 'int', "<?php\ndefine( 'WPSLONIK_ENABLED', 1 );\n" );
		$this->assertNull( DbGuard::readEnabled( $dir . '/wp-config.php' ) );
	}

	public function test_should_block_matrix(): void {
		$this->assertTrue( DbGuard::shouldBlock( true, '0' ) );
		$this->assertTrue( DbGuard::shouldBlock( true, '' ) );
		$this->assertFalse( DbGuard::shouldBlock( true, '1' ) );
		$this->assertFalse( DbGuard::shouldBlock( false, '' ) );
		$this->assertFalse( DbGuard::shouldBlock( null, '' ) );
	}

	/**
	 * D-31: the bypass works as an environment variable and as a
	 * constant in wp-config.php.
	 */
	public function test_allow_value_accepts_env_and_constant(): void {
		$this->assertSame( '1', DbGuard::allowValue( '1', '' ) );
		$this->assertSame( '1', DbGuard::allowValue( '', '1' ) );
		$this->assertSame( '', DbGuard::allowValue( '', '' ) );
		$this->assertSame( '', DbGuard::allowValue( '0', '0' ) );
	}

	public function test_read_constant_reads_a_define(): void {
		$dir = self::cfgDir( 'const', "<?php\ndefine( 'WPSLONIK_ALLOW_WP_DB', 1 );\ndefine( 'WPSLONIK_ENABLED', true );\n" );
		$this->assertSame( '1', DbGuard::readConstant( $dir . '/wp-config.php', 'WPSLONIK_ALLOW_WP_DB' ) );
		$this->assertNull( DbGuard::readConstant( $dir . '/wp-config.php', 'WPSLONIK_MISSING' ) );
	}

	public function test_find_config_walks_up(): void {
		$dir = self::cfgDir( 'walk', "<?php\n" );
		if ( ! is_dir( $dir . '/a/b' ) ) {
			mkdir( $dir . '/a/b', 0777, true );
		}
		$this->assertSame( $dir . '/wp-config.php', DbGuard::findConfig( $dir . '/a/b' ) );
		// The walk goes up; only assert that nothing outside the temp
		// root is picked up (the machine layout is not the test's).
		$found = DbGuard::findConfig( sys_get_temp_dir() . '/slonik-guard-nothing-here' );
		$this->assertTrue(
			null === $found || 0 === strpos( (string) $found, sys_get_temp_dir() ),
			'config found outside the temp dir: ' . (string) $found
		);
	}

	public function test_guard_check_warns_without_wiring(): void {
		$env     = Env::fromValues( array( 'content_dir' => sys_get_temp_dir() . '/slonik-guard-nothing-here' ) );
		$results = Runner::run( array( new GuardCheck( array() ) ), $env );
		$this->assertSame( 'warn', $results['H-C25']->status );
	}

	public function test_guard_check_ok_with_wiring(): void {
		$dir = self::cfgDir( 'yml', "<?php\n" );
		file_put_contents( $dir . '/wp-cli.yml', "require:\n  - tools/cli/guard-db.php\n" );
		$results = Runner::run(
			array( new GuardCheck( array( $dir . '/wp-cli.yml' ) ) ),
			Env::fromValues( array( 'content_dir' => $dir ) )
		);
		$this->assertSame( 'ok', $results['H-C25']->status );
	}

	public function test_guard_check_ok_with_shipped_guard(): void {
		$dir = self::cfgDir( 'yml-ship', "<?php\n" );
		file_put_contents( $dir . '/wp-cli.yml', "require:\n  - wp-content/plugins/slonik/wp-cli-guard.php\n" );
		$results = Runner::run(
			array( new GuardCheck( array( $dir . '/wp-cli.yml' ) ) ),
			Env::fromValues( array( 'content_dir' => $dir ) )
		);
		$this->assertSame( 'ok', $results['H-C25']->status );
	}

	public function test_shipped_wp_cli_guard_is_plugin_relative(): void {
		$file = dirname( __DIR__, 2 ) . '/wp-cli-guard.php';
		$this->assertFileExists( $file );
		$src = (string) file_get_contents( $file );
		$this->assertStringContainsString( 'before_invoke:db', $src );
		$this->assertStringContainsString( "/src/Cli/DbGuard.php'", $src );
		$this->assertStringNotContainsString( '/tools/cli/guard-db.php', $src );
	}
}
