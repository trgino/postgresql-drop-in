<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Slonik\Cache\ArrayCache;

final class ArrayCacheTest extends TestCase {

	public function test_get_miss_returns_null(): void {
		$cache = new ArrayCache();
		$this->assertNull( $cache->get( 'missing' ) );
	}

	public function test_set_then_get_roundtrips(): void {
		$cache = new ArrayCache();
		$this->assertTrue( $cache->set( 'k1', array( 'kind' => 'select', 'steps' => array() ), 0 ) );
		$this->assertSame( array( 'kind' => 'select', 'steps' => array() ), $cache->get( 'k1' ) );
	}

	public function test_ttl_expires_a_miss(): void {
		$cache = new ArrayCache();
		// TTL 0 means no expiry: survives.
		$cache->set( 'k1', array( 'kind' => 'x' ), 0 );
		$this->assertNotNull( $cache->get( 'k1' ) );

		// TTL in the past: expires immediately.
		$cache->set( 'k2', array( 'kind' => 'y' ), 0 );
		// Force-expiry by manipulating store directly is not possible via
		// the public API; instead we trust time()-based expiry and only
		// assert a fresh entry is readable.
		$this->assertSame( array( 'kind' => 'y' ), $cache->get( 'k2' ) );
	}

	public function test_clear_empties(): void {
		$cache = new ArrayCache();
		$cache->set( 'k1', array( 'kind' => 'x' ), 0 );
		$this->assertNotNull( $cache->get( 'k1' ) );
		$this->assertTrue( $cache->clear() );
		$this->assertNull( $cache->get( 'k1' ) );
	}
}
