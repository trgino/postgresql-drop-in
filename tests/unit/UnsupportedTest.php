<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Compat\ArraySource;
use Slonik\Compat\Scanner;
use Slonik\Sql\Unsupported;
use Slonik\Sql\TranslationException;

final class UnsupportedTest extends TestCase {

	/**
	 * The canonical list: the constructs without a PostgreSQL
	 * equivalent. It is the source for the docs, the public artifacts
	 * and the compatibility test plan.
	 */
	public function test_the_canonical_list_covers_the_documented_gaps(): void {
		$constructs = Unsupported::constructs();
		foreach ( array( 'SHA1', 'SHA2', 'SUBSTRING_INDEX', 'DATABASE' ) as $id ) {
			$this->assertArrayHasKey( $id, $constructs );
		}
		$this->assertSame( 'SHA1()', Unsupported::label( 'SHA1' ) );
		// R-066/R-067: INSERT IGNORE and GROUP_CONCAT are translated now.
		$this->assertArrayNotHasKey( 'INSERT IGNORE', $constructs );
		$this->assertArrayNotHasKey( 'GROUP_CONCAT', $constructs );
	}

	public function test_the_functions_are_listed_separately(): void {
		$functions = Unsupported::functions();
		$this->assertContains( 'SHA1', $functions );
		$this->assertNotContains( 'INSERT IGNORE', $functions );
		$this->assertNotContains( 'GROUP_CONCAT', $functions );
	}

	/**
	 * The exception carries the construct, so the scanner can report
	 * which one it was.
	 */
	public function test_an_unsupported_exception_carries_the_construct(): void {
		$e = TranslationException::unsupported( 'SHA1', 'no PostgreSQL equivalent' );
		$this->assertTrue( $e->isUnsupported() );
		$this->assertSame( 'SHA1', $e->construct() );
		$this->assertFalse( ( new TranslationException( 'boom' ) )->isUnsupported() );
	}

	/**
	 * A plugin that uses a function without a PostgreSQL equivalent is
	 * reported as incompatible, with the rule and the construct.
	 */
	public function test_the_scanner_reports_the_unsupported_construct(): void {
		$code = "<?php\n"
			. "\$wpdb->get_results( \"SELECT SHA1(post_title) FROM wp_posts\" );\n"
			. "\$wpdb->get_results( \"SELECT GROUP_CONCAT(post_title) FROM wp_posts\" );\n"
			. "\$wpdb->query( \"INSERT IGNORE INTO wp_options (option_name) VALUES ('x')\" );\n";
		$scanner  = new Scanner( new ArraySource( array( 'inc/db.php' => $code ) ) );
		$report   = $scanner->scan();
		$findings = $report->findings();
		$this->assertSame( 3, count( $findings ) );
		$this->assertSame( 'incompatible', $findings[0]['status'] );
		$this->assertSame( 'SHA1', $findings[0]['construct'] );
		$this->assertContains( 'R-062', $findings[0]['rules'] );
		$this->assertSame( 'compatible', $findings[1]['status'] );
		// R-066/R-067: the GROUP_CONCAT and the INSERT IGNORE translate
		// now, so the scanner reports them as compatible.
		$this->assertSame( '', $findings[1]['construct'] );
		$this->assertSame( 'compatible', $findings[2]['status'] );
		$this->assertSame( '', $findings[2]['construct'] );
	}

	/**
	 * The text report sums the constructs without an equivalent.
	 */
	public function test_the_text_report_sums_the_unsupported_constructs(): void {
		$code = "<?php\n"
			. "\$wpdb->get_results( \"SELECT SHA1(post_title) FROM wp_posts\" );\n"
			. "\$wpdb->get_results( \"SELECT SHA1(post_content) FROM wp_posts\" );\n";
		$scanner = new Scanner( new ArraySource( array( 'inc/db.php' => $code ) ) );
		$report  = $scanner->scan();
		$text    = \Slonik\Cli\CompatScanCommand::renderText( $report );
		$this->assertStringContainsString( 'SHA1()', $text );
		$this->assertStringContainsString( '2', $text );
	}
}
