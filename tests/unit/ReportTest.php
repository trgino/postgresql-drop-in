<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Migrate\Report;

final class ReportTest extends TestCase {

	public function test_table_format_aligns_columns(): void {
		$rows = array(
			array( 'name' => 'wp_posts', 'rows' => 12, 'pk' => 'yes' ),
			array( 'name' => 'wp_x', 'rows' => 3, 'pk' => 'no' ),
		);
		$text = Report::format( 'table', $rows, array( 'name', 'rows', 'pk' ) );
		$lines = explode( "\n", $text );
		$this->assertCount( 4, $lines );
		$this->assertStringContainsString( 'name', $lines[0] );
		$this->assertStringContainsString( '--', $lines[1] );
		$this->assertStringContainsString( 'wp_posts', $lines[2] );
		$this->assertStringContainsString( '12', $lines[2] );
	}

	public function test_json_format_round_trips_fields(): void {
		$rows = array( array( 'name' => 'wp_a', 'rows' => 5, 'pk' => 'yes' ) );
		$jq   = json_decode( Report::format( 'json', $rows, array( 'name', 'rows', 'pk' ) ), true );
		$this->assertSame( 'wp_a', $jq[0]['name'] );
		$this->assertSame( 5, $jq[0]['rows'] );
	}

	public function test_yaml_format_lists_fields(): void {
		$rows = array( array( 'name' => 'wp_a', 'state' => 'new', 'note' => '' ) );
		$text = Report::format( 'yaml', $rows, array( 'name', 'state', 'note' ) );
		$this->assertStringStartsWith( '- name: wp_a', $text );
		$this->assertStringContainsString( 'state: new', $text );
	}

	public function test_unknown_format_falls_back_to_table(): void {
		$text = Report::format( 'csv', array( array( 'a' => 1 ) ), array( 'a' ) );
		$this->assertStringContainsString( '1', $text );
	}

	public function test_plan_summary_counts_states(): void {
		$summary = Report::planSummary( array(
			'tables' => array(
				array( 'state' => 'new' ),
				array( 'state' => 'new' ),
				array( 'state' => 'done' ),
				array( 'state' => 'blocked' ),
				array( 'state' => 'excluded' ),
			),
			'warnings' => array( 'w1' ),
			'blockers' => array(),
		) );
		$this->assertStringContainsString( 'Migration plan — 5 table(s)', $summary );
		$this->assertStringContainsString( '2 planned', $summary );
		$this->assertStringContainsString( '1 done', $summary );
		$this->assertStringContainsString( '1 blocked', $summary );
		$this->assertStringContainsString( '1 warning(s)', $summary );
	}

	public function test_next_steps_cutover_footer(): void {
		$text = Report::nextSteps();
		$this->assertStringContainsString( 'WPSLONIK_ENABLED=true', $text );
		$this->assertStringContainsString( 'wp slonik health', $text );
		$this->assertStringContainsString( 'maintenance', $text );
	}

	public function test_redact_hides_values(): void {
		$this->assertSame( '(empty)', Report::redact( '' ) );
		$this->assertSame( '***', Report::redact( 'hunter2' ) );
	}
}
