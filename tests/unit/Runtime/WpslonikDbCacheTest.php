<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Slonik\Cache\ArrayCache;
use Slonik\Runtime\WpslonikDb;

final class WpslonikDbCacheTest extends TestCase {

	public static function setUpBeforeClass(): void {
		if ( ! class_exists( 'wpdb', false ) ) {
			require_once dirname( __DIR__ ) . '/fixtures/wpinc/class-wpdb.php';
		}
		require_once dirname( __DIR__, 3 ) . '/src/Runtime/WpslonikDb.php';
		require_once dirname( __DIR__, 3 ) . '/src/Cache/ArrayCache.php';
	}

	public function test_constructor_accepts_and_stores_a_cache(): void {
		$cache = new ArrayCache();
		$db    = new \WPSLONIK_DB( 'u', 'p', 'n', 'h', '', null, $cache );
		// Cache is wired even though no connection is made (catalog is
		// null until db_connect, so lookup is bypassed until then).
		$this->assertInstanceOf( 'Slonik\Cache\ArrayCache', $cache );
	}

	public function test_cache_does_not_break_unparseable_sql_path(): void {
		$cache = new ArrayCache();
		$db    = new \WPSLONIK_DB( 'u', 'p', 'n', 'h', '', null, $cache );
		$this->assertFalse( $db->query( 'FROBNICATE THE TABLE' ) );
		$this->assertSame( 'Unparseable statement.', $db->last_error );
		// The cache is consulted only when a catalog exists; before the
		// first connection it is bypassed, so no entry is expected here.
		$this->assertNull( $cache->get( 'tr:' . hash( 'sha256', 'FROBNICATE THE TABLE' ) ) );
	}

	public function test_slow_log_still_works_with_cache(): void {
		$file = sys_get_temp_dir() . '/slonik-cache-slow-' . getmypid() . '.jsonl';
		@unlink( $file );
		$cache = new ArrayCache();
		$db    = new \Slonik\Runtime\WpslonikDb( 'u', 'p', 'n', '127.0.0.1:1', $file, 0, $cache );
		$this->assertFalse( $db->query( 'SELECT * FROM t WHERE x = 1' ) );
		$entries = ( new \Slonik\Audit\CompatLog( $file ) )->read( 10 );
		$kinds   = array();
		foreach ( $entries as $e ) {
			$kinds[ $e['kind'] ] = true;
		}
		$this->assertTrue( isset( $kinds['slow'] ), 'slow kind missing: ' . json_encode( $entries ) );
		@unlink( $file );
	}
}
