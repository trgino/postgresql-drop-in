<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Audit\CompatLog;
use Slonik\Audit\SourceGuesser;
use Slonik\Health\Checks\CompatCheck;
use Slonik\Health\Env;
use Slonik\Health\Runner;

final class CompatHookTest extends TestCase {

	public function test_source_guesser_paths(): void {
		$this->assertSame( 'plugin:woocommerce', SourceGuesser::slug( 'C:\\site\\wp\\wp-content\\plugins\\woocommerce\\src\\db.php' ) );
		$this->assertSame( 'plugin:qmx', SourceGuesser::slug( '/srv/wp-content/plugins/qmx/inc/classes/trait.php' ) );
		$this->assertSame( 'theme:twentysixteen', SourceGuesser::slug( 'X:\\wp-content\\themes\\twentysixteen\\functions.php' ) );
		$this->assertSame( 'core', SourceGuesser::slug( '/var/www/wp-includes/class-wpdb.php' ) );
		$this->assertSame( 'core', SourceGuesser::slug( '\\wp\\wp-admin\\includes\\schema.php' ) );
		$this->assertSame( 'unknown', SourceGuesser::slug( 'E:\\dev\\src\\Cli\\Foo.php' ) );
	}

	public function test_log_entry_normalizes_kinds(): void {
		$e = \Slonik\Audit\LogEntry::build( array( 'kind' => 'slow', 'rule' => 'MS:51', 'shape' => 's', 'sql' => 'SELECT ?' ) );
		$this->assertSame( 'slow', $e['kind'] );
		$this->assertSame( 'MS:51', $e['rule'] );
		$e = \Slonik\Audit\LogEntry::build( array( 'kind' => 'bogus', 'shape' => 's', 'sql' => '?' ) );
		$this->assertSame( 'error', $e['kind'] );
		$e = \Slonik\Audit\LogEntry::build( array( 'shape' => 's', 'sql' => '?' ) );
		$this->assertSame( 'error', $e['kind'] );
		$this->assertSame( 1, $e['count'] );
		$this->assertGreaterThanOrEqual( 0, $e['first'] );
		$this->assertGreaterThanOrEqual( 0, $e['last'] );
	}

	public function test_hc16_counts_warn_and_error(): void {
		$file   = sys_get_temp_dir() . '/slonik-hc16warn-' . getmypid() . '.jsonl';
		@unlink( $file );
		$log = new CompatLog( $file );
		$log->append( array( 'kind' => 'error', 'rule' => 'R-043', 'shape' => 's1', 'sql' => 'Q' ) );
		$log->append( array( 'kind' => 'warn', 'rule' => 'ODKU', 'shape' => 's2', 'sql' => 'Q' ) );
		$env     = Env::fromValues( array( 'content_dir' => sys_get_temp_dir() ) );
		$results = Runner::run( array( new CompatCheck( $file ) ), $env );
		$this->assertSame( 'warn', $results['H-C16']->status );
		$this->assertStringContainsString( 'error/warn shape(s)', $results['H-C16']->message );
		$result2 = Runner::run( array( new CompatCheck( $file ) ), $env );
		$this->assertSame( 'warn', $result2['H-C16']->status );
		@unlink( $file );
	}
}
