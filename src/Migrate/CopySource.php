<?php
/**
 * Row source seam for the copy engine: real implementation is
 * MySqlSource, unit tests inject fake streaming rows (no live MySQL
 * required for copier unit tests).
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Migrate;

interface CopySource {

	/**
	 * @return iterable<int,array<string,?string>> Associative rows keyed by column name.
	 */
	public function stream( $sql );

	/**
	 * @return string scalar value for small scalar queries (COUNT(*)).
	 */
	public function value( $sql );
}
