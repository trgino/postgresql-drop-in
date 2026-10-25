<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The fixture wpdb class and the loader's global state must never collide
 * with the real wpdb stubs: the fixture mirrors the asserted contract
 * surface, so declaration order in the suite process does not matter.
 */
final class LoaderTest extends TestCase {

	public function test_transparent_without_flag(): void {
		self::boot_site();
		unset( $GLOBALS['wpdb'] );
		include dirname( __DIR__, 2 ) . '/dropin/db.php';
		$this->assertArrayNotHasKey( 'wpdb', $GLOBALS );
	}

	public function test_pg_mode_constructs_without_connecting(): void {
		self::boot_site();
		define( 'WPSLONIK_ENABLED', true );
		foreach ( array( 'WPSLONIK_DB_USER', 'WPSLONIK_DB_PASSWORD', 'WPSLONIK_DB_NAME', 'WPSLONIK_DB_HOST' ) as $const ) {
			define( $const, 'dummy' );
		}
		unset( $GLOBALS['wpdb'] );
		include dirname( __DIR__, 2 ) . '/dropin/db.php';
		$this->assertInstanceOf( 'WPSLONIK_DB', $GLOBALS['wpdb'] );
		$this->assertFalse( $GLOBALS['wpdb']->is_mysql );
		unset( $GLOBALS['wpdb'] );
	}

	public function test_loader_covers_double_quoted_strings(): void {
		self::boot_site();
		if ( ! defined( 'WPSLONIK_ENABLED' ) ) {
			define( 'WPSLONIK_ENABLED', true );
		}
		foreach ( array( 'WPSLONIK_DB_USER', 'WPSLONIK_DB_PASSWORD', 'WPSLONIK_DB_NAME', 'WPSLONIK_DB_HOST' ) as $const ) {
			if ( ! defined( $const ) ) {
				define( $const, 'dummy' );
			}
		}
		unset( $GLOBALS['wpdb'] );
		include dirname( __DIR__, 2 ) . '/dropin/db.php';
		$plan = \Slonik\Sql\MinTranslator::translate( 'SELECT "abc" FROM t' );
		$this->assertSame( "SELECT 'abc' FROM \"t\"", $plan->getSteps()[0]->getSql() );
		unset( $GLOBALS['wpdb'] );
	}

	public function test_loader_requires_every_src_file(): void {
		$loader = file_get_contents( dirname( __DIR__, 2 ) . '/dropin/db.php' );
		$root   = dirname( __DIR__, 2 ) . '/src';
		$iter   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		$missing = array();
		foreach ( $iter as $file ) {
			if ( '.' === substr( $file->getFilename(), 0, 1 ) ) {
				continue;
			}
			$rel = substr( $file->getPathname(), strlen( dirname( __DIR__, 2 ) ) );
			$rel = str_replace( '\\', '/', $rel );
			if ( false === strpos( $loader, "'" . $rel . "'" ) && false === strpos( $loader, '"' . $rel . '"' ) ) {
				$missing[] = $rel;
			}
		}
		$this->assertSame( array(), $missing, 'loader must require every src file (no autoloader in production)' );
	}

	public function test_loader_orders_interfaces_before_implementors(): void {
		$loader = file_get_contents( dirname( __DIR__, 2 ) . '/dropin/db.php' );
		$pos    = array();
		foreach ( explode( "\n", $loader ) as $i => $line ) {
			if ( 1 === preg_match( "#'/src/([A-Za-z/]+\\.php)'#", $line, $m ) ) {
				$pos[ '/src/' . $m[1] ] = $i;
			}
		}
		// Interfaces must load before the classes that implement them:
		// declare-time fatal otherwise (found live in T8).
		$iface_of = array(
			'/src/Migrate/MySqlSource.php' => '/src/Migrate/CopySource.php',
			'/src/Migrate/State.php'       => '/src/Migrate/StateStore.php',
		);
		foreach ( $iface_of as $class => $iface ) {
			$this->assertArrayHasKey( $iface, $pos );
			$this->assertArrayHasKey( $class, $pos );
			$this->assertLessThan( $pos[ $class ], $pos[ $iface ], basename( $iface ) . ' must load before ' . basename( $class ) );
		}
	}

