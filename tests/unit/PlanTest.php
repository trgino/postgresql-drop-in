<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Migrate\Plan;

final class PlanTest extends TestCase {

	private function inv( string $name, string $engine = 'InnoDB', string $type = 'BASE TABLE', bool $pk = true ): array {
		return array(
			'table_name' => $name,
			'engine'     => $engine,
			'table_type' => $type,
			'table_rows' => 12,
			'data_length' => 1024,
			'pk'         => $pk,
			'table_collation' => 'utf8mb4_0900_ai_ci',
		);
	}

	/**
	 * D-32: the source charset is checked before the copy: a latin1
	 * table is converted to UTF-8, and the operator has to know.
	 */
	public function test_non_utf8_table_charset_is_reported(): void {
		$inv                  = $this->inv( 'wp_posts' );
		$inv['table_collation'] = 'latin1_swedish_ci';
		$out = Plan::build(
			array( $inv ),
			'wp_',
			array(),
			array(),
			array(),
			array(),
			array()
		);
		$this->assertStringContainsString( 'latin1', implode( ' ', $out['warnings'] ) );
	}

	public function test_utf8_table_charset_is_not_reported(): void {
		$out = Plan::build(
			array( $this->inv( 'wp_posts' ) ),
			'wp_',
			array(),
			array(),
			array(),
			array(),
			array()
		);
		$this->assertSame( array(), $out['warnings'] );
	}

	/**
	 * D-32: an unknown name in --tables is reported instead of being
	 * silently dropped.
	 */
	public function test_unknown_table_in_the_subset_is_reported(): void {
		$plan = Plan::build(
			array( $this->inv( 'wp_posts' ), $this->inv( 'wp_options' ) ),
			'wp_',
			array(),
			array(),
			array(),
			array(),
			array()
		);
		$out = Plan::filter( $plan, array( 'wp_posts', 'wp_nope' ) );
		$this->assertCount( 1, $out['tables'] );
		$this->assertSame( 'wp_posts', $out['tables'][0]['name'] );
		$this->assertStringContainsString( 'wp_nope', implode( ' ', $out['warnings'] ) );
	}

	public function test_filter_without_a_subset_keeps_everything(): void {
		$plan = Plan::build(
			array( $this->inv( 'wp_posts' ) ),
			'wp_',
			array(),
			array(),
			array(),
			array(),
			array()
		);
		$out = Plan::filter( $plan, array() );
		$this->assertCount( 1, $out['tables'] );
		$this->assertSame( array(), $out['warnings'] );
	}

	public function test_plans_prefix_tables_and_flags_no_pk(): void {
		$out = Plan::build(
			array( $this->inv( 'wp_posts' ), $this->inv( 'wp_nopk', 'InnoDB', 'BASE TABLE', false ) ),
			'wp_',
			array(),
			array(),
			array(),
			array(),
			array()
		);
		$this->assertCount( 2, $out['tables'] );
		$this->assertSame( 'new', $out['tables'][0]['state'] );
		$this->assertTrue( $out['tables'][0]['pk'] );
		$this->assertFalse( $out['tables'][1]['pk'] );
		$this->assertSame( array(), $out['blockers'] );
	}

	public function test_excluded_tables_are_marked_not_dropped(): void {
		$out = Plan::build(
			array( $this->inv( 'wp_posts' ), $this->inv( 'wp_cruft' ) ),
			'wp_',
			array(),
			array( 'wp_cruft' ),
			array(),
			array(),
			array()
		);
		$states = array_column( $out['tables'], 'state' );
		$this->assertContains( 'excluded', $states );
		$this->assertContains( 'new', $states );
		$this->assertSame( array( 'wp_cruft' ), $out['excluded'] );
	}

	public function test_include_beyond_prefix_and_ignores_non_prefix(): void {
		$out = Plan::build(
			array( $this->inv( 'other_tbl' ), $this->inv( 'legacy' ) ),
			'wp_',
			array( 'legacy' ),
			array(),
			array(),
			array(),
			array()
		);
		$names = array_column( $out['tables'], 'name' );
		$this->assertContains( 'legacy', $names );
		$this->assertNotContains( 'other_tbl', $names );
	}

	public function test_views_warn_and_are_not_planned(): void {
		$out = Plan::build(
			array( $this->inv( 'wp_v', 'InnoDB', 'VIEW', false ) ),
			'wp_',
			array(),
			array(),
			array(),
			array(),
			array()
		);
		$this->assertSame( array(), $out['tables'] );
		$this->assertCount( 1, $out['warnings'] );
		$this->assertStringContainsString( 'view skipped: wp_v', $out['warnings'][0] );
	}

	public function test_myisam_warns(): void {
		$out = Plan::build(
			array( $this->inv( 'wp_old', 'MyISAM' ) ),
			'wp_',
			array(),
			array(),
			array(),
			array(),
			array()
		);
		$this->assertCount( 1, $out['tables'] );
		$this->assertStringContainsString( 'MyISAM', implode( "\n", $out['warnings'] ) );
	}

	public function test_anomalies_reported_as_skipped(): void {
		$out = Plan::build(
			array( $this->inv( 'wp_posts' ), $this->inv( 'wp_a' ) ),
			'wp_',
			array(),
			array(),
			array(),
			array(
				array( 'kind' => 'triggers', 'name' => 'tr_a', 'table' => 'wp_posts' ),
				array( 'kind' => 'fks', 'name' => 'fk_b', 'table' => 'wp_a' ),
			),
			array()
		);
		$text = implode( "\n", $out['warnings'] );
		$this->assertStringContainsString( 'triggers skipped', $text );
		$this->assertStringContainsString( 'fks skipped', $text );
	}

	public function test_target_table_without_state_blocks(): void {
		$out = Plan::build(
			array( $this->inv( 'wp_posts' ) ),
			'wp_',
			array(),
			array(),
			array(),
			array(),
			array( 'wp_posts' => true )
		);
		$this->assertSame( 'blocked', $out['tables'][0]['state'] );
		$this->assertStringContainsString( 'reset-target', $out['tables'][0]['note'] );
		$this->assertCount( 1, $out['blockers'] );
	}

	public function test_interrupted_state_resume_note_keeps_planned(): void {
		$out = Plan::build(
			array( $this->inv( 'wp_posts' ) ),
			'wp_',
			array(),
			array(),
			array( 'wp_posts' => array( 'status' => 'copy', 'rows_done' => 5 ) ),
			array(),
			array( 'wp_posts' => true )
		);
		$this->assertSame( 'copy', $out['tables'][0]['state'] );
		$this->assertSame( array(), $out['blockers'] );
		$this->assertStringContainsString( 'resuming', $out['tables'][0]['note'] );
	}

	public function test_done_state_from_previous_run_not_counted_again(): void {
		$out = Plan::build(
			array( $this->inv( 'wp_posts' ) ),
			'wp_',
			array(),
			array(),
			array( 'wp_posts' => array( 'status' => 'done', 'rows_done' => 12 ) ),
			array(),
			array( 'wp_posts' => true )
		);
		$this->assertSame( 'done', $out['tables'][0]['state'] );
		$this->assertCount( 0, $out['blockers'] );
	}
}
