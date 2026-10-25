<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Compat\ArraySource;
use Slonik\Compat\ScanReport;
use Slonik\Compat\Scanner;
use Slonik\Cli\CompatScanCommand;

final class CompatScanCliTest extends TestCase {

	private function report( $code ) {
		$scanner = new Scanner( new ArraySource( array( 'inc/db.php' => $code ) ) );
		return $scanner->scan();
	}

	public function test_text_golden(): void {
		$report = $this->report( "<?php\n\$wpdb->query( \"SELECT MATCH(post_title) AGAINST('q')\" );\nmysqli_connect();" );
		$text   = CompatScanCommand::renderText( $report );
		$this->assertStringContainsString( 'Target', $text );
		$this->assertStringContainsString( 'warning: 1', $text );
		$this->assertStringContainsString( 'inc/db.php:2', $text );
		$this->assertStringContainsString( 'H-09', $text );
		$this->assertStringContainsString( 'MATCH', $text );
	}

	public function test_text_escapes_and_no_leak(): void {
		$report = $this->report( "<?php \$wpdb->query( \"SELECT 1 WHERE a = 'hunter2'\" );" );
		$text   = CompatScanCommand::renderText( $report );
		$this->assertStringNotContainsString( 'hunter2', $text );
	}

	public function test_advisory_footer(): void {
		$report = $this->report( "<?php 1;" );
		$text   = CompatScanCommand::renderText( $report );
		$this->assertStringContainsString( 'advisory', $text );
		$this->assertStringContainsString( 'K-17', $text );
	}

	public function test_exit_codes(): void {
		$this->assertSame( 1, CompatScanCommand::exitCode( array( 'incompatible' => 2, 'warning' => 1 ) ) );
		$this->assertSame( 1, CompatScanCommand::exitCode( array( 'incompatible' => 1 ) ) );
		$this->assertSame( 0, CompatScanCommand::exitCode( array( 'incompatible' => 0 ) ) );
	}

	public function test_command_registered(): void {
		$entry = (string) file_get_contents( dirname( __DIR__, 2 ) . '/postgresql-drop-in.php' );
		$this->assertStringContainsString( "/src/Cli/CompatScanCommand.php", $entry );
		$this->assertStringContainsString( "'slonik compat'", $entry );
	}

	public function test_summary_line_counts(): void {
		$report = $this->report( "<?php\n\$wpdb->get_results( 'SELECT 1' );" );
		$line   = CompatScanCommand::summaryLine( $report->summary() );
		$this->assertStringContainsString( 'compatible: 1', $line );
		$this->assertStringContainsString( 'incompatible: 0', $line );
		$this->assertStringContainsString( 'unverified: 0', $line );
		$this->assertStringContainsString( 'info: 0', $line );
		$this->assertStringContainsString( 'warning: 0', $line );
	}
}
