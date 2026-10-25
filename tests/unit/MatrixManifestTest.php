<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The compatibility matrix (docs/11 §8): the plugin list and the tools
 * that fetch and scan it.
 */
final class MatrixManifestTest extends TestCase {

	private const ROOT = __DIR__ . '/../..';

	public function test_the_manifest_lists_the_tier_one_plugins(): void {
		$manifest = json_decode( (string) file_get_contents( self::ROOT . '/tools/matrix/plugins.json' ), true );
		$this->assertIsArray( $manifest );
		$this->assertContains( 'woocommerce', (array) $manifest['tier1'] );
		$this->assertContains( 'query-monitor', (array) $manifest['tier1'] );
		$this->assertContains( 'w3-total-cache', (array) $manifest['tier1'] );
		$this->assertNotEmpty( (array) $manifest['popular'] );
	}

	public function test_the_matrix_tools_are_packaged(): void {
		$this->assertFileExists( self::ROOT . '/tools/matrix/fetch.php' );
		$this->assertFileExists( self::ROOT . '/tools/matrix/scan.php' );
		// The fetched plugins are not ours to redistribute.
		$ignore = (string) file_get_contents( self::ROOT . '/.gitignore' );
		$this->assertStringContainsString( 'tools/matrix/out/', $ignore );
	}
}
