<?php
/**
 * Health check contract.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Health;

interface Check {

	/**
	 * @param Env $env Detected environment.
	 * @return Result|Result[] One result, or one per sub-check (flattened by the Runner).
	 */
	public function check( Env $env );
}
