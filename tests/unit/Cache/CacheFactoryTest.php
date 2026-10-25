<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Slonik\Cache\ApcuCache;
use Slonik\Cache\ArrayCache;
use Slonik\Cache\CacheFactory;
use Slonik\Cache\CacheInterface;
use Slonik\Cache\FileCache;

final class CacheFactoryTest extends TestCase {

	public function test_off_returns_null(): void {
		$this->assertNull( CacheFactory::make( 'off' ) );
	}

	public function test_explicit_apcu_returns_apcu_cache(): void {
		$cache = CacheFactory::make( 'apcu' );
		$this->assertInstanceOf( ApcuCache::class, $cache );
	}

	public function test_explicit_file_returns_file_cache(): void {
		$cache = CacheFactory::make( 'file', sys_get_temp_dir() . '/slonik-factory-' . uniqid( '', true ) );
		$this->assertInstanceOf( FileCache::class, $cache );
	}

	public function test_auto_returns_a_cache(): void {
		$cache = CacheFactory::make( 'auto' );
		$this->assertInstanceOf( CacheInterface::class, $cache );
	}

	public function test_unknown_mode_falls_back_to_auto(): void {
		$cache = CacheFactory::make( 'not-a-mode' );
		$this->assertInstanceOf( CacheInterface::class, $cache );
	}

	public function test_dir_is_passed_to_file_cache(): void {
		$dir = sys_get_temp_dir() . '/slonik-factory-dir-' . uniqid( '', true );
		$cache = CacheFactory::make( 'file', $dir );
		$this->assertInstanceOf( FileCache::class, $cache );
		$this->assertTrue( $cache->set( 'k', array( 'kind' => 'x' ), 0 ) );
		$this->assertNotNull( $cache->get( 'k' ) );
	}
}
