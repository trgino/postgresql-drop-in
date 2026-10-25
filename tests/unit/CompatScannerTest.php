<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Compat\ArraySource;
use Slonik\Compat\Scanner;

final class CompatScannerTest extends TestCase {

	public function test_classification_per_template(): void {
		$code  = "<?php\n"
			. "\$wpdb->get_results( \"SELECT ID FROM wp_posts\" );\n"                    // compatible
			. "\$wpdb->query( \"SELECT MATCH(post_title) AGAINST('q') FROM wp_posts\" );\n" // warning (H-09)
			. "\$wpdb->get_results( \$sql );\n"                                          // unverified
			. "\$wpdb->query( \"FROBNICATE wp_posts\" );\n"                              // unverified (not a statement)
			. "\$wpdb->query( \"SELECT WHERE FROM\" );\n"                                // incompatible (real failure)
			. "mysqli_connect();\n";                                                     // info
		$scanner = new Scanner( new ArraySource( array( 'inc/db.php' => $code ) ) );
		$report  = $scanner->scan();
		$this->assertSame( 1, $report->summary()['incompatible'] );
		$this->assertSame( 2, $report->summary()['unverified'] );
		$this->assertSame( 1, $report->summary()['warning'] );
		$this->assertSame( 1, $report->summary()['info'] );
		$findings = $report->findings();
		$this->assertSame( 'warning', $findings[1]['status'] );
		$this->assertSame( array( 'H-09' ), $findings[1]['rules'] );
		$this->assertSame( 'unverified', $findings[2]['status'] );
		$this->assertSame( 'incompatible', $findings[4]['status'] );
	}

	public function test_findings_not_multiplied_by_recollect(): void {
		// Regression: scan() must call Collector::collect() once, not per file
		// (O(n^2) parse blowup + duplicated findings on multi-file targets).
		$snippet = "<?php \$wpdb->get_results( 'SELECT 1 FROM wp_posts' );";
		$scanner = new Scanner( new ArraySource( array(
			'a.php'  => $snippet,
			'b.php'  => $snippet,
			'c.php'  => $snippet,
		) ) );
		$report  = $scanner->scan();
		$this->assertSame( 3, count( $report->findings() ) );
	}

	public function test_fragment_templates_do_not_look_incompatible(): void {
		// Partial fragments cannot be translated alone → 'unverified', never
		// a hard 'incompatible'. Builders concat statement-typed SQL:
		// 'SELECT ' . $frag starts with SELECT so any frag-translation that
		// succeeds is fine, but a leading non-statement fragment fails → U.
		$src = "<?php \$wpdb->query( \"SELECT * FROM wp_posts WHERE x = 1\" . \$frag );";
		$scanner   = new Scanner( new ArraySource( array( 'x.php' => $src ) ) );
		$report    = $scanner->scan();
		$findings  = $report->findings();
		$this->assertSame( 'unverified', $findings[0]['status'] );
	}

	public function test_nested_prepare_outer_call_counts_once(): void {
		// $wpdb->get_results($wpdb->prepare('SELECT ... %d')): the prepare
		// template and the outer caller resolve to one finding, not two.
		$src = "<?php \$wpdb->get_results( \$wpdb->prepare( 'SELECT ID FROM wp_posts WHERE ID = %d', 2 ) );";
		$scanner  = new Scanner( new ArraySource( array( 'x.php' => $src ) ) );
		$report   = $scanner->scan();
		$this->assertSame( 1, count( $report->findings() ) );
	}

	public function test_json_schema_stable_keys(): void {
		$scanner = new Scanner( new ArraySource( array( 'x.php' => "<?php \$wpdb->query( 'SELECT 1 FROM wp_posts' );" ) ) );
		$report  = $scanner->scan();
		$json    = $report->toJson();
		$data    = json_decode( $json, true );
		$this->assertSame( array( 'target', 'summary', 'findings', 'generated_by' ), array_keys( $data ) );
		$this->assertSame( array( 'type', 'slug', 'version', 'tree_hash' ), array_keys( $data['target'] ) );
		$this->assertSame( array( 'compatible', 'warning', 'incompatible', 'unverified', 'info', 'skipped' ), array_keys( $data['summary'] ) );
		$this->assertSame( array( 'file', 'line', 'status', 'sql_template', 'rules', 'message', 'remediation', 'construct' ), array_keys( $data['findings'][0] ) );
		$this->assertSame( 'sha256:', substr( (string) $data['target']['tree_hash'], 0, 7 ) );
	}

	public function test_unparseable_file_yields_skipped_notice(): void {
		$scanner = new Scanner( new ArraySource( array( 'bad.php' => '<?php function { ;;;' ) ) );
		$report  = $scanner->scan();
		$this->assertEquals( 0, $report->summary()['incompatible'] );
		$findings = array_values( array_filter( $report->findings(), static fn( $f ) => 'skipped' === $f['status'] ) );
		$this->assertCount( 1, $findings );
	}

	public function test_insert_table_args_classified_compatible(): void {
		$scanner = new Scanner( new ArraySource( array( 'x.php' => "<?php \$wpdb->insert( 'wp_shops', array( 'a' => 'b' ) );" ) ) );
		$report  = $scanner->scan();
		$this->assertEquals( 1, $report->summary()['compatible'] );
	}

	public function test_hunter2_does_not_leak_into_report(): void {
		$scanner = new Scanner( new ArraySource( array( 'x.php' => "<?php \$wpdb->query( \"SELECT * FROM wp_users WHERE pass = 'hunter2'\" );" ) ) );
		$report  = $scanner->scan();
		$json    = $report->toJson();
		$this->assertStringNotContainsString( 'hunter2', $json );
	}

	public function test_unverified_dynamic_paths_still_redacted_p19(): void {
		$code = <<<'CODE'
<?php
$wpdb->query( "SELECT * FROM wp_users WHERE pass = 'trustme' AND x = {$dyn}" );
$wpdb->query( 'SELECT * FROM wp_users WHERE pass = "leaky" AND id = ' . $id );
CODE;
		$scanner = new Scanner( new ArraySource( array( 'x.php' => $code ) ) );
		$json    = $scanner->scan()->toJson();
		$this->assertStringContainsString( 'unverified', $json );
		$this->assertStringNotContainsString( 'trustme', $json );
		$this->assertStringNotContainsString( 'leaky', $json );
	}
}
