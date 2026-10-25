<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Audit\CompatLog;
use Slonik\Audit\Redactor;
use Slonik\Health\Checks\CompatCheck;
use Slonik\Health\Env;
use Slonik\Health\Runner;

final class CompatLogTest extends TestCase {

	public function test_redacts_literals_keep_shape(): void {
		$this->assertSame(
			'SELECT * FROM t WHERE a = ? AND b = ?',
			Redactor::redact( "SELECT * FROM t WHERE a = 'x' AND b = 10" )
		);
	}

	public function test_redacts_adversarial(): void {
		$this->assertSame( 'SELECT ?, ?, ?, ?', Redactor::redact( "SELECT 0x1F, 1e3, 'a\\'b', N'x'" ) );
	}

	public function test_redacts_binary_and_big_int(): void {
		$this->assertSame( 'SELECT ?, ?, ?', Redactor::redact( "SELECT 0b101, B'101', 99999999999" ) );
	}

	public function test_unparseable_never_raw(): void {
		$this->assertSame( '?', Redactor::redact( 'SELECT (broken' ) );
	}

	private static function logFile( $suffix ) {
		$file = sys_get_temp_dir() . '/slonik-compat-' . getmypid() . '-' . $suffix . '.jsonl';
		@unlink( $file );
		return $file;
	}

	/**
	 * D-28: the timestamps are shown in the site's timezone, not in the
	 * server's timezone (the audit found date() in the panel and in the
	 * CLI export).
	 */
	public function test_the_timestamp_uses_the_site_timezone(): void {
		$this->assertSame( 'SITE-1970-01-01 00:00:02', CompatLog::formatStamp( 2 ) );
	}

	public function test_append_read_roundtrip_with_count(): void {
		$log = new CompatLog( self::logFile( 'rt' ) );
		$this->assertTrue( $log->append( array( 'kind' => 'error', 'rule' => 'R-043', 'shape' => 's1', 'sql' => 'SELECT ?' ) ) );
		$this->assertTrue( $log->append( array( 'kind' => 'error', 'rule' => 'R-043', 'shape' => 's1', 'sql' => 'SELECT ?' ) ) );
		$entries = $log->read( 10 );
		$this->assertCount( 1, $entries );
		$this->assertSame( 2, $entries[0]['count'] );
		$this->assertSame( array( 'error' => 1 ), $log->stats()['kinds'] );
	}

	public function test_ring_cap_evicts_oldest(): void {
		$log = new CompatLog( self::logFile( 'cap' ), 5 );
		for ( $i = 0; $i < 7; ++$i ) {
			$log->append( array( 'kind' => 'error', 'rule' => '', 'shape' => 's' . $i, 'sql' => 'Q' ) );
		}
		$entries = $log->read( 10 );
		$this->assertCount( 5, $entries );
		$this->assertSame( 's2', $entries[0]['shape'] );
	}

	public function test_corrupt_lines_skipped(): void {
		$file = self::logFile( 'bad' );
		file_put_contents( $file, "{bad json\n" . json_encode( array( 'ts' => 1, 'kind' => 'error', 'rule' => '', 'shape' => 's', 'sql' => 'Q', 'count' => 1, 'first' => 1, 'last' => 1 ) ) . "\n" );
		$log     = new CompatLog( $file );
		$entries = $log->read( 10 );
		$this->assertCount( 1, $entries );
	}

	public function test_unwritable_is_silent_false(): void {
		$log = new CompatLog( '/proc/slonik-nope/x.jsonl' );
		$this->assertFalse( $log->append( array( 'kind' => 'error', 'rule' => '', 'shape' => 's', 'sql' => 'Q' ) ) );
		$this->assertSame( array(), $log->read( 10 ) );
	}

	public function test_hc16_warns_on_recent_error(): void {
		$file = self::logFile( 'hc16' );
		$log  = new CompatLog( $file );
		$log->append( array( 'kind' => 'error', 'rule' => 'R-043', 'shape' => 's', 'sql' => 'Q' ) );
		$env     = Env::fromValues( array( 'content_dir' => sys_get_temp_dir() ) );
		$results = Runner::run( array( new CompatCheck( $file ) ), $env );
		$this->assertSame( 'warn', $results['H-C16']->status );
		$results = Runner::run( array( new CompatCheck( $file . '.missing' ) ), $env );
		$this->assertSame( 'ok', $results['H-C16']->status );
	}

	/**
	 * D-24: the log is written under a lock, so concurrent requests
	 * must not lose each other's entry.
	 */
	public function test_concurrent_appends_keep_every_entry(): void {
		$file   = self::logFile( 'conc' );
		$script = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'slonik-conc-' . getmypid() . '.php';
		$root   = dirname( __DIR__, 2 );
		$code   = '<?php' . "\n"
			. 'require ' . var_export( $root . '/vendor/autoload.php', true ) . ";\n"
			. '$log = new \Slonik\Audit\CompatLog( ' . var_export( $file, true ) . " );\n"
			. 'for ( $i = 0; $i < 25; $i++ ) { $log->append( array( "kind" => "error", "rule" => "R-043", "shape" => "s", "sql" => "Q" ) ); }';
		file_put_contents( $script, $code );

		$processes = array();
		for ( $i = 0; $i < 4; $i++ ) {
			// The array form runs the command without a shell.
			$pipes = array();
			$proc  = @proc_open( array( PHP_BINARY, $script ), array(), $pipes );
			if ( ! is_resource( $proc ) ) {
				@unlink( $script );
				$this->markTestSkipped( 'proc_open is not available.' );
			}
			$processes[] = $proc;
		}
		foreach ( $processes as $proc ) {
			proc_close( $proc );
		}
		@unlink( $script );

		$entries = ( new CompatLog( $file ) )->read( 10 );
		$this->assertCount( 1, $entries );
		$this->assertSame( 100, $entries[0]['count'], 'Concurrent appends lost entries.' );
	}
}
