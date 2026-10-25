<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Slonik\Cache\ApcuCache;

final class ApcuCacheTest extends TestCase {

	public function test_apcu_unavailable_is_a_silent_miss(): void {
		if ( ! function_exists( 'apcu_fetch' ) ) {
			$this->markTestSkipped( 'APCU not installed in this environment' );
		}
		$cache = new ApcuCache();
		$this->assertNull( $cache->get( 'missing' ) );
	}

	public function test_set_get_roundtrip_when_apcu_exists(): void {
		if ( ! function_exists( 'apcu_fetch' ) ) {
			$this->markTestSkipped( 'APCU not installed in this environment' );
		}
		// Functions existing does not mean APCu is usable (e.g. apcu.enable_cli=0).
		$probe = @apcu_store( '__slonik_apcu_probe__', array( 'x' => 1 ), 0 );
		if ( false === $probe ) {
			$this->markTestSkipped( 'APCU not usable for CLI in this environment' );
		}
		@apcu_delete( '__slonik_apcu_probe__' );
		$cache = new ApcuCache( 'slonik-test:' );
		$this->assertTrue( $cache->set( 'k1', array( 'kind' => 'x' ), 0 ) );
		$this->assertSame( array( 'kind' => 'x' ), $cache->get( 'k1' ) );
		$this->assertTrue( $cache->set( 'k1', array( 'kind' => 'y' ), 0 ) );
		$this->assertSame( array( 'kind' => 'y' ), $cache->get( 'k1' ) );
	}
}