	public function test_plugin_cli_requires_cover_cli_deps(): void {
		$plugin = file_get_contents( dirname( __DIR__, 2 ) . '/postgresql-drop-in.php' );
		$loader = file_get_contents( dirname( __DIR__, 2 ) . '/dropin/db.php' );
		$missing = array();
		foreach ( explode( "\n", $loader ) as $line ) {
			if ( 1 === preg_match( "#'/src/([A-Za-z/]+\\.php)'#", $line, $m ) ) {
				$rel = '/src/' . $m[1];
				if ( false === strpos( $plugin, "'" . $rel . "'" ) ) {
					$missing[] = $rel;
				}
			}
		}
		$this->assertSame( array(), $missing, 'postgresql-drop-in.php CLI file list must cover every dropin src require' );
	}

	public function test_loader_wires_runtime_compat_log(): void {
		$loader = (string) file_get_contents( dirname( __DIR__, 2 ) . '/dropin/db.php' );
		$this->assertStringContainsString( 'compat-log.jsonl', $loader );
		$this->assertStringContainsString( 'wp_mkdir_p', $loader );
		$this->assertStringContainsString( 'WPSLONIK_COMPAT_SLOW_MS', $loader );
	}

	public function test_loader_chains_class_a(): void {
		self::boot_site();
		if ( ! defined( 'WPSLONIK_ENABLED' ) ) {
			define( 'WPSLONIK_ENABLED', true );
		}
		foreach ( array( 'WPSLONIK_DB_USER', 'WPSLONIK_DB_PASSWORD', 'WPSLONIK_DB_NAME', 'WPSLONIK_DB_HOST' ) as $const ) {
			if ( ! defined( $const ) ) {
				define( $const, 'dummy' );
			}
		}
		$site = sys_get_temp_dir() . '/slonik-loader-' . getmypid();
		$code = "<?php\nclass LoaderChainedDB extends wpdb {}\n";
		@mkdir( $site . '/wpslonik/chained', 0777, true );
		@mkdir( $site . '/cache/wpslonik/chain', 0777, true );
		file_put_contents( $site . '/wpslonik/chained/db.php.bak', $code );
		file_put_contents( $site . '/wpslonik/chained/db.php.bak.sha256', hash( 'sha256', $code ) );
		$hash = hash( 'sha256', $code );
		file_put_contents(
			$site . '/cache/wpslonik/chain/db-' . $hash . '.php',
			\Slonik\Chain\Installer::guardCode( "<?php\nclass LoaderChainedDB extends WPSLONIK_DB {}\n" )
		);
		unset( $GLOBALS['wpdb'] );
		include dirname( __DIR__, 2 ) . '/dropin/db.php';
		$this->assertInstanceOf( 'LoaderChainedDB', $GLOBALS['wpdb'] );
		unset( $GLOBALS['wpdb'] );
		unlink( $site . '/wpslonik/chained/db.php.bak' );
		unlink( $site . '/wpslonik/chained/db.php.bak.sha256' );
		unlink( $site . '/cache/wpslonik/chain/db-' . $hash . '.php' );
	}

