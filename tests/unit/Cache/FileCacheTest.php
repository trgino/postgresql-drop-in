<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Slonik\Cache\FileCache;

final class FileCacheTest extends TestCase {

	private $dir;
	private $cache;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/slonik-filecache-' . getmypid() . '-' . uniqid( '', true );
		@mkdir( $this->dir, 0777, true );
		$this->cache = new FileCache( $this->dir );
	}

	protected function tearDown(): void {
		foreach ( glob( $this->dir . '/*.json' ) as $file ) {
			@unlink( $file );
		}
		foreach ( glob( $this->dir . '/*.tmp' ) as $file ) {
			@unlink( $file );
		}
		@rmdir( $this->dir );
		@rmdir( $this->dir . '/cache' ); // not used, but harmless
	}

	public function test_get_miss_returns_null(): void {
		$this->assertNull( $this->cache->get( 'missing' ) );
	}

	public function test_set_then_get_roundtrips(): void {
		$this->assertTrue( $this->cache->set( 'k1', array( 'kind' => 'select', 'steps' => array() ), 0 ) );
		$this->assertSame( array( 'kind' => 'select', 'steps' => array() ), $this->cache->get( 'k1' ) );
	}

	public function test_concurrent_safe_write_uses_tmp_then_rename(): void {
		$file = $this->dir . '/k1.json';
		$this->assertFalse( is_file( $file ) );
		$this->assertTrue( $this->cache->set( 'k1', array( 'kind' => 'x' ), 0 ) );
		$this->assertTrue( is_file( $file ) );
		// No leftover .tmp files.
		$this->assertSame( array(), glob( $this->dir . '/*.tmp' ) );
	}

	public function test_clear_empties(): void {
		$this->cache->set( 'k1', array( 'kind' => 'x' ), 0 );
		$this->assertNotNull( $this->cache->get( 'k1' ) );
		$this->assertTrue( $this->cache->clear() );
		$this->assertNull( $this->cache->get( 'k1' ) );
	}
}
