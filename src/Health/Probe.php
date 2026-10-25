<?php
/**
 * Read-only database probe for health checks.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Health;

interface Probe {

	/**
	 * @param string $sql Read-only SQL.
	 * @return mixed Single scalar value.
	 */
	public function queryValue( $sql );

	/**
	 * @param string $sql Harmless SQL (e.g. SET).
	 * @return bool
	 */
	public function execOk( $sql );
}
