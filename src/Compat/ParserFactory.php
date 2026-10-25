<?php
/**
 * Parser factory seam (docs/10 §2).
 *
 * Lets tests inject failures and keeps Collector from binding to the
 * nikic/php-parser library directly.
 *
 * PHP 7.4 compatible.
 *
 * @package Slonik
 */

declare( strict_types=1 );

namespace Slonik\Compat;

interface ParserFactory {

	/**
	 * @return object Parser with a parse(string): ?array method.
	 * @throws \Throwable when the backing library is unavailable.
	 */
	public function create();
}
