<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Audit\CompatLog;
use Slonik\Cli\CompatCommand;

final class CompatCommandTest extends TestCase {

	private static function seededFile() {
		$file = sys_get_temp_dir() . '/slonik-compatcmd-' . getmypid() . '.jsonl';
		@unlink( $file );
		$log = new CompatLog( $file );
		$log->append( array( 'kind' => 'error', 'rule' => 'R-043', 'shape' => 'sa', 'sql' => 'SELECT ?' ) );
		$log->append( array( 'kind' => 'warn', 'rule' => 'S-02', 'shape' => 'sb', 'sql' => 'SELECT ??' ) );
		return $file;
	}

	public function test_list_filters_by_rule(): void {
		$file = self::seededFile();
		$out  = CompatCommand::renderList( ( new CompatLog( $file ) )->read( 100 ), array( 'rule' => 'R-043' ) );
		$this->assertStringContainsString( 'R-043', $out );
		$this->assertStringNotContainsString( 'S-02', $out );
		@unlink( $file );
	}

	public function test_stats_golden(): void {
		$file = self::seededFile();
		$this->assertSame( "errors: 1, warnings: 1\n", CompatCommand::renderStats( ( new CompatLog( $file ) )->stats() ) );
		@unlink( $file );
	}

	public function test_export_is_valid_json(): void {
		$file    = self::seededFile();
		$decoded = json_decode( CompatCommand::renderExport( ( new CompatLog( $file ) )->read( 100 ) ), true );
		$this->assertCount( 2, $decoded );
		$this->assertSame( 'SELECT ?', $decoded[0]['sql'] );
		@unlink( $file );
	}

	public function test_run_returns_exit_codes(): void {
		$file = self::seededFile();
		$this->assertSame( 0, CompatCommand::run( $file, 'stats', array() ) );
		$this->assertSame( 3, CompatCommand::run( $file, 'nope', array() ) );
		@unlink( $file );
	}
}
