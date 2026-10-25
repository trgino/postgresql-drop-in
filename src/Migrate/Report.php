<?php
/**
 * Pure output shaping for the migration CLI (docs/15 §3/§4): table/json/yaml
 * row rendering and the plan/summary lines. English strings (public
 * artifact), em-dash summary in the house style.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Migrate;

final class Report {

	/**
	 * Render rows in one of table|json|yaml (docs/15 §3).
	 *
	 * @param string           $format table|json|yaml.
	 * @param array<int,array> $rows   Associative rows (all share keys).
	 * @param array<int,string>$fields Column order.
	 * @return string Rendered text (no trailing newline).
	 */
	public static function format( string $format, array $rows, array $fields ): string {
		$format = strtolower( $format );
		if ( 'json' === $format ) {
			$out = array();
			foreach ( (array) $rows as $row ) {
				$item = array();
				foreach ( (array) $fields as $f ) {
					$item[ $f ] = $row[ $f ] ?? null;
				}
				$out[] = $item;
			}
			return (string) json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}
		if ( 'yaml' === $format ) {
			$lines = array();
			foreach ( (array) $rows as $row ) {
				$parts = array();
				foreach ( (array) $fields as $f ) {
					$parts[] = $f . ': ' . str_replace( "\n", ' ', (string) ( $row[ $f ] ?? '' ) );
				}
				$lines[] = '- ' . implode( ', ', $parts );
			}
			return implode( "\n", $lines );
		}
		// Table.
		$widths = array();
		foreach ( (array) $fields as $f ) {
			$widths[ $f ] = strlen( (string) $f );
		}
		foreach ( (array) $rows as $row ) {
			foreach ( (array) $fields as $f ) {
				$widths[ $f ] = max( $widths[ $f ], strlen( (string) ( $row[ $f ] ?? '' ) ) );
			}
		}
		$lines = array();
		$head  = array();
		$rule  = array();
		foreach ( (array) $fields as $f ) {
			$head[] = sprintf( '%-' . $widths[ $f ] . 's', (string) $f );
			$rule[] = str_repeat( '-', $widths[ $f ] );
		}
		$lines[] = implode( '  ', $head );
		$lines[] = implode( '  ', $rule );
		foreach ( (array) $rows as $row ) {
			$cells = array();
			foreach ( (array) $fields as $f ) {
				$cells[] = sprintf( '%-' . $widths[ $f ] . 's', (string) ( $row[ $f ] ?? '' ) );
			}
			$lines[] = implode( '  ', $cells );
		}
		return implode( "\n", $lines );
	}

	/**
	 * @return string The summary line (em-dash house style).
	 */
	public static function planSummary( array $plan ): string {
		$planned   = 0;
		$done      = 0;
		$blocked   = 0;
		$excluded  = 0;
		$rows      = 0;
		foreach ( (array) $plan['tables'] as $row ) {
			++$rows;
			switch ( (string) $row['state'] ) {
				case 'done':
					++$done;
					break;
				case 'blocked':
					++$blocked;
					break;
				case 'excluded':
					++$excluded;
					break;
				default:
					++$planned;
			}
		}
		$warn = count( (array) $plan['warnings'] );
		return 'Migration plan — ' . $rows . ' table(s): ' . $planned . ' planned, '
			. $done . ' done, ' . $excluded . ' excluded, ' . $blocked . ' blocked, '
			. $warn . ' warning(s)';
	}

	/**
	 * Cutover/next-step footer (docs/15 §5.7/§7).
	 *
	 * @return string Multi-line footer text (leading newline included).
	 */
	public static function nextSteps(): string {
		return "\nNext steps (cutover, operator actions):\n"
			. "  1. wp slonik migrate verify\n"
			. "  2. Set WPSLONIK_ENABLED=true in wp-config.php (DB_* stays as the MySQL backup)\n"
			. "  3. wp slonik health\n"
			. "  4. Close maintenance mode";
	}

	/**
	 * @return string Redacted-safe value display for describe() lines.
	 */
	public static function redact( string $value ): string {
		return '' === $value ? '(empty)' : '***';
	}
}
