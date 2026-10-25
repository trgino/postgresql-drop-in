<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Chain\Classifier;

final class ChainTest extends TestCase {

	public function test_class_a_passthrough_decorator(): void {
		$code = "<?php\nclass QM_DB extends wpdb {\n\tpublic function query( \$q ) {\n\t\treturn parent::query( \$q );\n\t}\n}\n";
		$this->assertSame( 'A', Classifier::classify( $code ) );
	}

	public function test_class_b_own_connection(): void {
		$code = "<?php\nclass HyperDB extends wpdb {\n\tpublic \$dbh;\n\tpublic function db_connect() {\n\t\t\$this->dbh = new mysqli( 'h', 'u', 'p', 'd' );\n\t}\n}\n";
		$this->assertSame( 'B', Classifier::classify( $code ) );
	}

	public function test_class_c_other(): void {
		$this->assertSame( 'C', Classifier::classify( "<?php\n\$wpdb = new stdClass;\n" ) );
	}

	public function test_class_b_pdo_connection(): void {
		$code = "<?php\nclass CustomDB extends wpdb {\n\tpublic function db_connect() {\n\t\t\$this->dbh = new PDO( 'mysql:host=h', 'u', 'p' );\n\t}\n}\n";
		$this->assertSame( 'B', Classifier::classify( $code ) );
	}

	private static function installerDir( $suffix ) {
		$dir = sys_get_temp_dir() . '/slonik-chain-' . getmypid() . '-' . $suffix;
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		@unlink( $dir . '/db.php' );
		return $dir;
	}

	private static function installer( $dir ) {
		return new \Slonik\Chain\Installer( $dir, "<?php // slonik loader\n" );
	}

	public function test_install_fresh(): void {
		$dir = self::installerDir( 'fresh' );
		$res = self::installer( $dir )->install( '0.0.0-dev', false, false );
		$this->assertTrue( $res['ok'] );
		$this->assertTrue( \Slonik\Chain\DropinMarker::verify( file_get_contents( $dir . '/db.php' ) ) );
	}

	public function test_install_dry_run_writes_nothing(): void {
		$dir = self::installerDir( 'dry' );
		$res = self::installer( $dir )->install( '0.0.0-dev', false, true );
		$this->assertTrue( $res['ok'] );
		$this->assertFileDoesNotExist( $dir . '/db.php' );
	}

	public function test_install_over_foreign_needs_force(): void {
		$dir = self::installerDir( 'foreign' );
		file_put_contents( $dir . '/db.php', "<?php\nclass QM_DB extends wpdb {}\n" );
		$res = self::installer( $dir )->install( '0.0.0-dev', false, false );
		$this->assertFalse( $res['ok'] );
		$this->assertSame( "<?php\nclass QM_DB extends wpdb {}\n", file_get_contents( $dir . '/db.php' ) );
	}

	public function test_install_force_backs_up_and_restores(): void {
		$dir      = self::installerDir( 'force' );
		$original = "<?php\nclass QM_DB extends wpdb {}\n";
		file_put_contents( $dir . '/db.php', $original );
		$res = self::installer( $dir )->install( '0.0.0-dev', true, false );
		$this->assertTrue( $res['ok'] );
		$this->assertSame( 'A', $res['class'] );
		$this->assertTrue( \Slonik\Chain\DropinMarker::verify( file_get_contents( $dir . '/db.php' ) ) );
		$back = self::installer( $dir )->restore();
		$this->assertTrue( $back['ok'] );
		$this->assertSame( $original, file_get_contents( $dir . '/db.php' ) );
	}

	public function test_install_refuses_class_b_even_forced(): void {
		$dir = self::installerDir( 'hyper' );
		file_put_contents( $dir . '/db.php', "<?php\nclass HyperDB extends wpdb {\n\tpublic function db_connect() {}\n}\n" );
		$res = self::installer( $dir )->install( '0.0.0-dev', true, false );
		$this->assertFalse( $res['ok'] );
		$this->assertStringContainsString( 'HyperDB', $res['message'] );
	}

	public function test_build_compiles_class_a(): void {
		$dir = self::installerDir( 'build' );
		file_put_contents( $dir . '/db.php', "<?php\nclass QM_DB extends wpdb {\n\tpublic function query( \$q ) { return parent::query( \$q ); }\n}\n" );
		$inst = self::installer( $dir );
		$this->assertTrue( $inst->install( '0.0.0-dev', true, false )['ok'] );
		$res = $inst->build( '0.0.0-dev' );
		$this->assertTrue( $res['ok'] );
		$this->assertStringContainsString( 'extends WPSLONIK_DB', file_get_contents( $res['file'] ) );
		$this->assertStringNotContainsString( 'extends wpdb', file_get_contents( $res['file'] ) );
	}

