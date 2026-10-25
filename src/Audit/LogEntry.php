<?php
/**
 * Normalizer for compat log entries (docs/10 §4).
 *
 * Whitelists the kind, coerces scalars, defaults count/first/last so the
 * ring buffer can bump counters without trusting caller input.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Audit;

final class LogEntry {

	const KINDS = array( 'error', 'warn', 'slow', 'unsupported' );

	/**
	 * @param array $entry Raw entry.
	 * @return array Normalized entry.
	 */
	public static function build( $entry ) {
		$entry = (array) $entry;
		$kind  = isset( $entry['kind'] ) ? (string) $entry['kind'] : '';
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			$kind = 'error';
		}
		$now = time();
		return array(
			'ts'     => isset( $entry['ts'] ) ? (int) $entry['ts'] : $now,
			'kind'   => $kind,
			'rule'   => isset( $entry['rule'] ) ? (string) $entry['rule'] : '',
			'shape'  => isset( $entry['shape'] ) ? (string) $entry['shape'] : '',
			'sql'    => isset( $entry['sql'] ) ? (string) $entry['sql'] : '?',
			'source' => isset( $entry['source'] ) ? (string) $entry['source'] : '',
			'count'  => isset( $entry['count'] ) ? max( 1, (int) $entry['count'] ) : 1,
			'first'  => isset( $entry['first'] ) ? (int) $entry['first'] : $now,
			'last'   => isset( $entry['last'] ) ? (int) $entry['last'] : $now,
		);
	}
}
