<?php
/**
 * `wp slonik compat-log` commands.
 *
 * Pure renderers (unit-tested) + int-returning runner; entry exits
 * (M4a ManageCommand pattern).
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Cli;

use Slonik\Audit\CompatLog;

final class CompatCommand {

	/**
	 * @param array $entries Compat entries.
	 * @param array $assoc Assoc args (rule, since).
	 * @return string
	 */
	public static function renderList( $entries, $assoc ) {
		$assoc = (array) $assoc;
		$rule  = isset( $assoc['rule'] ) ? (string) $assoc['rule'] : '';
		$since = isset( $assoc['since'] ) ? strtotime( (string) $assoc['since'] ) : false;
		$lines = array();
		foreach ( (array) $entries as $entry ) {
			$entry = (array) $entry;
			if ( '' !== $rule && (string) $entry['rule'] !== $rule ) {
				continue;
			}
			if ( false !== $since && (int) $entry['last'] < (int) $since ) {
				continue;
			}
			$lines[] = CompatLog::formatStamp( (int) $entry['last'] ) . ' ' . $entry['kind'] . ' ' . $entry['rule']
				. ' x' . $entry['count'] . ' ' . $entry['sql']
				. ( isset( $entry['source'] ) && '' !== (string) $entry['source'] ? ' @' . $entry['source'] : '' );
		}
		if ( array() === $lines ) {
			return "no entries\n";
		}
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * @param array $stats CompatLog::stats() output.
	 * @return string
	 */
	public static function renderStats( $stats ) {
		$kinds    = isset( $stats['kinds'] ) ? (array) $stats['kinds'] : array();
		$errors   = isset( $kinds['error'] ) ? (int) $kinds['error'] : 0;
		$warnings = isset( $kinds['warn'] ) ? (int) $kinds['warn'] : 0;
		return 'errors: ' . $errors . ', warnings: ' . $warnings . "\n";
	}

	/**
	 * @param array $entries Compat entries.
	 * @return string Redacted JSON array.
	 */
	public static function renderExport( $entries ) {
		$json = json_encode( array_values( (array) $entries ) );
		return is_string( $json ) ? $json : '[]';
	}

	/**
	 * @param string $file Log file.
	 * @param string $sub list|stats|clear|export.
	 * @param array  $assoc Assoc args.
	 * @return int Exit code: 0 ok, 3 usage.
	 */
	public static function run( $file, $sub, $assoc ) {
		$log = new CompatLog( (string) $file );
		if ( 'stats' === $sub ) {
			echo self::renderStats( $log->stats() );
			return 0;
		}
		if ( 'list' === $sub ) {
			echo self::renderList( $log->read( 500 ), (array) $assoc );
			return 0;
		}
		if ( 'export' === $sub ) {
			echo self::renderExport( $log->read( 500 ) );
			return 0;
		}
		if ( 'clear' === $sub ) {
			echo $log->clear() ? "cleared\n" : "nothing to clear\n";
			return 0;
		}
		echo "usage: wp slonik compat-log <list|stats|clear|export>\n";
		return 3;
	}

	/**
	 * WP-CLI entry: wp slonik compat-log <subcommand>.
	 *
	 * @param array $args  Positional args (subcommand first).
	 * @param array $assoc Assoc args.
	 */
	public static function compatLog( $args, $assoc ) {
		$args = array_values( (array) $args );
		$sub  = isset( $args[0] ) ? (string) $args[0] : '';
		$file = self::logFile();
		$code = self::run( $file, $sub, (array) $assoc );
		exit( $code );
	}

	private static function logFile() {
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			return WP_CONTENT_DIR . '/wpslonik/compat-log.jsonl';
		}
		return '';
	}
}
