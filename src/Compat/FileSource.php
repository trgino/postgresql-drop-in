<?php
/**
 * Source of PHP files to scan (docs/10 §2 layer 1).
 *
 * Files are (path => contents) pairs so virtual sources (arrays in tests,
 * directories in production) share one shape.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Compat;

interface FileSource {

	/**
	 * @return array<int, array{path:string, contents:string}>
	 */
	public function files() : array;
}
