<?php
/**
 * Admin screen renderers (Tools → PostgreSQL).
 *
 * Pure render functions are unit-tested with an injected escaper; only
 * register()/render() touch WordPress (capability gate + nonces) and
 * are live-proven. English UI (K-41).
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Admin;

use Slonik\Audit\CompatLog;
use Slonik\Health\Result;

final class Page {

	const TABS = array(
		'health'  => 'Health',
		'compat'  => 'Compatibility',
		'log'     => 'Log',
		'settings' => 'Settings',
		'maintenance' => 'Maintenance',
	);

	/** @var callable|null */
	private static $escaper = null;

	/**
	 * Translate a string if WordPress l10n is loaded; otherwise return it
	 * unchanged (keeps unit tests working without wp-includes/l10n.php).
	 *
	 * @param string $text Text to translate.
	 * @return string
	 */
	private static function t( $text ) {
		if ( function_exists( '__' ) ) {
			return __( (string) $text, 'postgresql-drop-in' );
		}
		return (string) $text;
	}

	/**
	 * @param callable $escaper Function(string): string.
	 */
	public static function setEscaper( $escaper ) {
		self::$escaper = $escaper;
	}

	private static function esc( $text ) {
		if ( is_callable( self::$escaper ) ) {
			return (string) call_user_func( self::$escaper, (string) $text );
		}
		if ( function_exists( 'esc_html' ) ) {
			return esc_html( (string) $text );
		}
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * @param string $active Active tab key.
	 * @return string
	 */
	public static function renderTabs( $active ) {
		$out = '<h2 class="nav-tab-wrapper">';
		foreach ( self::TABS as $key => $label ) {
			$class = 'nav-tab' . ( (string) $active === (string) $key ? ' nav-tab-active' : '' );
			$out  .= '<a href="?page=slonik&amp;tab=' . $key . '" class="' . $class . '">' . self::esc( self::t( $label ) ) . '</a>';
		}
		return $out . '</h2>';
	}

	/**
	 * @param array<string,Result> $results
	 * @return string
	 */
	public static function renderHealthTable( $results ) {
		$out = '<table class="widefat"><thead><tr><th>ID</th><th>Status</th><th>Message</th><th>Remediation</th></tr></thead><tbody>';
		foreach ( (array) $results as $result ) {
			if ( ! $result instanceof Result ) {
				continue;
			}
			$rem = array();
			foreach ( $result->remediation as $step ) {
				$rem[] = self::esc( $step );
			}
			$out .= '<tr class="slonik-' . self::esc( $result->status ) . '"><td>' . self::esc( $result->id ) . '</td><td>'
				. self::esc( $result->status ) . '</td><td>' . self::esc( $result->message ) . '</td><td>'
				. implode( '<br>', $rem ) . '</td></tr>';
		}
		return $out . '</tbody></table>';
	}

	/**
	 * @param array $entries Compat entries.
	 * @return string
	 */
	public static function renderLogTable( $entries ) {
		$out = '<table class="widefat"><thead><tr><th>Last</th><th>Kind</th><th>Rule</th><th>Count</th><th>Source</th><th>SQL (redacted)</th></tr></thead><tbody>';
		foreach ( (array) $entries as $entry ) {
			$entry = (array) $entry;
			$out  .= '<tr><td>' . self::esc( isset( $entry['last'] ) ? CompatLog::formatStamp( (int) $entry['last'] ) : '' ) . '</td><td>'
				. self::esc( isset( $entry['kind'] ) ? $entry['kind'] : '' ) . '</td><td>'
				. self::esc( isset( $entry['rule'] ) ? $entry['rule'] : '' ) . '</td><td>'
				. self::esc( isset( $entry['count'] ) ? $entry['count'] : '' ) . '</td><td>'
				. self::esc( isset( $entry['source'] ) ? $entry['source'] : '' ) . '</td><td><code>'
				. self::esc( isset( $entry['sql'] ) ? $entry['sql'] : '' ) . '</code></td></tr>';
		}
		return $out . '</tbody></table>';
	}

	/**
	 * @param array $defines Constant name => value.
	 * @return string
	 */
	public static function renderSettingsTable( $defines ) {
		$out = '<table class="widefat"><thead><tr><th>Constant</th><th>Value</th></tr></thead><tbody>';
		foreach ( (array) $defines as $name => $value ) {
			$shown = self::isSecretName( $name ) ? self::secretValue( $value ) : self::scalar( $value );
			$out   .= '<tr><td><code>' . self::esc( $name ) . '</code></td><td><code>' . self::esc( $shown ) . '</code></td></tr>';
		}
		return $out . '</tbody></table>';
	}

	/**
	 * Is this constant name secret-bearing? Secret values are never
	 * rendered (docs/16 §3: "parola hiçbir çıktıda gösterilmez").
	 *
	 * @param string $name Constant name.
	 * @return bool
	 */
	public static function isSecretName( $name ) {
		$upper = strtoupper( (string) $name );
		$needles = array(
			'PASSWORD',
			'PASSWD',
			'SECRET',
			'TOKEN',
			'SALT',
			'API_KEY',
			'APIKEY',
			'PRIVATE_KEY',
			'AUTH_KEY',
			'SECURE_AUTH_KEY',
			'LOGGED_IN_KEY',
			'NONCE_KEY',
		);
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $upper, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Placeholder for a secret value: never the value itself.
	 *
	 * @param mixed $value Constant value.
	 * @return string
	 */
	private static function secretValue( $value ) {
		if ( null === $value || '' === $value || false === $value ) {
			return '(not set)';
		}
		return '***';
	}

	/**
	 * Compatibility tab: static scan how-to + saved runtime entries. K-17:
	 * strictly advisory copy, never gate-shaped.
	 *
	 * @return string
	 */
	public static function renderCompatTab() {
		$rows = array();
		if ( defined( 'WP_CONTENT_DIR' ) && is_file( WP_CONTENT_DIR . '/wpslonik/compat-log.jsonl' ) ) {
			$rows = ( new \Slonik\Audit\CompatLog( WP_CONTENT_DIR . '/wpslonik/compat-log.jsonl' ) )->read( 20 );
		}
		$out  = '<p><strong>Advisory only - not enforced (K-17).</strong> '
			. 'Static scans analyse PHP code for wpdb SQL shapes; they cannot verify dynamic SQL or runtime behavior. '
			. 'Run a scan with <code>wp slonik compat &lt;plugin-or-theme-path&gt;</code> '
			. '<code>--format=json</code> for machine output, then record verified trees with '
			. '<code>wp slonik known add</code>. Live validation: <code>wp slonik schema:check</code>.</p>';
		$out .= '<h3>Most recent runtime entries (redacted)</h3>'
			. ( count( $rows ) > 0 ? self::renderLogTable( $rows ) : '<p>No runtime compat entries yet.</p>' );
		return $out;
	}

	private static function scalar( $value ) {
		if ( true === $value ) {
			return 'true';
		}
		if ( false === $value ) {
			return 'false';
		}
		if ( null === $value ) {
			return 'null';
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		return '(non-scalar)';
	}

	/**
	 * Case sensitivity section: the mode, what it means and what the
	 * operator has to install for the full behaviour (audit D-02).
	 *
	 * @param array $results Health results (H-C06 carries the mode).
	 * @return string
	 */
	public static function renderCaseSensitivity( $results = array() ) {
		$message = '';
		$status  = '';
		foreach ( (array) $results as $result ) {
			if ( ! $result instanceof Result || 'H-C06' !== $result->id ) {
				continue;
			}
			$message = (string) $result->message;
			$status  = (string) $result->status;
		}
		$degraded = ( false !== stripos( $message, 'degraded' ) );
		$mode     = $degraded ? \Slonik\Schema\CiMode::DEGRADED : \Slonik\Schema\CiMode::CITEXT;
		$info     = \Slonik\Schema\CiMode::describe( $mode );

		$out  = '<h3>Case sensitivity</h3>';
		$out .= '<p><strong>' . self::esc( $info['label'] ) . '</strong> &mdash; ' . self::esc( $info['summary'] ) . '</p>';
		if ( '' !== $info['needs'] ) {
			$out .= '<p><strong>What you need for the full MySQL behaviour:</strong></p><ol>';
			foreach ( \Slonik\Schema\CiMode::installSteps() as $step ) {
				$out .= '<li>' . self::esc( $step ) . '</li>';
			}
			$out .= '</ol>';
		}
		$out .= '<p>Set <code>WPSLONIK_CI_MODE</code> to <code>citext</code> or <code>degraded</code> in '
			. '<code>wp-config.php</code> to choose the mode explicitly.</p>';
		return $out;
	}

	/**
	 * Maintenance tab: descriptive text only (actions run via WP-CLI).
	 *
	 * @return string
	 */
	public static function renderMaintenance() {
		return '<p>Maintenance runs through WP-CLI: <code>wp slonik install-dropin</code>, '
			. '<code>wp slonik chain build|status|restore</code>, <code>wp slonik schema:check --repair</code>, '
			. '<code>wp slonik compat-log clear</code>.</p>';
	}

	public static function register() {
		if ( ! function_exists( 'add_management_page' ) ) {
			return;
		}
		add_management_page( 'PostgreSQL', 'PostgreSQL', 'manage_options', 'postgresql-drop-in', array( __CLASS__, 'render' ) );
	}

	public static function render() {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			if ( function_exists( 'wp_die' ) ) {
				wp_die( 'Forbidden.' );
			}
			return;
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'health';
		if ( ! isset( self::TABS[ $tab ] ) ) {
			$tab = 'health';
		}
		echo self::renderTabs( $tab );
		echo self::renderTab( $tab );
	}

	/**
	 * @param string $tab Active tab key.
	 * @return string
	 */
	public static function renderTab( $tab ) {
		if ( 'log' === $tab ) {
			$entries = array();
			if ( defined( 'WP_CONTENT_DIR' ) ) {
				$log     = new \Slonik\Audit\CompatLog( WP_CONTENT_DIR . '/wpslonik/compat-log.jsonl' );
				$entries = $log->read( 200 );
			}
			return self::renderLogTable( $entries );
		}
		if ( 'settings' === $tab ) {
			$defines = array();
			foreach ( get_defined_constants( true ) as $group ) {
				if ( ! is_array( $group ) ) {
					continue;
				}
				foreach ( $group as $name => $value ) {
					if ( 0 === strpos( (string) $name, 'WPSLONIK_' ) ) {
						$defines[ $name ] = $value;
					}
				}
			}
			return self::renderSettingsTable( $defines ) . self::renderCaseSensitivity( \Slonik\Cli\HealthCommand::tabResults() );
		}
		if ( 'maintenance' === $tab ) {
			return self::renderMaintenance();
		}
		if ( 'compat' === $tab ) {
			return self::renderCompatTab();
		}
		$results = \Slonik\Cli\HealthCommand::tabResults();
		return self::renderHealthTable( $results );
	}
}