	public function test_loader_tampered_chain_falls_back(): void {
		self::boot_site();
		if ( ! defined( 'WPSLONIK_ENABLED' ) ) {
			define( 'WPSLONIK_ENABLED', true );
		}
		foreach ( array( 'WPSLONIK_DB_USER', 'WPSLONIK_DB_PASSWORD', 'WPSLONIK_DB_NAME', 'WPSLONIK_DB_HOST' ) as $const ) {
			if ( ! defined( $const ) ) {
				define( $const, 'dummy' );
			}
		}
		$site = sys_get_temp_dir() . '/slonik-loader-' . getmypid();
		$code = "<?php\nclass LoaderChainedDB extends wpdb {}\n";
		@mkdir( $site . '/wpslonik/chained', 0777, true );
		@mkdir( $site . '/cache/wpslonik/chain', 0777, true );
		file_put_contents( $site . '/wpslonik/chained/db.php.bak', $code . "// tampered\n" );
		file_put_contents( $site . '/wpslonik/chained/db.php.bak.sha256', hash( 'sha256', $code ) );
		unset( $GLOBALS['wpdb'] );
		include dirname( __DIR__, 2 ) . '/dropin/db.php';
		$this->assertInstanceOf( 'WPSLONIK_DB', $GLOBALS['wpdb'] );
		$this->assertNotInstanceOf( 'LoaderChainedDB', $GLOBALS['wpdb'] );
		unset( $GLOBALS['wpdb'] );
		unlink( $site . '/wpslonik/chained/db.php.bak' );
		unlink( $site . '/wpslonik/chained/db.php.bak.sha256' );
	}

	public function test_loader_broken_ctor_falls_back(): void {
		self::boot_site();
		if ( ! defined( 'WPSLONIK_ENABLED' ) ) {
			define( 'WPSLONIK_ENABLED', true );
		}
		foreach ( array( 'WPSLONIK_DB_USER', 'WPSLONIK_DB_PASSWORD', 'WPSLONIK_DB_NAME', 'WPSLONIK_DB_HOST' ) as $const ) {
			if ( ! defined( $const ) ) {
				define( $const, 'dummy' );
			}
		}
		$site = sys_get_temp_dir() . '/slonik-loader-' . getmypid();
		$code = "<?php\nclass LoaderBrokenDB extends wpdb {\n\tpublic function __construct( \$a, \$b, \$c, \$d, \$e ) {}\n}\n";
		@mkdir( $site . '/wpslonik/chained', 0777, true );
		@mkdir( $site . '/cache/wpslonik/chain', 0777, true );
		file_put_contents( $site . '/wpslonik/chained/db.php.bak', $code );
		file_put_contents( $site . '/wpslonik/chained/db.php.bak.sha256', hash( 'sha256', $code ) );
		$hash = hash( 'sha256', $code );
		file_put_contents(
			$site . '/cache/wpslonik/chain/db-' . $hash . '.php',
			\Slonik\Chain\Installer::guardCode( str_replace( 'extends wpdb', 'extends WPSLONIK_DB', $code ) )
		);
		unset( $GLOBALS['wpdb'] );
		include dirname( __DIR__, 2 ) . '/dropin/db.php';
		$this->assertInstanceOf( 'WPSLONIK_DB', $GLOBALS['wpdb'] );
		unset( $GLOBALS['wpdb'] );
		unlink( $site . '/wpslonik/chained/db.php.bak' );
		unlink( $site . '/wpslonik/chained/db.php.bak.sha256' );
		unlink( $site . '/cache/wpslonik/chain/db-' . $hash . '.php' );
	}

	private static function boot_site(): void {
		$site = sys_get_temp_dir() . '/slonik-loader-' . getmypid();
		@mkdir( $site . '/wp-includes', 0777, true );
		copy(
			dirname( __DIR__ ) . '/fixtures/wpinc/class-wpdb.php',
			$site . '/wp-includes/class-wpdb.php'
		);
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', $site . '/' );
		}
		if ( ! defined( 'WPINC' ) ) {
			define( 'WPINC', 'wp-includes' );
		}
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			define( 'WP_CONTENT_DIR', $site );
		}
		if ( ! defined( 'WPSLONIK_PLUGIN_DIR' ) ) {
			define( 'WPSLONIK_PLUGIN_DIR', dirname( __DIR__, 2 ) );
		}
	}
}
