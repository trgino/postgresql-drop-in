<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The autoload map of the test suite is case sensitive on Linux: the
 * CI found the classes missing (the local Windows checkout hid it).
 */
final class AutoloadMapTest extends TestCase {

	public function test_the_test_namespace_maps_to_the_real_directory(): void {
		$root     = dirname( __DIR__, 2 );
		$composer = json_decode( (string) file_get_contents( $root . '/composer.json' ), true );
		$map      = isset( $composer['autoload-dev']['psr-4'] ) ? (array) $composer['autoload-dev']['psr-4'] : array();

		$this->assertArrayHasKey( 'Slonik\\Tests\\Unit\\', $map, 'the test namespace must be mapped for the autoloader' );
		$dir = rtrim( (string) $map['Slonik\\Tests\\Unit\\'], '/' );
		// PSR-4 is case sensitive: the directory name must be the real one.
		$this->assertSame( 'unit', basename( $dir ), 'PSR-4 is case sensitive on Linux' );
		$this->assertFileExists( $root . '/' . $dir . '/ArrayProbe.php' );
	}

	/**
	 * The production namespace maps to the real source directory.
	 */
	public function test_the_source_namespace_maps_to_the_real_directory(): void {
		$root     = dirname( __DIR__, 2 );
		$composer = json_decode( (string) file_get_contents( $root . '/composer.json' ), true );
		$map      = isset( $composer['autoload']['psr-4'] ) ? (array) $composer['autoload']['psr-4'] : array();

		$this->assertArrayHasKey( 'Slonik\\', $map );
		$this->assertSame( 'src', rtrim( (string) $map['Slonik\\'], '/' ) );
		$this->assertFileExists( $root . '/src/Sql/HostPort.php' );
	}
}
