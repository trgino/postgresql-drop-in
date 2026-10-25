<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Cli\DbTools;

final class DbToolsTest extends TestCase {

	/**
	 * D-23: restoring overwrites the target database and needs --yes.
	 */
	public function test_restore_requires_yes(): void {
		ob_start();
		$code = DbTools::transfer( array( 'file' => 'dump.sql' ), 'restore' );
		$out  = (string) ob_get_clean();
		$this->assertSame( 2, $code );
		$this->assertStringContainsString( '--yes', $out );
	}

	private static function conn() {
		return array( 'host' => '127.0.0.1', 'port' => 5433, 'user' => 'postgres', 'pass' => 's3cret-pw', 'name' => 'wordpress' );
	}

	public function test_dump_command_has_no_password(): void {
		$built = DbTools::buildDump( 'pg_dump', self::conn(), '/tmp/x.dump' );
		$this->assertSame(
			array( 'pg_dump', '-h', '127.0.0.1', '-p', '5433', '-U', 'postgres', '-d', 'wordpress', '-F', 'c', '-f', '/tmp/x.dump' ),
			$built['cmd']
		);
		$this->assertSame( 's3cret-pw', $built['env']['PGPASSWORD'] );
		$logged = DbTools::redacted( $built );
		$this->assertStringNotContainsString( 's3cret-pw', $logged );
		$this->assertStringContainsString( '***', $logged );
	}

	public function test_restore_command_shape(): void {
		$built = DbTools::buildRestore( 'pg_restore', self::conn(), '/tmp/x.dump' );
		$this->assertSame(
			array( 'pg_restore', '-h', '127.0.0.1', '-p', '5433', '-U', 'postgres', '-d', 'wordpress', '-c', '/tmp/x.dump' ),
			$built['cmd']
		);
	}

	public function test_missing_binary_fails(): void {
		$this->assertSame( 1, DbTools::runBinary( '/nonexistent-pg-dump-xyz', self::conn(), '/tmp/x.dump', 'dump' ) );
	}

	public function test_missing_file_is_usage_error(): void {
		$this->assertSame( 3, DbTools::transfer( array(), 'dump' ) );
		$this->assertSame( 3, DbTools::transfer( array(), 'restore' ) );
	}

	public function test_path_lookup_finds_fixture_binary(): void {
		$dir = sys_get_temp_dir() . '/slonik-path-' . getmypid();
		@mkdir( $dir, 0777, true );
		file_put_contents( $dir . '/pg_dump', "#!/bin/sh\nexit 0\n" );
		$found = DbTools::findBinary( 'pg_dump', $dir . PATH_SEPARATOR . getenv( 'PATH' ) );
		$this->assertSame( $dir . DIRECTORY_SEPARATOR . 'pg_dump', $found );
		$this->assertNull( DbTools::findBinary( 'pg_dump-nope-xyz', $dir ) );
	}
}
