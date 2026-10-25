<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Compat\Collector;
use Slonik\Compat\DirSource;

final class CompatCollectorTest extends TestCase {

	private const FIXTURES = __DIR__ . '/../fixtures/compat/collector';

	public function test_wpdb_call_sites_collected(): void {
		$collector = new Collector( new DirSource( self::FIXTURES ) );
		$entries   = $collector->collect();

		$wpdb = array_values( array_filter(
			$entries,
			static fn( $e ) => 'query' === $e['kind']
		) );

		// good-wpdb.php: 13 call sites; info-handlers.php contributes 1 more query
		$this->assertCount( 14, $wpdb, json_encode( $entries ) );

		$good = array_values( array_filter( $wpdb, static fn( $e ) => 'good-wpdb.php' === $e['path'] ) );
		$this->assertCount( 13, $good );

		$methods = array_map( static fn( $e ) => $e['method'], $good );
		$this->assertSame(
			array( 'get_results', 'get_row', 'get_var', 'get_col', 'query', 'insert', 'update', 'delete', 'replace', 'prepare', 'query', 'query', 'query' ),
			$methods
		);

		$lines = array_map( static fn( $e ) => $e['line'], $good );
		$this->assertSame( array( 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 17, 18 ), $lines );
	}

	public function test_unknown_methods_and_foreign_receivers_ignored(): void {
		$collector = new Collector( new DirSource( self::FIXTURES ) );
		$entries   = $collector->collect();

		$sqls = array_map( static fn( $e ) => $e['sqlNode'] instanceof \PhpParser\Node\Scalar\String_ ? $e['sqlNode']->value : '', $entries );
		$this->assertNotContains( 'SELECT 2', $sqls, 'foreign receiver must not be collected' );
		foreach ( $entries as $e ) {
			$this->assertNotSame( 'non_compat_method', $e['method'] );
		}
	}

	public function test_info_signals_collected(): void {
		$collector = new Collector( new DirSource( self::FIXTURES ) );
		$entries   = $collector->collect();

		$info = array_values( array_filter( $entries, static fn( $e ) => 'info' === $e['kind'] ) );
		$this->assertCount( 4, $info, json_encode( $entries ) );

		$methods = array_map( static fn( $e ) => $e['method'], $info );
		$this->assertSame( array( 'mysqli_connect', 'PDO', 'dbh', 'mysqli_real_escape_string' ), $methods );

		// pgsql PDO ignored: no info entry at line 5
		$lines = array_map( static fn( $e ) => $e['line'], $info );
		$this->assertNotContains( 5, $lines );
	}

	public function test_dbdelta_collected(): void {
		$collector = new Collector( new DirSource( self::FIXTURES ) );
		$entries   = $collector->collect();

		$delta = array_values( array_filter( $entries, static fn( $e ) => 'dbdelta' === $e['kind'] ) );
		$this->assertCount( 1, $delta );
		$this->assertSame( 7, $delta[0]['line'] );
		$this->assertSame( 'dbdelta', $delta[0]['method'] );
	}

	public function test_unparseable_file_reports_skip_not_crash(): void {
		$collector = new Collector( new DirSource( self::FIXTURES ) );
		$entries   = $collector->collect();

		$skips = array_values( array_filter( $entries, static fn( $e ) => 'skipped' === $e['kind'] ) );
		$this->assertCount( 1, $skips, json_encode( $entries ) );
		$this->assertSame( 'broken-syntax.php', $skips[0]['path'] );
	}

	public function test_unresolvable_php_parser_reports_skip(): void {
		// ArraySource + missing parser: collector degrades to 'skipped' with reason (no fatal).
		$src = new \Slonik\Compat\ArraySource( array( 'tiny.php' => '<?php $wpdb->query("x");' ) );
		$collector = new \Slonik\Compat\Collector( $src, $this->failingParserFactory() );
		$entries   = $collector->collect();
		$this->assertCount( 1, $entries );
		$this->assertSame( 'skipped', $entries[0]['kind'] );
	}

	private function failingParserFactory(): \Slonik\Compat\ParserFactory {
		return new class() implements \Slonik\Compat\ParserFactory {
			public function create() {
				throw new \RuntimeException( 'php-parser missing' );
			}
		};
	}
}