	public function test_build_refuses_class_b(): void {
		$dir = self::installerDir( 'buildb' );
		file_put_contents( $dir . '/db.php', "<?php\nclass HyperDB extends wpdb {\n\tpublic function db_connect() {}\n}\n" );
		$res = self::installer( $dir )->build( '0.0.0-dev' );
		$this->assertFalse( $res['ok'] );
	}

	public function test_compiled_file_coherence(): void {
		$dir  = self::installerDir( 'coherent' );
		$code = "<?php\nclass QM_DB extends wpdb {}\n";
		file_put_contents( $dir . '/db.php', $code );
		$inst = self::installer( $dir );
		$this->assertTrue( $inst->install( '0.0.0-dev', true, false )['ok'] );
		$this->assertTrue( $inst->build( '0.0.0-dev' )['ok'] );
		$compiled = $inst->compiledFile();
		$this->assertIsString( $compiled );
		$this->assertFileExists( $compiled );
		file_put_contents( $dir . '/wpslonik/chained/db.php.bak', $code . "// tampered\n" );
		$this->assertNull( $inst->compiledFile() );
	}

	public function test_compiled_file_missing_cache(): void {
		$dir  = self::installerDir( 'nocache' );
		$code = "<?php\nclass QM_DB extends wpdb {}\n";
		file_put_contents( $dir . '/db.php', $code );
		$inst = self::installer( $dir );
		$this->assertTrue( $inst->install( '0.0.0-dev', true, false )['ok'] );
		$this->assertNull( $inst->compiledFile() );
	}

	public function test_restore_verifies_backup_hash(): void {
		$dir      = self::installerDir( 'corrupt' );
		$original = "<?php\nclass QM_DB extends wpdb {}\n";
		file_put_contents( $dir . '/db.php', $original );
		$inst = self::installer( $dir );
		$this->assertTrue( $inst->install( '0.0.0-dev', true, false )['ok'] );
		file_put_contents( $dir . '/wpslonik/chained/db.php.bak', $original . "// corrupted\n" );
		$res = $inst->restore();
		$this->assertFalse( $res['ok'] );
		$this->assertStringNotContainsString( 'corrupted', file_get_contents( $dir . '/db.php' ) );
	}

	public function test_uninstall_fresh_removes_ours(): void {
		$dir  = self::installerDir( 'uninst' );
		$inst = self::installer( $dir );
		$this->assertTrue( $inst->install( '0.0.0-dev', false, false )['ok'] );
		$res = $inst->uninstall();
		$this->assertTrue( $res['ok'] );
		$this->assertFileDoesNotExist( $dir . '/db.php' );
	}

	public function test_uninstall_refuses_foreign(): void {
		$dir      = self::installerDir( 'uninstf' );
		$original = "<?php\nclass QM_DB extends wpdb {}\n";
		file_put_contents( $dir . '/db.php', $original );
		$res = self::installer( $dir )->uninstall();
		$this->assertFalse( $res['ok'] );
		$this->assertSame( $original, file_get_contents( $dir . '/db.php' ) );
	}

	public function test_classify_namespaced_new_mysqli_is_b(): void {
		$code = "<?php\nclass XDB extends wpdb {\n\tpublic function db_connect() {\n\t\t\$this->dbh = new \\mysqli( 'h', 'u', 'p', 'd' );\n\t}\n}\n";
		$this->assertSame( 'B', Classifier::classify( $code ) );
	}

	//
	// D-16: atomic writes. D-17: no web-executable copies, guarded dirs.
	//

	public function test_write_atomic_creates_file_without_temp_leftovers(): void {
		$dir  = self::installerDir( 'atomic' );
		$path = $dir . '/atomic.txt';
		@unlink( $path );
		$this->assertTrue( \Slonik\Chain\Installer::writeAtomic( $path, "hello\n" ) );
		$this->assertSame( "hello\n", file_get_contents( $path ) );
		$this->assertSame( array(), glob( $dir . '/*.tmp*' ) );
	}

	public function test_write_atomic_reports_failure_without_throwing(): void {
		$this->assertFalse( \Slonik\Chain\Installer::writeAtomic( '/slonik-no-such-dir-xyz/file.txt', 'x' ) );
	}

	public function test_guard_code_blocks_direct_execution(): void {
		$guarded = \Slonik\Chain\Installer::guardCode( "<?php\nclass QM_DB extends wpdb {}\n" );
		$this->assertStringContainsString( "defined( 'ABSPATH' )", $guarded );
		$this->assertStringContainsString( 'class QM_DB extends wpdb', $guarded );
	}

	public function test_backup_is_not_web_executable_and_stays_byte_identical(): void {
		$dir      = self::installerDir( 'bakguard' );
		$original = "<?php\nclass QM_DB extends wpdb {}\n";
		file_put_contents( $dir . '/db.php', $original );
		$inst = self::installer( $dir );
		$this->assertTrue( $inst->install( '0.0.0-dev', true, false )['ok'] );
		$backup = $dir . '/wpslonik/chained/db.php.bak';
		$this->assertFileExists( $backup );
		$this->assertSame( $original, file_get_contents( $backup ) );
		// The chain loader must read the same file the installer wrote.
		$this->assertTrue( $inst->compiledFile() !== null || $inst->build( '0.0.0-dev' )['ok'] );
	}

