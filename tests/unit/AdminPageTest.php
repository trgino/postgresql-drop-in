<?php
declare( strict_types=1 );

namespace Slonik\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slonik\Admin\Page;
use Slonik\Health\Result;

final class AdminPageTest extends TestCase {

	public static function setUpBeforeClass(): void {
		Page::setEscaper(
			function ( $text ) {
				return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
			}
		);
	}

	public function test_tabs_mark_active(): void {
		$html = Page::renderTabs( 'health' );
		$this->assertStringContainsString( 'nav-tab-active">Health', $html );
		$this->assertStringContainsString( '>Log<', $html );
		$this->assertStringNotContainsString( 'nav-tab-active">Log', $html );
	}

	public function test_health_table_row_classes(): void {
		$html = Page::renderHealthTable(
			array(
				'H-C01' => Result::ok( 'H-C01', 'fine' ),
				'H-C06' => Result::block( 'H-C06', 'citext missing', array( 'CREATE EXTENSION citext;' ) ),
			)
		);
		$this->assertStringContainsString( 'class="slonik-ok"', $html );
		$this->assertStringContainsString( 'class="slonik-block"', $html );
		$this->assertStringContainsString( 'CREATE EXTENSION citext;', $html );
	}

	public function test_health_table_escapes_messages(): void {
		$html = Page::renderHealthTable( array( 'H-X' => Result::warn( 'H-X', '<script>alert(1)</script>' ) ) );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_log_table_renders_redacted(): void {
		$html = Page::renderLogTable(
			array(
				array( 'ts' => 1, 'kind' => 'error', 'rule' => 'R-043', 'shape' => 's', 'sql' => 'SELECT ?', 'count' => 2, 'first' => 1, 'last' => 2 ),
			)
		);
		$this->assertStringContainsString( 'SELECT ?', $html );
		$this->assertStringContainsString( 'R-043', $html );
	}

	public function test_action_links_carry_nonces(): void {
		$html = Page::renderMaintenance();
		$this->assertStringContainsString( 'wp slonik install-dropin', $html );
	}

	public function test_settings_table_escapes(): void {
		$html = Page::renderSettingsTable( array( 'WPSLONIK_DB_HOST' => 'h', 'WPSLONIK_X' => '<b>' ) );
		$this->assertStringContainsString( 'WPSLONIK_DB_HOST', $html );
		$this->assertStringNotContainsString( '<b>', $html );
	}

	public function test_settings_table_masks_secret_constants(): void {
		$html = Page::renderSettingsTable(
			array(
				'WPSLONIK_DB_PASSWORD'   => 'sup3rsecret',
				'WPSLONIK_DB_HOST'       => 'db.example',
				'WPSLONIK_DB_USER'       => 'wpuser',
				'WPSLONIK_API_KEY'       => 'ak_live_123',
				'WPSLONIK_AUTH_SALT'     => 'salted',
			)
		);
		// docs/16 §3: secrets never appear in any output.
		$this->assertStringNotContainsString( 'sup3rsecret', $html );
		$this->assertStringNotContainsString( 'ak_live_123', $html );
		$this->assertStringNotContainsString( 'salted', $html );
		// The constant name stays visible; non-secret values stay readable.
		$this->assertStringContainsString( 'WPSLONIK_DB_PASSWORD', $html );
		$this->assertStringContainsString( 'db.example', $html );
		$this->assertStringContainsString( 'wpuser', $html );
	}

	public function test_settings_table_marks_unset_secret(): void {
		$html = Page::renderSettingsTable( array( 'WPSLONIK_DB_PASSWORD' => '' ) );
		$this->assertStringNotContainsString( 'sup3rsecret', $html );
		$this->assertStringContainsString( 'not set', $html );
	}

	public function test_render_tab_dispatches(): void {
		$this->assertStringContainsString( 'widefat', Page::renderTab( 'log' ) );
		$this->assertStringContainsString( 'WP-CLI', Page::renderTab( 'maintenance' ) );
		$this->assertStringContainsString( 'K-17', Page::renderTab( 'compat' ) );
		$this->assertStringContainsString( 'Constant', Page::renderTab( 'settings' ) );
	}

	public function test_compat_tab_advisory_and_hint(): void {
		$html = Page::renderTab( 'compat' );
		$this->assertStringContainsString( 'Advisory only', $html );
		$this->assertStringContainsString( 'wp slonik compat', $html );
		$this->assertStringNotContainsString( 'Static plugin/theme analysis lands', $html );
	}

	/**
	 * D-02: the panel must say which case-sensitivity mode is active and
	 * what has to be installed for the full behaviour.
	 */
	public function test_case_sensitivity_section_explains_the_mode(): void {
		$html = Page::renderCaseSensitivity( array( \Slonik\Health\Result::warn( 'H-C06', 'citext extension missing - degraded (lower() comparison)' ) ) );
		$this->assertStringContainsString( 'Case sensitivity', $html );
		$this->assertStringContainsString( 'degraded', $html );
		$this->assertStringContainsString( 'CREATE EXTENSION citext', $html );
		$this->assertStringContainsString( 'superuser', $html );
		$this->assertStringContainsString( 'WPSLONIK_CI_MODE', $html );
	}

	public function test_case_sensitivity_section_reports_citext(): void {
		$html = Page::renderCaseSensitivity( array( \Slonik\Health\Result::ok( 'H-C06', 'citext installed - citext (full MySQL-like behaviour)' ) ) );
		$this->assertStringContainsString( 'citext', $html );
		$this->assertStringNotContainsString( 'CREATE EXTENSION citext', $html );
	}
}