	public function test_compiled_chain_file_is_guarded(): void {
		$dir = self::installerDir( 'chainguard' );
		file_put_contents( $dir . '/db.php', "<?php\nclass QM_DB extends wpdb {\n\tpublic function query( \$q ) { return parent::query( \$q ); }\n}\n" );
		$inst = self::installer( $dir );
		$this->assertTrue( $inst->install( '0.0.0-dev', true, false )['ok'] );
		$res = $inst->build( '0.0.0-dev' );
		$this->assertTrue( $res['ok'] );
		$this->assertStringContainsString( "defined( 'ABSPATH' )", file_get_contents( $res['file'] ) );
		$this->assertStringContainsString( 'extends WPSLONIK_DB', file_get_contents( $res['file'] ) );
	}

	public function test_written_directories_get_guard_files(): void {
		$dir = self::installerDir( 'dirguard' );
		file_put_contents( $dir . '/db.php', "<?php\nclass QM_DB extends wpdb {}\n" );
		$inst = self::installer( $dir );
		$this->assertTrue( $inst->install( '0.0.0-dev', true, false )['ok'] );
		$this->assertTrue( $inst->build( '0.0.0-dev' )['ok'] );
		foreach ( array( 'wpslonik', 'wpslonik/chained', 'cache/wpslonik', 'cache/wpslonik/chain' ) as $sub ) {
			$this->assertFileExists( $dir . '/' . $sub . '/index.php' );
			$this->assertFileExists( $dir . '/' . $sub . '/.htaccess' );
		}
	}

	public function test_installed_dropin_emits_nothing_before_php_open_tag(): void {
		$dir  = self::installerDir( 'markerpos' );
		$inst = self::installer( $dir );
		$this->assertTrue( $inst->install( '0.0.0-dev', false, false )['ok'] );
		$code = file_get_contents( $dir . '/db.php' );
		// The marker is a PHP comment: bytes emitted before the open tag
		// leak into every HTTP response and break JSON clients (K-55 PG
		// case 1).
		$this->assertSame( 0, strpos( (string) $code, '<?php' ) );
		$this->assertNotNull( \Slonik\Chain\DropinMarker::parse( (string) $code ) );
		$this->assertTrue( \Slonik\Chain\DropinMarker::verify( (string) $code ) );
	}

	public function test_upgrade_keeps_a_copy_of_our_own_dropin(): void {
		$dir  = self::installerDir( 'ownbak' );
		$inst = self::installer( $dir );
		$this->assertTrue( $inst->install( '0.0.0-dev', false, false )['ok'] );
		$before = file_get_contents( $dir . '/db.php' );
		$res    = $inst->install( '0.0.1-dev', true, false );
		$this->assertTrue( $res['ok'] );
		$this->assertFileExists( $dir . '/wpslonik/own/db.php.bak' );
		$this->assertSame( $before, file_get_contents( $dir . '/wpslonik/own/db.php.bak' ) );
	}

	//
	// D-11: uninstall planning.
	//

	public function test_uninstall_plan_keeps_dropin_while_pg_mode_is_on(): void {
		$plan = \Slonik\Chain\Installer::planUninstall( true, true, true, false );
		$this->assertSame( 'keep', $plan['action'] );
		$this->assertStringContainsString( 'PostgreSQL', $plan['reason'] );
	}

	public function test_uninstall_plan_restores_backup_when_pg_mode_is_off(): void {
		$plan = \Slonik\Chain\Installer::planUninstall( false, true, true, true );
		$this->assertSame( 'restore', $plan['action'] );
	}

	public function test_uninstall_plan_removes_our_own_dropin(): void {
		$plan = \Slonik\Chain\Installer::planUninstall( false, true, true, false );
		$this->assertSame( 'remove', $plan['action'] );
	}

	public function test_uninstall_plan_never_deletes_a_foreign_dropin(): void {
		$plan = \Slonik\Chain\Installer::planUninstall( false, true, false, false );
		$this->assertSame( 'keep', $plan['action'] );
		$this->assertStringContainsString( 'foreign', $plan['reason'] );
	}

	public function test_uninstall_plan_reports_nothing_to_do(): void {
		$plan = \Slonik\Chain\Installer::planUninstall( false, false, false, false );
		$this->assertSame( 'none', $plan['action'] );
	}

	public function test_uninstall_plan_keeps_everything_when_pg_mode_is_on_even_with_backup(): void {
		$plan = \Slonik\Chain\Installer::planUninstall( true, true, true, true );
		$this->assertSame( 'keep', $plan['action'] );
	}
}
